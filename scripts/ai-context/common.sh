#!/usr/bin/env bash
# Shared helpers for ai-context scripts.
# Source this file; do not execute it directly.
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"

ai_pack() {
  local name="$1"
  local budget="${2:-30000}"
  local include="$3"
  local out="/tmp/arteuction-${name}-context.md"

  echo "Generating ${name} context pack → ${out}"
  echo "Token budget: ${budget}"
  echo ""

  npx --yes "repomix@1.18.1" \
    --include "${include}" \
    --ignore "vendor/**,node_modules/**,storage/**,bootstrap/cache/**,public/build/**,*.sql,*.log,.env*,*.env,**/.env*" \
    --compress \
    --token-budget "${budget}" \
    --output "${out}" \
    "${ROOT}"

  echo ""
  echo "Pack written to: ${out}"
  echo "Review the bundle before sharing or pasting into a prompt."
  echo "Verify no secrets were included (repomix secret scan is enabled by default)."
}
