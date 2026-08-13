# Scheduling and attendance acceptance

This document fixes the current SCH-01 through SCH-08 and ATT-01/ATT-02 evidence boundary. It records what the live implementation proves and what still prevents a `done` parity claim. The [parity matrix](parity-matrix.md) remains the release ledger; endpoint presence alone is never completion.

## Accepted domain invariants

| Stable family | Implemented boundary | Authoritative evidence | Release state |
|---|---|---|---|
| SCH-SVC-001..006 | Tenant-scoped categories/services, integer-minor money, effective non-overlapping price/policy ranges, per-field offering/teacher/location precedence, optimistic retirement, and private-field projection | `SchedulingRecordRegistry`, `ResolveOfferingConfiguration`, scheduling foundation migration, `SchedulingApiTest`, Filament configuration tests | Scaffolded; production-scale catalog and complete UI/E2E proof remain |
| SCH-RES-001..006 | Tenant-bound locations, rooms, equipment/stock, room capacity, composite foreign keys, and hard resource conflicts | Scheduling foundation/core migrations, `ScheduleConflictDetector`, API and PostgreSQL scheduling tests | Scaffolded; broader concurrent resource-booking matrix remains |
| SCH-AVL-001..007 | Linked-teacher self-service, weekly availability, IANA-zone wall time, DST gap/fold rejection, directional travel buffers, versioned time-off approval, approved time off forced hard | Scheduling registry/actions, `LocalDateTime`/`ZonedLocalDateTime`, API/unit/PostgreSQL scheduling tests | Scaffolded; larger property/load set remains |
| SCH-REC-001..010 | RFC 5545 canonicalization, bounded expansion, explicit fold resolution, stable occurrence UID/history, exception materialization, rolling horizon, recurrence-set translation, and history-preserving one/future/series edits | `RlanvinRecurrenceEngine`, `RecurrenceSetTransformer`, materialization/preview/commit actions, recurrence unit and event API tests | Scaffolded; long-horizon randomized/load proof remains |
| SCH-EVT-001..006 | Private/group/open/workshop/camp/recital/general/closure kinds, visibility, holds, active-student roster preview/commit, capacity checks, and retained history | Event enums/models/actions/resources, event API and PostgreSQL tests | Scaffolded; public self-booking/waitlist product journeys are later capabilities |
| SCH-VIEW-001..005 | Public-safe projection, manager/teacher/billing projections, day/week/month/agenda Filament calendar, scoped filters, mobile/keyboard styling | Calendar query/context/resources, `ScheduleCalendar`, page tests, manual browser smoke | Scaffolded; automated visual/accessibility regression and production-volume proof remain |
| SCH-CFL-001..006 | Hard teacher/room/equipment/capacity constraints, soft availability/travel warnings, management-only acknowledgement, rerun at commit, advisory ranked slot search | `ScheduleConflictDetector`, preview actions, slot search, event API/PostgreSQL tests | Scaffolded; exhaustive concurrent slot ranking/capacity acceptance remains |
| SCH-ACT-001..009 | Create/clone/roster/hold/reschedule/cancel/restore preview-commit, one/future/series scope, ten-minute actor/tenant-bound previews, aggregate snapshots, translated recurrence sets, explicit downstream projections, exact replay | Scheduling preview/commit actions, typed `SchedulingConflict`, event API tests, contract audit | Scaffolded; exhaustive hardened concurrency cases and product journeys remain |
| ATT-STS-001..008 | Normalized outcomes, server-derived billing/make-up dispositions, one/bulk/express capture, optimistic correction history, overdue list, relationship/billing projection, idempotent outbox intent | Attendance actions/requests/resources, attendance migration, API and restricted PostgreSQL tests | Scaffolded; offline teacher synchronization and financial-ledger consumption remain ATT-03/04/05 |
| ATT-NOT-001..009 | Participant/group audiences, author-private enforcement, sanitized HTML, optimistic immutable revisions, templates, previewed recipient delivery, current-clean attachment snapshot, private quarantine and signed reauthorized downloads | Note/attachment actions/policies/resources/jobs, attendance/attachment migrations, API and restricted PostgreSQL tests | Scaffolded; provider-delivery completion and browser/a11y journeys remain |
| SCH-SEC-001..005 | Route-tenant resolution, composite tenant FKs, forced RLS, restricted runtime, policy recheck after locks, safe role projections, actor-scoped idempotency | Migrations, policies/access contexts, transaction actions, PostgreSQL security tests, OpenAPI audit | Scaffolded across this slice; FND-05/06/07/09 remain platform-wide release capabilities |

## Preview and commit contract

Scheduling mutations that can affect more than a local configuration row use a server-owned preview:

1. Preview validates the request and route tenant, canonicalizes recurrence/local time, authorizes the actor, snapshots referenced versions, detects conflicts, and stores a normalized command for ten minutes.
2. The response exposes impact, conflict code/severity/message, explicit billing/payroll/conditional make-up projection intents, expiry, and capabilities. It does not expose the command, tenant, actor, hashes, raw resource IDs from conflicts, or aggregate snapshots.
3. Soft warnings can be acknowledged only by scheduling management and only on the preview that actually contains them. Hard conflicts are not overrideable.
4. Commit accepts no replacement command or acknowledgement. It requires `Idempotency-Key`, reauthorizes after locks, validates preview binding/TTL/one-shot state, verifies every aggregate snapshot and warning fingerprint, reruns conflicts, and commits atomically.
5. Exact same-key replay returns the original projection. Same-key/different-command reuse, expired/consumed/tampered previews, post-preview drift, and fresh hard conflict return stable typed `409` codes. Cross-actor or cross-tenant preview lookup returns `404`; stale optimistic input detected during preview remains field-keyed `422`.

Billing, payroll, and make-up labels in a schedule preview are projection intent only. No invoice, ledger, payroll, or make-up-credit entry is created by this milestone. ATT-03, ATT-04, BIL-*, and PAY-* own those effects.

## Role and privacy boundary

| Caller | Calendar | Attendance | Notes and attachments |
|---|---|---|---|
| Owner/administrator/office | Operational schedule, private descriptions and pricing, assignments/roster, management capabilities | All records plus correction history | Compliance-visible notes, template management, attachment resolution/retirement |
| Assigned teacher | Only assigned schedule; operational assignments and eligible join URL; no management-only descriptions/pricing | Read/write for assigned occurrence | Distributable notes plus only own author-private content; current-revision attachment metadata needed to resolve unsafe scans |
| Billing | Restricted calendar without teaching/location/roster detail | Read-only outcome and billing disposition; learner identity, reason, lateness, make-up, and corrections redacted | Forbidden |
| Linked learner/guardian | Portal calendar surface remains a later product route | Only relationship-bound records | Only matching student/guardian audience and active roster; clean, current, unretired attachments only |
| Public | Scheduled non-hold occurrence projection from active public series only | None | None |

No response in this slice exposes `studio_id`, global actor IDs, command hashes, aggregate snapshots, idempotency keys, normalized configuration names, storage disk/key/path, scanner engine details, or public attachment URLs.

## Attachment security acceptance

- Upload requires the current note version, a 1–100 character actor-scoped idempotency key, an allowlisted extension, Laravel MIME validation, and server file-magic agreement.
- Default limits are 25 MiB per file, 20 active files per note, and 100 MiB active bytes per note. Configuration may lower them.
- PDF, PNG, JPEG, WebP, MP3, M4A, WAV, and OGG enter private quarantine. Unknown/unavailable scanners fail closed.
- Pending, failed, infected, and retired files cannot obtain a download URL or stream. Editors see the safe metadata needed to retry/retire; recipients see only clean current-revision metadata.
- A delivery preview fingerprints clean current-revision attachment metadata. Note, recipient, or attachment drift invalidates commit with typed `409`.
- A download URL is a short-lived signed application route, not a storage URL. The route reauthorizes the current user, studio, note, signature, recent password/passkey, and clean/unretired state on every use, then streams from private storage with no-store, `nosniff`, and CSP `sandbox` headers.
- Retirement is an immutable domain event, revokes outstanding signed URLs immediately, and retains scan/revision history.

## Current automated evidence

The named suites are the authoritative behavioral evidence; counts in the parity ledger are refreshed only after the entire branch is rerun:

- `tests/Feature/Api/SchedulingApiTest.php`
- `tests/Unit/Scheduling/RecurrenceEngineTest.php`
- `tests/Feature/Api/EventSchedulingApiTest.php`
- `tests/Feature/Filament/SchedulingConfigurationResourceTest.php`
- `tests/Feature/Filament/ScheduleCalendarPageTest.php`
- `tests/Feature/Api/AttendanceNotesApiTest.php`
- `tests/Feature/Api/LessonNoteAttachmentApiTest.php`
- `tests/Feature/Database/LessonNoteAttachmentMigrationTest.php`
- `tests/Feature/Security/LessonNoteAttachmentPostgresTest.php`
- `tests/Feature/Database/SchedulingFoundationMigrationTest.php`
- `tests/Feature/Database/AttendanceNotesMigrationTest.php`
- `tests/Feature/Security/SchedulingFoundationRowLevelSecurityTest.php`
- `tests/Feature/Security/EventSchedulingPostgresTest.php`
- `tests/Feature/Security/AttendanceNotesPostgresTest.php`
- strict OpenAPI lint, deterministic generated-TypeScript freshness, semantic contract audit, and declaration typecheck in `packages/contracts`

## Remaining release gaps

These rows stay `scaffolded`, not `done`, until all corresponding evidence exists:

- long-horizon randomized recurrence/property and production-load proof beyond the deterministic COUNT/UNTIL/RDATE/EXDATE/DST cases;
- complete simultaneous booking, exact-capacity, room/equipment, same-idempotency-key, and slot-ranking concurrency proof under restricted PostgreSQL;
- production-size cursor/range/load and horizon-extension testing;
- automated desktop/tablet/mobile visual regression, keyboard journey, and WCAG 2.2 AA checks for calendar and teaching surfaces;
- notification-provider delivery completion and retry/observability beyond the committed outbox intent;
- offline attendance/note capture and merge-conflict behavior (ATT-05);
- attendance-driven billing/payroll and make-up-credit ledgers (ATT-03/04);
- external calendar feed and two-way busy synchronization (SCH-09).

Primary behavior references are [Laravel 13 validation](https://laravel.com/docs/13.x/validation), [authorization](https://laravel.com/docs/13.x/authorization), [database transactions](https://laravel.com/docs/13.x/database#database-transactions), [queues after commit](https://laravel.com/docs/13.x/queues#jobs-and-database-transactions), [Filament 5 security](https://filamentphp.com/docs/5.x/advanced/security), [Filament tenancy](https://filamentphp.com/docs/5.x/users/tenancy), [PostgreSQL 18 row security](https://www.postgresql.org/docs/18/ddl-rowsecurity.html), [PostgreSQL 18 constraints](https://www.postgresql.org/docs/18/ddl-constraints.html), and [RFC 5545](https://datatracker.ietf.org/doc/html/rfc5545).
