#!/usr/bin/env bash

set -euo pipefail

repo_root=$(git rev-parse --show-toplevel)

"$repo_root/scripts/quality/secrets.sh"
"$repo_root/scripts/quality/backend.sh"
"$repo_root/scripts/quality/frontend.sh"
"$repo_root/scripts/quality/contracts.sh"
"$repo_root/scripts/quality/identity-smoke.sh"
