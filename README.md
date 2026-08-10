# Maestro

Maestro is a multi-tenant operating system for music studios. It brings scheduling, teaching, family communication, learning, billing, payroll, and business operations into one calm product while keeping every studio strictly isolated.

This repository is a clean-room implementation. Scrape captures, copied application code, credentials, personal data, and unlicensed third-party assets must never be committed or used by the product.

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

Compose creates a restricted `maestro_runtime` PostgreSQL role for exercising row-level security. Run migrations as the `maestro` owner, then run long-lived web and worker processes with the restricted role in production. The test suite uses `DB_RUNTIME_USERNAME` and `DB_RUNTIME_PASSWORD` to prove default-deny and cross-studio write rejection against that role.

## Quality gates

```bash
./scripts/quality/all.sh
```

The combined gate scans new product paths for secrets, validates/audits the Laravel and Next applications, runs their tests and production build, and validates/regenerates the OpenAPI contract. CI repeats the database suite on PostgreSQL 18 with Redis 8.

Behavioral coverage—not a line-coverage percentage—is the release gate for tenancy, authorization, recurrence, money, and side effects. See [the parity matrix](docs/product/parity-matrix.md).

## Repository safety

Keep behavioral research outside this repository. The committed history contains only Maestro product code, tests, contracts, and documentation; CI scans every product change for secrets before it can merge.
