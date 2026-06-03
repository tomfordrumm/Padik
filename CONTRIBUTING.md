# Contributing

Thanks for considering a contribution to Padik.

## Local Setup

```sh
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
```

For local development, run:

```sh
composer run dev
```

## Quality Checks

Run the focused checks before opening a pull request:

```sh
composer lint:check
npm run format:check
npm run lint:check
npm run types:check
php artisan test --compact
```

Use `composer lint`, `npm run format`, and `npm run lint` to fix formatting
locally before committing.

## Pull Requests

- Keep changes focused.
- Add or update tests for behavior changes.
- Avoid committing generated build output, local environment files, or IDE files.
- Document security-sensitive changes, especially around authentication,
  authorization, broadcasting, and secret chats.
