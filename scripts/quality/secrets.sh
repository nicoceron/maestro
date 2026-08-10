#!/usr/bin/env bash

set -euo pipefail

repo_root=$(git rev-parse --show-toplevel)
scan_tmp=$(mktemp -d)
scan_root="$scan_tmp/working-tree"

# Invoked indirectly by the EXIT trap below.
# shellcheck disable=SC2329
cleanup() {
  rm -rf -- "$scan_tmp"
}
trap cleanup EXIT

mkdir -p "$scan_root"
cd "$repo_root"

# Scan only staged, unstaged, and untracked product files. This intentionally
# avoids the known secret-bearing historical base commit and the legacy scrape
# corpus while still blocking new leaks in code, documentation, and automation.
{
  git diff --cached --name-only --diff-filter=ACMR -z
  git diff --name-only --diff-filter=ACMR -z
  git ls-files --others --exclude-standard -z
} | while IFS= read -r -d '' path; do
  case "$path" in
    .github/* | apps/* | packages/* | docs/* | scripts/* | README.md | compose.yaml | .gitignore)
      ;;
    *)
      continue
      ;;
  esac

  if [[ -f "$path" && ! -L "$path" ]]; then
    mkdir -p "$scan_root/$(dirname "$path")"
    cp -- "$path" "$scan_root/$path"
  fi
done

if [[ -z $(find "$scan_root" -type f -print -quit) ]]; then
  echo "No staged, unstaged, or untracked product files to scan."
  exit 0
fi

if command -v gitleaks >/dev/null 2>&1; then
  gitleaks dir "$scan_root" --redact --no-banner
  exit 0
fi

if command -v docker >/dev/null 2>&1; then
  docker run --rm \
    --volume "$scan_root:/work:ro" \
    ghcr.io/gitleaks/gitleaks:v8.30.0 \
    dir /work --redact --no-banner
  exit 0
fi

echo "Install Gitleaks or Docker to run the working-tree secret scan." >&2
exit 127
