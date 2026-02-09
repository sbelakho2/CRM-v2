#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════
# Discovery Script — Runs all 14 sectors × 5 regions
#
# Uses the Symfony console command `app:discover-companies` which now
# has a 3-layer filtering pipeline:
#   1. isJunkCompanyName()        — blocklist-based name filter
#   2. isCompetitorOrWrongType()  — snippet semantic analysis
#   3. isLikelyEMSBuyer()        — positive signal scoring
#   4. CompanyClassifierService   — Gemini-trained knowledge base (local)
#
# Plus contact name validation via isLikelyPersonName() to reject
# company names being saved as person contacts.
#
# Usage:
#   ./scripts/discover_all.sh           # All regions
#   ./scripts/discover_all.sh GCC       # Single region
#   ./scripts/discover_all.sh GCC US    # Multiple regions
# ═══════════════════════════════════════════════════════════════════════

set -euo pipefail
cd "$(dirname "$0")/.."

REGIONS=("GCC" "US" "EG" "EU" "MA")

# If specific regions are passed as args, use those instead
if [ $# -gt 0 ]; then
    REGIONS=("$@")
fi

echo "═══════════════════════════════════════════════"
echo "  Starz Electronics — Company Discovery"
echo "  Regions: ${REGIONS[*]}"
echo "  Date: $(date '+%Y-%m-%d %H:%M')"
echo "═══════════════════════════════════════════════"
echo ""

# Show current DB state
echo "📊 Current DB state:"
php bin/console doctrine:query:sql "SELECT COUNT(*) as total_companies FROM companies" 2>/dev/null || true
php bin/console doctrine:query:sql "SELECT region, COUNT(*) as count FROM companies GROUP BY region ORDER BY count DESC" 2>/dev/null || true
echo ""

TOTAL_SAVED=0
TOTAL_TIME=0

for REGION in "${REGIONS[@]}"; do
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "🌍 Region: $REGION"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    
    START=$(date +%s)
    
    php bin/console app:discover-companies --all --region="$REGION" -n -v 2>&1 | \
        tee /tmp/discovery_${REGION}.log | \
        grep -E "(New company|Skipping|REJECT|discovered|saved|ERROR|WARNING|Total)" || true
    
    END=$(date +%s)
    ELAPSED=$((END - START))
    TOTAL_TIME=$((TOTAL_TIME + ELAPSED))
    
    echo ""
    echo "⏱  Region $REGION completed in ${ELAPSED}s"
    echo ""
    
    # Brief pause between regions to respect API rate limits
    if [ "$REGION" != "${REGIONS[-1]}" ]; then
        echo "⏳ Pausing 5s between regions..."
        sleep 5
    fi
done

echo ""
echo "═══════════════════════════════════════════════"
echo "  Discovery Complete"
echo "  Total time: ${TOTAL_TIME}s"
echo "═══════════════════════════════════════════════"

# Final DB state
echo ""
echo "📊 Final DB state:"
php bin/console doctrine:query:sql "SELECT COUNT(*) as total_companies FROM companies" 2>/dev/null || true
php bin/console doctrine:query:sql "SELECT region, COUNT(*) as count FROM companies GROUP BY region ORDER BY count DESC" 2>/dev/null || true
php bin/console doctrine:query:sql "SELECT COUNT(*) as total_contacts FROM contacts" 2>/dev/null || true

echo ""
echo "🔍 Logs saved to /tmp/discovery_*.log"
echo "Done!"
