#!/usr/bin/env bash

set -euo pipefail

repo_root=$(git rev-parse --show-toplevel)
api_dir="$repo_root/apps/api"

if [[ ! -f "$api_dir/composer.json" || ! -f "$api_dir/composer.lock" || ! -f "$api_dir/package.json" || ! -f "$api_dir/package-lock.json" ]]; then
  echo "Expected Laravel manifests in $api_dir" >&2
  exit 1
fi

cd "$api_dir"

# Quality checks always boot the test environment, even when a developer keeps
# an ignored local `.env` configured for another environment.
export APP_ENV=testing

# Tests use a deterministic non-production key and never depend on, or expose,
# a developer's ignored local application key.
export APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='

if command -v composer >/dev/null 2>&1; then
  composer validate --strict --no-check-publish
  composer audit --locked --no-interaction
elif command -v docker >/dev/null 2>&1; then
  docker run --rm \
    --volume "$api_dir:/app:ro" \
    --workdir /app \
    composer:2 \
    validate --strict --no-check-publish
  docker run --rm \
    --volume "$api_dir:/app:ro" \
    --workdir /app \
    composer:2 \
    audit --locked --no-interaction
else
  echo "Install Composer or Docker to validate and audit backend dependencies." >&2
  exit 127
fi

if [[ ! -x vendor/bin/pint ]]; then
  echo "Backend dependencies are missing; run composer install in $api_dir" >&2
  exit 1
fi

if [[ ! -d node_modules ]]; then
  echo "Filament JavaScript dependencies are missing; run npm ci in $api_dir" >&2
  exit 1
fi

npm audit --audit-level high
npm run build

vendor/bin/pint --test
php artisan test --colors=always
