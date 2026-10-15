#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/common.sh"

ai_pack "payments" 32000 \
  'AGENTS.md,docs/ai/INVARIANTS.md,docs/ai/CURRENT_STATE.md,app/Domain/Settlement/**,app/Domain/Payment/**,app/Domain/SellNow/**,app/Domain/Auction/SettleAuction.php,app/Domain/Auction/PlaceBid.php,app/Domain/Auction/PlaceMaxBid.php,app/Application/Settlement/**,app/Jobs/Dispatch*.php,app/Jobs/HandleStripeWebhook.php,app/Http/Controllers/StripeWebhookController.php,app/Http/Controllers/Api/V1/**Bid*.php,app/Http/Controllers/Api/V1/**Checkout*.php,app/Http/Controllers/Api/V1/**Settlement*.php,app/Models/Settlement*.php,app/Models/Ledger*.php,app/Models/Transfer*.php,app/Models/Refund*.php,app/Models/Webhook*.php,database/migrations/*settlement*,database/migrations/*ledger*,database/migrations/*transfer*,database/migrations/*refund*,database/migrations/*webhook*,tests/Feature/Settlement/**,tests/Feature/Payment/**,tests/Feature/Webhook/**,tests/Feature/Jobs/**,tests/Unit/Settlement/**'
