# Maestro API contracts

The OpenAPI 3.1 document in `openapi.yaml` is the source of truth for the implemented Laravel browser identity, onboarding, studio invitation, studio, People/household, scheduling/calendar, attendance, lesson-note, private lesson-note-attachment, tenant-data lifecycle, immutable audit projection, and delegated-support APIs. Generated TypeScript declarations live in `generated/schema.d.ts` and can be imported as types from `@maestro/contracts` once this package is added to the workspace.

## Commands

```bash
pnpm install --frozen-lockfile
pnpm lint
pnpm generate
pnpm test
pnpm check
```

- `lint` validates the description with Redocly's strict ruleset.
- `generate` deterministically regenerates immutable, alphabetized TypeScript declarations while preserving optional request fields that have server-side defaults.
- `test` proves the generated declarations are current and audits critical API paths, operation IDs, schemas, privacy boundaries, optimistic versions, cookie/CSRF invariants, and the uniqueness of every stable platform-operations and CRM data-portability acceptance ID.
- `check` fails when the schema is invalid, generated declarations are stale, the contract audit fails, or the declarations do not type-check.

Browser clients first call `GET /sanctum/csrf-cookie`. Public Fortify mutations require the XSRF cookie/header pair; authenticated mutations require both that pair and the Sanctum session. Never interpret the UI-only onboarding `workspace_mode` as a membership role.

Public registration is Maestro-owned rather than Fortify auto-login: every syntactically valid new, existing, or semantically unusable-invitation request receives the same unauthenticated generic `202`, with no redirect or session rotation. Only a genuinely new eligible identity is created and sent the queued verification notification. Password schemas require 12 or more characters, exact confirmation, and server-side compromised-password validation without mixed-case, number, or symbol composition rules.

The identity contract follows the installed Laravel 13 behavior exactly: TOTP QR setup returns `{svg,url}` (or Fortify's empty array before setup), recovery-code GET returns the raw string array while regeneration returns an empty JSON string, and WebAuthn options/credentials follow the official passkey package. Sensitive identity responses are `no-store`; TOTP/passkey management, session revocation, and invitation create/revoke expose `423` when the current session needs recent confirmation.

Session inventory uses opaque public ULIDs, derived device labels, and coarse network prefixes rather than raw IP/user-agent values, cookies, or backend storage identifiers. The current session can revoke itself (which logs out that browser), or the user can revoke one other/all other database-backed sessions.

Invitation create returns a safe `201` queued resource. Resend is a bodyless, recently-confirmed `POST` to the tenant invitation's `/resend` child route and returns the fresh replacement resource with `202`; pending or expired invitations may be replaced after cooldown. List resources expose server-derived filters, collection capabilities, action permissions, delivery state, cooldown, and terminal supersession state, while internal lineage/version IDs, bearer/token digests, queue/outbox IDs, and global-account state remain absent. A delivery job's invitation-domain payload contains only the invitation ULID and internal version, alongside Laravel's framework queue metadata, and derives bearer material at execution.

Invitation preview and acceptance are JSON-body POST operations at `/api/v1/invitations/preview` and `/api/v1/invitations/accept`; no bearer token appears in an API path. Invite and reset links deliver credentials in URL fragments, which the browser scrubs before submitting the credential in a CSRF-protected JSON body.

People list/create/detail/PATCH and student-status transition operations are tenant-scoped to the route studio. Person resources keep privacy-sensitive properties structurally stable by returning `null` or empty collections when the caller lacks visibility, never internal studio/global-user/assignment identifiers. Mutations accept typed student, staff, instrument, tag, and custom-field inputs; person PATCH and student lifecycle transition require the current optimistic `version`, and status changes use the dedicated immutable-history transition operation.

Household PATCH treats the household as an optimistic aggregate: it always requires the household `version`, requires current person versions when retaining/updating members, and atomically replaces the nested member/guardian graph only when that graph is supplied.

Scheduling configuration is a registry-selected tenant API with strict typed request variants, optimistic PATCH-only retirement, and privacy-aware projections. Calendar mutations that can fan out use an actor/studio-bound ten-minute preview and an `Idempotency-Key` commit; commit bodies cannot replace the server-stored command or warning acknowledgement. Hard conflicts are non-overrideable, soft warnings require management acknowledgement, and previews publish billing/payroll/conditional make-up projection intents without claiming financial-ledger effects.

Attendance dispositions are server-derived and cannot be written by clients. Single, bulk, and express capture use actor-scoped idempotency; corrections are optimistic and immutable. Lesson notes and templates sanitize HTML and retain immutable revisions. Delivery preview/commit fingerprints recipients and current clean attachments. Attachments enter private fail-closed quarantine, expose no storage key or public URL, and become downloadable only after a clean scan through a short-lived signed route that rechecks current authorization and recent identity.

Immutable audit/support access, the reusable outbox, and full tenant export/retention/deletion/restore are governed by [`../../docs/product/platform-operations-acceptance.md`](../../docs/product/platform-operations-acceptance.md). That document is a normative release target. OpenAPI operations are added only for live routes and use allowlisted projections; an internal table, job, or scheduled command is not advertised as a public API.

Duplicate-safe People CSV/portable-bundle import and canonical export are governed by [`../../docs/product/crm-data-portability-acceptance.md`](../../docs/product/crm-data-portability-acceptance.md). OpenAPI describes only the live requester-scoped routes and allowlisted DTOs. Its stable IDs remain a normative release target: route or schema presence does not claim complete snapshot consistency, portable round trip, crash/concurrency, expiry/orphan cleanup, restricted PostgreSQL, production-size, or browser evidence.

`GET /api/v1/studios` uses Laravel's unpaginated resource collection envelope (`{ "data": [...] }`), while invitation and household lists use Laravel 13's full length-aware paginator (`data`, `links`, and `meta`, including each meta link's `page`). API authentication, envelope, error, authorization, and pagination conventions are documented in [`../../docs/api/README.md`](../../docs/api/README.md).
