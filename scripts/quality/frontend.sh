#!/usr/bin/env bash

set -euo pipefail

repo_root=$(git rev-parse --show-toplevel)
web_dir="$repo_root/apps/web"

if [[ ! -f "$web_dir/package.json" || ! -f "$web_dir/pnpm-lock.yaml" ]]; then
  echo "Expected Next.js manifests in $web_dir" >&2
  exit 1
fi

pnpm --dir "$web_dir" audit --audit-level high
pnpm --dir "$web_dir" lint
pnpm --dir "$web_dir" test:run
pnpm --dir "$web_dir" build
