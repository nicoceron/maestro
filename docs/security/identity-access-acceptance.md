# Identity and access acceptance specification

- Status: **normative target**
- Version: 1.0 (2026-08-10)
- Parity scope: FND-01, FND-03, FND-04, FND-05, FND-07, CRM-01, POR-01, POR-02, INT-01, QUA-04
- Companion: [Executable test matrix](./identity-test-matrix.md)

## 1. Purpose and conformance

This document is the release contract for Maestro identity, authentication, studio authorization, invitations, and sensitive identity data. `MUST`, `MUST NOT`, `SHOULD`, and `MAY` are normative. A feature is not done because a screen or endpoint exists: every applicable requirement and test ID in the companion matrix must pass through the API, authenticated Filament surface, policies, jobs, PostgreSQL tenant boundary, and any relevant public Next.js credential landing.

This specification extends the accepted [platform architecture](../architecture/0001-platform-architecture.md), [product parity matrix](../product/parity-matrix.md), and [product vision](../product/vision.md). It does not claim that all controls are implemented today.

### 1.1 Current implementation baseline

| Capability | Verified repository state | Acceptance state |
|---|---|---|
| Global `User` plus per-studio `StudioMembership` | Present; memberships use ULIDs and one current role | Scaffolded |
| Current membership roles | `owner`, `administrator`, `office`, `billing`, `teacher` | Scaffolded |
| Current membership statuses | `invited`, `active`, `suspended` | Scaffolded; lifecycle below supersedes ambiguous use |
| Tenant route resolution, active-membership check, policies, composite constraints, PostgreSQL RLS | Present for implemented People paths; forced PostgreSQL RLS now also covers memberships and invitations with restricted-role tests | Scaffolded; every future tenant table must adopt the same pattern |
| Sanctum first-party cookie authentication | Sanctum 4.3, `statefulApi()`, `web` guard, credentialed CORS, trusted host/origin enforcement, security headers, database-backed session inventory/revocation, eight-hour idle maximum, and 30-day absolute maximum are configured | Scaffolded; platform-specific shorter sessions and broader global-account lifecycle remain |
| Password broker | Database reset-token broker, 60-minute expiry, generic `202`, HMAC limiter keys, uncompromised-password validation, database-session invalidation, and PAT revocation are tested | Scaffolded; security audit/notification and broader session backends remain |
| Fortify | Headless normalized-email login, uniform unauthenticated public/invited registration, reset, verification, ten-minute password/passkey confirmation, encrypted TOTP setup/confirm/recovery/disable with a ten-minute pending-setup expiry and replay-resistant five-minute login challenge, and official WebAuthn passkey login/confirmation/management routes are present under `/api/v1/auth` | Scaffolded; `IDA-REG-001` passes current backend/frontend evidence with fixed generic `202`, no login/session rotation, bounded work/timing, keyed rate limits, passphrase support, and no semantic account/invitation errors; mandatory-role MFA/grace, full security notifications/audit, and recovery governance remain |
| Invitations | Tenant-scoped list/create/resend/revoke, JSON-body preview/accept, fragment-delivered and scrubbed browser credentials, replacement-token supersession, token-safe versioned delivery/outbox recovery, immutable tenant audit, 30-day digest redaction, forced RLS, normalized-email atomic acceptance, non-accepting invited registration, and recent confirmation on create/resend/revoke are present | `IDA-INVITE-001` passes current invitation-domain evidence; broader role/onboarding lifecycle and cross-domain audit/outbox remain scaffolded |
| Verification enforcement | Verification/resend routes and verified middleware on studio business routes are present | Scaffolded |
| MFA, passkeys, session inventory/revocation | TOTP and passkey ceremonies plus safe metadata/session inventory and revocation endpoints are implemented; user sessions have forced PostgreSQL RLS | Scaffolded; remaining acceptance gaps are stated above and in the executable ledger |
| Platform support access | Not implemented | Planned next gap |
| Household redaction | Billing sees payer contact only; household notes, non-payer contact, birth date, pronouns, and guardian relationships are redacted | Scaffolded and normative |

An implementation must preserve the verified controls while satisfying the target below. Tests may use Fortify's routes directly, but Maestro owns response normalization, policy enforcement, audit events, and UX.

Snapshot gaps that the test ledger deliberately exposes: mandatory owner/administrator MFA enrollment and grace enforcement, complete security notifications, Sanctum PAT expiry, cross-domain immutable security audit/outbox, ownership transfer, membership removal, and support-access flows are absent. Platform-specific 30-minute idle/eight-hour absolute sessions are not yet a separate runtime profile. Implemented controls now include uniform unauthenticated `202` registration for new/existing/semantic-invitation cases, verified-email owner onboarding, invitation-bound account creation without invite consumption, HMAC-derived registration/login/reset limiter keys, uncompromised passphrase-friendly password validation, reset invalidation of database sessions, session-registry rows, and PATs, trusted browser boundaries, fragment credential scrubbing, forced PostgreSQL RLS for memberships/invitations/user-session/audit/delivery metadata, ten-minute recent confirmation and pending TOTP setup expiry, post-confirmation-only recovery-code disclosure, replay-resistant five-minute TOTP challenge, exact-origin WebAuthn passkeys, user-facing session inventory/revocation with stale-record pruning and eight-hour idle/30-day absolute limits, and invitation replacement-token resend/supersession with recoverable versioned delivery, append-only tenant audit, and idempotent 30-day digest redaction. These are implementation observations, not accepted exceptions for the remaining target gaps.

### 1.2 Stable identity control IDs

These IDs are permanent review handles. A later implementation may add narrower IDs but must not reuse or silently redefine one. The executable evidence IDs remain in the companion matrix.

| Acceptance ID | Stable control | Primary executable evidence IDs |
|---|---|---|
| `IDA-AUTH-001` | Sanctum first-party session, CSRF, trusted-origin, fixation, and anti-enumeration boundary | `AUTH-E001`–`AUTH-E011`, `ADV-003` |
| `IDA-REG-001` | Public and invitation-bound signup use one unauthenticated anti-enumeration response and grant no tenant authority before server onboarding/acceptance | `REGISTER-E001`–`REGISTER-E005`, `VERIFY-E001`–`VERIFY-E004` |
| `IDA-STEPUP-001` | Sensitive identity changes require a same-session password or passkey confirmation no older than 10 minutes | `ACCOUNT-E004`, `MFA-E001`–`MFA-E004`, `PASS-E004`–`PASS-E006`, `AUTH-E013` |
| `IDA-MFA-001` | TOTP setup remains pending until a valid confirmation; secret and recovery material are encrypted and non-cacheable | `MFA-E001`–`MFA-E003`, `MFA-E006`, `FIELD-006`, `ADV-006` |
| `IDA-MFA-002` | TOTP login challenge and recovery codes are limited, session-bound, replay resistant, and generic on failure | `AUTH-E007`–`AUTH-E010`, `E2E-002`, `E2E-003` |
| `IDA-MFA-003` | TOTP disablement is stepped up, audited, notified, and cannot bypass a mandatory-role policy | `MFA-E004`, `MFA-E005`, `JOB-011` |
| `IDA-PASSKEY-001` | WebAuthn uses exact RP/origins and single-use session challenges for login and confirmation | `PASS-E001`–`PASS-E004`, `CFG-002`, `ADV-004` |
| `IDA-PASSKEY-002` | Passkey registration, inventory, and deletion expose metadata only and require recent confirmation | `PASS-E005`–`PASS-E008`, `FIELD-006`, `E2E-004` |
| `IDA-SESSION-001` | Users can inventory opaque browser sessions and revoke one, the current session, or all other sessions without enumerating another user | `AUTH-E012`, `AUTH-E013`, `AUTH-E017`, `AUTH-E018`, `E2E-014` |
| `IDA-SESSION-002` | Idle and absolute server-side lifetimes terminate sessions independently of the browser cookie | `AUTH-E014`, `CFG-001` |
| `IDA-INVITE-001` | Tenant-authorized create/resend/revoke uses replacement tokens, supersession, quotas, and safe tenant-local disclosure | `INV-E001`–`INV-E008`, `INV-E018`, `JOB-001`, `JOB-002` |
| `IDA-INVITE-002` | Acceptance is server-bound and atomic; same-member replay is an idempotent safe success | `INV-E009`–`INV-E017`, `DB-007`, `ADV-004` |
| `IDA-AUDIT-001` | Security writes emit immutable after-commit audit records and external delivery uses an idempotent outbox | `AUDIT-E001`–`AUDIT-E004`, `DB-008`, `JOB-001`–`JOB-012`, `FIELD-008` |

## 2. Security invariants

1. Laravel is the sole authentication and authorization boundary. Filament navigation, hidden controls, and client-stored studio selection are usability aids only; public Next.js pages never own authenticated state or policy decisions.
2. A `User` is global. A `StudioMembership` is tenant-owned. Authentication proves the global user; authorization always resolves a fresh active membership for the route studio.
3. A user may belong to many studios and have a different role in each. No studio choice, role, or permission supplied by a client is trusted.
4. A valid identity is not sufficient for tenant access. Every tenant request requires `auth:sanctum`, verified email, an active membership for the resolved route tenant, a policy decision, and the appropriate step-up state.
5. Cross-tenant object access returns `404` after authentication. A user who can see a studio exists but lacks an active membership receives `403`. A guest receives `401`. Responses must never reveal whether an object, person, membership, invitation, or account exists in another tenant.
6. Pending invitations grant no tenant access. Suspended or removed memberships grant no tenant access, including through cached permissions, queued jobs, exports, files, notifications, broadcast channels, or personal access tokens.
7. Authorization is deny-by-default. Role is a baseline; relationship scope and explicit permissions can narrow it. Additive grants must be typed, tenant-scoped, auditable, expiring where appropriate, and may never come from profile preferences.
8. Tenant identity travels explicitly in HTTP routes, Filament tenant state, jobs, schedules, outbox records, cache keys, search documents, files, notifications, webhooks, and realtime channels. Workers re-resolve an active membership or service grant before doing user-authorized work.
9. Secrets and bearer credentials are never logged, placed in analytics, rendered into HTML, returned after initial issuance, or stored in plaintext.
10. Security-relevant writes are transactional, emit an immutable audit event after commit, and use an idempotent outbox for external delivery.

## 3. Canonical identity model

### 3.1 Global user

`User` owns global authentication data only:

- ULID, normalized unique email, pending email, display name, locale/timezone, `email_verified_at`;
- password hash and password-change timestamp;
- TOTP secret/confirmation/recovery data and passkey credentials;
- global account state: `pending_verification`, `active`, `locked`, `deactivated`;
- global sessions, personal access tokens, and security-event references.

Email normalization MUST trim Unicode whitespace, apply Unicode normalization, and compare the domain and ordinary email identifiers case-insensitively. The database MUST enforce the same normalized uniqueness used by application lookup. The original address MAY be retained for display. Plus-address stripping or provider-specific dot folding MUST NOT be used.

Changing a global email changes the sign-in identifier for every studio. It requires recent authentication, MFA when enrolled, proof of the new address, notification to the old and new addresses, collision-safe normalized uniqueness, and an audit event. Until confirmed, the old address remains active. Confirmation rotates the session, invalidates all other sessions and reset tokens, and does not silently retarget outstanding invitations.

Global `locked` and `deactivated` states deny all authentication and revoke all sessions and personal tokens. Deactivation is reversible only through a separately authorized recovery/support flow. It must not delete tenant business records.

### 3.2 Studio membership

There is exactly one membership per `(studio_id, user_id)`. It has one base role and a lifecycle state:

- `active`: may authorize access;
- `suspended`: retains history but denies all studio access;
- `removed`: terminal, soft-deleted history; a new invitation is required to return.

The current `invited` membership status is a migration compatibility state only. New flows MUST use a separate invitation and MUST NOT create a user-linked membership until acceptance. Legacy `invited` rows grant no access and must be migrated to invitations or expired.

One base role avoids contradictory multiple membership rows. Future granular permission grants may narrow or add a named capability, but cannot confer ownership, platform access, or bypass relationship scope. Effective access is the intersection of global account state, verified email, active membership, base role, relationship scope, explicit grants, studio state, and step-up requirements.

### 3.3 Tenant person and portal relationship

`Person` is a tenant-owned business profile and is not an authentication identity. It MAY link to one global `User`; the link is unique within a studio and requires verified ownership or an accepted invitation. The same user may link to different `Person` records in different studios. A studio cannot discover or mutate another studio's person link.

Guardian and student access is further restricted by the user's linked person, household/learner relationships, legal/adult status, and guardian `portal_permissions`. A role never turns every guardian or learner in a studio into visible data.

### 3.4 Invitation

`StudioInvitation` is tenant-owned and contains an ULID, `studio_id`, normalized target email, intended role, optional tenant person link, inviter membership/user IDs, SHA-256 token digest, monotonic delivery version, predecessor/replacement lineage, `expires_at`, `last_sent_at`, `accepted_at`, `accepted_by_user_id`, `revoked_at`, `superseded_at`, and audit metadata. The plaintext token exists only at the delivery boundary. The serialized delivery job contains exactly the invitation ULID and delivery version.

Only one pending invitation per normalized `(studio_id, email)` is allowed. The same email may have independent invitations to different studios. An invitation does not disclose whether the target already has a global account or memberships elsewhere.

### 3.5 Platform identity

`platform` is not a studio membership role. Platform operators use a separate global assignment and separate platform panel. Tenant support access requires an explicit support grant containing target studio, purpose/ticket, requested-by and approved-by identities, maximum duration, allowed capabilities, and start/end timestamps. It is MFA/passkey protected, prominently indicated, read-only by default, non-exportable by default, and fully audited. No hidden super-admin tenant bypass is permitted.

## 4. Role and capability matrix

Legend: `A` allow; `S` allow only for assigned/linked scope; `R` read-only or redacted; `-` deny. Every `A`, `S`, or `R` still requires an active membership, tenant match, policy, and any step-up control.

| Capability | Owner | Administrator | Office | Billing | Teacher | Guardian (future) | Student (future) | Accountant (future) | Platform (future) |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| Enter studio / switch into studio | A | A | A | A | A | A | A | A | grant only |
| Read studio settings | A | A | R | R | R | public subset | public subset | finance subset | grant |
| Edit ordinary studio settings | A | A | - | - | - | - | - | - | grant + approval |
| Transfer ownership / delete studio | A | - | - | - | - | - | - | - | break-glass only |
| View staff directory | A | A | A | R | R | - | - | - | grant |
| Invite owner | transfer only | - | - | - | - | - | - | - | - |
| Invite administrator | A | - | - | - | - | - | - | - | - |
| Invite office, billing, teacher | A | A | - | - | - | - | - | - | - |
| Invite guardian/student/accountant when enabled | A | A | S | - | - | - | - | - | - |
| Change roles / suspend / remove members | A | non-owner | - | - | - | - | - | - | grant + approval |
| Household list/detail | A | A | A | R | S | S | S | R | grant |
| Create/update household and people | A | A | A | - | - | own safe fields | own safe fields | - | - |
| Household notes and guardian relationship flags | A | A | A | - | assigned operational subset | own relationship subset | - | - | explicit grant |
| Payer contact and billing records | A | A | A | A | - | S if permitted/payer | S if adult payer | A | explicit grant |
| Non-payer contact, birth date, pronouns | A | A | A | - | S as needed for assigned teaching | S within own household | own data only | - | explicit grant |
| Schedule and attendance | A | A | A | R | S | S portal | S portal | - | explicit grant |
| Public lesson notes/resources | A | A | A | - | S | S portal | S portal | - | explicit grant |
| Private staff/teacher notes | A | A | S by purpose | - | S authored/assigned | - | - | - | explicit grant |
| Billing configuration, processor, payouts | A | A | R operational | A except ownership/payout destination | - | - | - | R/export | grant + approval |
| Reports/exports | A | A | operational | finance only | assigned teaching only | own household | own only | finance only | explicit, time-bound |
| Platform operations / cross-studio search | - | - | - | - | - | - | - | - | scoped platform assignment |

Clarifications:

- Owner is the only studio role that can transfer ownership or initiate destructive studio lifecycle actions. There MUST always be exactly one active owner; transfer is atomic and cannot leave zero or multiple owners.
- Administrator cannot create, promote, demote, suspend, or remove an owner or another administrator. Owner may do so with recent MFA-authenticated step-up.
- Office is operational staff, not membership administration. Office may invite portal users only as part of an existing tenant person/household workflow and cannot infer global account state.
- Billing is the current finance operator. Accountant is a future narrower, audit/export-oriented role. Neither can read private family, teaching, medical/accessibility, custody, or non-payer contact data.
- Teacher access is assignment-scoped. Until assignment-aware household policies exist, the current safe behavior—deny household endpoints—is normative.
- Guardian effective access is relationship-scoped and permission-scoped. `portal_permissions` can only reduce the guardian baseline. A legal-guardian flag does not automatically grant billing or all records.
- Student effective access is self-scoped. Minor students cannot view guardian contact, custody/legal flags, sibling data, or family finances. Adult students may see their own payer records only when they are the payer.
- Platform operators are never members by implication. Support grants are evaluated as capabilities, not mapped to owner.

## 5. Sensitive field visibility

Serialization MUST be allowlist-based per action and role. Query policies and resource serializers both enforce this matrix; returning a key with `null` is acceptable only where the documented contract requires a stable shape. Exports, search, audit payloads, notifications, logs, realtime events, and caches apply the same or stricter rules.

| Data class | Owner/Admin/Office | Billing | Teacher | Guardian | Student | Accountant | Platform support |
|---|---|---|---|---|---|---|---|
| Global user ID, memberships in other studios, auth state | self only / never for other users | never | never | self only | self only | never | never by default |
| Password hash, reset/invite token, TOTP secret, recovery codes, passkey public-key material/user handle, session cookie | never serialized | never | never | never | never | never | never |
| Person name/display/status | tenant need-to-know | household ledger context | assigned learners/contacts | linked household | self/approved peers | payer ledger context | explicit grant |
| Email/phone | tenant operations | payer rows only | assigned contact purpose only | linked household per permissions | self | payer rows only | explicit grant |
| Birth date/pronouns | tenant operations | never | assigned learner only when required | linked household | self | never | explicit grant |
| Household notes | A | never | assigned operational excerpt only | never unless explicitly shared | never | never | explicit grant |
| Guardian relationship flags, custody, emergency/pickup | A | never | assignment-relevant emergency/pickup only | own relationship only | never | never | explicit grant |
| Guardian portal permissions | A | never | never | own effective permissions | never | never | explicit grant |
| Student profile/grade/status | A | learner name/status only when tied to payer record | assigned | linked | self | invoice context only | explicit grant |
| Private lesson/staff notes | policy-scoped | never | assigned/authored | never | never | never | explicit grant |
| Invoice, payment, balance, tax | A | A | never | own household if billing permission | own if adult payer | A | explicit grant |
| Processor tokens, bank data, full card data | last-four/brand or provider reference only; raw secrets never | same | never | own masked method | own masked method | masked only | never by default |
| Audit/security events | tenant-scoped metadata; no secrets | own actions | own actions | own actions | own actions | own actions | grant-scoped |

The current household API rule is a mandatory regression gate: billing staff receive email/phone only for members marked `receives_billing`; all other household contact is `null`, birth date/pronouns are `null`, household notes are `null`, and guardian relationships are an empty collection. Search must not become an oracle: matching private fields may not reveal the match source or unauthorized value.

## 6. Authentication and account transitions

All transitions produce a success/failure audit event with actor, subject, request ID, coarse IP and user-agent metadata, authentication method, affected studio when applicable, and safe reason code. Audit payloads never contain credentials or plaintext tokens.

### 6.1 Registration and verification

1. Public registration is an intentional owner-onboarding entry point. A syntactically valid request for a new or existing normalized email returns exactly `202 {"message":"If registration can be completed, check your email for next steps."}` with the same headers, cookie behavior, absent redirect, and bounded timing class. Registration does not authenticate, rotate the existing CSRF session, create an authenticated cookie, or trigger a post-response current-user probe. Ordinary public registration never probes current-user state; an invitation landing may probe once before form rendering solely to route an already-authenticated recipient to onboarding. The response never claims creation or delivery.
2. Only a genuinely new email creates an unverified global user and queues the encrypted verification notification after commit. An existing account is never renamed, re-passworded, notified, logged in, or otherwise mutated. Input syntax, password confirmation, minimum length, and compromised-password validation run identically before account-existence can affect the result; only those non-semantic validation failures return `422`.
3. A valid pending invitation bound to a genuinely new normalized email may permit the same unverified-user creation, but registration does not verify the email, accept or mutate the invitation, create a membership, expose its studio/role, or authenticate the browser. An existing email, invalid/expired/revoked/accepted token, or token/email mismatch receives the same `202` with no user, membership, invitation mutation, or verification delivery. The browser scrubs the token from the URL and retains it only for later authenticated onboarding/acceptance.
4. Every semantically accepted path performs password-hash work inside a minimum 300 ms timebox. Registration is limited to five requests/hour per HMACed normalized-email-plus-IP key and 20/hour per HMACed IP key, plus the shared 60/minute auth-form IP ceiling. Raw emails are never limiter keys, and limiting does not disclose account or invitation state.
5. Password registration creates only the pending, unverified identity and grants no membership access. Password policy is at least 12 characters, uncompromised-password checked, confirmed, and not dependent on mixed-case, number, symbol, or other composition tricks; passphrases are allowed. Hash using the framework-selected Argon2id or bcrypt work factor and rehash on successful authentication when parameters change.
6. Verification links use Laravel temporary signed URLs, expire after 60 minutes, and are bound to user ID plus email hash. Resend is limited to 6/minute per user and 20/hour per IP and returns the same `202` shape.
7. Successful verification is idempotent, marks the exact current address verified, rotates the session, and resumes invitation acceptance if one is pending. A link for an old/pending-replaced email is rejected.

### 6.2 Password login

1. The Filament login page submits directly to Laravel's stateful session guard under CSRF protection. The server normalizes the email before lookup, applies keyed account/IP limits, performs generic timeboxed credential failure, completes any required second factor, and regenerates the session before redirecting into `/manage`.
2. Lookup uses normalized email. Unknown account, wrong password, locked account, and unverified account use the same credential failure body and materially similar timing; the UI may present a separate verification state only after valid credentials prove account ownership.
3. Fortify's pipeline must retain username canonicalization, login throttling, two-factor redirection, authentication attempt, and authenticated-session preparation. Successful completion—not merely the first factor—regenerates the session ID.
4. Rate limits are 5 failed attempts/minute per normalized-email hash plus IP and 50 attempts/15 minutes per IP. The normalized email is HMACed before being used as a distributed limiter key. A successful full login clears the account-specific limiter. Failure responses include retry metadata without revealing account existence.
5. A user with confirmed TOTP receives Fortify's `two_factor: true` response and is not authenticated until `POST /api/v1/auth/two-factor-challenge` succeeds with a six-digit TOTP or single-use recovery code.
6. A passkey login uses Fortify's usernameless WebAuthn endpoints and produces the same post-authentication session protections.

### 6.3 Password reset and change

1. `POST /api/v1/auth/forgot-password` always returns the same generic `202`, including for unknown, locked, deactivated, or SSO/passkey-only users. Issuance is limited to one token per address per 60 seconds, 5/hour per normalized-email hash, and 20/hour per IP.
2. Reset tokens are random, broker-hashed, single-use, and expire after 60 minutes. Creating a new reset invalidates the prior token. Reset URLs are generated only for trusted application hosts and are redacted from logs and analytics.
3. `POST /api/v1/auth/reset-password` validates the exact token/email pair and the password policy. Success hashes the password, rotates the remember token, fires `PasswordReset`, invalidates every existing session and personal token, clears other reset tokens, and sends a security notification. The user signs in again; reset does not implicitly accept an invitation.
4. Authenticated password change requires the current password or passkey confirmation, MFA if enrolled, and a step-up not older than 10 minutes. It has the same global session/token invalidation as reset except a newly rotated current session MAY remain.
5. Reset and verification-token cleanup runs at least hourly; expired rows are retained only as non-secret audit metadata.

### 6.4 Session lifecycle

- Session storage MUST be server-side database or Redis, encrypted at rest by infrastructure, and use JSON serialization. Production cookies are `Secure`, session cookie `HttpOnly`, `SameSite=Lax`, path `/`, and scoped to the minimum shared parent domain needed by `app`, `api`, and `admin`. The readable `XSRF-TOKEN` is not an authentication credential.
- Standard sessions expire after 8 hours idle and 30 days absolute; remembered sessions remain subject to the same 30-day server-side absolute limit. Deployments may configure shorter limits but not longer ones. Platform sessions expire after 30 minutes idle and 8 hours absolute and cannot be remembered. Absolute age is enforced server-side, not only by cookie expiry.
- Session ID is regenerated after complete login, passkey login, MFA completion, privilege-bearing recovery, and email/password change. Studio switching changes tenant context but does not copy permissions into session; authorization re-queries the target membership.
- Logout calls the framework logout operation, invalidates the server session, and regenerates the CSRF token. “Log out all browser sessions” revokes every other browser session; personal-token mass revocation is a separate explicit security action. Session inventory shows an opaque public ID, device label, coarse location, created/last-active time, and current marker without exposing the session cookie or backend storage key.
- Membership suspension/removal terminates active use of that studio on the next request and invalidates tenant-specific cached grants; the global session remains usable for other active studios. Global lock/deactivation, password reset, or confirmed account takeover revokes all sessions and tokens.
- `auth.session`/Sanctum session-authentication protection is required on authenticated browser routes so password-change invalidation is enforced. Authorization decisions and active membership objects MUST NOT be stored long-term in session.

### 6.5 CSRF, CORS, and browser boundary

- The authenticated Filament browser uses Laravel's stateful database session and CSRF middleware directly, never a personal access token in local/session storage. Public Next.js content does not own an authenticated application session.
- `statefulApi()` remains enabled. Stateful domains include exact host and port. Production CORS origins are explicit HTTPS origins; wildcard origins and reflected arbitrary origins are forbidden when credentials are supported.
- The client calls `GET /sanctum/csrf-cookie`; URL-decodes `XSRF-TOKEN`; sends it as `X-XSRF-TOKEN` on every state-changing request; and includes credentials, `Accept: application/json`, and a valid `Origin` or `Referer`.
- GET/HEAD/OPTIONS are side-effect free. All state changes require CSRF validation. Cookie-authenticated requests with missing/unapproved Origin/Referer fail before domain logic. Login, logout, invitation acceptance, MFA, passkey, verification resend, password change, and support access are covered.
- Session cookies are never accessible to Next.js client JavaScript. CSP, HSTS, frame-ancestor restrictions, trusted proxies/hosts, and `Referrer-Policy: strict-origin-when-cross-origin` are production requirements. Invite/reset landing pages use `no-referrer` and no third-party resources.

### 6.6 MFA and recovery

- TOTP is mandatory for owner, administrator, and platform roles. It is mandatory before payout destination, processor credential, API-key, bulk-export, ownership, or support operations for every role. Office, billing, teacher, guardian, student, and accountant users are prompted and may later be made mandatory by studio policy.
- A newly promoted owner/administrator gets a 7-day enrollment grace period with persistent warning, but cannot perform step-up actions until MFA is confirmed. Platform has no grace period. At grace expiry the membership is restricted to MFA setup and account recovery.
- `POST /api/v1/auth/user/two-factor-authentication` creates a pending encrypted secret; the feature is not enabled until `POST /api/v1/auth/user/confirmed-two-factor-authentication` validates a TOTP. Pending setup expires after 10 minutes.
- Recovery codes are high entropy, shown only after confirmation/regeneration, stored encrypted or individually hashed, and single-use. Regeneration invalidates every old code. Reading, regenerating, or disabling MFA requires a step-up within 10 minutes. A used recovery code triggers notification and a prompt to regenerate.
- `POST /api/v1/auth/two-factor-challenge` is limited to 5 attempts/5 minutes per challenge plus IP and 20/hour per IP. TOTP replay within the same time step is rejected. A challenge expires after 5 minutes or on login restart.
- Recovery that bypasses MFA requires verified email plus a separately reviewed recovery mechanism, revokes all sessions/tokens, and creates a high-severity audit event. Support staff cannot read TOTP secrets or recovery codes and cannot disable MFA directly.

### 6.7 Passkeys

- Fortify passkeys are enabled with password/passkey confirmation for registration and deletion. The user implements Fortify's `PasskeyUser`; Next.js uses `@laravel/passkeys/react` or protocol-equivalent code.
- Relying-party ID is the production parent application domain, allowed origins are an exact allowlist, `user_handle_secret` is a dedicated rotatable secret, ceremony timeout is 60 seconds, attestation is `none`, and user verification is `required`.
- Options/challenges are random, session-bound, origin/RP-bound, expire after 60 seconds, and are single-use. Login, confirmation, and registration share a dedicated limiter of 10 ceremonies/5 minutes per session/IP and 50/hour per IP.
- Supported Fortify routes under Maestro's configured prefix are `GET /api/v1/auth/passkeys/login/options`, `POST /api/v1/auth/passkeys/login`, `GET /api/v1/auth/passkeys/confirm/options`, `POST /api/v1/auth/passkeys/confirm`, `GET /api/v1/auth/user/passkeys/options`, `POST /api/v1/auth/user/passkeys`, and `DELETE /api/v1/auth/user/passkeys/{passkey}`.
- Passkey names are plain labels, escaped on output, maximum 80 characters, and not trusted as device facts. Credential IDs are unique globally. Signature counters, where supplied, are checked for cloning anomalies.
- Registration/deletion requires a 10-minute step-up and MFA if enrolled. A user cannot delete the last usable authentication method. Passkey confirmation may satisfy Laravel password confirmation, but it does not by itself satisfy a required second factor unless the policy explicitly accepts a phishing-resistant, user-verified passkey as that factor.

### 6.8 Personal access tokens

Personal access tokens are reserved for native clients and integrations, never the first-party web apps. Plaintext is displayed once; only the SHA-256 digest is stored. A token is bound to its owner, explicit abilities, allowed studio IDs, creation purpose, last-used timestamp, and an expiry no longer than 90 days. Policies still check active membership and tenant ownership because Sanctum `tokenCan()` is not sufficient and is always true for first-party SPA requests. Revocation, user lock, password reset, membership suspension/removal, and role reduction take effect immediately. Expired tokens are pruned daily.

## 7. Invitation lifecycle

### 7.1 State machine

```text
requested -> pending_delivery -> pending
    |              |                |-- resend -> superseded -> pending(new token)
    |              |                |-- revoke -> revoked
    |              |                |-- time -> expired
    |              |                `-- accept transaction -> accepted -> active membership
    `-- validation/policy failure -> no record
```

Accepted, revoked, superseded, and expired are terminal. Resend creates a new token/digest and marks the former invitation superseded; it never extends or reuses a token. Default invitation lifetime is 7 days. Cleanup redacts token digests after 30 days and retains non-secret audit metadata according to retention policy.

The exact resend, job-payload, audit, cleanup, privacy, and executable-evidence contract is defined in [Invitation audit and delivery acceptance](./invitation-audit-delivery-acceptance.md). The named repository evidence currently passes for the invitation-domain slice; broader cross-domain audit and outbox controls remain pending as recorded there.

### 7.2 Create, list, resend, and revoke

1. The inviter selects a target studio already resolved from the route, an email, and an allowed role. No body `studio_id`, inviter ID, status, or accepted user is honored.
2. Authorization follows the role matrix, cannot affect owner through the ordinary invitation endpoint, and requires a step-up for owner/administrator actions. A studio cannot use invitation APIs to discover whether the email has a global user or another studio membership.
3. Create returns `201` with the tenant invitation resource when a new invitation is committed. It MAY return `422` for an existing pending invitation or current membership in that same route studio because the authorized caller can already list that tenant-local state. The response MUST NOT reveal whether the address has a global account or any membership outside the route studio. Resend returns `202` when a replacement delivery is queued.
4. Invite list shows normalized/masked target email according to membership-management permission, intended role, inviter display name, and tenant-local state. It never includes global user ID, other studios, login/MFA state, or token digest.
5. Create is limited to 20/hour per inviter and 100/day per studio; resend is limited to 3/day per `(studio,email)` and one/minute. Delivery jobs are unique by invitation version and use after-commit outbox dispatch.
6. Revocation is idempotent, invalidates pending delivery and acceptance immediately, and emits an audit event. Deleting a UI row cannot erase invitation history.

### 7.3 Secure acceptance without tenant leakage

1. The email link carries at least 256 bits of random opaque entropy. The server stores only a digest. The landing page sends no third-party request, uses `Referrer-Policy: no-referrer`, posts the token once, then removes it from browser history with `replaceState`. Tokens are redacted from access logs, error trackers, analytics, and replay recordings.
2. Token inspection returns only a generic valid/invalid/expired result until the user proves control of the invited email. A valid-token page may show the studio display name and intended role needed for informed consent, but not members, people, counts, billing, or other tenant data.
3. Existing user: authenticate normally, complete MFA, and require the user's current verified normalized email to equal the invitation email. A logged-in user with a different email receives a generic account-mismatch flow; the API does not reveal either address or offer to attach the wrong account.
4. New user: token-bound registration creates the global user at the invitation email, verifies that address, and then returns to acceptance. Account creation alone does not create membership.
5. Acceptance is a single database transaction that locks the invitation, rechecks digest/state/expiry, verifies exact email and account state, confirms the intended tenant and role from the invitation, ensures membership uniqueness, creates/activates the membership, optionally links the preselected tenant person, marks the invitation accepted by that user, and commits audit/outbox events.
6. The client never submits or overrides `studio_id`, role, target email, person ID, or inviter ID during acceptance. Any mismatch fails without partial membership or link creation.
7. Repeating an accepted token as the same authenticated active member is intentionally idempotent and returns `200` with the same safe joined-studio resource; it creates no second membership or event. Expired, revoked, superseded, mismatched-user, and otherwise unusable tokens return the same generic failure family. Concurrent acceptance yields exactly one membership and one accepted event. Idempotency keys remain required for other retryable security writes where replay cannot be derived safely from terminal domain state.
8. A user may accept invitations to multiple studios. Acceptance does not switch the current studio automatically if that would discard unsaved work; it adds the studio to the switcher and offers an explicit switch.
9. An existing suspended or removed membership is not silently reactivated. The invite is rejected tenant-generically and requires an authorized restore/reinvite workflow with audit history.

## 8. Membership transitions

| From | Action | To | Required controls |
|---|---|---|---|
| none | accept valid invitation | active | exact verified email, token lock, membership uniqueness, atomic audit |
| legacy invited | migration | pending invitation or expired | no access before/after migration |
| active | role change | active/new role | actor matrix, target tenant, recent auth; MFA for privileged role; invalidate grants/caches |
| active | suspend | suspended | actor matrix, reason; immediate tenant denial; revoke tenant tokens/jobs |
| suspended | restore | active | owner/admin within matrix, reason, current account safe; re-evaluate MFA |
| active/suspended | remove | removed | actor matrix, reason; immediate denial; preserve history |
| removed | return | active | new accepted invitation; never raw status flip |
| owner | transfer | administrator or chosen role | recipient active + verified + MFA, actor recent MFA, atomic single owner |

Self-demotion/suspension/removal that would leave no owner is forbidden. Administrators cannot affect administrators or owners. Role reduction invalidates outstanding invitations/actions that the target can no longer authorize, permission caches, tenant PAT abilities, exports, and queued user-authorized work. Jobs already running must recheck at the irreversible boundary.

Studio switching uses an authorized `GET /api/v1/studios` result, not guessed slugs or client roles. Selecting a studio stores only a preference. Each request resolves the route studio, retrieves the active membership, initializes `TenantContext`, and clears it in `finally`. Suspended studios disappear from normal switching but remain as generic inaccessible history where legally required.

## 9. Anti-enumeration and response rules

| Surface | Public/safe response rule |
|---|---|
| Login | Same status/body for unknown user, wrong password, locked/deactivated user; bounded timing |
| Forgot password | Always generic `202`; never state whether email was sent |
| Verification resend | Authenticated generic `202`; no disclosure of alternative/current addresses |
| Passkey login options | Usernameless flow; no email/account lookup oracle |
| Invitation inspect/accept | Invalid/expired/revoked/used are one generic family; tenant details only after valid token and only minimum consent context |
| Invite create/resend | Authorized caller sees tenant-local queued result, never global account or other-studio state |
| Studio/object route | Cross-tenant object `404`; known studio without active membership `403`; guest `401` |
| Membership/person search | Only current tenant, policy-filtered fields; stable pagination/counts cannot include hidden rows |

Rate-limit keys use keyed hashes, not raw email or tokens. Error monitoring groups by safe reason code. Operational staff can diagnose through tenant-scoped audit records; user-facing errors stay generic.

## 10. Step-up and high-risk operations

“Recent authentication” means a successful password or user-verified passkey confirmation within 10 minutes. “Recent MFA” means recent authentication plus the required second-factor/passkey policy within 10 minutes. The server records timestamps and method in the session; client claims are ignored.

Recent MFA is required for ownership transfer, administrator promotion/demotion, MFA recovery/disable, email/password/passkey changes, session/token mass revocation, payment processor or payout changes, API key creation, full/contact/financial export, destructive studio operations, and platform support grants. Reauthentication is required again after a role/studio change or detected risk event even inside the window.

## 11. Audit, notification, and background work

Mandatory audit types include login success/failure/lockout, logout, reset requested/completed, email changed/verified, MFA setup/confirm/disable/recovery-use, passkey add/use/delete, session/token create/revoke, invite create/send/resend/revoke/expire/accept/replay, membership role/status/ownership change, tenant denial/IDOR signal, export, and platform support request/approval/entry/action/expiry.

Security notifications go to the global user for password/email/MFA/passkey/session changes, recovery-code use, new device login, role promotion, suspension/removal, and ownership transfer. Invitation notifications use the target address without leaking global-user status to the inviter.

Jobs contain immutable `studio_id`, actor or service-grant ID, authorization intent, and idempotency key. They initialize and clear tenant context per unit of work, run under RLS, and re-authorize immediately before sending/exporting/mutating. Missing, suspended, removed, expired, or role-reduced authorization causes a safe discard or compensating failure—never fallback to unscoped execution. Payloads and failed-job records contain no plaintext auth/invite/reset tokens.

## 12. Release gate

Identity and access may be marked complete only when:

- every MUST in this document is implemented or explicitly superseded by an accepted ADR;
- the complete companion endpoint, policy, job, PostgreSQL, and E2E matrix passes in CI;
- role/field serializers, exports, search, and notifications share authorization fixtures;
- session fixation, CSRF, token replay, invitation races, IDOR, RLS default-deny, role downgrade, and job revocation tests pass;
- production configuration is checked automatically for exact origins/hosts, secure cookies, trusted proxies/hosts, secrets, rate-limit store, queue isolation, and expiring tokens;
- a manual WebAuthn/TOTP recovery and multi-studio invitation drill is recorded;
- no unresolved P0/P1 authorization or tenant-isolation finding remains.

## 13. Official framework sources

This acceptance contract was reconciled against the current Laravel 13 documentation on 2026-08-10:

- [Laravel Sanctum: SPA cookie authentication, CSRF, stateful domains, CORS, token abilities and expiry](https://laravel.com/docs/13.x/sanctum)
- [Laravel Fortify: headless authentication, login pipeline/throttling, TOTP, passkeys, resets and verification](https://laravel.com/docs/13.x/fortify)
- [Laravel authentication: session regeneration, logout, other-device invalidation and password confirmation](https://laravel.com/docs/13.x/authentication)
- [Laravel password reset: broker storage, expiry/throttle, trusted hosts and reset events](https://laravel.com/docs/13.x/passwords)
- [Laravel email verification: signed verification flow, resend throttling and verified middleware](https://laravel.com/docs/13.x/verification)
- [Laravel CSRF protection: encrypted session token and `X-XSRF-TOKEN`](https://laravel.com/docs/13.x/csrf)
- [Laravel authorization: policies and deny-by-default application authorization](https://laravel.com/docs/13.x/authorization)
- [Laravel rate limiting: named distributed limiters](https://laravel.com/docs/13.x/rate-limiting)
- [Laravel hashing: bcrypt/Argon2 and rehash checks](https://laravel.com/docs/13.x/hashing)
- [Laravel queues: after-commit dispatch, uniqueness, encrypted jobs, retries and failed-job behavior](https://laravel.com/docs/13.x/queues)
- [Laravel notifications: queued and after-commit delivery](https://laravel.com/docs/13.x/notifications)
- [Laravel task scheduling: single-server and non-overlapping commands](https://laravel.com/docs/13.x/scheduling)

Framework defaults are a floor, not the whole product policy. Numeric lifetimes, rate ceilings, role decisions, invitation semantics, field visibility, platform separation, and revocation behavior above are Maestro decisions.
