# Platform operations acceptance

- Status: **scaffolded implementation with a normative release target; evidence is tracked per family below**
- Parity scope: `FND-07`, `FND-09`, `FND-10`

This document fixes the security, privacy, lifecycle, concurrency, and recovery contract for immutable audit history, platform support access, the reusable transactional outbox, and tenant export/deletion/restore. The [parity matrix](./parity-matrix.md) remains the release ledger. A route, table, queue job, or Filament page does not by itself make a family `done`.

The words **MUST**, **MUST NOT**, **SHOULD**, and **MAY** are normative. Every release report MUST list every stable acceptance ID as `pass`, `fail`, or `planned`; an absent route, unsupported role fixture, SQLite-only result, skipped restricted-role test, or missing concurrency process is not a pass.

## 1. Trust and authorization boundary

Studio membership roles never confer platform authority. Platform assignments never confer tenant data access. The application MUST preserve the authenticated platform actor and an explicit support-grant identity throughout a support session; it MUST NOT replace the actor with, serialize a session as, or claim to be a tenant user. “Impersonation” in product language means visibly delegated support mode, not identity masquerading.

| Principal | Permitted operations | Explicitly forbidden |
|---|---|---|
| Studio owner | View the studio audit projection; request/export the studio; manage retention and deletion requests; approve, deny, or revoke support grants | Platform audit, another studio, credentials, raw infrastructure/outbox payloads |
| Studio administrator with the relevant explicit capability | View the studio audit projection; request exports; approve/revoke ordinary support grants if studio policy allows | Ownership/destruction controls unless separately granted; platform audit; credentials |
| Office, teacher, billing, guardian, learner | No platform-operations access by role alone | Audit browsing, support grants, full export, retention/deletion/restore |
| Platform support operator | Global operational metadata; request a scoped support grant; enter an active grant; exercise only its capabilities | Hidden tenant bypass, self-approval, tenant export by default, credential access, destructive tenant operations |
| Platform security administrator | Review platform audit; approve the platform side of break-glass; revoke any support session; perform separately authorized restore operations | Silent tenant entry, acting as a tenant member, approving their own request |
| Queue/scheduler service | Lease declared outbox/export/retention work under an explicit tenant or global service context | User-derived authority, unscoped tenant queries, exporting secrets |

All sensitive operations MUST use Laravel policies or gates at the domain boundary and explicit authorization on every custom Filament action/page. Authorization MUST run again after row locks and on every Livewire interaction. Route tenant resolution and policy checks are necessary but do not replace forced PostgreSQL row-level security, tenant composite constraints, allowlisted serialization, or reauthorization at signed-download use time.

Recent authentication means a successful password or passkey confirmation no more than ten minutes old. Support entry, support capability elevation, full export request/download, deletion request/cancel, legal-hold mutation, and restore preview/commit MUST require recent authentication. A platform or studio principal performing these operations MUST also complete MFA in the current authentication session; enrollment state alone is not proof. Failure is `423` with a typed `recent_authentication_required` or `mfa_challenge_required` problem and has no success audit or outbox effect.

Cross-tenant or cross-boundary identifier substitution MUST be indistinguishable from absence (`404`). An authenticated but underprivileged actor within the correct boundary receives `403`. Validation is field-keyed `422`; current-state, optimistic-version, preview, idempotency, lease, and lifecycle conflicts are typed `409`.

## 2. FND-07 — immutable audit and support access

### Audit record contract

An audit event MUST be appended in the same database transaction as the domain transition it describes. A rolled-back transition leaves no success event. Delivery/worker outcomes append new events; they never rewrite the initiating event.

The stored record MUST contain an opaque event ULID, tenant scope (`studio_id` or an explicitly global event class), stable event type and schema version, occurred/recorded timestamps, actor kind and safe actor reference, authentication method and MFA presence, support-grant reference when applicable, safe subject kind/reference, request/correlation ID, outcome, safe reason code, coarse network/client metadata, and an allowlisted JSON payload. Tenant-facing projections MUST NOT disclose global user IDs, platform assignment IDs, raw IP addresses, complete user agents, credentials, secrets, bearer/digest material, session/cookie values, storage keys, provider payloads, encryption material, raw SQL, exception traces, or another tenant's identifiers.

Tenant audit rows MUST form a deterministic append sequence and tamper-evident chain using a canonical representation, previous-event hash, and event hash. The first event uses the documented genesis marker. Concurrent writers MUST serialize sequence allocation per audit scope. A daily verifier MUST detect gaps, reordering, or hash mismatch without “repairing” history, publish safe health/alert signals, and append a separate global verification outcome. Key rotation MAY add signed checkpoints but MUST NOT rewrite historical hashes.

PostgreSQL tables MUST enable and force RLS, default deny without a transaction-local scope, apply both `USING` and `WITH CHECK`, and reject `UPDATE`, `DELETE`, and cross-scope foreign keys for the restricted runtime role and table owner used by the application. No application maintenance command may disable constraints or RLS to mutate history. Retention may move old immutable events to an equally immutable archive only after an audited, checksummed handoff; ordinary tenant deletion MUST retain the minimum non-secret platform evidence required by law and the declared retention policy.

| Stable ID | Required executable proof |
|---|---|
| `OPS-AUD-001` | A successful tenant/global security write commits exactly one initiating audit event with the declared actor, scope, subject, correlation, outcome, reason, and schema version; rollback commits none. |
| `OPS-AUD-002` | Direct SQL as the restricted runtime role cannot update/delete audit rows; missing scope is default-deny; studio A cannot select/insert studio B; a global context cannot silently become a tenant context. |
| `OPS-AUD-003` | Two concurrent writers receive unique monotonic scope sequences and one unbroken canonical hash chain; retry does not duplicate an event. |
| `OPS-AUD-004` | Chain verification detects changed payload, removed row, inserted row, sequence gap, and reordered row, emits safe observability, and does not modify the audited chain. |
| `OPS-AUD-005` | Role projections and export/log/job/cache/trace scans prove the audit allowlist and redaction rules; forbidden fields are absent rather than masked with reversible values. |
| `OPS-AUD-006` | Cursor pagination is stable under concurrent appends, bounds range filters, and never duplicates/skips records within the captured cursor window. |

### Delegated support grants

An ordinary support grant MUST identify one studio, a non-secret ticket/reference and purpose, requester, distinct tenant approver, optional distinct platform approver when required by policy, allowed read/write capabilities, requested/approved/start/end/revoked timestamps, and optimistic version. It is read-only and non-exportable by default, starts no earlier than approval, lasts at most four hours, cannot be extended in place, and ends immediately when revoked, expired, the operator loses the platform assignment, or the studio is suspended/deletion-locked.

Write capabilities require an explicit per-capability justification, a studio owner approval, and a distinct platform security approval. Full tenant export, credential/authenticator inspection, MFA disablement, payment-secret access, ownership transfer, legal-hold removal, tenant deletion, and destructive restore are never delegable. A support actor's query is the intersection of the active grant, their current platform assignment, the tenant user's ordinary privacy boundary represented by the capability, and RLS. Support mode MUST show a persistent tenant/purpose/expiry banner and MUST NOT open a tenant session or remember the tenant after exit.

Break-glass is disabled by default. If enabled for an incident, it requires a current platform-security incident, recent auth plus MFA, a requester and different platform-security approver, read-only capabilities, a maximum 60-minute duration, immediate owner/security notification, and a high-severity global plus tenant-visible audit trail. It does not bypass RLS; it creates a short-lived grant through the same access path. There is no self-approval or wildcard/all-tenant grant.

| Stable ID | Required executable proof |
|---|---|
| `OPS-SUP-001` | Only eligible studio approvers can approve/deny/revoke their studio's grant; requester cannot self-approve; cross-studio substitution is `404`; stale version is typed `409`. |
| `OPS-SUP-002` | Entry requires an approved active grant, current platform assignment, recent authentication, current-session MFA, exact studio, and a declared capability; each missing condition fails before tenant data access. |
| `OPS-SUP-003` | Every request and Livewire action reauthorizes the grant; expiry/revocation/assignment loss between interactions terminates access and clears tenant context. |
| `OPS-SUP-004` | Read-only grants cannot mutate. Write grants require dual approval and permit only declared actions; all non-delegable operations remain denied. Denial creates no success event/outbox effect. |
| `OPS-SUP-005` | Support entry, view/search/export attempt, mutation attempt/outcome, exit, expiry, and revocation append tenant-visible and platform events without leaking another boundary's private identifiers. |
| `OPS-SUP-006` | The UI always identifies delegated support mode, tenant, purpose, capabilities, and expiry; exit cannot return to a support-scoped page; browser history and a second tab cannot revive an ended grant. |
| `OPS-SUP-007` | A support session preserves the platform actor and grant; session/auth/audit assertions prove it never becomes or claims a tenant user's identity. |
| `OPS-SUP-008` | Break-glass requires separate actors, is read-only and at most 60 minutes, notifies the tenant/platform security, and cannot target multiple studios. |

## 3. FND-09 — reusable outbox, idempotency, and observability

Every external effect MUST originate from a durable outbox record committed atomically with its domain transition. The record MUST include an opaque message ULID, tenant/global scope, aggregate kind/reference and monotonic aggregate sequence, event/message type and schema version, idempotency/deduplication key, safe destination class, encrypted or reference-only payload, safe headers, state, attempt count, availability time, lease token/expiry, created/published/dead-letter timestamps, correlation/request ID, and last safe reason code. Public APIs and tenant audit projections MUST NOT expose queue IDs, lease tokens, raw destination addresses, provider payloads, or exception text.

Outbox states are `pending`, `processing`, `published`, `retry_scheduled`, and `dead_letter`. Workers claim due rows atomically with bounded batches and `FOR UPDATE SKIP LOCKED` (or equivalent), commit the lease before delivery, and use a renewable lease with a documented maximum. Expired leases are reclaimable. Success is recorded only after provider acknowledgement. Retry uses bounded exponential backoff with jitter and a maximum attempt policy by message type. Exhaustion dead-letters once and alerts. Operator replay creates an audited new delivery attempt bound to the original logical message; it never edits history or bypasses the consumer dedupe key.

At-least-once delivery is assumed. Producers enforce uniqueness on the logical domain effect. Consumers MUST store an inbox/deduplication receipt atomically with their local effect. Per-aggregate ordering is preserved or a stale/out-of-order consumer transition is rejected safely. Same idempotency key plus canonical request fingerprint returns the original completed response; the same key plus different input returns `409 idempotency_key_reused`; an in-progress owner returns `409 operation_in_progress` with a bounded retry hint. Idempotency records have an explicit retention period no shorter than the maximum client retry and provider-redelivery window.

Metrics/logs/traces MUST use safe, bounded-cardinality dimensions: message type, schema version, queue/destination class, tenant tier or hashed tenant bucket, outcome, attempt bucket, and safe reason. They MUST expose pending age, due depth, claim-to-publish latency, retry/dead-letter counts, lease reclamation, duplicate suppression, and consumer lag. Correlation IDs connect domain audit, outbox, worker attempt, and provider acknowledgement without copying private payloads. Alerts cover oldest-pending SLO, dead letters, sustained retries, lease churn, and unknown schema versions.

| Stable ID | Required executable proof |
|---|---|
| `OPS-OUT-001` | Domain state, audit event, and one logical outbox message commit atomically; forced rollback leaves none; after-commit dispatch cannot observe uncommitted data. |
| `OPS-OUT-002` | Producer uniqueness and exact request fingerprinting return the original response on same-key replay and typed `409` on input drift or live ownership. |
| `OPS-OUT-003` | Concurrent workers claim disjoint rows; a crashed worker's expired lease is reclaimed; a stale lease token cannot acknowledge or reschedule the message. |
| `OPS-OUT-004` | Provider timeout/error schedules bounded jittered retry; acknowledgement publishes once; exhaustion dead-letters once; replay is separately audited and preserves the logical dedupe key. |
| `OPS-OUT-005` | A duplicate/redelivered message produces one consumer effect and one inbox receipt; concurrent duplicates and out-of-order aggregate versions are safe. |
| `OPS-OUT-006` | Tenant context is restored/cleared around every worker item; a malformed/missing tenant or inactive service grant fails closed without reading or writing another tenant. |
| `OPS-OUT-007` | Serialized jobs, failed-job storage, logs, traces, metrics, audit, cache, and alerts contain only the declared safe envelope and never credentials/private payloads. |
| `OPS-OUT-008` | Recovery scans pending/retry rows after process loss, dispatches each logical message idempotently, and does not duplicate already published/dead-letter outcomes. |
| `OPS-OUT-009` | Deterministic time and load tests prove due ordering, retry boundaries, lease expiry, retention, backlog metrics, and the oldest-pending/dead-letter alert thresholds. |

## 4. FND-10 — export, retention, deletion, and restore

### Full tenant export

Only a studio owner, or an administrator with the explicit `tenant.export` capability allowed by studio policy, may request a full export. Request and download require recent authentication plus current-session MFA. Support access is non-exportable. Creation requires `Idempotency-Key`; exact replay returns the original job while changed options return typed `409`. Export generation is asynchronous through the reusable outbox and runs under an explicit service tenant context.

The export MUST capture one declared point-in-time snapshot. It MUST contain a deterministic UTF-8 JSON manifest plus versioned, dependency-ordered NDJSON data and separately addressed files. It MUST NOT be a raw database dump. The archive and every object remain private, encrypted in transit and at rest, malware-safe, and available only through a short-lived, one-use application download authorization that rechecks membership, policy, recent auth, MFA, export state, expiry, and legal restrictions. Download responses use no-store and safe content headers. Export rows and archives expire on the declared schedule and are purged idempotently.

The manifest MUST contain:

- format name and semantic format version;
- export ULID, studio public identifier, requested/completed timestamps, UTC snapshot boundary, application release and source schema version;
- locale, IANA timezone, currency, and canonical encoding/newline rules;
- every data set's stable name, schema version, relative path, media type, record count, byte count, SHA-256 digest, dependency names, and minimum/maximum stable record reference;
- every included file's stable logical reference, relative path, media type, byte count, and SHA-256 digest, never its storage key;
- deterministic archive SHA-256, manifest canonicalization version, completion state, warnings, and an explicit list of excluded secret classes.

Credential and infrastructure material MUST NOT be exported: password hashes, reset/verification/invitation/token digests, plaintext or encrypted TOTP secrets, recovery codes, WebAuthn/passkey credential material, session/cookie/PAT values, OAuth/payment/provider secrets, encryption/signing keys, storage keys, queue/lease IDs, raw provider payloads, raw IP/user agents, internal exception traces, malware-scanner internals, or environment/configuration secrets. Audit is exported only through its privacy-safe tenant projection. Global identities are represented by tenant-local people/membership references; another tenant and global platform audit are absent.

| Stable ID | Required executable proof |
|---|---|
| `OPS-EXP-001` | Role, current membership, recent auth, MFA, support denial, and cross-studio matrix execute before export creation or quota consumption. |
| `OPS-EXP-002` | Same-key/same-options replay returns one export; changed options conflict; concurrent requests create one logical snapshot/outbox effect. |
| `OPS-EXP-003` | Concurrent domain writes do not produce a torn export: every data set and file belongs to the declared snapshot boundary and dependency graph. |
| `OPS-EXP-004` | Manifest schema, canonicalization, counts, byte sizes, checksums, archive digest, dependency order, semantic version, and deterministic fixture output validate exactly. |
| `OPS-EXP-005` | Automated field/byte scans prove every forbidden credential/infrastructure class absent from manifest, rows, filenames, files, logs, jobs, failures, and traces. |
| `OPS-EXP-006` | Pending/failed/expired exports are nondownloadable; signed use reauthorizes all current controls, is one-use/short-lived, and fails after membership, MFA, export, or tenant state changes. |
| `OPS-EXP-007` | Expiry/purge removes the private archive and download capability once, retains only safe audit metadata, and is tenant-safe under partial batch failure. |

### Retention, legal hold, and tenant deletion

Retention policy is versioned per data class, has a jurisdiction-safe minimum/maximum, and records its effective date and approver. Policy changes require owner authority, recent auth plus MFA, optimistic versioning, previewed impact counts/date ranges, and an immutable audit event. A purge job locks/rechecks each candidate under tenant context, honors the policy version captured for the run, and emits aggregate evidence without placing deleted private data into audit or logs.

A legal hold has an opaque ULID, exact tenant, defined data classes/subjects/time range, non-secret case reference and reason, creator, distinct approver when required, start/review/end timestamps, and optimistic version. It prevents retention purge, archive expiry where legally required, tenant purge, and cryptographic key destruction for matching data. It does not grant viewing authority. Creation/release requires a studio owner plus the configured legal/security approver; support operators cannot create, approve, or release it. Release never deletes immediately: a new retention/deletion preview is required.

Tenant deletion requires owner authority, recent authentication, current-session MFA, exact typed studio confirmation, optimistic tenant version, and a server preview that enumerates blocking legal holds, billing/provider obligations, archive size, affected counts, and irreversible consequences. Commit creates a deletion request with a **14-day cooling-off period**. The owner may cancel during cooling-off with the same step-up controls. Execution rechecks all blockers, ends support grants, revokes sessions/tokens/invitations/provider callbacks, disables new writes, produces the policy-allowed final export, and places the tenant in inaccessible deletion quarantine for **30 days**. An authorized verified restore may run during quarantine. After that window, purge and key destruction are irreversible except for separately governed disaster-recovery media, whose expiry remains fixed and which cannot be used as a product restore shortcut. A legal hold pauses the relevant clocks and produces `409 legal_hold_active`; it never silently shortens retention.

| Stable ID | Required executable proof |
|---|---|
| `OPS-RET-001` | Retention preview/commit enforces role, recent auth, MFA, optimistic policy version, exact impact fingerprint, and same-key replay; drift is typed `409`. |
| `OPS-RET-002` | Boundary-time, DST, leap-day, class precedence, and concurrent-new-row fixtures purge exactly eligible data and retain immutable safe evidence. |
| `OPS-RET-003` | A matching active legal hold blocks retention, archive expiry, deletion execution, and key destruction but grants no additional read/export permission. |
| `OPS-RET-004` | Hold create/release enforces exact tenant, dual control where configured, optimistic version, no support delegation, and a complete immutable lifecycle. |
| `OPS-DEL-001` | Deletion preview/commit requires owner, recent auth, MFA, exact studio confirmation, current version, no blocker, and idempotency; denied/drifted attempts make no state/outbox changes. |
| `OPS-DEL-002` | Cooling-off is exactly 14 days; cancellation before execution is idempotent; races between cancel and execute have one locked terminal outcome. |
| `OPS-DEL-003` | Execution rechecks blockers, revokes every access/delivery path, prevents new tenant work, creates only allowed final-export effects, and enters 30-day inaccessible quarantine. |
| `OPS-DEL-004` | Quarantine expiry irreversibly purges tenant rows/files/keys in dependency-safe batches without crossing tenants; retries resume from durable checkpoints and do not duplicate evidence. |

### Restore

Restore accepts only a Maestro tenant archive whose manifest and every object pass size/path/type allowlists, semantic-version compatibility, canonical digest/checksum verification, malware scanning, and the no-credentials rule. Paths containing traversal, links, devices, duplicate normalized names, or undeclared objects fail closed. A restore targets the same quarantined tenant or a new empty tenant created for recovery; it MUST NOT merge into or overwrite an active non-empty tenant.

Dry-run is mandatory. It parses into isolated staging storage under a restore service context, validates schemas, references, tenant ownership, stable-ID collision/remapping, counts, money/currency, timestamps/timezones, file checksums, RLS policies, capacity, and unsupported/lossy transformations. It returns a safe plan ULID, expiry, archive digest, target version, exact counts/warnings/blockers, required transformations, and fingerprint. Commit accepts only the plan reference, current target version, and `Idempotency-Key`; it cannot replace archive/options. It reauthorizes, locks the target, revalidates the archive/plan fingerprint, imports through domain-safe versioned loaders, and verifies counts/checksums/references/RLS before an atomic activation switch.

Failure before activation drops/quarantines staging and leaves the prior target unchanged. Failure after the activation decision invokes the tested metadata switch rollback and keeps the previous dataset available until verification succeeds. No partial target is user-visible. A successful restore rotates/revokes all authentication and provider material rather than restoring credentials, appends immutable source/target audit evidence, and requires the owner to re-establish integrations.

| Stable ID | Required executable proof |
|---|---|
| `OPS-RST-001` | Dry-run rejects bad format/version, traversal/link/device paths, undeclared/duplicate objects, checksum/count/reference/RLS errors, malware, forbidden fields, cross-tenant rows, and non-empty targets with typed safe problems. |
| `OPS-RST-002` | Dry-run output is privacy-safe and deterministic for the same archive/target; archive, target, policy, or plan-expiry drift makes commit typed `409`. |
| `OPS-RST-003` | Same-key commit replay returns the original restore; different input conflicts; concurrent commits activate at most one dataset. |
| `OPS-RST-004` | Export→dry-run→restore round trip reconciles every manifest count/checksum/reference and declared file while proving credentials/provider secrets are absent and disabled. |
| `OPS-RST-005` | Injected failure at each load/verify/activation checkpoint leaves no visible partial state, preserves or restores the prior target, and allows an idempotent retry from a durable safe checkpoint. |
| `OPS-RST-006` | Restricted PostgreSQL tests prove staging and restored rows remain forced-RLS/default-deny, exact-tenant constrained, and inaccessible to another tenant/runtime scope. |
| `OPS-RST-007` | Successful activation is followed by an independent verification pass; mismatch automatically rolls back/quarantines, alerts safely, and never marks the restore complete. |

## 5. Typed problem families

Implementations MAY add more specific codes but MUST preserve these meanings and statuses. No problem detail may disclose tenant existence, credentials, archive paths, provider payloads, raw database errors, or exception text.

| Status | Required codes |
|---:|---|
| `403` | `operation_forbidden`, `support_capability_forbidden`, `export_forbidden` |
| `404` | `support_grant_not_found`, `operation_not_found`, `export_not_found`, `restore_plan_not_found` |
| `409` | `optimistic_version_conflict`, `idempotency_key_reused`, `operation_in_progress`, `support_grant_not_active`, `support_grant_scope_violation`, `support_approval_conflict`, `audit_sequence_conflict`, `outbox_lease_lost`, `legal_hold_active`, `retention_preview_changed`, `deletion_preview_changed`, `deletion_state_conflict`, `export_snapshot_changed`, `archive_integrity_failed`, `archive_schema_unsupported`, `restore_plan_expired`, `restore_plan_changed`, `restore_target_not_empty`, `restore_verification_failed` |
| `422` | `support_grant_invalid`, `retention_policy_invalid`, `legal_hold_invalid`, `archive_invalid` |
| `423` | `recent_authentication_required`, `mfa_challenge_required` |
| `429` | `operation_rate_limited` with `Retry-After` |

The scaffolded HTTP slice currently also emits the narrower live codes `support_current_session_mfa_required` (`423`), `support_mfa_invalid` (`422`), `RECENT_CONFIRMATION_REQUIRED` (`423`), and `MFA_REQUIRED` (`423`). These are documented in OpenAPI so clients do not guess from messages. They do not weaken the normative meanings above and MUST remain stable until a versioned API migration replaces them.

## 6. Current evidence boundary

All three parity families are now `scaffolded`; none is `done`. The combined focused SQLite run covers 25 tests with 21 passes and 163 assertions plus four intentional PostgreSQL-only skips. Clean PostgreSQL 18 evidence passes the audit/outbox/support slice at 17 tests / 118 assertions and the tenant-data slice at 8 tests / 74 assertions, including fresh migration through `150000`, restricted `maestro_runtime` execution with `NOSUPERUSER`/`NOBYPASSRLS`, and a four-process audit-writer test with contiguous sequence and an unbroken `previous_hash` chain. A restricted-role skip is never promoted to a pass. Current executable evidence lives in:

- `tests/Feature/Foundation/AuditOutboxSupportAccessTest.php`, `Foundation/SupportAccessApiTest.php`, `Database/AuditOutboxSupportMigrationTest.php`, and `Security/AuditOutboxSupportPostgresTest.php`;
- `tests/Feature/TenantData/TenantDataLifecycleTest.php`, `Database/TenantDataLifecycleMigrationTest.php`, `Security/TenantDataLifecyclePostgresTest.php`, and `Filament/TenantDataLifecyclePageTest.php`.

For FND-07, the live slice supplies forced-RLS append-only tenant streams, canonical HMAC chaining and verification, a four-process contiguous-chain concurrency test, allowlisted tenant projections, read-only support grant approval/rejection/revocation, current-session TOTP proof, response-once session bearer, per-request operator/grant/scope revalidation, visible support context, and delegated `audit.read`. It does not yet supply platform-global audit browsing, complete cross-domain audit adoption, every declared support reader, per-view dual-boundary audit evidence, write grants, break-glass, broader concurrency/load profiles, or browser history/multi-tab/accessibility proof. Consequently `OPS-AUD-*` and `OPS-SUP-*` remain release targets even where focused assertions cover a portion of an ID.

For FND-09, the live reusable producer requires a transaction, assigns aggregate sequence, encrypts the versioned payload, dispatches after commit, leases with claim token/expiry, rejects stale acknowledgement, retries with bounded backoff, dead-letters safely, and restores explicit tenant context. It does not yet provide consumer inbox deduplication, full cross-domain migration, jitter, replay governance, production metrics/traces/alerts, crash recovery across every terminal race, or real concurrent/load proof. `OPS-OUT-*` therefore remain release targets.

For FND-10, the live slice supplies owner-only idempotent asynchronous exports, an encrypted deterministic Maestro JSON envelope, an ordered NDJSON manifest with counts/bytes/SHA-256 and explicit secret and private-object-coordinate exclusions, one-use signed application downloads, an optimistic retention policy and studio-wide hold that also pauses archive expiry, 14-day deletion cooling-off, independent approval by a distinct active studio administrator, revalidation of the unexpired encrypted archive at approval, 30-day reversible quarantine, ordinary-route suspension with a narrow lifecycle recovery control plane, and a non-writing restore verification drill. Maestro permits one active owner, and the requesting owner cannot approve. The slice does not yet provide a transactionally consistent snapshot under concurrent updates, attachment bytes and complete dependency metadata, scoped/dual-approved legal holds, preview-fingerprint retention, irreversible purge/key destruction, archive upload/import/activation, independent post-activation verification, or fault-injected rollback. `OPS-EXP-*`, `OPS-RET-*`, `OPS-DEL-*`, and `OPS-RST-*` remain release targets.

Primary implementation references are [Laravel 13 authorization](https://laravel.com/docs/13.x/authorization), [database transactions](https://laravel.com/docs/13.x/database#database-transactions), [queue transactions, unique and encrypted jobs](https://laravel.com/docs/13.x/queues), [file storage](https://laravel.com/docs/13.x/filesystem), [encryption](https://laravel.com/docs/13.x/encryption), [Filament 5 security](https://filamentphp.com/docs/5.x/advanced/security), [Filament strict authorization](https://filamentphp.com/docs/5.x/panel-configuration#strict-authorization-mode), [Filament tenancy](https://filamentphp.com/docs/5.x/users/tenancy), [PostgreSQL 18 row security](https://www.postgresql.org/docs/18/ddl-rowsecurity.html), and [PostgreSQL 18 `CREATE POLICY`](https://www.postgresql.org/docs/18/sql-createpolicy.html).
