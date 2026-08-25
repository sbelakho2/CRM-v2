#!/bin/bash
# Discover ~30 companies per major region with SECTOR DIVERSITY.
# Strategy: Run EVERY sector with ONE location per region.
# This ensures we get companies across 8-14 sectors, not just "Automotive".
# Each sector+location combo typically yields 2-5 companies after filtering,
# so 14 sectors × 1 location ≈ 28-70 raw companies per region.
set -e
cd "$(dirname "$0")/.."

get_count() {
    php bin/console doctrine:query:sql "SELECT COUNT(*) as c FROM companies WHERE region='$1'" 2>/dev/null | grep -oP '\d+' | tail -1
}

get_sector_count() {
    php bin/console doctrine:query:sql "SELECT COUNT(DISTINCT sector) as c FROM companies WHERE region='$1'" 2>/dev/null | grep -oP '\d+' | tail -1
}

TARGET=30

# Top sectors — ordered by relevance to EMS/PCBA outsourcing
SECTORS=(
    "Automotive"
    "Aerospace"
    "Industrial"
    "Power Electronics"
    "Renewables"
    "Telecom"
    "Medical"
    "Defense"
    "Rail"
    "HVAC"
    "Consumer Electronics"
    "Marine"
    "Data Center"
    "Energy Storage"
)

discover_region() {
    local REGION="$1"
    shift
    local LOCATIONS=("$@")
    
    echo ""
    echo "=========================================="
    echo "  REGION: $REGION — Target: $TARGET companies across many sectors"
    echo "=========================================="
    
    local CNT
    CNT=$(get_count "$REGION")
    echo "  Starting count: $CNT"
    
    if [ "$CNT" -ge "$TARGET" ]; then
        local SC
        SC=$(get_sector_count "$REGION")
        echo "  Already at $CNT companies ($SC sectors), skipping."
        return
    fi
    
    # Pick the BEST location for each region (main industrial hub)
    local PRIMARY_LOC="${LOCATIONS[0]}"
    local SECONDARY_LOC="${LOCATIONS[1]:-}"
    
    # Phase 1: Run ALL sectors with PRIMARY location
    echo "  Phase 1: All sectors @ $PRIMARY_LOC"
    for SECTOR in "${SECTORS[@]}"; do
        echo "  -> $SECTOR @ $PRIMARY_LOC..."
        timeout 120 php bin/console app:discover-companies --sector="$SECTOR" --location="$PRIMARY_LOC" -n 2>&1 | grep -E "Discovered|saved|Error|Verified" | head -3 || true
        sleep 1
    done
    
    CNT=$(get_count "$REGION")
    local SC
    SC=$(get_sector_count "$REGION")
    echo "  After Phase 1: $CNT companies ($SC sectors)"
    
    # Phase 2: If under target, run key sectors with SECONDARY location
    if [ "$CNT" -lt "$TARGET" ] && [ -n "$SECONDARY_LOC" ]; then
        echo "  Phase 2: Key sectors @ $SECONDARY_LOC (need more)"
        for SECTOR in "Automotive" "Aerospace" "Industrial" "Power Electronics" "Telecom" "Medical"; do
            CNT=$(get_count "$REGION")
            if [ "$CNT" -ge "$TARGET" ]; then
                break
            fi
            echo "  -> $SECTOR @ $SECONDARY_LOC..."
            timeout 120 php bin/console app:discover-companies --sector="$SECTOR" --location="$SECONDARY_LOC" -n 2>&1 | grep -E "Discovered|saved|Error|Verified" | head -3 || true
            sleep 1
        done
    fi
    
    # Phase 3: If STILL under target, try remaining locations
    if [ "$CNT" -lt "$TARGET" ]; then
        for LOC_IDX in $(seq 2 $((${#LOCATIONS[@]} - 1))); do
            local LOC="${LOCATIONS[$LOC_IDX]}"
            CNT=$(get_count "$REGION")
            if [ "$CNT" -ge "$TARGET" ]; then
                break
            fi
            echo "  Phase 3: Key sectors @ $LOC"
            for SECTOR in "Automotive" "Industrial" "Telecom"; do
                CNT=$(get_count "$REGION")
                if [ "$CNT" -ge "$TARGET" ]; then
                    break 2
                fi
                echo "  -> $SECTOR @ $LOC..."
                timeout 120 php bin/console app:discover-companies --sector="$SECTOR" --location="$LOC" -n 2>&1 | grep -E "Discovered|saved|Error|Verified" | head -3 || true
                sleep 1
            done
        done
    fi
    
    local FINAL
    FINAL=$(get_count "$REGION")
    SC=$(get_sector_count "$REGION")
    echo "  DONE $REGION: $FINAL companies across $SC sectors"
}

# ══════════════════════════════════════════════════════════════════════
# Run each region with best locations (primary first, then secondary)
# ══════════════════════════════════════════════════════════════════════
discover_region "GCC" "Dubai UAE" "Abu Dhabi UAE" "Riyadh Saudi Arabia" "Jeddah" "Doha Qatar"
discover_region "US" "Texas" "Michigan Detroit" "New York" "Massachusetts" "North Carolina" "Pennsylvania"
discover_region "EG" "Cairo Egypt" "Suez Egypt" "6th of October City Egypt" "10th of Ramadan City Egypt"
discover_region "EU" "Germany" "France" "Netherlands" "Czech Republic" "Poland" "Romania"
discover_region "MA" "Tanger Automotive City Morocco" "Atlantic Free Zone Kenitra Morocco" "Casablanca Morocco" "Nouaceur Morocco"

echo ""
echo "=========================================="
echo "  FINAL SUMMARY"
echo "=========================================="
php bin/console doctrine:query:sql "SELECT region, COUNT(*) as cnt FROM companies GROUP BY region ORDER BY region" 2>&1
php bin/console doctrine:query:sql "SELECT sector, COUNT(*) as cnt FROM companies GROUP BY sector ORDER BY cnt DESC" 2>&1
php bin/console doctrine:query:sql "SELECT COUNT(*) as total, COUNT(DISTINCT sector) as sectors FROM companies" 2>&1
