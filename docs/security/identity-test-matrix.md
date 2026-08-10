# Executable identity and access test matrix

- Status: **normative release ledger**
- Version: 1.0 (2026-08-10)
- Specification: [Identity and access acceptance](./identity-access-acceptance.md)

## 1. How to execute this ledger

Each stable ID below becomes one named automated test or one data-provider row. API and policy tests belong under `apps/api/tests/Feature/Security`; database/RLS tests under `apps/api/tests/Integration/Security`; Next component tests under `apps/web/src/**/__tests__`; full browser journeys under `apps/web/e2e/security`. Proposed Maestro routes are marked `M`; unmarked auth routes are Laravel Fortify/Sanctum routes.

Required suites:

```bash
cd apps/api && composer test
cd apps/api && php artisan test --testsuite=Integration
cd apps/web && pnpm test:run
cd apps/web && pnpm exec playwright test e2e/security
```

The Integration and Playwright commands become required only when their configuration lands; the absence of either suite is a release failure, not a skipped pass. Tests use PostgreSQL with the restricted runtime role for isolation assertions. SQLite is insufficient for an acceptance result.

Every successful security write asserts the domain state, one immutable audit event, and one after-commit outbox effect where notification is required. Every denied write asserts no domain mutation, no success audit, no notification, and tenant context cleared. Time-sensitive tests freeze the clock. Concurrency tests use distinct database connections/processes, not sequential requests.

### 1.1 Canonical fixtures

`SecurityScenario` creates studios `allegro` and `nocturne`; users with each current/future role; one user who is owner in Allegro and teacher in Nocturne; active/suspended/removed memberships; linked and unlinked tenant people; two households; payer/non-payer guardians; minor/adult students; TOTP/passkey users; a platform operator without a support grant; and pending/expired/revoked/superseded/accepted invitations. ULIDs are fixed in fixtures so response snapshots are deterministic.

Role data providers MUST include `guest`, `unverified`, `owner`, `administrator`, `office`, `billing`, `teacher`, `guardian`, `student_minor`, `student_adult`, `accountant`, `platform_without_grant`, and `platform_with_grant`. Future roles remain pending tests until enabled, but cannot be deleted from the matrix.

## 2. Endpoint acceptance matrix

Expected `problem` means the shared `application/problem+json` shape with safe title/status/code/request ID and no stack trace, SQL, email, token, other-tenant ID, or policy class.

| ID | Route and condition | Required assertion |
|---|---|---|
| AUTH-E001 | `GET /sanctum/csrf-cookie` from allowed app origin | `204`; credentialed CORS exact origin; `XSRF-TOKEN` and session cookies have required production attributes |
| AUTH-E002 | Same from unapproved/null cross-site origin | No credentialed CORS; subsequent cookie write fails CSRF/origin validation |
| AUTH-E003 | `POST /api/v1/auth/login` without CSRF | `419` problem; no session/auth event |
| AUTH-E004 | Valid password login without MFA | `200`; session ID differs from pre-login ID; global user only, no tenant role cached |
| AUTH-E005 | Unknown email vs wrong password vs locked/deactivated user | Same status and public body; timing statistical test stays within approved CI tolerance |
| AUTH-E006 | Sixth failed login in one minute for email+IP | `429`, safe retry metadata; distributed limiter key contains no raw email |
| AUTH-E007 | Valid first factor for confirmed-TOTP user | `200` with `two_factor: true`; protected route still `401`/not fully authenticated |
| AUTH-E008 | `POST /api/v1/auth/two-factor-challenge` with valid TOTP | `204`; challenge consumed; regenerated authenticated session |
| AUTH-E009 | Reuse TOTP in same step, invalid TOTP, expired challenge | `422` generic; no authentication; limits increment |
| AUTH-E010 | Valid recovery code then replay | first `204` plus notification/audit; replay `422`; stored code no longer usable |
| AUTH-E011 | `POST /api/v1/auth/logout` with valid CSRF | `204`; session invalid; CSRF token regenerated; protected route `401` |
| AUTH-E012 | `DELETE /api/v1/auth/sessions/{userSession}` (M), other device | current user only; target session revoked; opaque public ID does not expose the cookie/storage key and is not enumerable |
| AUTH-E013 | `DELETE /api/v1/auth/sessions/others` (M) | recent auth required; other sessions revoked; current remains usable; PAT mass revocation remains a separate explicit action |
| AUTH-E014 | Standard session at 8h idle/30d absolute; remembered at 30d absolute; platform at 30m/8h | next request `401`; server record invalidated even if cookie remains |
| AUTH-E015 | Suspended active studio while user has another studio | suspended studio `403`, other studio remains usable, stale tenant cache cannot authorize |
| AUTH-E016 | Global lock/deactivation during session | all sessions/PATs rejected on next use |
| AUTH-E017 | `GET /api/v1/auth/sessions` | only the current user's opaque ULIDs, server-derived device label, coarse network prefix, created/last-seen metadata, and current marker; expired/orphaned registry rows are pruned; no raw IP/user-agent, session cookie/storage ID, or other user row |
| AUTH-E018 | `DELETE /api/v1/auth/sessions/{userSession}` for current session | recent confirmation required; `204`, logout, server session invalidation, CSRF regeneration, and next protected request `401` |
| REGISTER-E001 | `POST /api/v1/auth/register` without invitation, new vs existing normalized email | identical unauthenticated generic `202`, headers, cookies, redirect behavior, and bounded timing; only a new address creates an unverified user/verification delivery; neither creates studio authority before verified onboarding |
| REGISTER-E002 | `POST /api/v1/auth/register` with valid `invitation_token` | token-bound invited email, password policy, pending-verification user only; no membership before verification/acceptance |
| REGISTER-E003 | Invitation-bound request with changed email, invalid/terminal token, or existing normalized email | safe generic verification/login guidance that does not disclose account existence; no duplicate user or membership and no automatic invite consumption |
| RESET-E001 | `POST /api/v1/auth/forgot-password` for existing/unknown/locked/passkey-only email | same `202`; only eligible existing account gets queued notification |
| RESET-E002 | Reset request limits | one address issuance/60s, 5/hour email hash, 20/hour IP; no raw email key |
| RESET-E003 | `POST /api/v1/auth/reset-password` valid token | password/remember token change; reset tokens, all sessions and PATs revoked; event + notification |
| RESET-E004 | Expired, replaced, used, wrong-email reset token | same safe `422`; no mutation; replay denied |
| RESET-E005 | Host-header injection when requesting reset | generated link uses configured trusted host or request is rejected |
| VERIFY-E001 | Temporary signed verification URL for exact current email | marks verified once, rotates session, emits one event |
| VERIFY-E002 | Expired/bad signature/wrong user/old changed email | safe `403`; no verification |
| VERIFY-E003 | `POST /api/v1/auth/email/verification-notification` | same `202`; 6/min user and 20/hour IP; no alternate-email disclosure |
| VERIFY-E004 | Unverified user on studio route | denied before tenant data serialization; verification/setup endpoints remain available |
| ACCOUNT-E001 | Email change request | recent auth + MFA; old login remains until new signed proof; both addresses notified safely |
| ACCOUNT-E002 | Confirm email change | normalized uniqueness enforced; other sessions/reset tokens revoked; invitation emails unchanged |
| ACCOUNT-E003 | Password change | current password/passkey + MFA; policy and uncompromised check; other sessions/PATs revoked |
| ACCOUNT-E004 | `POST /api/v1/auth/user/confirm-password` and confirmation-status route | valid current password marks only this session recent for 10m; wrong password/throttle safe; expiry deterministic |
| ACCOUNT-E005 | `GET /api/v1/auth/user` | global self fields plus `two_factor_enabled`/`passkeys_count`; no memberships, auth secrets, other users, or client-selected role; studios come only from the separate studio collection |
| MFA-E001 | `POST /api/v1/auth/user/two-factor-authentication` | recent auth; pending encrypted secret only; no enabled MFA before confirmation |
| MFA-E002 | `POST /api/v1/auth/user/confirmed-two-factor-authentication` valid/invalid/after 10m | valid enables TOTP and permits the post-confirmation recovery-code reveal; invalid/expired has no partial enablement or recovery-code disclosure |
| MFA-E003 | `GET/POST /api/v1/auth/user/two-factor-recovery-codes` | recent auth; GET never cached/logged; POST invalidates all former codes |
| MFA-E004 | `DELETE /api/v1/auth/user/two-factor-authentication` | recent auth + existing MFA; notification/audit; cannot bypass mandatory-role policy without safe downgrade/recovery |
| MFA-E005 | Promoted admin/owner grace period | warning during 7d, sensitive operations denied; after 7d only setup/recovery allowed |
| MFA-E006 | `GET /api/v1/auth/user/two-factor-qr-code` and `/two-factor-secret-key` | recent confirmation; QR `{svg,url}` or stock empty array before setup, secret `404` before setup; every response `Cache-Control: no-store, private`, `Pragma: no-cache`; no secret in logs |
| PASS-E001 | `GET /api/v1/auth/passkeys/login/options` | usernameless random 60s challenge; exact RP/origin; no account oracle |
| PASS-E002 | `POST /api/v1/auth/passkeys/login` valid assertion | challenge single-use, UV required, session regenerated; audit contains credential reference not payload |
| PASS-E003 | Wrong origin/RP, expired/replayed challenge, invalid signature | generic `422`; no login; limiter increments |
| PASS-E004 | `GET /api/v1/auth/passkeys/confirm/options` then `POST /api/v1/auth/passkeys/confirm` | authenticated and session-bound; success sets 10m recent-auth state only for same session |
| PASS-E005 | `GET /api/v1/auth/user/passkeys/options` then `POST /api/v1/auth/user/passkeys` | recent auth + MFA; unique credential; escaped label max 80; no credential payload in logs |
| PASS-E006 | `DELETE /api/v1/auth/user/passkeys/{passkey}` | own credential only, recent auth + MFA; cannot delete last usable method |
| PASS-E007 | Eleventh ceremony in 5m / 51st IP ceremony in hour | `429` with shared dedicated limiter across login/confirm/register |
| PASS-E008 | `GET /api/v1/auth/passkeys` | safe current-user metadata only (`id`, label, authenticator, last-used/created timestamps); no credential ID, public key, user handle, assertion, or other user's passkey |
| INV-E001 | `POST /api/v1/studios/{studio}/invitations` (M) allowed role | `201`; route studio wins; digest only stored; pending audit/outbox after commit |
| INV-E002 | Same body for nonexistent/existing/other-studio user/current route-studio member | new invite `201`; authorized tenant-local pending/member conflicts may be `422`; no global ID, account existence, or other-studio membership disclosure |
| INV-E003 | Body attempts `studio_id`, inviter, status, accepted user, role escalation | ignored/validation error; no cross-tenant or elevated record |
| INV-E004 | `GET /api/v1/studios/{studio}/invitations` (M) | only authorized tenant records/safe fields; no tokens/global state; counts exclude other studio |
| INV-E005 | `POST .../invitations/{invitation}/resend` (M) | `202`; old row/token superseded, new digest and 7d expiry; old token `410` |
| INV-E006 | `DELETE .../invitations/{invitation}` (M) | idempotent `204`; pending job cannot deliver/use revoked token |
| INV-E007 | `POST /api/v1/invitations/preview` with JSON `invitation_token` | minimum consent context only, no people/member/billing/count data; fragment scrubbed before transport and page `no-referrer` |
| INV-E008 | Inspect random/expired/revoked/superseded/accepted token | same generic invalid family and bounded timing |
| INV-E009 | `POST /api/v1/invitations/accept` with JSON `invitation_token`, matching existing verified user | `200`; exact studio/role from locked invite; one membership and accepted audit |
| INV-E010 | Same for new user before/after verification | before: no membership; after verified token-bound account: acceptance succeeds |
| INV-E011 | Logged-in different verified email | generic account mismatch; no email/studio cross-account details; no membership |
| INV-E012 | Client overrides studio/role/email/person/inviter | fields rejected/ignored; server-bound values used; no partial link |
| INV-E013 | Accepted token replay by same active member; expired/revoked/superseded/mismatched replay | same member receives idempotent `200` Studio resource; all other unusable states share a generic failure family; no second membership/event and digest not logged |
| INV-E014 | Two concurrent accepts | one transition wins; the same authenticated user receives the same safe `200` joined-studio result from either completion path; one membership/event/outbox |
| INV-E015 | Retryable security write using `Idempotency-Key` | identical actor/route/request returns the saved safe response; changed payload/key reuse `409`; no duplicate effect; invitation acceptance may instead derive its idempotent `200` from terminal membership state |
| INV-E016 | Pending invite where membership is suspended/removed | no reactivation; safe rejection; authorized restore/reinvite required |
| INV-E017 | Same email invited to two studios | accepts independently; no auto-switch; two isolated memberships |
| INV-E018 | Create/resend quota exceeded | `429`; no notification/job; tenant/user enumeration unchanged |
| MEMBER-E001 | `GET /api/v1/studios` | active current-user memberships only; correct role per studio; no suspended/foreign rows |
| MEMBER-E002 | `PATCH /api/v1/studios/{studio}/memberships/{membership}` (M), role | policy matrix; recent auth/MFA as required; route tenant immutable; caches/tokens/jobs invalidated |
| MEMBER-E003 | Suspend/restore/remove membership (M) | lifecycle matrix; immediate access effect; reason/audit; removed cannot raw-restore |
| MEMBER-E004 | `POST .../ownership-transfer` (M) | both users active/verified/MFA; atomic exactly-one-owner invariant; sessions/grants reevaluated |
| MEMBER-E005 | Last owner self-demote/remove/suspend or concurrent transfers | `409` problem; database invariant preserves one owner |
| MEMBER-E006 | Admin attempts owner/admin mutation; any role attempts platform grant | `403`; no state/audit success |
| TENANT-E001 | Any `{studio}/{resource}` with object ULID from another studio | authenticated response `404`; response/body/timing does not identify foreign row |
| TENANT-E002 | Known studio with no/suspended membership | `403`; no tenant context leakage to next request |
| TOKEN-E001 | Create PAT (M) | recent MFA; plaintext once; digest stored; explicit abilities/studios; <=90d expiry |
| TOKEN-E002 | PAT ability without resource policy/membership | denied; `tokenCan` alone never authorizes |
| TOKEN-E003 | Expiry/revoke/password reset/lock/member suspension/role reduction | next token request rejected immediately; scheduled prune removes expired records |
| SUPPORT-E001 | Platform operator without grant enters tenant | denied; platform assignment never maps to studio owner |
| SUPPORT-E002 | Approved time-bound support grant | exact studio/capabilities only; visible banner; every read/write audited |
| SUPPORT-E003 | Expired/revoked/wrong-studio grant or export without export grant | immediate denial; no fallback/bypass |

## 3. Policy and field-visibility matrix

Implement a single data provider per action. It must exercise API policies, Filament `canAccessTenant`/resource actions, Next API-backed affordances, and direct serializer/export behavior. `A`, `S`, `R`, and `-` have the meanings in the main specification.

| ID | Policy/action | Owner | Admin | Office | Billing | Teacher | Guardian | Student | Accountant | Platform grant |
|---|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| POL-001 | `StudioPolicy::view` | A | A | A | A | A | A | A | A | S |
| POL-002 | `StudioPolicy::update` | A | A | - | - | - | - | - | - | S + approval |
| POL-003 | `StudioPolicy::transferOwnership/delete` | A | - | - | - | - | - | - | - | break-glass |
| POL-004 | `InvitationPolicy::create` administrator | A | - | - | - | - | - | - | - | - |
| POL-005 | `InvitationPolicy::create` office/billing/teacher | A | A | - | - | - | - | - | - | - |
| POL-006 | `MembershipPolicy::updateStatus/updateRole` target non-admin | A | A | - | - | - | - | - | - | S + approval |
| POL-007 | Same target owner/admin | A except ordinary owner transfer | - | - | - | - | - | - | - | - |
| POL-008 | `HouseholdPolicy::viewAny/view` | A | A | A | R | deny until scoped | S | S | R | S |
| POL-009 | `HouseholdPolicy::create/update` | A | A | A | - | - | own safe fields | own safe fields | - | - |
| POL-010 | `HouseholdPolicy::delete` | A | - | - | - | - | - | - | - | - |
| POL-011 | Schedule/attendance | A | A | A | R | S assigned | S portal | S portal | - | S |
| POL-012 | Billing record view | A | A | A | A | - | S permission/payer | S adult payer | A | S |
| POL-013 | Private lesson/staff notes | A | A | S purpose | - | S assigned/authored | - | - | - | S explicit |
| POL-014 | Export contacts/full/finance | A | A | operational only | finance only | assigned only | own | own | finance only | explicit grant |

Serializer assertions:

| ID | Fixture/requester | Required exact result |
|---|---|---|
| FIELD-001 | Billing requests household | payer email/phone present; household notes `null`; non-payer email/phone, every birth date/pronoun `null`; `guardian_relationships: []` |
| FIELD-002 | Teacher requests unassigned household | `403`/`404`, never partially serialized; when assignment feature lands only allowlisted assigned fields appear |
| FIELD-003 | Guardian A requests Guardian B or unrelated household | only own linked/permission-scoped records; unrelated and sibling-restricted data absent |
| FIELD-004 | Minor student requests household | self portal fields only; guardian/sibling/custody/finance absent |
| FIELD-005 | Accountant exports finance | payer contact and ledger fields only; family notes, non-payer contact, teaching fields absent |
| FIELD-006 | Any user/account response | no password hash, remember token, TOTP secret, recovery code, passkey key/user handle, session ID, PAT/invite/reset digest |
| FIELD-007 | Search/pagination | hidden rows and values do not appear in data, snippets, facets, counts, totals, links, or timing-based exact-match behavior |
| FIELD-008 | Audit/notification/realtime/cache/export | same or stricter allowlist than HTTP serializer; tenant ID included in keys/channels but not exposed cross-tenant |

## 4. Domain, database, and concurrency matrix

| ID | Layer | Scenario and invariant |
|---|---|---|
| DB-001 | Migration | Unique normalized global email; unique `(studio_id,user_id)` membership; unique pending `(studio_id,normalized_email)` invite |
| DB-002 | Constraint | Tenant child/parent composite foreign keys reject a cross-studio person, invitation-person link, membership, and audit target |
| DB-003 | RLS | Restricted runtime role with no `app.current_studio_id` reads zero tenant rows and cannot insert/update/delete |
| DB-004 | RLS | With Allegro context, direct SQL cannot read/write Nocturne memberships, invitations, people, or future tenant-scoped audit rows |
| DB-005 | RLS | Clearing/reusing pooled connection cannot retain previous tenant; transaction-local setting resets on commit/rollback |
| DB-006 | Ownership | Deferred/transactional invariant plus locked transfer prevents zero or multiple active owners under concurrency |
| DB-007 | Invitation | Two transactions accepting one token produce exactly one active membership and one terminal accepted invitation |
| DB-008 | Idempotency | Same key+request returns stored result; same key+different request conflicts; records are actor/route scoped and expire after 24h |
| DB-009 | Revocation | Role/suspension/global-lock update and protected action race: action locks/rechecks at irreversible boundary and cannot commit stale authority |
| DB-010 | Token storage | Password reset broker and SHA-256 invite/PAT digests contain no recoverable plaintext; database/log scans reject seeded plaintext markers |
| DB-011 | Email change | Two concurrent normalized-equivalent email claims yield one success, one safe conflict; invitations do not retarget |
| DB-012 | Person link | Global user may link to separate people in separate studios; no duplicate link in one studio; RLS hides other link |
| DB-013 | User-session RLS | Restricted runtime role with one `app.current_user_id` cannot read/write another user's `user_sessions`; hidden backend session IDs never serialize |

## 5. Job and scheduler matrix

| ID | Job/command | Required assertions |
|---|---|---|
| JOB-001 | `SendStudioInvitation` (M) | serialized payload has invitation/version/studio IDs, not plaintext token; token material generated only at delivery boundary; revoked/superseded/expired invite does not send |
| JOB-002 | Resend race | only newest invitation version sends; unique job/outbox prevents duplicate delivery; retries do not mint multiple valid tokens |
| JOB-003 | `SendPasswordResetLink` / verification notification | unknown user performs no send but caller response unchanged; URL trusted-host only; failed job/log has no token |
| JOB-004 | `ExpireStudioInvitations` (M) | freezes time; marks only pending past expiry; one audit each; accepted/revoked untouched; idempotent rerun |
| JOB-005 | `auth:clear-resets` | expired reset rows removed on schedule without affecting valid rows |
| JOB-006 | `sanctum:prune-expired --hours=24` | expired PAT rows pruned; active token retained; command scheduled daily |
| JOB-007 | User-authorized export | job carries studio/actor/intent/idempotency; rechecks active role and export grant; suspension/reduction before run safely fails and produces no file/URL |
| JOB-008 | Notification/broadcast | recipient re-resolved in same studio; removed/suspended/relationship-revoked user receives no tenant content; channel is tenant-scoped |
| JOB-009 | Support-grant work | grant checked before each irreversible unit; expiration mid-job stops remaining work; no unscoped fallback |
| JOB-010 | Tenant context hygiene | success, exception, retry, timeout, and batch loop clear context; following job cannot see prior studio |
| JOB-011 | Security notification | password/email/MFA/passkey/recovery/session/role/ownership events send after commit once and contain no secret/private tenant fields |
| JOB-012 | Cleanup | invitation digests redacted after 30d; audit metadata retained; cleanup idempotent and tenant-safe |

## 6. End-to-end browser journeys

| ID | Journey | Required observable result |
|---|---|---|
| E2E-001 | Password login, CSRF, logout | cookie is not JS-readable; login succeeds only after CSRF; fixation probe changes session; back navigation cannot reopen authenticated data after logout |
| E2E-002 | Owner with TOTP | password leads to challenge, incorrect/replayed code fails, valid code opens switcher, sensitive action asks for 10m step-up |
| E2E-003 | Recovery code | code works once, warning/notification shown, second use fails, regeneration invalidates downloaded old set |
| E2E-004 | Passkey lifecycle in virtual authenticator | register, login, confirm sensitive action, rename-safe display, delete; wrong-origin/replay and last-method delete fail |
| E2E-005 | Existing user invitation | email link shows minimum studio/role, authenticates matching account, accepts, studio appears in switcher without leaking existing studios to inviter |
| E2E-006 | New user invitation | token-bound address cannot be edited, account verifies, acceptance creates one membership, replay page remains generic |
| E2E-007 | Invitation opened while signed into different email | safe mismatch screen; no target/current address or tenant private data; sign-out then correct login succeeds |
| E2E-008 | Expired/revoked/random/used invite links | indistinguishable safe recovery UI; no studio detail or third-party/referrer request |
| E2E-009 | Multi-studio switching | same user owner in A/teacher in B; role-specific navigation/data changes on explicit switch; guessed object from A in B is `404`; no stale A UI/cache |
| E2E-010 | Membership suspended during active use | next mutation blocked, studio removed/disabled in switcher, unsaved UI explains safely, other studio still works |
| E2E-011 | Billing privacy | payer contact visible; private note/non-payer contact/birth/pronouns/guardian flags absent from DOM, network body, export, search, client cache |
| E2E-012 | Teacher/guardian/student scoping | only assigned/linked/self records and permitted portal modules; URL edits and client state edits cannot widen scope |
| E2E-013 | Ownership transfer | recent MFA for both, confirmation names target/studio, exactly one owner afterward; old owner controls disappear immediately |
| E2E-014 | Session management | two browser contexts visible as devices; revoke other context; revoked context fails next request; current remains rotated |
| E2E-015 | Password reset | public response generic, link works once, all pre-existing browser contexts and PAT fixture are invalidated |
| E2E-016 | Platform support | separate panel, mandatory MFA, approved tenant/purpose banner, expiry ends access, every viewed/actioned record has audit evidence |
| E2E-017 | Accessibility/security UX | keyboard/screen-reader complete login, MFA, passkey fallback, invitation, recovery; generic errors remain actionable without enumeration |

## 7. Configuration and adversarial gates

| ID | Gate | Failure condition |
|---|---|---|
| CFG-001 | Production config test | insecure/non-HttpOnly session cookie, wildcard credentialed CORS, non-HTTPS origin, wrong parent cookie domain, or missing `statefulApi()` |
| CFG-002 | Trusted host/proxy test | attacker-controlled Host/forwarded headers influence reset, verify, invite, passkey origin, secure-cookie, or redirect URLs |
| CFG-003 | Fortify feature test | public registration can grant tenant authority before verification/onboarding, or reset/verification/TOTP/passkeys/confirmation route or limiter differs from this contract |
| CFG-004 | Secret test | app/passkey derivation secrets default/reused in unsafe way, secrets committed, or generated links/tokens appear in logs/source maps/analytics |
| ADV-001 | IDOR fuzz | any ULID/slug substitution returns foreign data or a distinguishable object state |
| ADV-002 | Mass assignment fuzz | identity/tenant/role/status/accepted/audit fields can be client-assigned |
| ADV-003 | CSRF suite | any cookie-authenticated mutation succeeds cross-origin, without token, or via GET |
| ADV-004 | Replay/race suite | reset/invite/MFA/passkey/idempotency artifact succeeds twice or concurrent request creates duplicate authority |
| ADV-005 | Cache/search suite | a key/index/document without studio scope or role-aware result exposes data after switch/downgrade |
| ADV-006 | Log scan | seeded password/TOTP/recovery/passkey assertion/session/PAT/invite/reset marker appears in application, proxy, queue, failed-job, analytics, or error logs |
| ADV-007 | Dependency/SAST | known critical auth dependency issue, unsafe redirect, unserialized tenant job, missing policy, or secret scan finding remains unresolved |

## 8. Definition of a passing run

A report must list every stable ID as pass, pending-future-role, or fail. “Skipped,” missing route, missing future-role fixture, unsupported browser, absent PostgreSQL restricted role, or absent audit/outbox assertion is a failure for the corresponding release capability. Future guardian/student/accountant/platform rows may remain `pending-future-role` only while their parity capability remains planned; current-role, global identity, invitation, session, and auth rows have no such exemption.

The CI artifact retains test results, framework/package versions, production-config assertion output with values redacted, and the manual WebAuthn/recovery/support drill reference. It never retains plaintext test credentials or live-like tokens.
