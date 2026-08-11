# ADR 0001: Modular monolith with shared-database tenancy

Status: accepted on 2026-08-10.

## Decision

Maestro is a modular monolith with two independently deployable applications:

- Laravel 13.24 is the source of truth for identity, tenants, authorization, domain rules, persistence, integrations, queues, scheduled work, and audit history.
- Filament 5.7, served by Laravel at `/manage`, is the complete authenticated studio and customer application, including login, onboarding, security, dashboards, portals, and studio operations. A separate platform-operations panel may be added later.
- Next.js 16.3 is public-only: marketing, acquisition, documentation/content, and unauthenticated credential landing pages. It does not own login, onboarding, security, dashboards, portals, or studio workflows.
- PostgreSQL 18 is the canonical database; Redis handles cache, sessions, queues, and rate limiting.

The initial deployment uses one PostgreSQL database and schema. Every tenant-owned row carries a non-null `studio_id`. A user may have many studio memberships and a different role in each.

## Tenant boundary

`Studio` is the tenant. The effective studio is resolved from an authenticated route or Filament tenant route, then checked against an active membership. The client may never select tenancy with an unverified header or submitted `studio_id`.

Tenant safety has three layers:

1. Request/job-scoped `TenantContext`, Eloquent scopes, and policies.
2. Composite database constraints that make cross-studio relationships invalid.
3. PostgreSQL row-level security using a restricted runtime role that cannot bypass RLS.

The People module is the first concrete implementation of all three layers: API and persistent Filament middleware initialize `TenantContext`, composite foreign keys reject cross-studio relationships, and forced PostgreSQL policies compare every tenant row with `app.current_studio_id`. Memberships and invitations now apply the same forced-RLS boundary, with authenticated-user and digest-scoped invitation contexts for their non-route workflows. Global browser-session metadata uses a separate forced-RLS policy keyed by `app.current_user_id`; it is never treated as tenant-owned data. The Compose bootstrap creates a non-owner, non-superuser, non-`BYPASSRLS` role, and the integration suite probes default-deny reads and cross-tenant writes through that role. Each new tenant or user-security table must adopt the corresponding direct-SQL test pattern.

Queues, scheduled commands, imports, exports, cache keys, search documents, files, notifications, realtime channels, and webhooks all carry explicit studio identity. Platform support access is separate, MFA-protected, time-bound, and audited.

## Authentication

The authenticated browser application is Laravel/Filament and uses Laravel's database-backed session guard and CSRF middleware directly. Fortify owns password/TOTP flows and Laravel's official WebAuthn passkey ceremonies. Browser sessions have server-enforced idle and absolute limits, expose only opaque user-scoped inventory IDs, and require recent password/passkey confirmation for revocation and sensitive identity changes. Personal access tokens are reserved for native clients and integrations. Public Next.js pages never become an authorization boundary or duplicate authenticated application state. During the transition, legacy `/studio/{studio}/*` links issue temporary redirects to Filament `/manage/studio/{studio}` and contain no Next.js studio shell, tenant gate, or dashboard implementation.

Production hostnames are expected to share a top-level domain:

- `www.example.com` — public Next.js marketing/content
- `app.example.com` — Laravel/Filament authenticated application
- `api.example.com` — Laravel JSON API

While transitional Next.js credential/onboarding pages still establish Laravel sessions across these hosts, production must use a secure shared parent-domain session cookie (for example `SESSION_DOMAIN=.example.com` and `SESSION_SECURE_COOKIE=true`) plus exact `SANCTUM_STATEFUL_DOMAINS` and credentialed CORS origins. Otherwise the temporary studio redirect reaches Filament without the authenticated session. This cross-host bridge is removed with the transitional identity pages; it is not permission to keep authenticated Next.js product state.

## Domain modules

Code is organized around `Identity`, `Studios`, `People`, `Scheduling`, `Attendance`, `Billing`, `Communications`, `Learning`, `Growth`, `Reporting`, and `Integrations`. API controllers and Filament actions call the same use-case classes. Domain writes publish after-commit events; externally visible side effects flow through an idempotent outbox.

## Core modeling choices

- Global `User`, tenant-specific `Membership`.
- Separate `Person`, `Household`, guardian, payer, learner, and teacher relationships.
- `EventSeries` plus occurrence overrides; edits explicitly target one occurrence, future occurrences, or the series.
- Attendance emits idempotent billing, payroll, make-up, notification, and learning effects.
- Money uses integer minor units and an immutable double-entry-style ledger with explicit payment allocations.
- Make-up credits use a transaction ledger, never a mutable counter.
- Payer and beneficiary are separate so businesses, sponsors, scholarships, and split guardians work naturally.

## Current version baseline

- PHP 8.5; Laravel `^13.8` (locked at 13.24.0).
- Filament `^5.0` (locked at 5.7.6).
- Sanctum 4.3.
- Node 24 LTS for production.
- Next.js 16.3.0 and React 19.2.8.
- Tailwind CSS 4.3.

Primary documentation: [Laravel 13](https://laravel.com/docs/13.x), [Sanctum](https://laravel.com/docs/13.x/sanctum), [Filament tenancy](https://filamentphp.com/docs/5.x/users/tenancy), [Filament security](https://filamentphp.com/docs/5.x/advanced/security), [Next.js support](https://nextjs.org/support-policy), [Next.js data security](https://nextjs.org/docs/app/guides/data-security), and [PostgreSQL row security](https://www.postgresql.org/docs/current/ddl-rowsecurity.html).

## Consequences

- We do not adopt database-per-tenant, schema-per-tenant, microservices, or a general-purpose page builder at launch.
- The first launch site builder is a fast branded one-page site plus accessible native booking components and a headless API.
- PostgreSQL integration tests are mandatory before tenant-owned modules are considered complete; SQLite may remain only as a fast unit/HTTP smoke layer.
- No feature is complete until the API, relevant UI, authorization, isolation, audit behavior, and automated acceptance checks are mapped in the parity matrix.
