# Product parity and coverage matrix

This is the release ledger. A feature is `done` only when its acceptance behavior, tenant/role rules, API/domain path, relevant Filament and Next.js surfaces, audit effects, and automated tests are all present. `scaffolded` means implementation has begun but does not satisfy that exit rule.

| ID | Capability | Priority | Status | Required proof |
|---|---|---:|---|---|
| FND-01 | Global user identity and multi-studio memberships | P0 | scaffolded | API + policy + Filament tenant tests; cross-studio switching E2E |
| FND-02 | Studio onboarding, regional defaults, trial state | P0 | scaffolded | Validated create API, owner membership, onboarding E2E |
| FND-03 | Roles, granular permissions, invitations, suspension | P0 | scaffolded | Complete role matrix across API, Filament, Next, jobs |
| FND-04 | Password reset, verification, session security, MFA/passkeys | P0 | scaffolded | Throttle, fixation, reset invalidation, MFA recovery tests |
| FND-05 | Tenant context across HTTP, queues, scheduler, cache, files | P0 | scaffolded | Cross-tenant denial matrix for every execution surface |
| FND-06 | PostgreSQL RLS and composite tenant constraints | P0 | scaffolded | Direct-SQL default-deny integration suite |
| FND-07 | Audit log, impersonation controls, support access | P0 | planned | Immutable security-event assertions and admin E2E |
| FND-08 | Locale, IANA timezone, currency, accessible design tokens | P0 | scaffolded | DST, formatting, keyboard, contrast, responsive tests |
| FND-09 | Outbox, idempotency, retries, observability | P0 | planned | Duplicate delivery/replay and failed-job tests |
| FND-10 | Full tenant export, retention, deletion, restore | P0 | planned | Export/import round trip and restore drill |
| CRM-01 | People, households, guardians, adult students | P1 | scaffolded | Relationship/privacy matrix and household E2E |
| CRM-02 | Students, statuses, leads, trials, waiting list | P1 | scaffolded | Lifecycle transition and permission tests |
| CRM-03 | Teachers, office staff, substitutes, profiles | P1 | planned | Role/profile/availability tests |
| CRM-04 | Instruments, tags, custom fields, assignments | P1 | planned | Scoped uniqueness, bulk actions, export assertions |
| CRM-05 | Duplicate-safe CSV import and full export | P1 | planned | Partial failure, resume, idempotency, round-trip tests |
| CRM-06 | Lead pipeline, tasks, source attribution, sequences | P4 | planned | Funnel and automation acceptance suite |
| SCH-01 | Services, programs, categories, prices, policies | P1 | planned | Per-program/teacher/location override tests |
| SCH-02 | Locations, rooms, equipment, capacity | P1 | planned | Composite constraints and conflict matrix |
| SCH-03 | Teacher availability, travel buffers, time off | P1 | planned | DST and overlap property tests |
| SCH-04 | Event series, occurrences, exceptions, closures | P1 | planned | Recurrence property suite across DST and edits |
| SCH-05 | Private, group, open, workshop, camp, recital events | P1 | planned | Capacity/enrollment/visibility acceptance tests |
| SCH-06 | Calendar day/week/month/timeline/agenda views | P1 | planned | Desktop/mobile/keyboard/visual regression |
| SCH-07 | Conflict detection and constraint-ranked slot finder | P1 | planned | Concurrent booking and ranking scenarios |
| SCH-08 | Reschedule, substitute, clone, cancel scopes | P1 | planned | One/future/series behavior and side-effect preview tests |
| SCH-09 | External calendar feeds and two-way busy sync | P4 | planned | Provider contract and sync-loop tests |
| ATT-01 | Attendance statuses and bulk attendance | P1 | planned | Role, group, offline sync, and overdue tests |
| ATT-02 | Public/private lesson notes and templates | P1 | planned | Visibility, sanitization, attachment, notification tests |
| ATT-03 | Attendance-driven billing and payroll effects | P2 | planned | Idempotent golden-ledger scenarios |
| ATT-04 | Make-up credit transaction ledger and expiry | P3 | planned | Issue/use/reverse/expire/pool property tests |
| ATT-05 | Offline teacher agenda, notes, attendance, conflict sync | P5 | planned | Network loss and merge-conflict E2E |
| BIL-01 | Billing profiles: per lesson, tuition, hourly, packages | P2 | planned | Calendar-independent billing rule tests |
| BIL-02 | Immutable accounts and ledger entries | P2 | planned | Balanced-entry and mutation tests |
| BIL-03 | Split guardians, businesses, sponsors, scholarships | P2 | planned | Multi-payer allocation golden scenarios |
| BIL-04 | Invoices, lines, tax, discounts, credits, due dates | P2 | planned | Rounding, numbering, voiding, PDF tests |
| BIL-05 | Auto-invoicing preview and recurring runs | P2 | planned | Idempotency, timezone, retry, edit-impact tests |
| BIL-06 | Stripe Connect, payment methods, Auto Pay | P2 | planned | Signed webhook, replay, order, failure contract tests |
| BIL-07 | Manual payments, allocations, receipts, refunds | P2 | planned | Partial/over/refund/chargeback golden ledger |
| BIL-08 | Dunning, late fees, write-offs, reconciliation | P2 | planned | Scheduled-job and missed-webhook tests |
| PAY-01 | Teacher pay rules and category overrides | P2 | planned | Percentage/hour/fixed/per-student scenarios |
| PAY-02 | Payroll runs, adjustments, remittance, expense link | P2 | planned | Review/finalize/reverse/export tests |
| OPS-01 | Expenses, revenue, categories, receipt attachments | P4 | planned | Money/file authorization and export tests |
| OPS-02 | Mileage manual, recurring, and location capture | P4 | planned | Unit/route/privacy/offline tests |
| COM-01 | Unified family/studio conversation inbox | P5 | planned | Channel threading, assignment, consent, quiet hours |
| COM-02 | Email/SMS/in-app templates and placeholders | P3 | planned | Sanitization, locale, preview, missing-value tests |
| COM-03 | Immediate, scheduled, bulk, reminder messages | P3 | planned | Audience isolation, rate, retry, dedupe tests |
| COM-04 | Delivery, reply, failure, opt-out history | P3 | planned | Provider webhook and consent state tests |
| LRN-01 | Online resources, folders, permissions, streaming | P3 | planned | Signed URL, MIME, malware, tenant tests |
| LRN-02 | Practice timer, logs, goals, media, feedback | P3 | planned | Privacy, duration, upload, engagement tests |
| LRN-03 | Repertoire, assignments, milestones, performances | P3 | planned | Progress and recital readiness tests |
| LRN-04 | Homework/curriculum and parent digest | P5 | planned | Assignment workflow and notification tests |
| LIB-01 | Lending library, loans, due dates, reminders | P3 | planned | Availability, overdue, return history tests |
| POR-01 | Parent household/child switcher and family finances | P3 | planned | Minor/adult/guardian privacy E2E |
| POR-02 | Student calendar, notes, resources, practice | P3 | planned | Student-only isolation and responsive E2E |
| POR-03 | Booking, cancellation, payment self-service | P3 | planned | Policy cutoff, concurrency, idempotency E2E |
| GRW-01 | Registration/contact/booking forms | P4 | planned | Spam, consent, attribution, paid booking tests |
| GRW-02 | Holds, waitlists, capacity, abandoned recovery | P3 | planned | Expiry and race-condition tests |
| GRW-03 | Hosted one-page site, custom domain, branding, SEO | P4 | planned | Domain verification, CSP, a11y, performance tests |
| GRW-04 | Native embeddable components without iframe coupling | P4 | planned | Cross-origin, CSP, analytics, resize tests |
| GRW-05 | Teacher profiles, reviews, moderation, publishing | P3 | planned | Consent, moderation, aggregate tests |
| REP-01 | Operational dashboard and actionable alerts | P4 | planned | Metric definitions, drill-down, source reconciliation |
| REP-02 | Attendance, student, retention, lesson reports | P4 | planned | Known-fixture reconciliation and export tests |
| REP-03 | Revenue, A/R, tax, expense, payroll reports | P4 | planned | Ledger reconciliation and rounding tests |
| REP-04 | Calendar, mileage, practice, make-up reports | P4 | planned | Timezone/filter/export tests |
| INT-01 | Public versioned API keys, scopes, rate limits | P4 | planned | Scope/rotation/revocation/tenant tests |
| INT-02 | Signed webhooks, delivery logs, replay | P4 | planned | SSRF, signature, dedupe, backoff tests |
| INT-03 | Stripe, calendar, Zoom, accounting integrations | P4 | planned | Provider sandbox/contract suites |
| INT-04 | No-code automation rules | P5 | planned | Trigger/filter/action loop and quota tests |
| QUA-01 | WCAG 2.2 AA and keyboard release gate | P0 | scaffolded | Automated axe plus manual journey audits |
| QUA-02 | Desktop/tablet/mobile visual regression | P0 | scaffolded | Playwright snapshots for critical surfaces |
| QUA-03 | Performance budgets and large-tenant load tests | P0 | planned | p95 budgets and production-size fixtures |
| QUA-04 | Dependency, secret, SAST, and authorization CI | P0 | scaffolded | Required checks on every change |
| QUA-05 | Backup, PITR, disaster restore, incident drills | P0 | planned | Recorded restore and reconciliation exercises |

## Current verification

- Identity: headless Fortify registration/login/logout/reset/verification/confirmation, current-user lookup, JSON-body studio invitation preview/management/acceptance, fragment credential scrubbing, and two-path onboarding are scaffolded. Password compromise checks, reset throttling/invalidation, trusted browser boundaries, and membership/invitation RLS are covered. MFA, passkeys, session inventory/revocation, invitation resend, and full security audit/outbox remain next gaps, not completed capabilities; implement them against [Laravel 13 Fortify's TOTP and passkey contracts](https://laravel.com/docs/13.x/fortify) and the [identity acceptance specification](../security/identity-access-acceptance.md).
- Laravel: SQLite runs 61 tests with 57 passed, 434 assertions, and four PostgreSQL-only skips. A clean PostgreSQL 18 run passes all 61 tests / 480 assertions, including restricted-role membership/invitation RLS, token-scoped invitation access, browser-boundary controls, reset invalidation, and the existing studio/People/household policy coverage.
- Next.js: 10 test files / 51 tests pass; ESLint and the production build pass. The live identity transport smoke also passes against Laravel with cookie/CSRF registration and login, current-user lookup, onboarding, tenant membership lookup, and logout. Fragment credential scrubbing and invitation preview/acceptance are covered by focused frontend and Laravel tests.
- API contract: strict OpenAPI lint, deterministic TypeScript generation, and declaration typecheck.
- Visual smoke: landing and Studio Home reviewed at desktop and 390px mobile widths with no horizontal overflow or application console errors.
