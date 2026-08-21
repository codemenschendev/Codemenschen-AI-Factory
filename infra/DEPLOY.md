# Deploy — codemenschen.at server

Host: `ssh -p 7172 root@65.108.206.249`. Apache terminates TLS; all services
bind to 127.0.0.1 (ports chosen to avoid existing services: postgres 5433,
redis 6380, api 8181, web 3105).

## One-time setup

```bash
# 1. Code
mkdir -p /var/www/ai-factory && cd /var/www/ai-factory
git clone <repo-url> .          # or rsync from the workstation until the repo has a remote

# 2. Secrets
cp infra/.env.example infra/.env         # set POSTGRES_PASSWORD
cp apps/api/.env.example apps/api/.env   # set APP_KEY, DB (127.0.0.1:5433), REDIS (6380), Stripe keys

# 3. Stack
cd infra && docker compose -f docker-compose.prod.yml up -d --build
docker compose exec api php artisan migrate --force

# 4. Apache vhosts + TLS
apt-get install -y apache2-utils   # if htpasswd missing
htpasswd -c /etc/apache2/.htpasswd-appwerk-admin patrick
a2enmod proxy proxy_http
cp infra/apache/*.conf /etc/apache2/sites-available/
a2ensite appwerk.codemenschen.at api.appwerk.codemenschen.at admin.appwerk.codemenschen.at
apachectl configtest && systemctl reload apache2
certbot --apache -d appwerk.codemenschen.at -d api.appwerk.codemenschen.at -d admin.appwerk.codemenschen.at
```

## Update deploy

```bash
cd /var/www/ai-factory && git pull
cd infra && docker compose -f docker-compose.prod.yml up -d --build
docker compose exec api php artisan migrate --force
```

## Customer app backends (Type B, MVP 1 Phase 3+)

Provisioner starts one PocketBase container per app on a loopback port,
writes an Apache vhost for `<slug>.apps.codemenschen.at` (wildcard DNS already
in place), and issues a per-host certbot cert. No DNS changes needed per app.

## Notes

- OpenClaw gateway already runs on this host at 127.0.0.1:18789 — keep
  loopback-only; the Factory API talks to it via hook token (PLAN.md §6).
- Disk cleaned to 41 % on 2026-08-20; watch Docker build cache growth
  (`docker system df`).

## Host relay (OpenClaw agent bridge)

Code stages (coding/fix) run through the full OpenClaw agent on the host,
not through the gateway's tool-less completions endpoint. The relay runs as
the `openclaw` user (user-level systemd, like the manager's portal-worker):

```bash
install -o openclaw -g openclaw -m 600 /dev/null /home/openclaw/.config/ai-factory-relay.env
cat > /home/openclaw/.config/ai-factory-relay.env <<EOT
RELAY_TOKEN=<same as OPENCLAW_RELAY_TOKEN in infra/.env>
OPENCLAW_BIN=/home/openclaw/.nvm/versions/node/v22.23.1/bin/openclaw
PATH=/home/openclaw/.nvm/versions/node/v22.23.1/bin:/usr/local/bin:/usr/bin:/bin
EOT
cp infra/host-relay/ai-factory-relay.service /home/openclaw/.config/systemd/user/
loginctl enable-linger openclaw
sudo -u openclaw XDG_RUNTIME_DIR=/run/user/1006 systemctl --user daemon-reload
sudo -u openclaw XDG_RUNTIME_DIR=/run/user/1006 systemctl --user enable --now ai-factory-relay
curl -s http://127.0.0.1:8310/healthz
```
