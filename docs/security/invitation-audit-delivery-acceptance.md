# Invitation audit and delivery acceptance

- Status: **implemented for the invitation domain; broader cross-domain audit/outbox remains pending**
- Version: 1.0 (2026-08-10)
- Parent controls: [`IDA-INVITE-001`, `IDA-INVITE-002`, and `IDA-AUDIT-001`](./identity-access-acceptance.md#12-stable-identity-control-ids)

This document fixes the security and transport contract for invitation delivery, resend, supersession, audit, and secret cleanup. The Laravel routes, schema, queue and notification payload assertions, scheduler behavior, rollback behavior, and restricted-role PostgreSQL coverage described here are present and passing. This evidence completes the invitation-domain slice of `IDA-INVITE-001`; it does not complete `IDA-AUDIT-001` for every security write or the platform-wide outbox.

## 1. External HTTP contract

`POST /api/v1/studios/{studio}/invitations/{invitation}/resend` takes no request body. The route studio and invitation ULID are authoritative. It requires a verified Sanctum browser session, valid CSRF token, an active route-studio membership authorized to invite the invitation's locked role, and password or passkey confirmation no older than ten minutes.

A successful resend returns `202 application/json`:

```json
{
  "data": {
    "id": "01KZN7C5V3EVQW9G2FN8X6MP4S",
    "email": "new.teacher@example.com",
    "role": "teacher",
    "status": "pending",
    "expires_at": "2026-08-17T18:45:00+00:00",
    "accepted_at": null,
    "revoked_at": null,
    "superseded_at": null,
    "last_sent_at": null,
    "send_count": 0,
    "resend_available_at": "2026-08-10T18:46:00+00:00",
    "delivery_status": "pending",
    "permissions": {
      "can_resend": false,
      "can_revoke": true
    },
    "created_at": "2026-08-10T18:45:00+00:00"
  },
  "message": "Invitation resend queued."
}
```

`data` is the fresh replacement invitation, not the superseded row. It uses the same token-free `StudioInvitation` allowlist as create/list. The response never contains internal lineage/version IDs, a plaintext token, digest, queue/outbox identifier, global user ID, account-existence state, other-studio membership, or provider delivery claim.

`GET /api/v1/studios/{studio}/invitations` accepts `q` (normalized email substring, maximum 100 characters), `status`, `role`, and one-based `page`. Its paginator adds `capabilities.can_create` and the exact server-authorized `capabilities.invitable_roles`. Each invitation carries server-derived `permissions.can_resend` and `permissions.can_revoke`; clients never reconstruct policy from the current user's role. `delivery_status` is `pending`, `sent`, `suppressed`, or `null` for migrated history without a delivery row.

The authenticated management surface is the tenant-scoped Filament resource at `/manage/studio/{studio}/studio-invitations`. It default-denies missing tenants, derives available roles and actions from the Laravel policy, requires the current password before every sensitive mutation, and invokes the same domain actions and HMAC-keyed create/resend limits as the API. The candidate intentionally contains no authenticated Next.js invitation-management route or component.

Exact error families are:

| Status | Meaning |
|---:|---|
| `401` | No authenticated browser session |
| `403` | Route-studio membership or role policy denies resend |
| `404` | Studio or invitation is unknown outside the authorized route-studio scope |
| `419` | CSRF validation failed |
| `422` | The tenant-visible invitation is accepted, revoked, superseded, or fails the domain resend eligibility check; no replacement or job is created |
| `423` | The current session lacks recent password/passkey confirmation |
| `429` | Authorized create or resend quota exceeded; no invitation/replacement, audit-success event, or delivery job is created |

Accepted, revoked, already-superseded, and domain-ineligible rows use the same `422` field key, `invitation`, without token or cross-tenant details. The HTTP one-minute limiter may intercept an immediate repeat first with `429` and `Retry-After`. An expired invitation may be renewed as a fresh replacement after cooldown; it is never reopened in place. Cross-tenant substitution is always `404`. The operation is limited to three replacements/day per invitation lineage and one/minute per actor/invitation/IP boundary; limiter keys contain keyed digests rather than raw email or invitation tokens.

## 2. Atomic resend and supersession

The request pipeline completes route binding, tenant membership, recent confirmation, and actor role-policy authorization before entering the sensitive resend limiter. The resend transaction then locks the current invitation and its tenant-local pending key; rechecks current-row eligibility, cooldown, and the persistent daily lineage quota; creates a new invitation ULID with a fresh seven-day expiry and monotonically increasing delivery version; links the replacement to its predecessor; marks the predecessor with `superseded_at` and `superseded_by_id`; clears the predecessor's pending uniqueness key; stores only the new token digest; appends the resend/supersession audit records; and records the after-commit delivery intent.

The old bearer becomes unusable before `202` is returned. Public preview of the old token returns the ordinary generic `404` unavailable response. Authenticated acceptance returns the ordinary generic `422 invitation_token` validation family. Resend never updates a token digest or expiry in place, never reuses plaintext token material, and never changes the locked email, role, studio, or inviter history.

Concurrent resend attempts may produce at most one replacement for a given predecessor/version. The loser returns the terminal `422` or rate-limited `429` family; it never creates a second pending invitation or a second usable token.

## 3. Token-safe queued delivery

The serialized queue payload contains exactly the invitation ULID and delivery version. It does not contain the studio ID, email, role, inviter, plaintext token, token digest, signed URL, or mail body. Queue encryption is defense in depth and does not relax this payload rule. Failed-job storage, logs, exception context, tracing, metrics, and audit metadata apply the same exclusion.

The worker resolves the invitation and tenant context at execution time, then locks and rechecks that the row still has the requested version and is deliverable. Missing, revoked, accepted, expired, superseded, replaced-version, already-terminal, or exhausted-failure work is acknowledged as a safe no-op. It emits no email and cannot resurrect or rotate a token. After five provider failures the queue failure callback atomically suppresses the pending delivery as `delivery_failed`, so the pending-intent dispatcher cannot create an unbounded retry storm; recovery requires an authorized resend.

Delivery is intentionally at-least-once at the provider boundary: a process crash after provider acceptance but before the database commit can retry the same version and send the same credential again. The database guarantees one current delivery intent and one usable token/version, not exactly-once external email. Routine retries after a committed send are no-ops, and any crash-window duplicate carries the same bearer rather than minting multiple valid credentials.

Plaintext bearer material exists only at the delivery boundary long enough to build the fragment URL. The database retains only the digest. The fragment is never placed in an HTTP query, API response, audit event, queue payload, or log. A successful provider handoff records `last_sent_at` and the safe delivery audit event without claiming that a human received or opened the message.

## 4. Immutable tenant-aware audit

Each event has a ULID, `studio_id`, event type, actor user ID or service actor, subject type and ULID, request/correlation ID, keyed request-IP hash, allowlisted metadata, and immutable `occurred_at`. Invitation metadata may include role, delivery version, predecessor/replacement ULIDs, and coarse outcome. It never includes full email, plaintext token, token digest, URL fragment, provider message body, session identifier, raw IP, or raw user agent.

Required invitation event types are:

- `invitation.created`
- `invitation.delivery_queued`
- `invitation.delivered`
- `invitation.delivery_suppressed`
- `invitation.superseded`
- `invitation.resent`
- `invitation.revoked`
- `invitation.accepted`
- `invitation.digest_redacted`

Domain state and its success audit rows commit atomically. After-commit delivery may append delivery outcome events, but may not rewrite the original domain event. PostgreSQL constraints keep the subject in the same studio. Forced RLS is default-deny without `app.current_studio_id`; tenant context can read only its studio; application roles cannot update or delete an audit row. The acceptance finalizer first proves the bearer/user/membership tuple, then sets the verified studio context transaction-locally before delivery/audit writes and restores the previous context. Disposable PostgreSQL proof deliberately re-owns that `SECURITY DEFINER` function to the restricted non-superuser/non-`BYPASSRLS` runtime role, preventing a superuser-owned function from masking policy defects. Cleanup never deletes audit history.

## 5. Digest cleanup

A scheduled, non-overlapping, single-server command redacts `token_hash` only for terminal invitations whose terminal boundary is at least 30 days old. The boundary is `accepted_at`, `revoked_at`, `superseded_at`, or `expires_at` for naturally expired rows. Pending and recently terminal digests remain untouched.

Each row is locked and rechecked under tenant context immediately before redaction. Rerunning the command changes zero already-redacted rows and emits no duplicate `invitation.digest_redacted` event. Batch failure in one tenant cannot cause unscoped work or expose another tenant. Rows, lineage, safe timestamps, and audit events are retained; only the digest is set to `null`. A later use of a redacted bearer remains in the ordinary unavailable family.

## 6. Stable executable evidence

| ID | Required proof |
|---|---|
| `INV-E005` | Resend returns the exact `202` replacement envelope, atomically supersedes the old row, and invalidates the old bearer |
| `INV-E008` | Expired, revoked, superseded, accepted, random, and cleaned bearers remain in the documented generic public/authenticated error families |
| `INV-E018` | Route binding, membership, recent confirmation, and mutation role policy precede sensitive quota consumption; create attempt 21/hour per inviter across studios or 101/day per studio, plus resend one/minute or three/day limits, produce `429` with `Retry-After` and no invitation, delivery, success audit, or job effects; hourly/daily windows reset at their declared boundary |
| `JOB-001` | Serialized delivery payload contains exactly invitation ULID plus version; token/email/studio/mail content are absent; terminal/stale work is suppressed |
| `JOB-002` | Create/resend/retry races leave one current version, one delivery intent, and at most one usable bearer; provider handoff is documented at-least-once for the crash-after-send window |
| `JOB-012` | Thirty-day digest redaction is terminal-only, tenant-safe, audited once, and idempotent |
| `AUDIT-E001` | Create/resend/revoke/accept commit the required append-only tenant event with allowlisted metadata |
| `AUDIT-E002` | Delivery success and stale suppression append safe outcome events without credentials or message content |
| `AUDIT-E003` | Restricted PostgreSQL runtime proves default-deny and cross-tenant isolation; update/delete are rejected even in the owning tenant |
| `AUDIT-E004` | Domain rollback leaves no success audit/outbox effect; after-commit dispatch cannot run for rolled-back work |
| `FIELD-008` | HTTP, audit, notification, job, log, cache, and tracing scans reveal no token/digest or unauthorized tenant field |
| `DB-010` | Persistent storage contains invite digests only and seeded plaintext markers are absent |

The invitation lifecycle suite provides repository evidence for `INV-E005`, `INV-E008`, `INV-E018`, `JOB-001`, `JOB-002`, `JOB-012`, and `AUDIT-E001`–`AUDIT-E004`, including rollback/no-effect branches, serialized job and notification privacy, provider-exception redaction, the complete cleanup state matrix, audit update/delete rejection, and restricted PostgreSQL runtime isolation. It also proves the invitation-specific portions of `FIELD-008` and `DB-010`; their password-reset, PAT, cache, realtime, export, and other cross-domain cases remain governed by the parent matrix. `IDA-INVITE-001` therefore passes for this slice. `IDA-AUDIT-001` remains scaffolded outside the invitation domain until every other security write and external-delivery workflow adopts and proves the same immutable audit/outbox boundary.

## 7. Framework floor

Laravel's after-commit dispatch, job uniqueness, encrypted-job, notification, and single-server/non-overlapping scheduler controls are useful primitives, not substitutes for the domain checks above:

- [Laravel 13 queues](https://laravel.com/docs/13.x/queues)
- [Laravel 13 notifications](https://laravel.com/docs/13.x/notifications)
- [Laravel 13 task scheduling](https://laravel.com/docs/13.x/scheduling)
