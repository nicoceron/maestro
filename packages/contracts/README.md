# Maestro API contracts

The OpenAPI 3.1 document in `openapi.yaml` is the source of truth for the implemented Laravel browser identity, onboarding, studio invitation, studio, and household JSON APIs. Generated TypeScript declarations live in `generated/schema.d.ts` and can be imported as types from `@maestro/contracts` once this package is added to the workspace.

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
- `test` proves the generated declarations are current and audits the critical identity paths, operation IDs, schemas, and cookie/CSRF security invariants.
- `check` fails when the schema is invalid, generated declarations are stale, the identity audit fails, or the declarations do not type-check.

Browser clients first call `GET /sanctum/csrf-cookie`. Public Fortify mutations require the XSRF cookie/header pair; authenticated mutations require both that pair and the Sanctum session. Never interpret the UI-only onboarding `workspace_mode` as a membership role.

Public registration is Maestro-owned rather than Fortify auto-login: every syntactically valid new, existing, or semantically unusable-invitation request receives the same unauthenticated generic `202`, with no redirect or session rotation. Only a genuinely new eligible identity is created and sent the queued verification notification. Password schemas require 12 or more characters, exact confirmation, and server-side compromised-password validation without mixed-case, number, or symbol composition rules.

The identity contract follows the installed Laravel 13 behavior exactly: TOTP QR setup returns `{svg,url}` (or Fortify's empty array before setup), recovery-code GET returns the raw string array while regeneration returns an empty JSON string, and WebAuthn options/credentials follow the official passkey package. Sensitive identity responses are `no-store`; TOTP/passkey management, session revocation, and invitation create/revoke expose `423` when the current session needs recent confirmation.

Session inventory uses opaque public ULIDs, derived device labels, and coarse network prefixes rather than raw IP/user-agent values, cookies, or backend storage identifiers. The current session can revoke itself (which logs out that browser), or the user can revoke one other/all other database-backed sessions.

Invitation create returns a safe `201` queued resource. Resend is a bodyless, recently-confirmed `POST` to the tenant invitation's `/resend` child route and returns the fresh replacement resource with `202`; pending or expired invitations may be replaced after cooldown. List resources expose server-derived filters, collection capabilities, action permissions, delivery state, cooldown, and terminal supersession state, while internal lineage/version IDs, bearer/token digests, queue/outbox IDs, and global-account state remain absent. Delivery jobs are keyed only by invitation ULID and internal version and derive bearer material at execution.

Invitation preview and acceptance are JSON-body POST operations at `/api/v1/invitations/preview` and `/api/v1/invitations/accept`; no bearer token appears in an API path. Invite and reset links deliver credentials in URL fragments, which the browser scrubs before submitting the credential in a CSRF-protected JSON body.

`GET /api/v1/studios` uses Laravel's unpaginated resource collection envelope (`{ "data": [...] }`), while invitation and household lists use Laravel 13's full length-aware paginator (`data`, `links`, and `meta`, including each meta link's `page`). API authentication, envelope, error, authorization, and pagination conventions are documented in [`../../docs/api/README.md`](../../docs/api/README.md).
