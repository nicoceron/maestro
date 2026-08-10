# API conventions

The versioned contract is [`packages/contracts/openapi.yaml`](../../packages/contracts/openapi.yaml). Update the contract and generated declarations in the same change as an API behavior change.

## Authentication

First-party browser clients authenticate through Laravel Sanctum's stateful session cookie. Mutating requests also send the URL-decoded `XSRF-TOKEN` cookie value in the `X-XSRF-TOKEN` header. Browser requests must include credentials. The Laravel application must keep Sanctum stateful middleware, trusted domains, credentialed CORS, session cookie attributes, and the contract aligned in every environment.

## JSON envelopes

- A single resource is `{ "data": { ... } }`.
- Creation responses add a fixed `message` alongside `data`.
- The current studio list is deliberately unpaginated and returns `{ "data": [{ ... }] }`.
- The household list is paginated and returns top-level `data`, `links`, and `meta`. Laravel 13's `meta.links` entries include `url`, `label`, `page`, and `active`.
- Future endpoints must document the envelope their controller actually returns; pagination is not implied globally.

## Errors

Laravel API failures use a JSON problem envelope with a required human-readable `message`. Validation failures use status `422` and add an `errors` object whose keys are input paths and whose values are arrays of messages. Clients should branch on HTTP status and field keys, not localized message text.

## Studio routing

Studio IDs are ULIDs, but the `{studio}` route parameter is the unique studio slug because `Studio::getRouteKeyName()` returns `slug`. Listing includes only the authenticated user's active memberships and is sorted by studio name. Cross-tenant access returns `403`; an unknown slug returns `404`.

## Households

`GET /api/v1/studios/{studio}/households` accepts an optional case-insensitive `q` search across household and member names, `per_page` from 1 through 100 (default 25), and a one-based `page`. Pagination URLs preserve the supplied query string. The collection is sorted by household name.

Households are aggregate roots containing members, people, optional learner profiles, guardian relationships, portal permissions, timestamps, an aggregate version, and caller-specific `edit` and `delete` permissions. Household IDs and all nested entity IDs are ULIDs. A household ID is always resolved through its studio relationship, so a cross-studio ID returns `404`.

The creation payload uses request-local member keys to connect guardians and learners before ULIDs exist. It must contain exactly one primary contact with an email address or phone number. Learners require a student profile, non-learners cannot have one, relationship keys must point to the appropriate member roles, and a guardian/learner pair can appear only once.

Active owners, administrators, office staff, and billing staff can view households. Owners, administrators, and office staff can create or edit; only owners can delete. Teachers cannot access household records. An unrelated or suspended studio membership returns `403` before tenant-scoped household work runs.
