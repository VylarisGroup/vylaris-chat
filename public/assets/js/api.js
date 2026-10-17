// Dünner fetch-Wrapper mit CSRF-Header, Zeitlimit und einheitlichen Fehlern.

import { state } from './util.js';

export class ApiError extends Error {
  constructor(status, message, data = {}) {
    super(message);
    this.status = status;
    this.data = data;
  }
}

export const api = {
  csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',
  onAuthLost: null,

  url(path) {
    return `${state.base}${path}`;
  },

  async request(method, path, body, { timeout = 30000, signal } = {}) {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), timeout);
    if (signal) signal.addEventListener('abort', () => ctrl.abort());
    const headers = { Accept: 'application/json', 'X-CSRF-Token': this.csrf };
    let payload;
    if (body instanceof FormData) {
      payload = body;
    } else if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      payload = JSON.stringify(body);
    }
    let res;
    try {
      res = await fetch(this.url(path), { method, headers, body: payload, credentials: 'same-origin', signal: ctrl.signal });
    } catch (e) {
      throw new ApiError(0, e.name === 'AbortError' ? 'Zeitüberschreitung bei der Verbindung.' : 'Keine Verbindung zum Server.');
    } finally {
      clearTimeout(timer);
    }
    let data = {};
    try { data = await res.json(); } catch { /* leere Antwort */ }
    if (!res.ok) {
      if (res.status === 401 && this.onAuthLost) this.onAuthLost();
      throw new ApiError(res.status, data.error || `Fehler ${res.status}`, data);
    }
    return data;
  },

  get(path, opts) { return this.request('GET', path, undefined, opts); },
  post(path, body, opts) { return this.request('POST', path, body ?? {}, opts); },
  put(path, body, opts) { return this.request('PUT', path, body ?? {}, opts); },
  patch(path, body, opts) { return this.request('PATCH', path, body ?? {}, opts); },
  del(path, body, opts) { return this.request('DELETE', path, body, opts); },

  /** POST (oder GET bei body=null) mit Datei-Antwort und anschliessendem Browser-Download. */
  async download(path, body, { timeout = 120000 } = {}) {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), timeout);
    let res;
    try {
      res = await fetch(this.url(path), {
        method: body === null ? 'GET' : 'POST', credentials: 'same-origin', signal: ctrl.signal,
        headers: { Accept: 'application/json, */*', 'X-CSRF-Token': this.csrf, 'Content-Type': 'application/json' },
        body: body === null ? undefined : JSON.stringify(body ?? {}),
      });
    } catch (e) {
      throw new ApiError(0, e.name === 'AbortError' ? 'Zeitüberschreitung bei der Verbindung.' : 'Keine Verbindung zum Server.');
    } finally {
      clearTimeout(timer);
    }
    if (!res.ok) {
      let data = {};
      try { data = await res.json(); } catch { /* keine JSON-Antwort */ }
      if (res.status === 401 && this.onAuthLost) this.onAuthLost();
      throw new ApiError(res.status, data.error || `Fehler ${res.status}`, data);
    }
    const blob = await res.blob();
    const name = /filename="([^"]+)"/.exec(res.headers.get('Content-Disposition') || '')?.[1] || 'meine-daten';
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = name;
    document.body.append(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 15000);
    return { name, size: blob.size };
  },

  /** Datei-Upload mit Fortschritt (XMLHttpRequest, da fetch keinen Upload-Fortschritt liefert). */
  upload(path, file, onProgress, field = 'file') {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      const fd = new FormData();
      fd.append(field, file, file.name);
      xhr.open('POST', this.url(path));
      xhr.setRequestHeader('X-CSRF-Token', this.csrf);
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.responseType = 'json';
      xhr.upload.onprogress = (e) => { if (e.lengthComputable && onProgress) onProgress(e.loaded / e.total); };
      xhr.onload = () => {
        const data = xhr.response || {};
        if (xhr.status >= 200 && xhr.status < 300) resolve(data);
        else {
          if (xhr.status === 401 && this.onAuthLost) this.onAuthLost();
          reject(new ApiError(xhr.status, data.error || (xhr.status === 413 ? 'Die Datei ist zu gross.' : `Fehler ${xhr.status}`), data));
        }
      };
      xhr.onerror = () => reject(new ApiError(0, 'Keine Verbindung zum Server.'));
      xhr.ontimeout = () => reject(new ApiError(0, 'Zeitüberschreitung beim Upload.'));
      xhr.timeout = 10 * 60 * 1000;
      xhr._abort = () => xhr.abort();
      xhr.onabort = () => reject(new ApiError(0, 'Upload abgebrochen.'));
      xhr.send(fd);
      onProgress?.(0, xhr);
    });
  },
};
