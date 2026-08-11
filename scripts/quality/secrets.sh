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

# Build a clean scan tree from every tracked and untracked product file so
# ignored dependencies and local runtime data never enter the directory scan.
{
  git ls-files -z
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
  echo "No product files to scan."
  exit 0
fi

if command -v gitleaks >/dev/null 2>&1; then
  gitleaks git "$repo_root" --redact --no-banner
  gitleaks dir "$scan_root" --redact --no-banner
  exit 0
fi

if command -v docker >/dev/null 2>&1; then
  docker run --rm \
    --volume "$repo_root:/repo:ro" \
    ghcr.io/gitleaks/gitleaks:v8.30.0 \
    git /repo --redact --no-banner
  docker run --rm \
    --volume "$scan_root:/work:ro" \
    ghcr.io/gitleaks/gitleaks:v8.30.0 \
    dir /work --redact --no-banner
  exit 0
fi

echo "Install Gitleaks or Docker to run the repository secret scan." >&2
exit 127
