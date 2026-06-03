# Padik

Padik is a self-hosted real-time messenger built with Laravel, Inertia, Vue,
Tailwind CSS, Fortify, Wayfinder, and Reverb.

The project currently supports shared rooms, direct messages, invitations,
notifications, unread counts, user profiles, two-factor authentication, and
secret chats that do not store message plaintext or ciphertext in the database.

## Status

Padik is early-stage software. It is suitable for evaluation, local development,
and contribution, but it should not be presented as production-hardened yet.

Known gaps:

- Admin moderation is only partially represented in the data model. A complete
  moderation UI, policies, and workflows still need to be implemented.
- Secret chats have not been independently audited. See `SECURITY.md` before
  relying on them for sensitive communication.
- Deployment defaults are intended for self-hosting experiments and should be
  reviewed before production use.

## Features

- General room at `/r/general`
- Group rooms with invitations
- Direct 1-on-1 conversations
- Real-time message delivery through Laravel Reverb
- Notifications and unread message counts
- User profiles
- Fortify authentication, registration, password reset, email verification, and
  two-factor authentication
- Secret chats with browser-side encryption and no server-side message storage

## Requirements

- PHP 8.3+
- Composer 2
- Node.js 22+
- npm
- Redis
- SQLite for the default local setup, or another Laravel-supported database

## Local Setup

```sh
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
```

Start the local development stack:

```sh
composer run dev
```

The app defaults to `http://localhost:8000`.

## Docker

The repository includes a Docker Compose setup for the Laravel app, Nginx,
Reverb, queue worker, scheduler, and Redis.

```sh
docker compose build
docker compose up
```

Create the application key and run migrations inside the app container if this
is the first run:

```sh
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

## Reverb

The default `.env.example` is configured for Reverb and Redis in Docker:

```dotenv
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=redis
REVERB_HOST=reverb
VITE_REVERB_HOST=localhost
```

For non-Docker development, adjust `REVERB_HOST`, `VITE_REVERB_HOST`, and Redis
settings to match your local services.

## Testing and Quality

```sh
composer lint:check
npm run format:check
npm run lint:check
npm run types:check
php artisan test --compact
```

Use the fixer commands locally when needed:

```sh
composer lint
npm run format
npm run lint
```

## Security

Do not commit `.env`, credentials, private keys, production database files, or
generated storage secrets. The repository ignores the common local files, but
maintainers should also review git history before publishing.

Security reports should follow `SECURITY.md`.

## License

Padik is open-sourced under the MIT license.
