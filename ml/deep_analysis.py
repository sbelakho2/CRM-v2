#!/usr/bin/env python3
"""
Deep analysis of model weaknesses + actionable improvements.
Reads the spot-check report and harvested data to identify:
1. Systematic biases by category/price-tier/supplier
2. Which text features actually differentiate prices within a category
3. Specific part families where the model struggles
4. Data distribution gaps between synthetic and real data
"""

import json
import math
import numpy as np
from collections import defaultdict
from pathlib import Path

SCRIPT_DIR = Path(__file__).parent
REPORT_PATH = SCRIPT_DIR / "harvested_data" / "spotcheck_report.json"
HARVEST_PATH = SCRIPT_DIR / "harvested_data" / "harvested_prices.json"

def load_data():
    with open(REPORT_PATH) as f:
        report = json.load(f)
    with open(HARVEST_PATH) as f:
        harvested = json.load(f)
    return report, harvested

def analyze_errors(report):
    """Deep dive into prediction errors."""
    results = report["results"]
    
    print("=" * 70)
    print("DEEP ERROR ANALYSIS")
    print("=" * 70)
    
    # 1. Bias by price tier (more granular)
    print("\n1. DETAILED PRICE-TIER ANALYSIS")
    tiers = [
        (0, 0.005, "< $0.005 (ultra-cheap)"),
        (0.005, 0.01, "$0.005-$0.01"),
        (0.01, 0.05, "$0.01-$0.05"),
        (0.05, 0.10, "$0.05-$0.10"),
        (0.10, 0.50, "$0.10-$0.50"),
        (0.50, 1.00, "$0.50-$1.00"),
        (1.00, 5.00, "$1.00-$5.00"),
        (5.00, 20.00, "$5.00-$20.00"),
        (20.00, 100.00, "$20.00-$100.00"),
    ]
    for lo, hi, label in tiers:
        tier_results = [r for r in results if lo <= r["adjusted_real"] < hi]
        if not tier_results:
            continue
        errors = [r["pct_error"] for r in tier_results]
        biases = [r["log_ratio"] for r in tier_results]
        mdape = float(np.median(errors))
        mean_bias = float(np.mean(biases))
        n = len(tier_results)
        direction = "OVER" if mean_bias > 0.1 else ("UNDER" if mean_bias < -0.1 else "OK")
        print(f"  {label:25s}  n={n:3d}  MdAPE={mdape:5.1f}%  bias={mean_bias:+.2f} ({direction})")
    
    # 2. Error patterns by category × supplier
    print("\n2. CATEGORY × SUPPLIER ERROR MATRIX")
    cat_supplier = defaultdict(list)
    for r in results:
        cat_supplier[(r["category"], r["supplier"])].append(r["pct_error"])
    
    # Show worst category-supplier combos
    worst = sorted(cat_supplier.items(), key=lambda x: np.median(x[1]), reverse=True)[:15]
    print(f"  {'Category':25s} {'Supplier':15s} {'MdAPE':>7s} {'n':>3s}")
    print(f"  {'─'*25} {'─'*15} {'─'*7} {'─'*3}")
    for (cat, sup), errors in worst:
        print(f"  {cat:25s} {sup:15s} {np.median(errors):6.1f}% {len(errors):3d}")
    
    # 3. Individual part analysis - which parts are hardest
    print("\n3. HARDEST INDIVIDUAL PARTS (worst median error across all conditions)")
    part_errors = defaultdict(list)
    part_info = {}
    for r in results:
        part_errors[r["mpn"]].append(r["pct_error"])
        if r["supplier"] == "digikey":
            part_info[r["mpn"]] = (r["category"], r["real_price"])
    
    sorted_parts = sorted(part_errors.items(), key=lambda x: np.median(x[1]), reverse=True)
    print(f"  {'MPN':30s} {'Category':20s} {'Real$':>8s} {'MdAPE':>7s} {'MaxErr':>7s}")
    print(f"  {'─'*30} {'─'*20} {'─'*8} {'─'*7} {'─'*7}")
    for mpn, errors in sorted_parts[:20]:
        cat, real = part_info.get(mpn, ("?", 0))
        print(f"  {mpn:30s} {cat:20s} ${real:7.4f} {np.median(errors):6.1f}% {max(errors):6.1f}%")
    
    # 4. Parts where model is excellent
    print("\n4. BEST PREDICTED PARTS (lowest median error)")
    for mpn, errors in sorted_parts[-10:]:
        cat, real = part_info.get(mpn, ("?", 0))
        print(f"  {mpn:30s} {cat:20s} ${real:7.4f} {np.median(errors):6.1f}%")


def analyze_data_gaps(harvested):
    """Compare real price distributions to synthetic data assumptions."""
    print("\n" + "=" * 70)
    print("DATA DISTRIBUTION GAPS")
    print("=" * 70)
    
    # Real price distributions by category
    cat_prices = defaultdict(list)
    for h in harvested:
        cat = h.get("category", "unknown")
        price = h.get("unit_price_usd")
        if price and price > 0:
            cat_prices[cat].append(price)
    
    # Synthetic medians from training code
    SYNTHETIC_MEDIANS = {
        'capacitor': 0.02, 'resistor': 0.008, 'inductor': 0.08,
        'ic_microcontroller': 3.50, 'ic_memory': 2.00, 'ic_analog': 1.20,
        'ic_power': 1.50, 'ic_logic': 0.25, 'connector': 0.40,
        'switch': 0.30, 'relay': 2.00, 'transformer': 4.00,
        'crystal_oscillator': 0.50, 'led': 0.08, 'diode': 0.04,
        'transistor': 0.04, 'mosfet': 1.20, 'igbt': 5.00,
        'sensor': 2.50,
    }
    
    print(f"\n  {'Category':25s} {'Real Med':>9s} {'Synth Med':>10s} {'Ratio':>7s} {'Real Range':>20s} {'n':>3s}")
    print(f"  {'─'*25} {'─'*9} {'─'*10} {'─'*7} {'─'*20} {'─'*3}")
    
    for cat in sorted(cat_prices.keys()):
        prices = cat_prices[cat]
        real_med = np.median(prices)
        synth_med = SYNTHETIC_MEDIANS.get(cat, 0)
        ratio = real_med / synth_med if synth_med > 0 else float('inf')
        lo, hi = min(prices), max(prices)
        n = len(prices)
        flag = " ⚠️" if ratio > 3 or ratio < 0.33 else ""
        print(f"  {cat:25s} ${real_med:8.4f} ${synth_med:9.4f} {ratio:6.2f}× ${lo:.4f}-${hi:.4f} {n:3d}{flag}")


def analyze_text_features(harvested, report):
    """Analyze which text tokens are informative for pricing."""
    print("\n" + "=" * 70)
    print("TEXT FEATURE ANALYSIS")
    print("=" * 70)
    
    # For each category, compare descriptions of cheap vs expensive parts
    cat_parts = defaultdict(list)
    for h in harvested:
        cat = h.get("category", "unknown")
        price = h.get("unit_price_usd", 0)
        desc = h.get("description", "")
        mpn = h.get("mpn", "")
        if price > 0:
            cat_parts[cat].append((price, desc, mpn))
    
    print("\n  TEXT DIFFERENTIATION: cheap vs expensive within same category")
    for cat in sorted(cat_parts.keys()):
        parts = sorted(cat_parts[cat], key=lambda x: x[0])
        if len(parts) < 3:
            continue
        cheapest = parts[0]
        most_expensive = parts[-1]
        ratio = most_expensive[0] / cheapest[0] if cheapest[0] > 0 else 0
        print(f"\n  {cat.upper()} (price range: {ratio:.0f}×)")
        print(f"    Cheapest:     ${cheapest[0]:.4f} — {cheapest[2]:30s} '{cheapest[1][:60]}'")
        print(f"    Most expensive: ${most_expensive[0]:.4f} — {most_expensive[2]:30s} '{most_expensive[1][:60]}'")
        
        # What tokens differentiate them?
        import re
        cheap_tokens = set(re.findall(r'[a-z0-9]+', (cheapest[1] + " " + cheapest[2]).lower()))
        expensive_tokens = set(re.findall(r'[a-z0-9]+', (most_expensive[1] + " " + most_expensive[2]).lower()))
        unique_cheap = cheap_tokens - expensive_tokens
        unique_expensive = expensive_tokens - cheap_tokens
        if unique_cheap:
            print(f"    Unique cheap tokens: {', '.join(list(unique_cheap)[:10])}")
        if unique_expensive:
            print(f"    Unique expensive tokens: {', '.join(list(unique_expensive)[:10])}")


def suggest_improvements(report, harvested):
    """Generate specific improvement recommendations."""
    print("\n" + "=" * 70)
    print("IMPROVEMENT RECOMMENDATIONS")
    print("=" * 70)
    
    results = report["results"]
    
    # 1. Categories needing more real data
    print("\n  1. CATEGORIES NEEDING MORE TRAINING DATA:")
    cat_errors = defaultdict(list)
    for r in results:
        cat_errors[r["category"]].append(r["pct_error"])
    
    for cat, errors in sorted(cat_errors.items(), key=lambda x: np.median(x[1]), reverse=True):
        mdape = np.median(errors)
        if mdape > 30:
            print(f"     ⚠️  {cat:25s} MdAPE={mdape:.0f}% — needs 20+ more real MPNs")
        elif mdape > 25:
            print(f"     ⚡  {cat:25s} MdAPE={mdape:.0f}% — needs 10+ more real MPNs")
    
    # 2. Synthetic data calibration issues
    print("\n  2. SYNTHETIC DATA CALIBRATION FIXES:")
    cat_biases = defaultdict(list)
    for r in results:
        cat_biases[r["category"]].append(r["log_ratio"])
    
    for cat, biases in sorted(cat_biases.items(), key=lambda x: abs(np.mean(x[1])), reverse=True):
        mean_bias = np.mean(biases)
        if abs(mean_bias) > 0.2:
            direction = "over-predicting" if mean_bias > 0 else "under-predicting"
            fix = f"reduce median" if mean_bias > 0 else f"increase median"
            factor = math.exp(abs(mean_bias))
            print(f"     Fix: {cat:25s} bias={mean_bias:+.2f} ({direction} by {factor:.1f}×) → {fix} by {factor:.1f}×")
    
    # 3. Architecture improvements
    print("\n  3. ARCHITECTURE IMPROVEMENTS:")
    print("     • Add value-extraction features (parse resistance/capacitance values from desc)")
    print("     • Add package-size as explicit input feature (not just text)")
    print("     • Add voltage/current rating as numeric features")
    print("     • Consider separate prediction heads for cheap (<$0.10) vs expensive parts")
    
    # 4. Data augmentation strategies
    print("\n  4. DATA AUGMENTATION STRATEGIES:")
    print("     • Harvest 100+ more MPNs from DigiKey focusing on weak categories")
    print("     • For resistors: harvest specific value families (0R, 1K, 10K, 100K, 1M)")
    print("     • For connectors: harvest by pin count (2P, 4P, 10P, 20P, 40P, 100P)")
    print("     • For LEDs: harvest by type (indicator, high-power, addressable)")
    print("     • For relays: more diverse samples (signal, power, solid-state)")

    # 5. Additional MPNs to harvest for weak categories
    print("\n  5. SPECIFIC MPNs TO HARVEST NEXT:")
    additional_mpns = {
        "resistor": [
            "RC0402FR-071KL", "RC0402FR-07100KL", "RC0402FR-071ML",
            "CRCW06031K00FKEA", "CRCW0603100KFKEA", "ERJ-3EKF1001V",
            "MFR-25FBF52-100K", "RC0201FR-0710KL", "RC1206FR-0710KL",
        ],
        "connector": [
            "B4B-XH-A(LF)(SN)", "B8B-XH-A(LF)(SN)", "B16B-XH-A(LF)(SN)",
            "0022232081", "0022232161", "640456-2", "640456-8", "640456-16",
        ],
        "led": [
            "LTST-C171GKT", "150060BS75000", "CREE XP-L2",
            "VLMW41R2T1-6K-08", "OSRAM DURIS S5",
        ],
        "relay": [
            "G5V-2-H1-DC5", "G6K-2F-Y-DC5", "HF49FD/005-1H11",
            "SRD-05VDC-SL-C", "TQ2-5V", "OMIH-SS-105LM",
        ],
    }
    for cat, mpns in additional_mpns.items():
        print(f"     {cat}: {', '.join(mpns[:5])}...")


if __name__ == "__main__":
    report, harvested = load_data()
    analyze_errors(report)
    analyze_data_gaps(harvested)
    analyze_text_features(harvested, report)
    suggest_improvements(report, harvested)
