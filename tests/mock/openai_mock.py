#!/usr/bin/env python3
"""Minimaler OpenAI-kompatibler Mock-Server für Tests (POST /v1/chat/completions).
Sonderfälle: «FEHLER» in der letzten Nachricht -> HTTP 500, «LANGSAM» -> 3 s Verzögerung,
Modell «o-mock» verlangt max_completion_tokens und lehnt temperature ab. GET /_last liefert die letzte Anfrage."""
import json, sys, time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

LAST = {}
KEY = 'sk-test-123'


class H(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def _send(self, code, obj):
        raw = json.dumps(obj).encode()
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def do_GET(self):
        if self.path == '/_last':
            return self._send(200, LAST)
        self._send(404, {})

    def do_POST(self):
        n = int(self.headers.get('Content-Length', '0'))
        body = json.loads(self.rfile.read(n) or b'{}')
        LAST.clear()
        LAST.update({'path': self.path, 'auth': self.headers.get('Authorization', ''), 'body': body})
        if self.path != '/v1/chat/completions':
            return self._send(404, {'error': {'message': 'not found'}})
        if self.headers.get('Authorization') != 'Bearer ' + KEY:
            return self._send(401, {'error': {'message': 'bad key'}})
        if body.get('model') == 'o-mock':
            if 'max_tokens' in body:
                return self._send(400, {'error': {'message': "Unsupported parameter: 'max_tokens'. Use 'max_completion_tokens' instead."}})
            if 'temperature' in body:
                return self._send(400, {'error': {'message': "Unsupported value: 'temperature' does not support 0.5 with this model."}})
        msgs = body.get('messages', [])
        last = next((m['content'] for m in reversed(msgs) if m['role'] == 'user'), '')
        tail = last.splitlines()[-1] if last else ''
        if 'FEHLER' in tail:
            return self._send(500, {'error': {'message': 'boom'}})
        if 'LANGSAM' in tail:
            time.sleep(3)
        text = '<think>interne Gedanken</think>Mock-Antwort auf: ' + last[:200]
        self._send(200, {'id': 'x', 'object': 'chat.completion', 'choices': [{'index': 0, 'message': {'role': 'assistant', 'content': text}, 'finish_reason': 'stop'}]})


if __name__ == '__main__':
    ThreadingHTTPServer(('127.0.0.1', int(sys.argv[1]) if len(sys.argv) > 1 else 8094), H).serve_forever()
