# API conventions

The versioned contract is [`packages/contracts/openapi.yaml`](../../packages/contracts/openapi.yaml). Update the contract and generated declarations in the same change as an API behavior change.

## Authentication

First-party browser clients authenticate through Laravel Sanctum's stateful session cookie. Mutating requests also send the URL-decoded `XSRF-TOKEN` cookie value in the `X-XSRF-TOKEN` header. Browser requests must include credentials. The Laravel application must keep Sanctum stateful middleware, trusted domains, credentialed CORS, session cookie attributes, and the contract aligned in every environment.

The browser begins with `GET /sanctum/csrf-cookie`. Headless Fortify routes live under `/api/v1/auth`: login, registration, logout, forgot/reset password, verification consume/resend, password confirmation/status, TOTP, passkeys, and current-user lookup. Registration and login normalize email with Unicode whitespace trim, NFKC, and lowercase. Forgot-password returns the same `202` message for known and unknown syntactically valid emails and uses HMAC-derived account limiter keys. Password reset applies the uncompromised-password rule, rotates the remember token, removes the user's database sessions, and revokes the user's Sanctum personal access tokens.

Registration may optionally carry an invitation token. The server validates that a pending token is bound to the submitted normalized email, but registration itself does not verify the user, accept the invitation, or create a membership. Explicit onboarding or `POST /api/v1/invitations/accept` performs that transition with the server-owned studio and role.

Current registration behavior is not security-accepted yet: a new email returns `201` and an authenticated session while an existing email returns a field-level `422`, creating an account-existence oracle. The accepted replacement is the uniform unauthenticated generic `202` flow in `IDA-REG-001`; clients must not treat the current route shape as a stable privacy guarantee.

Reset and invitation email credentials are carried in URL fragments so they are not sent in HTTP request targets. The Next.js landing code reads each fragment once, immediately scrubs it from browser history, and submits the credential only in a CSRF-protected JSON body. Laravel enforces trusted hosts/request origins and emits production security headers. Fortify identity, TOTP, passkey, and invitation-preview responses are non-cacheable.

TOTP setup is a two-step transition: enable creates encrypted pending setup material for at most ten minutes, and confirmation with a valid six-digit code activates it. A confirmed account receives `two_factor: true` after password login and must complete the five-minute, rate-limited challenge using a current TOTP or one-time recovery code. The server rejects replay of the same TOTP time step. Reading setup secrets or post-confirmation recovery codes, recovery-code regeneration, and disablement require a recently confirmed password or passkey; recovery-code routes are unavailable until TOTP is confirmed.

Fortify's WebAuthn routes provide usernameless passkey login, same-session passkey confirmation, and recently confirmed registration/deletion. Relying-party ID and allowed origins are exact configuration boundaries, user verification is required, challenges are session-bound/single-use, and a dedicated limiter protects ceremonies. `GET /api/v1/auth/passkeys` exposes only safe metadata; credential IDs and public-key material are never serialized.

Password/passkey confirmation remains valid for at most ten minutes in production. `GET /api/v1/auth/sessions` lists opaque public session ULIDs, server-derived device labels, coarse network prefixes, created/last-seen timestamps, and a current marker; raw IP addresses, user agents, and backend session IDs are withheld. Expired and orphaned registry rows are pruned during inventory. The two DELETE routes revoke one owned session or all other browser sessions and require recent confirmation; revoking the current session logs that browser out. Standard sessions have a maximum eight-hour idle and 30-day absolute server-side lifetime, configurable downward. Session expiry returns `401` even when a browser still holds its cookie.

Privileged-role MFA enforcement/grace policy, platform-specific shorter sessions, security notifications, immutable audit events, and the idempotent outbox remain explicit acceptance gaps; endpoint presence alone does not complete them. The target behavior and stable control/test IDs are in [`../security/identity-access-acceptance.md`](../security/identity-access-acceptance.md).

The current global `User` resource uses Laravel's existing positive bigint ID. Studio, membership, invitation, household, person, and other tenant-domain resources use ULIDs. Clients must not assume one identifier format for every resource.

## JSON envelopes

- A single resource is `{ "data": { ... } }`.
- Creation responses add a fixed `message` alongside `data`.
- The current studio list is deliberately unpaginated and returns `{ "data": [{ ... }] }`.
- The studio invitation list is paginated at 25 records and returns top-level `data`, `links`, and `meta`.
- The household list is paginated and returns top-level `data`, `links`, and `meta`. Laravel 13's `meta.links` entries include `url`, `label`, `page`, and `active`.
- Future endpoints must document the envelope their controller actually returns; pagination is not implied globally.

## Errors

Laravel API failures use a JSON problem envelope with a required human-readable `message`. Validation failures use status `422` and add an `errors` object whose keys are input paths and whose values are arrays of messages. Clients should branch on HTTP status and field keys, not localized message text.

## Studio routing

Studio IDs are ULIDs, but the `{studio}` route parameter is the unique studio slug because `Studio::getRouteKeyName()` returns `slug`. Listing includes only the authenticated user's active memberships and is sorted by studio name. Cross-tenant access returns `403`; an unknown slug returns `404`.

## Onboarding

`POST /api/v1/onboarding` accepts exactly one of `invitation_token` or a `studio` creation intent. Invitation onboarding accepts the invitation's server-locked role and matching email. New-studio onboarding requires a verified email and creates an owner membership only when the user has no active membership; a user who already has one receives their earliest active studio instead of another automatic studio. The response is the ordinary `Studio` resource plus `Onboarding completed.`

`preferred_name`, `workspace_mode`, and `primary_goal` merge into membership preferences. `workspace_mode` (`owner`, `administrator`, or `teacher`) is never an authorization input: it cannot choose the new membership role or override an invitation. The request rejects both top-level `role` and `studio.role`.

## Invitations

`POST /api/v1/invitations/preview` is public and rate-limited and accepts `{ "invitation_token": "…" }`. A valid pending token returns only `status`, a masked `email_hint`, and `expires_at`; it intentionally omits the studio, role, inviter, account state, and full email. Invalid, expired, revoked, and accepted tokens return the same unavailable response. The retired bearer-path routes are not registered.

Owners and administrators can list invitations for an active route studio. Owners can invite administrator, office, billing, and teacher roles. Administrators can invite office, billing, and teacher roles. Owner invitations are invalid because ownership requires a separate transfer workflow. Creation normalizes the email and returns `201` with a safe invitation resource without token material; authorized tenant-local pending/member conflicts may return `422` without disclosing global-account or other-studio state. Creation and revocation require recent password/passkey confirmation. Revocation is idempotent unless the invitation was accepted.

Authenticated `POST /api/v1/invitations/accept` accepts the same JSON token body and requires the token email to match the current normalized global email. It atomically creates the active membership with the invitation's server-side role, marks the invitation accepted, and verifies the matching email. Repeating acceptance by the same active member returns the same joined `Studio` resource; mismatches and unusable states share an `invitation_token` validation error.

PostgreSQL forced RLS protects both studio memberships and invitations. Authenticated requests establish the current user database context, tenant routes additionally establish the route studio, and token preview/registration establish only the digest-scoped invitation context needed for that transaction.

Invitation resend/supersession, immutable security audit events, and an idempotent delivery outbox are not implemented by this slice and remain acceptance work. Same-user accepted-token replay deliberately derives an idempotent `200` from the terminal membership state; it does not require a client idempotency key or create a second membership/event.

## Households

`GET /api/v1/studios/{studio}/households` accepts an optional case-insensitive `q` search across household and member names, `per_page` from 1 through 100 (default 25), and a one-based `page`. Pagination URLs preserve the supplied query string. The collection is sorted by household name.

Households are aggregate roots containing members, people, optional learner profiles, guardian relationships, portal permissions, timestamps, an aggregate version, and caller-specific `edit` and `delete` permissions. Household IDs and all nested entity IDs are ULIDs. A household ID is always resolved through its studio relationship, so a cross-studio ID returns `404`.

The creation payload uses request-local member keys to connect guardians and learners before ULIDs exist. It must contain exactly one primary contact with an email address or phone number. Learners require a student profile, non-learners cannot have one, relationship keys must point to the appropriate member roles, and a guardian/learner pair can appear only once.

Active owners, administrators, office staff, and billing staff can view households. Owners, administrators, and office staff can create or edit; only owners can delete. Teachers cannot access household records. An unrelated or suspended studio membership returns `403` before tenant-scoped household work runs.
