#!/usr/bin/env python3
"""Get a Google Ads refresh token for the factory's own account, once.

    GOOGLE_ADS_CLIENT_ID=... GOOGLE_ADS_CLIENT_SECRET=... python3 google-ads-oauth.py

Opens the consent page in your browser, catches the redirect on localhost, exchanges the code and
prints the refresh token. The token is printed to this terminal and nowhere else: not logged, not
written to a file. Copy it into the server's .env yourself.

Desktop-app OAuth clients may redirect to a loopback address, which is why no hosted callback and
no copy-pasting of codes is needed. The client id and secret come from the environment or a
prompt, never from an argument, so they do not land in shell history.
"""
import http.server, json, os, secrets, sys, urllib.parse, urllib.request, webbrowser
from getpass import getpass

SCOPE = 'https://www.googleapis.com/auth/adwords'
PORT = 8765


def main():
    cid = os.environ.get('GOOGLE_ADS_CLIENT_ID') or input('client id: ').strip()
    sec = os.environ.get('GOOGLE_ADS_CLIENT_SECRET') or getpass('client secret: ').strip()
    if not cid or not sec:
        sys.exit('cần client id và client secret')

    redirect = f'http://127.0.0.1:{PORT}/'
    state = secrets.token_urlsafe(16)
    url = 'https://accounts.google.com/o/oauth2/v2/auth?' + urllib.parse.urlencode({
        'client_id': cid, 'redirect_uri': redirect, 'response_type': 'code', 'scope': SCOPE,
        'access_type': 'offline', 'prompt': 'consent', 'state': state,
    })

    got = {}

    class Catch(http.server.BaseHTTPRequestHandler):
        def do_GET(self):
            q = urllib.parse.parse_qs(urllib.parse.urlparse(self.path).query)
            got.update({k: v[0] for k, v in q.items()})
            self.send_response(200)
            self.send_header('content-type', 'text/html; charset=utf-8')
            self.end_headers()
            self.wfile.write('<p>Xong. Quay lại terminal.</p>'.encode())

        def log_message(self, *a):
            pass

    srv = http.server.HTTPServer(('127.0.0.1', PORT), Catch)
    print('mở trình duyệt để đồng ý quyền...', file=sys.stderr)
    webbrowser.open(url)
    srv.handle_request()

    if got.get('state') != state or 'code' not in got:
        sys.exit('không nhận được code (state sai hoặc bị từ chối)')

    body = urllib.parse.urlencode({
        'code': got['code'], 'client_id': cid, 'client_secret': sec,
        'redirect_uri': redirect, 'grant_type': 'authorization_code',
    }).encode()
    with urllib.request.urlopen('https://oauth2.googleapis.com/token', body, timeout=30) as r:
        tok = json.loads(r.read())

    rt = tok.get('refresh_token')
    if not rt:
        sys.exit('không có refresh_token trong câu trả lời: ' + json.dumps({k: v for k, v in tok.items() if k != 'access_token'}))

    print('\nGOOGLE_ADS_REFRESH_TOKEN=' + rt)
    print('\nDán vào apps/api/.env trên server, rồi restart api và horizon.', file=sys.stderr)


if __name__ == '__main__':
    main()
