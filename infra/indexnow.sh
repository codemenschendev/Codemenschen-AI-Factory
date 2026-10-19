#!/usr/bin/env bash
# Tells the IndexNow search engines (Bing, which feeds ChatGPT Search, Yandex, Seznam, Naver) that the
# pages of appmitki.com changed, so they read them again soon. Run after a deploy that changes content:
#   infra/indexnow.sh                 every URL in the sitemap
#   infra/indexnow.sh /de /en/app     only these paths
# The key is public by design: apps/web/public/<key>.txt proves the site sends the ping.
set -euo pipefail
HOST=appmitki.com
KEY=ea451b64fc07ea5806cbf3218a217e88
if [ $# -gt 0 ]; then URLS=$(for p in "$@"; do echo "https://$HOST$p"; done)
else URLS=$(curl -fsS "https://$HOST/sitemap.xml" | grep -o '<loc>[^<]*</loc>' | sed 's#</*loc>##g'); fi
BODY=$(python3 -c "import json,sys; print(json.dumps({'host': '$HOST', 'key': '$KEY', 'keyLocation': 'https://$HOST/$KEY.txt', 'urlList': sys.stdin.read().split()}))" <<<"$URLS")
curl -sS -o /dev/null -w "IndexNow: HTTP %{http_code} for $(wc -l <<<"$URLS" | tr -d ' ') URLs\n" -X POST https://api.indexnow.org/indexnow \
  -H 'content-type: application/json; charset=utf-8' --data "$BODY"
