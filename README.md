# FamZone API

Backend API for **FamZone** — a mobile-first safety and connection app for family and friend circles.
Chat, live location sharing, SOS alerts and a social feed, built on Laravel 13.

> Status: **Phase 0 — Foundation.** Base Laravel 13 install complete. Feature work has not started.

---

## Stack

| Layer | Choice |
|---|---|
| Framework | Laravel 13 (PHP 8.3+) |
| Database | MySQL 8 |
| Cache / Queues | Redis + Horizon |
| Real-time | Laravel Reverb (WebSockets) |
| Admin panel | Filament v5 + Spatie laravel-permission |
| Auth | Laravel Sanctum + phone/OTP via MSG91 |
| Storage | S3-compatible bucket, pre-signed uploads |
| Notifications | FCM / APNs (push), MSG91 (SMS) |
| AI | Claude via the Laravel AI SDK (`laravel/ai`) |
| Payments | Stripe (web) / RevenueCat (mobile IAP) |

Clients live in separate repos: React + Vite + TypeScript (web dashboard), Flutter (iOS + Android).

---

## Requirements

- PHP **8.3+** (8.4 recommended) with `pdo_mysql`, `mbstring`, `openssl`, `bcmath`, `intl`, `fileinfo`, `zip`
- Composer 2.x
- MySQL 8
- Node 20+ and npm
- Redis (added in a later step, not required to boot right now)

## Local setup

```bash
composer install

cp .env.example .env          # Windows: copy .env.example .env
php artisan key:generate
```

Create the database, then point `.env` at it:

```sql
CREATE DATABASE famzone CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=famzone
DB_USERNAME=root
DB_PASSWORD=
```

Run migrations and start the dev server:

```bash
php artisan migrate

npm install
composer run dev        # serves the app, queue worker, log tail and Vite together
```

The app is then at <http://localhost:8000>.

## Useful commands

```bash
php artisan test         # run the test suite
./vendor/bin/pint        # format code (PSR-12)
php artisan about        # environment summary
php artisan tinker       # REPL
```

## Layout

Standard Laravel 13 skeleton for now. A domain-based structure under `app/Domains/`
is introduced in the next step of Phase 0, before any feature work begins.

## Roadmap

**Phase 0 — Foundation**

- [x] Laravel 13 install, MySQL configured
- [ ] Domain-based folder structure and API conventions (versioning, response envelope, Form Requests, error handling)
- [ ] Sanctum + phone/OTP auth (MSG91)
- [ ] Filament v5 admin panel with Spatie roles & permissions
- [ ] Multi-channel notification system (push + SMS)
- [ ] Redis queues + Horizon
- [ ] One working Reverb channel, backend → Reverb → client
- [ ] Pre-signed S3 upload flow

**Phase 1 — MVP (target: day 65)**

- [ ] Circles & groups with roles
- [ ] 1:1 and group chat — text, media, receipts, typing indicators, real-time
- [ ] Foreground live location sharing on a map
- [ ] One-tap SOS: push + in-app + SMS
- [ ] Stories / post feed with expiry
- [ ] AI chatbot — 5 free messages, then paywalled
- [ ] Admin moderation: report, block, delete

**Phase 2+ (not in scope, but the architecture must not block it)**

Voice/video calling, background location tracking, geofencing and alarms, voice-call SOS fallback.
