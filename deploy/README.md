# Deployment configs

Server configuration kept in git, so a rebuilt box is a `cp` away rather than
an afternoon of remembering.

Nothing here is applied automatically. Each file says at the top how to
install it.

| File | What it is |
|---|---|
| `nginx/ws.sfamily.co.conf` | TLS websocket host in front of Reverb |
| `supervisor/famzone-workers.conf` | Reverb + the queue workers |

## First install on a new box

```bash
sudo apt install -y supervisor
sudo mkdir -p /var/log/famzone && sudo chown www-data:www-data /var/log/famzone

# nginx
sudo cp deploy/nginx/ws.sfamily.co.conf /etc/nginx/sites-available/ws.sfamily.co
sudo ln -sfn /etc/nginx/sites-available/ws.sfamily.co /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d ws.sfamily.co

# supervisor
sudo cp deploy/supervisor/famzone-workers.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status
```

Check these two before the first `supervisorctl update` and edit the conf if
they differ — a mismatched `user` is the usual reason a worker starts, fails
to write to `storage/logs`, and dies in a restart loop:

```bash
stat -c '%U' /var/www/famzone/storage/logs    # -> the `user` value
which php                                     # -> the php path
```

## Every deploy

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache

# Both hold your code in memory. Without this you ship an update they keep
# ignoring, and the symptom is that the fix "did not work".
php artisan reverb:restart
php artisan queue:restart
```

## Required `.env` on the server

```
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=redis

REVERB_APP_ID=...
REVERB_APP_KEY=...
REVERB_APP_SECRET=...

# Where the Laravel app publishes events — same box, over loopback, no TLS.
REVERB_HOST="localhost"
REVERB_PORT=8080
REVERB_SCHEME=http

# What the Reverb process binds to. Without these it binds 0.0.0.0 and the
# socket server is reachable directly, bypassing nginx and TLS.
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
```

`REVERB_APP_KEY` is public — it ships in the mobile app. `REVERB_APP_SECRET`
signs channel authorisation and must never leave the server.

## Raise the file descriptor limit

Every open connection is a file descriptor. The default of 1024 caps the whole
app at roughly a thousand concurrent users, and the failure mode is refused
connections rather than anything in a log.

```bash
# /etc/security/limits.conf
www-data soft nofile 65535
www-data hard nofile 65535
```

Supervisor has its own limit — set `minfds=65535` in `/etc/supervisor/supervisord.conf`,
then `sudo systemctl restart supervisor`.

## Health checks

```bash
sudo supervisorctl status                     # all RUNNING
redis-cli ping                                # PONG
php artisan route:list --path=broadcasting    # POST api/v1/broadcasting/auth
npx wscat -c "wss://ws.sfamily.co/app/<REVERB_APP_KEY>?protocol=7"
```

The last one should answer with `pusher:connection_established` and a socket id.
