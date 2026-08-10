# Maestro API contracts

The OpenAPI 3.1 document in `openapi.yaml` is the source of truth for the implemented Laravel studio and household JSON APIs. Generated TypeScript declarations live in `generated/schema.d.ts` and can be imported as types from `@maestro/contracts` once this package is added to the workspace.

## Commands

```bash
pnpm install --frozen-lockfile
pnpm lint
pnpm generate
pnpm check
```

- `lint` validates the description with Redocly's strict ruleset.
- `generate` deterministically regenerates immutable, alphabetized TypeScript declarations while preserving optional request fields that have server-side defaults.
- `check` fails when the schema is invalid, generated declarations are stale, or the declarations do not type-check.

`GET /api/v1/studios` uses Laravel's unpaginated resource collection envelope (`{ "data": [...] }`), while the household list uses Laravel 13's full length-aware paginator (`data`, `links`, and `meta`, including each meta link's `page`). API authentication, envelope, error, authorization, and pagination conventions are documented in [`../../docs/api/README.md`](../../docs/api/README.md).
