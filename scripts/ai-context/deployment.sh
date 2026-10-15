#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/common.sh"

ai_pack "deployment" 20000 \
  'AGENTS.md,docs/ai/CURRENT_STATE.md,docs/ai/RELEASE_GATES.md,docs/operations/**,.github/workflows/**,config/queue.php,config/broadcasting.php,config/services.php,composer.json,package.json'
