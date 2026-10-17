// Zentraler Zustand mit einfachem Ereignissystem.

const listeners = new Map();

export const store = {
  me: null,
  ready: false,
  branding: { company_name: '', accent_color: '#2f6fed', has_logo: false },
  limits: { max_upload_bytes: 0, max_body: 8000, edit_minutes: 0 },
  reactions: [],
  conversations: new Map(), // id -> Konversation (Listenansicht)
  activeId: null,
  cursor: 0,
  notificationsUnread: 0,
  online: true,
  msgs: new Map(), // convId -> { items, hasBefore, hasAfter, lastReadAtOpen }
  members: new Map(), // convId -> Details (Mitglieder, Dateien, Links)
  drafts: new Map(),
  threadDrafts: new Map(), // "convId:rootId" -> Text
  thread: null, // { convId, rootId } wenn ein Thread offen ist
  typing: { conversation_id: 0, names: [] },
  tab: 'chats', // chats | groups | contacts
  showArchive: false,
  detailsOpen: false,
  mobileView: 'list', // list | chat

  on(evt, fn) {
    if (!listeners.has(evt)) listeners.set(evt, new Set());
    listeners.get(evt).add(fn);
    return () => listeners.get(evt).delete(fn);
  },
  emit(evt, data) {
    for (const fn of listeners.get(evt) || []) {
      try { fn(data); } catch (e) { console.error(`Listener-Fehler (${evt})`, e); }
    }
  },

  conv(id) { return this.conversations.get(id) || null; },
  get active() { return this.activeId ? this.conversations.get(this.activeId) || null : null; },

  upsertConversation(c) {
    this.conversations.set(c.id, c);
  },

  /** Sortiert: angepinnt zuerst, dann nach letzter Nachricht. */
  sorted({ archived = false, type = null } = {}) {
    return [...this.conversations.values()]
      .filter((c) => c.archived === archived && (!type || c.type === type))
      .sort((a, b) => (Number(b.pinned) - Number(a.pinned)) || String(b.last_message_at).localeCompare(String(a.last_message_at)) || b.id - a.id);
  },

  /** Ungelesene Nachrichten (ohne stummgeschaltete und archivierte Unterhaltungen, ausser bei Erwähnungen). */
  totalUnread() {
    let n = 0;
    for (const c of this.conversations.values()) {
      if (c.muted || c.archived) n += c.unread_mentions > 0 ? c.unread_mentions : 0;
      else n += c.unread;
    }
    return n;
  },

  cache(convId) {
    let e = this.msgs.get(convId);
    if (!e) {
      e = { items: [], hasBefore: false, hasAfter: false, loaded: false, lastReadAtOpen: 0 };
      this.msgs.set(convId, e);
    }
    return e;
  },
};
