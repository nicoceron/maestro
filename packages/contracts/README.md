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

Invitation preview and acceptance are JSON-body POST operations at `/api/v1/invitations/preview` and `/api/v1/invitations/accept`; no bearer token appears in an API path. Invite and reset links deliver credentials in URL fragments, which the browser scrubs before submitting the credential in a CSRF-protected JSON body.

`GET /api/v1/studios` uses Laravel's unpaginated resource collection envelope (`{ "data": [...] }`), while invitation and household lists use Laravel 13's full length-aware paginator (`data`, `links`, and `meta`, including each meta link's `page`). API authentication, envelope, error, authorization, and pagination conventions are documented in [`../../docs/api/README.md`](../../docs/api/README.md).
