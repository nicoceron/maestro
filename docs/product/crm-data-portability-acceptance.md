# CRM data portability acceptance

- Status: **normative CRM-05 release target; live evidence is reconciled in section 8**
- Parity scope: `CRM-05`

This document fixes the security, privacy, CSV, duplicate-resolution, concurrency, retry, and round-trip contract for People imports and exports. The [parity matrix](parity-matrix.md) remains the release ledger. A route, table, queued job, or Filament action does not by itself make CRM-05 `done`.

The words **MUST**, **MUST NOT**, **SHOULD**, and **MAY** are normative. Every release report MUST mark each stable acceptance ID as `pass`, `fail`, or `planned`. A skipped restricted-role test, SQLite-only isolation result, mocked concurrency path, or missing large-file/browser journey is not a pass.

## 1. Trust, role, and step-up boundary

| Principal | Permitted | Forbidden |
|---|---|---|
| Studio owner | Stage, preview, resolve, commit, inspect outcomes, export, and download for the exact active studio | Another studio, global identities, credentials, internal storage/queue metadata |
| Active administrator or office staff | The same operational workflow within the exact authorized studio; a future granular People portability capability can narrow this role grant | Ownership/security fields, hidden global identity links, another studio |
| Billing staff | No import/export by role alone; a future explicit billing-safe export is a different projection | Full People CSV, import, duplicate resolution, private learner/staff fields |
| Teacher, guardian, learner | No CRM portability access by role alone | Upload, preview, row outcomes, export, or download |
| Support operator | No CRM import/export even when a read-only support grant declares `people.read` | Staging, download, commit, or delegated export |
| Queue/scheduler service | Process one durable operation under its captured studio and service context | User-derived authority, unscoped queries, cross-studio deduplication |

Every entry point MUST authorize the current active studio membership and operation policy. Custom Filament actions/pages MUST authorize on every Livewire interaction; visibility alone is not authorization. Import row creation/update MUST perform the same domain and per-record authorization as ordinary People mutations because Filament does not do that automatically. Authorization MUST run again after locks and before each resumable batch.

Upload, duplicate-resolution mutation, commit/resume, export request, and download MUST require password or passkey confirmation no more than ten minutes old plus MFA verified in the current browser session. MFA enrollment is not session proof. Safe status and paginated preview reads MAY use the ordinary active People-management role/session boundary when their projection contains no data beyond what that role can already read. A failed step-up has no staged object, resolution, domain write, success audit, quota, or outbox effect.

## 2. CSV profile and canonical representation

The simple import media type is `text/csv`. Maestro's profile is UTF-8, with an optional UTF-8 BOM accepted only at the start, a mandatory single header record, comma separators, RFC 4180 double-quote escaping, and CRLF or LF input records. NUL bytes, invalid UTF-8, duplicate normalized headers, missing headers, unknown required mappings, inconsistent field counts, trailing data after a closing quote, unbounded fields, and archives or spreadsheet formats disguised as CSV fail closed. A previously downloaded full portable export is the decrypted `.maestro` JSON bundle using `application/vnd.maestro.crm-portability+json`; its manifest, exact dataset allowlist/order/schema/dependencies, base64 entries, byte/row counts, checksums, reference graph, and canonical CSV profile MUST all verify before staging succeeds. Import limits MUST bound bytes, records, columns, field length, parse time, decoded bundle size, and cumulative staged storage before expensive work.

Canonical Maestro CSV datasets use UTF-8, a documented BOM policy, CRLF records, a final CRLF, a stable versioned header order, double quotes around every field, doubled embedded quotes, and deterministic row order. Spaces and line breaks inside quoted fields are data. The simple import template and safe error diagnostics use `text/csv; charset=utf-8; header=present`. A full portable export is a private `.maestro` JSON bundle with a versioned manifest plus named deterministic UTF-8 CSV datasets, using `application/vnd.maestro.crm-portability+json`. Every byte response has a safe generated filename, `nosniff`, and private no-store headers. RFC 7111 row numbers count each CSV dataset header as row 1 and column names refer to the exact canonical header; row outcomes use source record numbers rather than a private database key.

Spreadsheet formula injection is a serialization boundary, not a validation shortcut. Every exported or downloadable diagnostic cell derived from tenant/user input MUST be neutralized when its first effective character is `=`, `+`, `-`, `@`, tab, CR, LF, or a documented full-width equivalent, including after delimiter/quote normalization. Neutralization MUST be deterministic, applied after canonical value selection and before CSV quoting, and reversible by Maestro's matching versioned importer so a Maestro export round-trips the original literal value. Formula-like input is stored as literal domain data only after ordinary field validation; it is never evaluated. Raw uploaded cells MUST NOT be copied into a downloadable failure CSV.

| Stable ID | Required executable proof |
|---|---|
| `CRM-CSV-001` | Valid RFC 4180 quoted commas, quotes, embedded CRLF, final/no-final record break, LF input, and optional leading UTF-8 BOM parse identically; malformed quotes, invalid UTF-8, NUL, duplicate headers, inconsistent fields, and disguised non-CSV content fail before staging succeeds. |
| `CRM-CSV-002` | Configured byte/row/column/field/time/storage bounds fail safely at their exact boundary and do not leave an addressable staged object or partial domain writes. |
| `CRM-CSV-003` | Deterministic template, diagnostic CSV, and portable-bundle fixtures have exact media/header/disposition bytes, versioned manifest/header order, CRLF/final CRLF, stable row/dataset order, escaping, and reproducible digests. |
| `CRM-CSV-004` | Formula payloads using ASCII/full-width initiators, leading control characters, delimiter/quote breakout, and embedded new cells never become executable in exports or diagnostics; re-import restores the original literal value exactly. |

## 3. Staged upload and preview

An upload MUST be streamed to a private, random server-generated quarantine key. The original filename is display-only after control-character/path stripping and MUST NOT determine a path. Extension, client MIME, Laravel content-based MIME validation, UTF-8 validation, and the CSV parser MUST agree. The API and Filament state expose no disk, bucket, path, temporary-upload token, object ETag/version, content hash, queue/batch ID, raw exception, parser offset, or malware/scanner internals.

Staging is asynchronous once bounded synchronous validation succeeds. Preview parses under an explicit service tenant context and writes only import-operation and sanitized row-plan records. It MAY use the ordinary active People-management role/session boundary because it performs no People-domain mutation; it still requires the current requester, exact studio, and current import version. It MUST NOT create or update a person, membership, profile, household, guardian link, tag, instrument, or custom-field value. A preview is bound to the exact studio, initiating actor, uploaded byte digest, import schema version, header mapping, normalization version, and operation version. It expires on a declared schedule and cannot be rebound to another file or tenant.

The two import profiles have deliberately different merge boundaries. A daily People CSV MAY target a nonempty studio and uses duplicate planning as described below. A `.maestro` portable bundle v1 is a lossless restore profile, not a merge tool: preview and commit each require an empty CRM target and return typed `409` code `CRM_PORTABLE_TARGET_NOT_EMPTY` otherwise. Every valid bundled person is planned `create`, bundle commit is capped at 5,000 people and returns `CRM_PORTABLE_ROW_LIMIT_EXCEEDED` above that bound, and row resolutions are forbidden with typed `409` code `CRM_PORTABLE_DECISIONS_FORBIDDEN`. The commit boundary MUST recheck emptiness under a concurrency control shared by ordinary People, household, instrument, tag, and custom-field-definition writes; a preview-time check alone is insufficient.

The preview exposes bounded aggregate counts plus paginated row plans. Each row uses an opaque row-plan ULID and source record number, normalized proposed values the actor is authorized to see, validation issues, duplicate candidates represented only by tenant-local People ULIDs and safe labels, available resolutions, selected resolution, and readiness. It never echoes hidden input columns, another row's private raw content, global `user_id`, internal profile/join IDs, storage coordinates, hashes, fingerprints, or database errors.

| Stable ID | Required executable proof |
|---|---|
| `CRM-IMP-001` | Role, exact active membership, recent auth, current-session MFA, CSRF, quota, extension/MIME/content/encoding, and size checks precede a successful private staged upload; denial leaves no storage or success effect. |
| `CRM-IMP-002` | Preview is actor/studio/file/schema/mapping/version bound, performs zero People-domain writes, produces deterministic aggregate counts and stable paginated row plans, and redacts every forbidden internal/private field. |
| `CRM-IMP-003` | Same-studio email, phone, normalized name plus context, and external-reference fixtures produce declared exact/probable/none candidate classes without ever matching another studio or a global identity. |
| `CRM-IMP-004` | Expired, purged, foreign, wrong-actor, wrong-studio, or substituted operation/row identifiers are nondisclosing and cannot reveal whether the referenced artifact exists. |

## 4. Explicit duplicate resolution

No daily-CSV duplicate is auto-merged. A valid no-match CSV row is planned as `create` without per-row confirmation so production-size clean imports remain usable. Every exact, probable, ambiguous, or same-file CSV duplicate remains `conflict` until explicitly resolved as `create`, `update`, or `skip`; `update` additionally binds one candidate People or household ULID and its current optimistic version. Resolution requires the authenticated requester, valid CSRF, current-session step-up, exact tenant-scoped row, and the locked import/plan version; a guessed ULID is never authority. Under the same lock, the server MUST revalidate the row content snapshot, candidate set, and candidate versions. A separate resolution bearer token is intentionally not issued or persisted. An invalid row is a safe invalid/failed plan and cannot be committed as a domain mutation. Exact safe replays return the original selected resolution. A changed resolution increments the import version and invalidates any earlier commit fingerprint. Portable-bundle rows are create-only under the empty-target rule and never enter this resolution workflow.

Resolution MUST NOT merge global users, households, portal accounts, historical student/staff profiles, or records across studios. An update uses ordinary People invariants: tenant-scoped references, lifecycle history preservation, immutable identifiers, typed custom-field validation, inactive-taxonomy rules, and current-record authorization. A row cannot clear a value merely because a CSV column was absent; the mapping declares `ignore`, `set`, or an explicitly permitted `clear` semantic.

| Stable ID | Required executable proof |
|---|---|
| `CRM-IMP-005` | Every exact, probable, ambiguous, and same-file duplicate blocks commit until explicitly resolved under recent auth and current-session MFA, while valid no-match rows auto-plan `create`; invalid candidate, cross-studio candidate, stale person version, unsupported clear, and unauthorized update fail without changing the selected resolution or person. |
| `CRM-IMP-006` | Concurrent resolution updates use the import version; one wins, stale input is typed conflict, exact replay is idempotent, and the final commit fingerprint changes after any accepted resolution change. |
| `CRM-IMP-007` | Create/update/skip projections disclose only tenant-local safe candidate data and cannot link, merge, or infer a global identity, portal credential, household outside the declared row action, or another tenant. |

## 5. Idempotent, resumable commit and row outcomes

Commit accepts only the staged import ULID, current import version, and `Idempotency-Key`; it cannot replace the file, mapping, or resolutions. The server reauthorizes, locks, verifies ready preview state and expiry, and binds the key to actor, studio, upload digest, mapping, resolutions, and import version. Same-key/same-fingerprint replay returns the original operation. Same key with different input is typed conflict. Concurrent commit attempts produce one logical run.

Rows process in stable source order in bounded durable batches. Each row is its own atomic domain transaction: recheck tenant context, operation state, policy, resolution, candidate version, references, and normalized field validation; apply at most one create/update effect; append its safe audit/outbox evidence; then record one terminal row outcome. A failed row rolls back its own domain effects and does not roll back previously committed rows. Retrying a crashed/released batch resumes at durable nonterminal rows and MUST NOT repeat terminal effects. The operation terminal state is derived from exact row totals, never from queue progress alone.

Safe row outcomes are `created`, `updated`, `skipped`, or `failed`. They contain the opaque plan ID, source record number, safe tenant-local person reference when successful, bounded field-keyed issue codes/messages, and attempt state. They contain no raw row, email/phone beyond the already authorized safe preview, global IDs, exception text, SQL, stack, file coordinates, digest, job/batch/queue ID, or provider payload. Progress is monotonic and internally consistent: total equals pending plus processing plus each terminal outcome; completed never decreases; a terminal operation has zero pending/processing rows.

| Stable ID | Required executable proof |
|---|---|
| `CRM-IMP-008` | Commit requires ready state, every required duplicate resolution complete, current version, exact actor/studio, recent auth, current-session MFA, CSRF, and idempotency; denial/drift creates no People, row-success, audit-success, or outbox effect. |
| `CRM-IMP-009` | Same-key replay, changed-key fingerprint, concurrent commit, worker retry, crash between domain commit and outcome acknowledgement, and stale job fixtures create at most one logical effect per source row. |
| `CRM-IMP-010` | Mixed valid/invalid input proves per-row atomic partial success, stable source ordering, exact terminal totals, field-keyed safe outcomes, and retry of only nonterminal work. |
| `CRM-IMP-011` | Person/candidate/taxonomy/version drift after preview is rechecked under lock and becomes a safe row failure or typed operation conflict according to the declared rule; it never silently changes resolution. |
| `CRM-IMP-012` | Restricted PostgreSQL execution proves default-deny without studio scope, exact-studio RLS for operation/row/domain tables, tenant composite constraints, and explicit context restoration between worker items. |
| `CRM-IMP-013` | Expiry cleanup is tenant-safe and idempotent, refuses active/leased work, removes private uploads and preview/raw staging on schedule, and retains only declared safe aggregate audit evidence. |

## 6. Deterministic export and download

An export captures a declared boundary and includes only the CRM-05 portable People/household schema that the requesting role is authorized to export. The `.maestro` JSON bundle manifest declares its format/version, export and snapshot timestamps, application/source schema, CSV canonicalization/formula escaping, ordered dataset names and dependencies, record/byte counts, and SHA-256 checksums. Each dataset is represented by a named deterministic UTF-8 CSV entry; the bundle is not a raw database dump. It MUST NOT export password/session/reset/invitation/token digests, MFA/passkey/recovery material, global user IDs, actor IDs, membership IDs, internal household/join/profile IDs, normalized internal keys, storage paths, deleted-row internals, audit integrity data, queue/outbox fields, request fingerprints, or exception metadata. Relationships use stable tenant-local public People/taxonomy references or canonical portable values.

Generation is asynchronous and idempotent. The operation records safe status/progress and a deterministic byte digest internally, but public JSON MUST NOT expose the storage key, raw/escaped request fingerprint, ciphertext/object hash, queue job or batch ID, or actor/global identity. Download is a short-lived signed application route, never a storage URL. It rechecks exact studio membership, export policy, recent auth, current-session MFA, ready state, expiry, and signature at use time; suspension or authorization loss revokes an already issued URL.

Round-trip means export from fixture A, import into an empty same-schema studio with declared reference remapping, verify that every bundled person auto-plans `create` and no resolution mutation is permitted, commit, export again, and compare the canonical portable projection. Stable domain values, Unicode, null/empty distinctions, dates, locale/timezone-sensitive values, taxonomy assignments, custom fields, multiline values, and formula-like literals MUST reconcile. Server-generated tenant and record ULIDs, timestamps, history actors, and other documented nonportable fields are compared through the declared remap or excluded. Daily CSV duplicate resolution is tested separately and does not weaken the bundle's empty-target-only rule.

| Stable ID | Required executable proof |
|---|---|
| `CRM-EXP-001` | Role/current membership/support denial/recent-auth/MFA/CSRF/quota/idempotency matrix executes before export work; same-key replay yields one operation and changed input conflicts. |
| `CRM-EXP-002` | Deterministic `.maestro` bundle bytes, exact manifest/schema/dataset headers/version/checksums, stable tenant-local ordering, privacy allowlist, formula neutralization, and internal digest all match golden fixtures across repeated runs. |
| `CRM-EXP-003` | Pending/failed/expired/foreign exports cannot issue or use a URL; signed use reauthorizes all current controls and streams only the private no-store `.maestro` JSON bundle with safe headers and filename. |
| `CRM-EXP-004` | Automated schema/byte/job/log/cache/trace scans prove forbidden credential, global identity, storage, hash/fingerprint, queue, raw-error, and cross-tenant fields absent. |
| `CRM-EXP-005` | Export→empty-target preview→create-only commit→export round trip reconciles the complete declared portable projection, including Unicode, quoted multiline cells, null/empty, taxonomy/custom fields, and formula-like literals; nonempty preview/commit and any bundle resolution are typed conflicts with no partial bundle write. |
| `CRM-EXP-006` | Production-size generation remains bounded and resumable; concurrent domain writes obey the declared export boundary, failure never serves a partial CSV, and expiry removes the artifact idempotently. |

## 7. HTTP and typed problem contract

Cross-studio, wrong-actor, expired/purged, and substituted operation, row, candidate, export, or download identifiers use the live nondisclosing `404` family. Problems MUST NOT disclose tenant existence, raw cell data, original storage coordinates, hashes/fingerprints, queue identifiers, SQL, exception text, or another row's private values.

| Status | Exact meaning |
|---:|---|
| `401` | Browser session missing or invalid |
| `403` | Authenticated active route-studio member lacks the operation or per-record capability; support access is forbidden |
| `404` | Route studio or scoped artifact/row/candidate/export is unavailable in the caller boundary, including expired/purged artifacts where the live API uses nondisclosure |
| `409` | Idempotency reuse, stale operation/version/fingerprint, incomplete duplicate resolution, invalid lifecycle transition, concurrent ownership/nonterminal lease conflict, nonempty portable target, or forbidden portable-bundle decision |
| `419` | Browser mutation lacks the valid Sanctum XSRF cookie/header pair |
| `422` | Multipart/file, CSV format/encoding/header/mapping, row field, resolution shape, or bounded option validation failed |
| `423` | Recent password/passkey confirmation or current-session MFA is missing/expired |
| `429` | Authorized upload/preview/commit/export rate or storage quota exceeded, with bounded `Retry-After` and no success effect |

## 8. Current evidence boundary

CRM-05 is `scaffolded`: live routes, allowlisted DTOs, policies, private storage, queue lifecycle, API/Filament behavior, strict OpenAPI, SQLite behavior, an accessible browser journey, and focused restricted-PostgreSQL RLS/round-trip evidence are present. It MUST remain below `done` until every stable ID above has production-shaped proof, including two-process concurrency/resume, deterministic formula-safe fuzz/golden-byte fixtures, complete edge-case round trip, purge fault injection and orphan reconciliation, immutable snapshots, and production-size bounds.

No stable ID above has complete release-shaped proof yet. In particular, the live upload accepts one generic file rule and sniffs bundle JSON rather than proving extension/client MIME/detected content agreement; duplicate matching does not yet implement the complete phone/probable fixture matrix; and the HTTP suite does not execute the exact 401/403/404/409/419/422/423/429 matrix. CRM-IMP-009 through CRM-IMP-013 and CRM-EXP-002, CRM-EXP-004, CRM-EXP-005, and CRM-EXP-006 remain explicit `fail` or `planned` areas.

A focused happy-path test now restores all 12 declared datasets into an empty studio through portable reference remapping and compares the re-exported dataset projection. It also exercises nonempty preview, forbidden bundle resolution, and sequential commit-time target drift. Those fixtures do not prove a truly concurrent ordinary-write race, Unicode, multiline/formula/null boundaries, immutable snapshot semantics, failure rollback, lifecycle/policy coverage, or deterministic golden bytes. The live command lock and row leases are not yet backed by restricted-PostgreSQL concurrent request, crash-window, and resume tests; the existing restricted-role test proves only default-deny and cross-tenant RLS for the portability tables. An hourly purge exists, but active/leased refusal, object-delete retry, and orphan-object reconciliation remain unproved. A production startup validator checks a private local/S3 disk and numeric limits, but its small configuration test does not prove the encryption-key or orphan-reconciliation boundary. The export labels its creation time as a snapshot boundary while reading datasets later without a repeatable-read snapshot. Focused parser, migration, API, Filament, RLS, purge, and round-trip tests are scaffold evidence only; they are not substitutes for these acceptance IDs.

Primary references are [Laravel 13 authorization](https://laravel.com/docs/13.x/authorization), [file validation](https://laravel.com/docs/13.x/validation#validating-files), [private file storage](https://laravel.com/docs/13.x/filesystem), [queue transactions and batching](https://laravel.com/docs/13.x/queues), [Filament 5 import security](https://filamentphp.com/docs/5.x/actions/import#security), [Filament export security](https://filamentphp.com/docs/5.x/actions/export#security), [Filament security](https://filamentphp.com/docs/5.x/advanced/security), [Filament file upload security](https://filamentphp.com/docs/5.x/forms/file-upload), [RFC 4180](https://www.rfc-editor.org/rfc/rfc4180), [RFC 7111](https://www.rfc-editor.org/rfc/rfc7111), [OWASP CSV Injection](https://owasp.org/www-community/attacks/CSV_Injection), and [PostgreSQL 18 row security](https://www.postgresql.org/docs/18/ddl-rowsecurity.html).
