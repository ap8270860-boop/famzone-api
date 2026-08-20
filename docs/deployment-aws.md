# FamZone — AWS deployment runbook

Ubuntu 24.04 LTS · Nginx · PHP 8.3-FPM · MySQL 8 · Redis · Let's Encrypt

Target setup:

| Host | Purpose | Access |
|---|---|---|
| `admin-cp.mydomain.com` | Laravel app + admin panel | public, HTTPS |
| `db.mydomain.com` | phpMyAdmin | HTTPS, restricted to your IP |

> Throughout, replace `mydomain.com` with your real domain and `X.X.X.X` with your
> Elastic IP. Commands prefixed `PS>` run in **Windows PowerShell**; everything else
> runs **on the server** over SSH.

---

## 0. What this costs

| Item | Rate | ~Monthly |
|---|---|---|
| t3.small, ap-south-1 | $0.0224/hr | ~$16 |
| Public IPv4 (the Elastic IP) | $0.005/hr | ~$3.60 |
| 30 GB gp3 EBS | $0.08/GB-mo | ~$2.40 |
| **Total** | | **~$22/mo** |

Two things to know about the IPv4 charge. There is **no free tier** for public IPv4 —
since Feb 2024 every public IPv4 address is billed, including on free-tier accounts.
And an Elastic IP is billed **whether or not it's attached to anything**, so if you
tear down the instance later, release the IP too or you'll keep paying for it.

---

## 1. Launch the EC2 instance

In the AWS console, set your region to **Asia Pacific (Mumbai) ap-south-1** using the
region selector at top right. Do this first — resources are region-scoped, and an
Elastic IP in one region can't attach to an instance in another.

Go to **EC2 → Instances → Launch instances**.

**Name:** `famzone-prod`

**Application and OS Image:** search the Quick Start list for **Ubuntu**, and select
**Ubuntu Server 24.04 LTS (HVM), SSD Volume Type**, architecture **64-bit (x86)**.

> Don't pick 64-bit (Arm) unless you mean to. Arm/Graviton is cheaper and works fine,
> but a few PHP extensions and any x86-only binaries you add later get fiddly.

**Instance type:** `t3.small`

### Create the key pair — this is your PEM file

Under **Key pair (login)**, click **Create new key pair**.

- Name: `famzone-prod-key`
- Type: **RSA**
- Format: **.pem**

Click **Create key pair**. The browser downloads `famzone-prod-key.pem` **once**.
AWS keeps only the public half — if you lose this file you cannot SSH in with it
again, and recovery means detaching the root volume or rebuilding. Move it somewhere
permanent now:

```powershell
PS> mkdir $HOME\.ssh -Force
PS> Move-Item $HOME\Downloads\famzone-prod-key.pem $HOME\.ssh\
```

Back it up somewhere private — a password manager's file attachment is ideal. Do
**not** put it in the git repo.

### Network settings

Click **Edit**, then create a security group named `famzone-prod-sg` with three
inbound rules:

| Type | Port | Source | Why |
|---|---|---|---|
| SSH | 22 | **My IP** | admin access — never `0.0.0.0/0` |
| HTTP | 80 | `0.0.0.0/0` | Let's Encrypt validation + redirect to HTTPS |
| HTTPS | 443 | `0.0.0.0/0` | the actual site |

Selecting **My IP** pins SSH to your current home IP. If your ISP gives you a dynamic
address you'll need to update this rule when it changes — that's the tradeoff for not
leaving port 22 open to the internet.

Leave **Auto-assign public IP** as-is; the Elastic IP supersedes it in the next step.

### Storage

Change the root volume to **30 GiB**, type **gp3**. The 8 GiB default fills up fast
once you have MySQL data, Composer caches and logs.

Click **Launch instance**, then wait for **Instance state: Running** and
**Status checks: 2/2 passed**.

---

## 2. Allocate and attach the Elastic IP

The auto-assigned public IP changes every time the instance stops and starts, which
would break your DNS. An Elastic IP is static.

**EC2 → Network & Security → Elastic IPs → Allocate Elastic IP address**

Network border group: `ap-south-1`. Click **Allocate**.

Select the new address → **Actions → Associate Elastic IP address**:

- Resource type: **Instance**
- Instance: `famzone-prod`
- Private IP: leave as the default

Click **Associate**. Note the address — this is your `X.X.X.X` everywhere below.

---

## 3. Connect over SSH from Windows

Windows 10/11 ships OpenSSH, so PowerShell is all you need. But SSH refuses to use a
key file that other Windows accounts can read, and freshly downloaded files inherit
broad permissions. Fix that first:

```powershell
PS> cd $HOME\.ssh
PS> icacls .\famzone-prod-key.pem /inheritance:r
PS> icacls .\famzone-prod-key.pem /grant:r "$($env:USERNAME):(R)"
```

Then connect (`ubuntu` is the default user on Ubuntu AMIs — not `ec2-user`, not `root`):

```powershell
PS> ssh -i $HOME\.ssh\famzone-prod-key.pem ubuntu@X.X.X.X
```

Type `yes` at the host-key prompt.

> **"Permissions are too open" / "UNPROTECTED PRIVATE KEY FILE"** — the `icacls`
> commands above didn't take. Re-run them and confirm with
> `icacls .\famzone-prod-key.pem` that only your user is listed.
>
> **Connection times out** — the security group's SSH source doesn't match your
> current IP. Check <https://checkip.amazonaws.com> and update the rule.

Optionally save yourself the typing, in `$HOME\.ssh\config`:

```
Host famzone
    HostName X.X.X.X
    User ubuntu
    IdentityFile ~/.ssh/famzone-prod-key.pem
```

Then it's just `ssh famzone`.

---

## 4. Point GoDaddy DNS at the server

Do this now — DNS propagation runs in the background while you provision, and
Let's Encrypt in step 9 will fail if the records haven't resolved yet.

Sign in to GoDaddy → **My Products** → find your domain → **DNS** → **Manage Zones**
(or **Add Record** on the DNS Management page).

Add two **A** records:

| Type | Name | Value | TTL |
|---|---|---|---|
| A | `admin-cp` | `X.X.X.X` | 600 seconds |
| A | `db` | `X.X.X.X` | 600 seconds |

The **Name** field is the subdomain label only — enter `admin-cp`, not
`admin-cp.mydomain.com`. GoDaddy appends the domain automatically. Putting the full
hostname in creates `admin-cp.mydomain.com.mydomain.com`, which is the single most
common mistake here.

Use a 600-second TTL while you're setting up, so mistakes are cheap to correct. Raise
it to an hour once things are stable.

Verify from PowerShell — don't move on until both return your Elastic IP:

```powershell
PS> nslookup admin-cp.mydomain.com 8.8.8.8
PS> nslookup db.mydomain.com 8.8.8.8
```

GoDaddy usually propagates in a few minutes, occasionally up to an hour. Querying
`8.8.8.8` directly skips your local resolver's cache.

---

## 5. Provision the server

Everything from here runs over SSH on the instance.

### Base packages

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx mysql-server redis-server git unzip curl \
    php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl \
    php8.3-zip php8.3-bcmath php8.3-gd php8.3-intl php8.3-redis
```

Ubuntu 24.04 ships PHP 8.3 and MySQL 8.0 in its own repositories, so no third-party
PPA is needed. Laravel 13 requires PHP ^8.3, so 8.3 satisfies it. (If you later want
8.4, `ppa:ondrej/php` has it — but don't add complexity you don't need yet.)

Confirm:

```bash
php -v          # expect 8.3.x
mysql --version # expect 8.0.x
nginx -v
```

### Firewall

The security group already filters traffic, but a host firewall is a cheap second
layer — it protects you if the SG is ever loosened by accident.

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw --force enable
sudo ufw status
```

**Check `OpenSSH` is in the output before you disconnect.** Enabling UFW without it
locks you out, and the only way back in is detaching the root volume.

### Composer

```bash
cd ~
curl -sS https://getcomposer.org/installer -o composer-setup.php
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php
composer --version
```

### Node (for `npm run build`)

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
node -v
```

---

## 6. Set up MySQL

```bash
sudo mysql_secure_installation
```

Answer: validate-password plugin **y** (level **1 = MEDIUM**), set a strong root
password, remove anonymous users **y**, disallow remote root login **y**, remove test
database **y**, reload privileges **y**.

Now create the application database and a dedicated user. Never let the app connect as
root — if the app is compromised, a scoped user limits the damage to one schema.

```bash
sudo mysql
```

```sql
CREATE DATABASE famzone CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'famzone'@'localhost' IDENTIFIED BY 'a-long-random-password-here';
GRANT ALL PRIVILEGES ON famzone.* TO 'famzone'@'localhost';

FLUSH PRIVILEGES;
EXIT;
```

Generate that password with `openssl rand -base64 24` rather than inventing one.
Save it in your password manager — you'll need it in `.env` and in phpMyAdmin.

MySQL listens on `127.0.0.1` only by default on Ubuntu. Leave it that way. There is no
reason to expose port 3306 to the internet, and doing so is how databases get
ransomed.

---

## 7. Deploy the Laravel app

### Give the server read access to your private repo

Generate a deploy key on the server:

```bash
ssh-keygen -t ed25519 -C "famzone-prod deploy key" -f ~/.ssh/id_ed25519 -N ""
cat ~/.ssh/id_ed25519.pub
```

Copy the printed public key. In GitHub go to your repo →
**Settings → Deploy keys → Add deploy key**, title `famzone-prod`, paste the key, and
leave **Allow write access unchecked**. A read-only deploy key is scoped to this one
repo, unlike a personal access token which carries your whole account's reach.

Test it:

```bash
ssh -T git@github.com   # "Hi ap8270860-boop/famzone-api! You've successfully authenticated"
```

### Clone and install

```bash
sudo mkdir -p /var/www
sudo chown -R ubuntu:ubuntu /var/www
cd /var/www
git clone git@github.com:ap8270860-boop/famzone-api.git famzone
cd famzone

composer install --no-dev --optimize-autoloader
npm install && npm run build
```

`--no-dev` skips PHPUnit, Faker and friends — they have no business on a production
box. `--optimize-autoloader` builds a classmap, which is a real startup win.

### Configure the environment

```bash
cp .env.example .env
php artisan key:generate
nano .env
```

Set at minimum:

```dotenv
APP_NAME=FamZone
APP_ENV=production
APP_DEBUG=false
APP_URL=https://admin-cp.mydomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=famzone
DB_USERNAME=famzone
DB_PASSWORD=the-password-from-step-6

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_HOST=127.0.0.1

SESSION_SECURE_COOKIE=true
```

`APP_DEBUG=false` is not optional. With it on, any uncaught exception renders a stack
trace containing your database credentials and environment to whoever triggered it.

### Migrate and seed

```bash
php artisan migrate --force
php artisan db:seed --class=AdminSeeder --force
```

`--force` is required because Laravel refuses to run migrations in production
without it.

**Change the seeded admin password immediately** — `famzone@admin.com` / `password`
is fine on your laptop and unacceptable on a public host:

```bash
php artisan tinker
```

```php
$a = App\Models\Admin::where('email', 'famzone@admin.com')->first();
$a->password = Illuminate\Support\Facades\Hash::make('your-real-strong-password');
$a->save();
exit
```

### Permissions

Nginx and PHP-FPM run as `www-data`, which needs to write to two directories and
read everything else.

```bash
sudo chown -R www-data:www-data /var/www/famzone/storage /var/www/famzone/bootstrap/cache
sudo chmod -R 775 /var/www/famzone/storage /var/www/famzone/bootstrap/cache
sudo usermod -aG www-data ubuntu

php artisan storage:link
```

### Cache the config

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Re-run these after **every** `.env` or route change — once config is cached, edits to
`.env` are ignored until you re-cache. That surprises everyone once.

---

## 8. Nginx virtual hosts

### The app — `admin-cp.mydomain.com`

```bash
sudo nano /etc/nginx/sites-available/famzone
```

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name admin-cp.mydomain.com;

    root /var/www/famzone/public;
    index index.php;

    charset utf-8;
    client_max_body_size 20M;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header Referrer-Policy "strict-origin-when-cross-origin";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Never serve dotfiles — this is what stops /.env being downloadable.
    location ~ /\.(?!well-known).* {
        deny all;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_log  /var/log/nginx/famzone-error.log;
    access_log /var/log/nginx/famzone-access.log;
}
```

`client_max_body_size 20M` matters later — chat media uploads will hit it. Note that
`root` points at `public/`, never at the project root; pointing it one level up
exposes `.env`, `composer.json` and your entire source tree.

### phpMyAdmin — `db.mydomain.com`

Install from upstream rather than apt. The Ubuntu package pulls in Apache config you
don't want and lags behind on security fixes.

```bash
cd /tmp
curl -LO https://files.phpmyadmin.net/phpMyAdmin/5.2.3/phpMyAdmin-5.2.3-all-languages.tar.gz
tar xzf phpMyAdmin-5.2.3-all-languages.tar.gz
sudo mv phpMyAdmin-5.2.3-all-languages /var/www/phpmyadmin
sudo mkdir -p /var/www/phpmyadmin/tmp
sudo chown -R www-data:www-data /var/www/phpmyadmin
sudo chmod 750 /var/www/phpmyadmin/tmp
```

Configure it:

```bash
sudo cp /var/www/phpmyadmin/config.sample.inc.php /var/www/phpmyadmin/config.inc.php
sudo nano /var/www/phpmyadmin/config.inc.php
```

Set the blowfish secret to exactly 32 characters (generate with
`openssl rand -base64 24 | cut -c1-32`), and disable root login:

```php
$cfg['blowfish_secret'] = 'paste-your-32-character-string!!';

$cfg['Servers'][$i]['AllowRoot'] = false;
$cfg['TempDir'] = '/var/www/phpmyadmin/tmp';
```

Find your current public IP — you'll need it for the allowlist:

```powershell
PS> curl.exe https://checkip.amazonaws.com
```

Then the vhost:

```bash
sudo nano /etc/nginx/sites-available/phpmyadmin
```

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name db.mydomain.com;

    root /var/www/phpmyadmin;
    index index.php;

    # Only your IP may reach phpMyAdmin. Everyone else gets 403.
    # Let's Encrypt's HTTP-01 challenge must stay reachable, hence the
    # .well-known exception below.
    allow YOUR.PUBLIC.IP.HERE;
    deny all;

    client_max_body_size 64M;

    location /.well-known/acme-challenge/ {
        allow all;
        root /var/www/phpmyadmin;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /(libraries|setup|templates|sql)/ {
        deny all;
    }

    error_log /var/log/nginx/pma-error.log;
}
```

An exposed phpMyAdmin is scanned constantly by bots — `/phpmyadmin` is in every
scanner's wordlist. The allowlist is what makes putting it on a public hostname
reasonable. If your home IP rotates, add the new one here rather than removing the
rule.

### Enable both

```bash
sudo ln -s /etc/nginx/sites-available/famzone /etc/nginx/sites-enabled/
sudo ln -s /etc/nginx/sites-available/phpmyadmin /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default

sudo nginx -t && sudo systemctl reload nginx
```

`nginx -t` before every reload. A config error on reload takes the site down.

At this point `http://admin-cp.mydomain.com` should load over plain HTTP. Confirm
that before adding TLS — it isolates DNS/Nginx problems from certificate problems.

---

## 9. SSL certificates for both hosts

Install Certbot from snap. The apt package is older, and snap is what the EFF
currently recommends:

```bash
sudo snap install core && sudo snap refresh core
sudo apt remove -y certbot
sudo snap install --classic certbot
sudo ln -s /snap/bin/certbot /usr/local/bin/certbot
```

Issue a single certificate covering both hostnames:

```bash
sudo certbot --nginx \
  -d admin-cp.mydomain.com \
  -d db.mydomain.com \
  --agree-tos \
  -m you@youremail.com \
  --redirect
```

Certbot edits both vhosts in place — adding the 443 blocks, certificate paths, and an
HTTP→HTTPS redirect. `--redirect` is what forces the redirect rather than leaving
plain HTTP working.

**Preconditions, in order of how often they bite:** both A records must already
resolve to this server (step 4), port 80 must be open in the security group, and
Nginx must be running with those `server_name` values. Let's Encrypt validates by
fetching a file over HTTP from the real hostname — if any link in that chain is
missing, issuance fails.

Verify renewal works:

```bash
sudo certbot renew --dry-run
sudo systemctl list-timers | grep certbot
```

Certificates last 90 days; the snap installs a systemd timer that renews at ~60 days
unattended. The dry run proves that path works now rather than discovering it's broken
in two months.

Now visit:

- <https://admin-cp.mydomain.com/admin/login>
- <https://db.mydomain.com>
- <https://admin-cp.mydomain.com/api/v1/ping>

---

## 10. Queue worker and scheduler

You'll need these as soon as notifications and chat land. Setting them up now costs
five minutes.

```bash
sudo nano /etc/systemd/system/famzone-worker.service
```

```ini
[Unit]
Description=FamZone queue worker
After=network.target

[Service]
User=www-data
Group=www-data
Restart=always
RestartSec=5
ExecStart=/usr/bin/php /var/www/famzone/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now famzone-worker
sudo systemctl status famzone-worker
```

Scheduler, via cron:

```bash
sudo crontab -u www-data -e
```

```cron
* * * * * cd /var/www/famzone && php artisan schedule:run >> /dev/null 2>&1
```

This gets replaced by Horizon in Phase 0 step 6 — treat it as the interim setup.

---

## 11. Deploying updates

```bash
sudo nano /var/www/famzone/deploy.sh
```

```bash
#!/usr/bin/env bash
set -e

cd /var/www/famzone

php artisan down --render="errors::503" || true

git pull origin main
composer install --no-dev --optimize-autoloader
npm ci && npm run build

php artisan migrate --force

php artisan config:cache
php artisan route:cache
php artisan view:cache

sudo systemctl restart famzone-worker
php artisan up

echo "Deployed: $(git rev-parse --short HEAD)"
```

```bash
chmod +x /var/www/famzone/deploy.sh
```

Then each release is `ssh famzone` followed by `/var/www/famzone/deploy.sh`.

`set -e` aborts on the first failure, so a broken `composer install` won't leave you
half-deployed with the site already brought back up. Restarting the worker matters —
`queue:work` holds your code in memory and will keep running the old version
otherwise.

---

## 12. After it's live

**Do these soon:**

- Take an EBS snapshot before any risky change — **EC2 → Volumes → Actions → Create snapshot**
- Schedule MySQL dumps: `mysqldump famzone | gzip > /home/ubuntu/backups/famzone-$(date +\%F).sql.gz` in cron, then copy off-box to S3. A backup that only exists on the machine it's backing up is not a backup.
- `sudo apt install -y fail2ban` — bans repeated SSH brute-force attempts out of the box
- Set an AWS Budget alert at ~$30/mo so a surprise never compounds

**Watch out for:**

- Updating `.env` without re-running `php artisan config:cache` — the change silently does nothing
- Your home IP rotating, which locks you out of both SSH and phpMyAdmin. Fix both the security group and the Nginx `allow` line
- Stopping the instance to save money: the Elastic IP keeps billing, and the instance store is wiped

---

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| 502 Bad Gateway | PHP-FPM down or wrong socket. `sudo systemctl status php8.3-fpm`; confirm the socket path matches `fastcgi_pass` |
| 500, blank page | Permissions on `storage/`. Check `tail -50 storage/logs/laravel.log` |
| 404 on every route but `/` | `try_files` missing, or `root` not pointing at `public/` |
| Certbot: "Timeout during connect" | Port 80 blocked in the security group, or DNS not resolving yet |
| Certbot: "unauthorized" on `db.` | The `deny all` rule is blocking the ACME challenge — confirm the `.well-known` location block is present |
| 403 on phpMyAdmin | Your IP changed. Compare `curl https://checkip.amazonaws.com` against the `allow` line |
| Login redirect loop | `SESSION_SECURE_COOKIE=true` while browsing over plain HTTP |
| Changes not taking effect | Stale caches: `php artisan optimize:clear`, then re-cache |
