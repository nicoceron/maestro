# Maestro

Maestro is a multi-tenant operating system for music studios. It brings scheduling, teaching, family communication, learning, billing, payroll, and business operations into one calm product while keeping every studio strictly isolated.

This repository is a clean-room implementation. The legacy scrape/recon material at the repository root is reference evidence only; no captured code, credentials, personal data, or third-party assets may be used by the application.

## Monorepo

- `apps/api` — Laravel 13 domain API, Sanctum authentication, queues, and Filament 5 studio operations.
- `apps/web` — Next.js 16.3 App Router experience for owners, teachers, families, students, and public booking.
- `docs/architecture` — accepted architecture decisions and security boundaries.
- `docs/product` — product vision and executable parity roadmap.

Laravel is the system of record. Both Next.js and Filament call the same policies and application actions; business rules never live only in a UI.

## Local development

Required: PHP 8.5, Composer 2, Node 24 LTS, pnpm 10, and Docker.

```bash
docker compose up -d

cd apps/api
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve
```

In a second terminal:

```bash
cd apps/web
pnpm install
pnpm dev
```

The web app runs at [http://localhost:3000](http://localhost:3000), the API at [http://localhost:8000](http://localhost:8000), and Mailpit at [http://localhost:8025](http://localhost:8025).

## Quality gates

```bash
cd apps/api
./vendor/bin/pint --test
php artisan test

cd ../web
pnpm lint
pnpm test:run
pnpm build
```

Behavioral coverage—not a line-coverage percentage—is the release gate for tenancy, authorization, recurrence, money, and side effects. See [the parity matrix](docs/product/parity-matrix.md).

## Repository safety

The original local recon commit contains captured credentials and personal fields. This repository has no remote and must not be pushed until those credentials are revoked and the original history is sanitized. New product commits intentionally exclude all pre-existing recon changes.
