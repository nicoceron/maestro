#!/usr/bin/env bash

set -euo pipefail

repo_root=$(git rev-parse --show-toplevel)
contracts_dir="$repo_root/packages/contracts"

if [[ ! -f "$contracts_dir/package.json" || ! -f "$contracts_dir/pnpm-lock.yaml" || ! -f "$contracts_dir/openapi.yaml" ]]; then
  echo "Expected OpenAPI contract manifests in $contracts_dir" >&2
  exit 1
fi

pnpm --dir "$contracts_dir" audit --audit-level high
pnpm --dir "$contracts_dir" check
