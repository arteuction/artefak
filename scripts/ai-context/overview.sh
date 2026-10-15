#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/common.sh"

ai_pack "overview" 22000 \
  'AGENTS.md,CLAUDE.md,composer.json,package.json,phpunit.xml,phpunit.xml.dist,docs/ai/**/*.md,docs/architecture/**/*.md,docs/operations/**/*.md,.github/workflows/*.yml,.github/workflows/*.yaml,routes/api.php,routes/web.php'
