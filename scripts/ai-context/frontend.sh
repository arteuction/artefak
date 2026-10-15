#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/common.sh"

ai_pack "frontend" 30000 \
  'AGENTS.md,docs/ai/INVARIANTS.md,docs/ai/CURRENT_STATE.md,resources/js/marketplace/**,resources/css/**,resources/js/api/generated/types.gen.ts,package.json,vite.config.*,tsconfig*,tests/e2e/**,playwright.config.*'
