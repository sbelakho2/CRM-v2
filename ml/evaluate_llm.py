#!/usr/bin/env python3
"""
LLM Company Classifier Evaluation Suite
========================================
Tests a local llama.cpp server against the golden dataset v2 (100 entries).
Reports accuracy, precision, recall, F1, confusion matrix, and per-category stats.

Usage:
    python3 ml/evaluate_llm.py [--url http://127.0.0.1:8082] [--dataset config/golden_dataset_v2.yaml]
"""

import argparse
import json
import sys
import time
import yaml
import requests
from pathlib import Path
from dataclasses import dataclass, field
from typing import Optional

# ──────────────────────────────────────────────────────────────
# System prompt — exactly what we'll deploy in LlmService.php
# ──────────────────────────────────────────────────────────────

SYSTEM_PROMPT = """You classify companies for a B2B sales team at Starz Electronics — a contract manufacturer based in Morocco providing:
  - Wire harness / cable assembly
  - EMS (Electronics Manufacturing Services) / PCB assembly
  - Injection molding
  - CNC machining / precision machining / tooling
  - Industrial automation assembly

We seek companies that MANUFACTURE physical products and could potentially BUY these services from us (= our PROSPECTS). Content may be in English, French, Arabic, or German — you MUST handle all four languages equally.

═══ WHAT COUNTS AS A MANUFACTURER (ACCEPT) ═══
A manufacturer is a company that DESIGNS and/or PRODUCES physical products in factories it owns or operates. This includes:
- Automotive OEMs and tier-1/2/3 suppliers (Bosch, Valeo, Continental, ZF)
- Industrial automation and sensor makers (Schneider Electric, Sick AG, Omron, Pepperl+Fuchs, Beckhoff)
- Semiconductor companies with their own fabs (NXP, Infineon, STMicroelectronics, Texas Instruments, Analog Devices, Microchip)
- Test and measurement instrument makers (Rohde & Schwarz, Tektronix, Keysight)
- Defence/aerospace electronics manufacturers (Thales, Safran, Leonardo, Airbus, HENSOLDT)
- Medical device manufacturers (Dräger, Getinge, Mindray, B. Braun)
- Appliance manufacturers (Miele, Arçelik, Groupe SEB)
- Connector/component OEMs that MAKE products (TE Connectivity, Amphenol, Harting, Phoenix Contact, Wago) — when they mention "cable assembly" it's a PRODUCT they sell, not a service
- Any company with its own factory producing tangible goods

IMPORTANT: Semiconductor companies ARE manufacturers — they produce physical chips/ICs in fabs. ACCEPT them.
IMPORTANT: Sensor companies ARE manufacturers — they produce physical sensors. Even though sensors are used in automation, these companies are NOT our competitors. ACCEPT them.
IMPORTANT: Test/measurement companies ARE manufacturers — they produce physical instruments. ACCEPT them.
IMPORTANT: Companies that say "designs and manufactures" or "produces" or "our factory/plant" are manufacturers. ACCEPT them.

═══ REJECT CATEGORIES (26 types) ═══
1. TRADERS/DISTRIBUTORS: Trading companies, importers, distributors, resellers, wholesalers, agents — including electronics distributors (Arrow, Avnet, Mouser, DigiKey, RS Components, Farnell)
2. EMS COMPETITORS: Companies providing wire harness/cable assembly, PCB assembly, contract manufacturing, SMT assembly, box-build — they sell the SAME services Starz sells (e.g. Jabil, Celestica, Flex, Lacroix Electronics, Sanmina, Plexus, GPV)
3. NEWS/MEDIA: News sites, magazines, press agencies, trade publications (EE Times, Electronics Weekly)
4. GOVERNMENT: Ministries, agencies, state enterprises not manufacturing products (FIPA, GAFI, GCT, OCP, STEG)
5. ASSOCIATIONS/NGOs: Trade associations, chambers, federations, IEEE chapters, student organizations
6. JOB PORTALS/RECRUITMENT: Job boards, staffing agencies, recruitment firms (Hays, Adecco, Randstad, ManpowerGroup)
7. DIRECTORIES/PORTALS: Business directories, sourcing platforms, listing sites (Kompass, Europages, Alibaba B2B)
8. EVENTS: Trade shows, exhibitions, conferences (Hannover Messe, Electronica, Embedded World)
9. CONSULTING/ADVISORY: Management, strategy, IT, engineering consulting firms (Deloitte, McKinsey, Accenture, Capgemini, BearingPoint)
10. SYSTEM INTEGRATORS: Companies that build control panels, program PLCs, or resell automation equipment — they provide SERVICES, not products
11. DEALERSHIPS: Car dealerships, spare parts shops, auto repair
12. WRONG INDUSTRY: Food/beverage/FMCG, cosmetics, construction, real estate, banking/insurance, agriculture, telecom operators, energy utilities, airlines/tourism
13. OIL/GAS/MINING: Petroleum companies, mineral extraction, quarrying, cement producers
14. CHEMICALS/RAW MATERIALS: Chemical producers, specialty chemicals, fertilizers, paint/coatings — not manufacturers of electronic/mechanical products
15. LAW FIRMS: Legal practices, attorneys, solicitors
16. LOGISTICS/FREIGHT: Shipping companies, freight forwarders, 3PL providers, customs brokers (DHL, Bolloré, CMA CGM)
17. FINANCIAL: Investment funds, banks, insurance companies, asset management, private equity
18. EQUIPMENT SUPPLIERS: Companies that sell wire processing machines, SMT equipment, test equipment TO manufacturers — they supply the industry but aren't buyers of EMS
19. MRO/OVERHAUL: Aircraft maintenance, engine overhaul, component repair services
20. CERTIFICATION/TESTING BODIES: ISO certification providers, auditing bodies (Bureau Veritas, SGS, TÜV)
21. TRAINING: Training providers, online courses, educational institutions, universities
22. RECYCLING/WASTE: Waste management, recycling services (Veolia)
23. E-COMMERCE: B2B marketplaces, sourcing platforms (Made-in-China.com)
24. FACILITIES MANAGEMENT: Cleaning, security, catering service companies (ISS, Securitas)
25. SOFTWARE-ONLY: Pure SaaS/software companies with NO hardware manufacturing (SAP, Salesforce)
26. MARKET REPORTS: Market research firms selling industry reports (MarketsandMarkets, Mordor Intelligence)

═══ CRITICAL RULES ═══
- If a company says it DESIGNS AND MANUFACTURES products → ACCEPT (they are a manufacturer)
- If a company mentions "our factory", "our plant", "our production facility" → ACCEPT
- Semiconductor = manufacturer. Sensor = manufacturer. Instrument = manufacturer. ACCEPT all.
- "Contract manufacturing" or "manufacturing services" = COMPETITOR → REJECT
- Government-owned chemical/mining (GCT, OCP) = NOT our buyer → REJECT
- Automation/PLC resellers and system integrators = services company → REJECT
- Wire processing machine makers (Komax, Schleuniger) supply OUR industry but are NOT buyers → REJECT
- Connector OEMs mentioning "cable assembly" → it's their PRODUCT, not a service → ACCEPT

SECTOR OVERRIDE — even if a company "manufactures" in some sense, REJECT these sectors because they will NEVER buy EMS/wire harness/CNC services from us:
- Food/beverage/dairy companies (Danone, Nestlé, Coca-Cola) — they make food, not electronics → REJECT
- Chemical/fertilizer producers (Arkema, BASF) — they make chemicals, not electronic products → REJECT
- Mining/quarrying/cement (AMMC, LafargeHolcim) — extract minerals, not electronic products → REJECT
- Oil/gas/petroleum (TotalEnergies, Shell) — energy extraction, not electronics → REJECT
- Government-owned SOEs (Groupe Chimique Tunisien, OCP Group) — even if they "produce" chemicals, they are NOT EMS buyers → REJECT
- Trade/industry associations (ZVEI, SNITEM, GIMELEC) — they REPRESENT manufacturers but are not manufacturers themselves → REJECT

═══ FEW-SHOT EXAMPLES ═══
Example 1: Schneider Electric → ACCEPT (makes circuit breakers, PLCs, switchgear in own factories)
Example 2: Sick AG → ACCEPT (manufactures photoelectric sensors, encoders in own factories)
Example 3: NXP Semiconductors → ACCEPT (semiconductor manufacturer with own fabs producing chips)
Example 4: Jabil → REJECT (provides electronics manufacturing services = our direct competitor)
Example 5: Arrow Electronics → REJECT (electronics component distributor, doesn't manufacture)
Example 6: Accenture → REJECT (IT/management consulting firm, no manufacturing)
Example 7: Komax → REJECT (makes wire processing machines, supplies our industry, not a buyer)
Example 8: Tunisair → REJECT (airline operator, wrong industry entirely)
Example 9: Bureau Veritas → REJECT (certification/testing body, provides ISO audits)
Example 10: Pepperl+Fuchs → ACCEPT (manufactures industrial sensors in own factories)
Example 11: ZVEI → REJECT (German electronics trade ASSOCIATION, not a manufacturer)
Example 12: Groupe Chimique Tunisien → REJECT (government-owned chemical company, not EMS buyer)
Example 13: Danone → REJECT (food/dairy manufacturer — wrong industry, will never buy EMS)
Example 14: AMMC Mining → REJECT (mineral extraction company — wrong industry)

Respond with ONLY a valid JSON object, no other text before or after."""


def build_user_prompt(entry: dict) -> str:
    """Build the user prompt exactly as LlmService.php does."""
    name = entry['name']
    domain = entry['domain']
    snippet = entry['snippet']
    title = entry.get('title', '')
    country = entry.get('country', '')

    location_clause = ""
    if country:
        location_clause = f"\nDoes this company have confirmed manufacturing/operational presence in {country}?"

    return f"""Name: {name}
Domain: {domain}
Snippet: {snippet}
Title: {title}

Classify this company. Consider:
1. Does it MAKE physical products of ANY kind in its own factory?
2. Is it a COMPETITOR (provides wire harness, EMS, CNC machining, injection molding, or automation assembly services)? Competitors = REJECT.
3. Is it a news site, trade show, government portal, association, trader/distributor, system integrator, research centre?
{location_clause}
Respond: {{"verdict": "ACCEPT" or "REJECT", "is_manufacturer": true/false, "has_local_presence": true/false, "reason": "one sentence", "confidence": 0.0-1.0}}"""


# ──────────────────────────────────────────────────────────────
# LLM Client
# ──────────────────────────────────────────────────────────────

def call_llm(url: str, system_prompt: str, user_prompt: str, temperature: float = 0.1, max_tokens: int = 150) -> Optional[dict]:
    """Call llama.cpp /v1/chat/completions endpoint."""
    try:
        resp = requests.post(
            f"{url}/v1/chat/completions",
            json={
                "model": "qwen2.5-7b",
                "messages": [
                    {"role": "system", "content": system_prompt},
                    {"role": "user", "content": user_prompt},
                ],
                "temperature": temperature,
                "max_tokens": max_tokens,
                "stream": False,
            },
            timeout=120,
        )
        resp.raise_for_status()
        data = resp.json()
        content = data.get("choices", [{}])[0].get("message", {}).get("content", "")
        tokens = data.get("usage", {})
        return {"content": content, "tokens": tokens}
    except Exception as e:
        print(f"  ⚠ LLM call failed: {e}")
        return None


def parse_json_response(content: str) -> Optional[dict]:
    """Parse JSON from LLM response, handling markdown wrapping."""
    content = content.strip()
    if content.startswith("```json"):
        content = content[7:]
    elif content.startswith("```"):
        content = content[3:]
    if content.endswith("```"):
        content = content[:-3]
    content = content.strip()

    # Extract JSON object from potential surrounding text
    if not content.startswith("{"):
        import re
        m = re.search(r'\{[^}]+\}', content, re.DOTALL)
        if m:
            content = m.group(0)

    try:
        data = json.loads(content)
        if "verdict" in data:
            return data
    except json.JSONDecodeError:
        pass
    return None


# ──────────────────────────────────────────────────────────────
# Evaluation Engine
# ──────────────────────────────────────────────────────────────

@dataclass
class EvalResult:
    name: str
    domain: str
    expected: str
    actual: str
    correct: bool
    category: str
    confidence: float = 0.0
    reason: str = ""
    is_manufacturer: bool = False
    latency_ms: int = 0
    raw_response: str = ""
    parse_failed: bool = False


def run_evaluation(url: str, dataset_path: str) -> list[EvalResult]:
    """Run the full evaluation suite."""
    with open(dataset_path) as f:
        data = yaml.safe_load(f)

    entries = data.get("entries", [])
    print(f"\n{'='*70}")
    print(f"  LLM Company Classifier Evaluation")
    print(f"  Dataset: {dataset_path} ({len(entries)} entries)")
    print(f"  Server:  {url}")
    print(f"{'='*70}\n")

    # Health check
    try:
        r = requests.get(f"{url}/health", timeout=5)
        health = r.json()
        print(f"  Server health: {health.get('status', 'unknown')}")
    except:
        print("  ⚠ Server health check failed — is llama-server running?")
        sys.exit(1)

    results = []
    for i, entry in enumerate(entries):
        name = entry["name"]
        expected = entry["expected"].upper()

        print(f"  [{i+1:3d}/{len(entries)}] {name:<35s} expected={expected:<6s} ", end="", flush=True)

        user_prompt = build_user_prompt(entry)
        start = time.time()
        llm_result = call_llm(url, SYSTEM_PROMPT, user_prompt)
        elapsed_ms = int((time.time() - start) * 1000)

        if llm_result is None:
            result = EvalResult(
                name=name, domain=entry["domain"], expected=expected,
                actual="ERROR", correct=False, category=entry.get("category", ""),
                latency_ms=elapsed_ms, parse_failed=True,
            )
            print(f"→ ERROR ({elapsed_ms}ms)")
            results.append(result)
            continue

        parsed = parse_json_response(llm_result["content"])
        if parsed is None:
            result = EvalResult(
                name=name, domain=entry["domain"], expected=expected,
                actual="PARSE_FAIL", correct=False, category=entry.get("category", ""),
                latency_ms=elapsed_ms, raw_response=llm_result["content"][:200],
                parse_failed=True,
            )
            print(f"→ PARSE_FAIL ({elapsed_ms}ms)")
            results.append(result)
            continue

        verdict = parsed.get("verdict", "REJECT").upper()
        # Map verdict to PASS/REJECT for comparison
        actual = "PASS" if verdict == "ACCEPT" else "REJECT"
        correct = (actual == expected)

        result = EvalResult(
            name=name, domain=entry["domain"], expected=expected,
            actual=actual, correct=correct, category=entry.get("category", ""),
            confidence=float(parsed.get("confidence", 0)),
            reason=parsed.get("reason", ""),
            is_manufacturer=bool(parsed.get("is_manufacturer", False)),
            latency_ms=elapsed_ms,
            raw_response=llm_result["content"][:200],
        )

        icon = "✓" if correct else "✗"
        print(f"→ {actual:<6s} {icon} conf={result.confidence:.2f} ({elapsed_ms}ms) {result.reason[:50]}")
        results.append(result)

    return results


# ──────────────────────────────────────────────────────────────
# Statistical Report
# ──────────────────────────────────────────────────────────────

def print_report(results: list[EvalResult]):
    """Print comprehensive statistical report."""
    total = len(results)
    errors = [r for r in results if r.parse_failed]
    valid = [r for r in results if not r.parse_failed]

    correct = sum(1 for r in valid if r.correct)
    incorrect = len(valid) - correct

    # Confusion matrix (relative to PASS = positive class)
    tp = sum(1 for r in valid if r.expected == "PASS" and r.actual == "PASS")
    fp = sum(1 for r in valid if r.expected == "REJECT" and r.actual == "PASS")
    tn = sum(1 for r in valid if r.expected == "REJECT" and r.actual == "REJECT")
    fn = sum(1 for r in valid if r.expected == "PASS" and r.actual == "REJECT")

    accuracy = correct / len(valid) if valid else 0
    precision = tp / (tp + fp) if (tp + fp) > 0 else 0
    recall = tp / (tp + fn) if (tp + fn) > 0 else 0
    f1 = 2 * precision * recall / (precision + recall) if (precision + recall) > 0 else 0

    # 95% CI for accuracy (Wilson score interval)
    import math
    n = len(valid)
    if n > 0:
        z = 1.96
        p_hat = accuracy
        ci_low = (p_hat + z*z/(2*n) - z * math.sqrt(p_hat*(1-p_hat)/n + z*z/(4*n*n))) / (1 + z*z/n)
        ci_high = (p_hat + z*z/(2*n) + z * math.sqrt(p_hat*(1-p_hat)/n + z*z/(4*n*n))) / (1 + z*z/n)
    else:
        ci_low = ci_high = 0

    avg_latency = sum(r.latency_ms for r in valid) / len(valid) if valid else 0
    avg_confidence = sum(r.confidence for r in valid) / len(valid) if valid else 0

    print(f"\n{'='*70}")
    print(f"  EVALUATION REPORT")
    print(f"{'='*70}")
    print(f"\n  Total entries:     {total}")
    print(f"  Parse errors:      {len(errors)}")
    print(f"  Valid results:     {len(valid)}")
    print(f"\n  ┌──────────────────────────────────────┐")
    print(f"  │  ACCURACY:   {accuracy*100:6.1f}%  ({correct}/{len(valid)})     │")
    print(f"  │  95% CI:     [{ci_low*100:.1f}% — {ci_high*100:.1f}%]       │")
    print(f"  │  PRECISION:  {precision*100:6.1f}%                  │")
    print(f"  │  RECALL:     {recall*100:6.1f}%                  │")
    print(f"  │  F1 SCORE:   {f1*100:6.1f}%                  │")
    print(f"  └──────────────────────────────────────┘")

    print(f"\n  Confusion Matrix (PASS = positive class):")
    print(f"  ┌──────────────┬────────────┬────────────┐")
    print(f"  │              │ Pred PASS  │ Pred REJECT│")
    print(f"  ├──────────────┼────────────┼────────────┤")
    print(f"  │ Actual PASS  │  TP = {tp:3d}  │  FN = {fn:3d}  │")
    print(f"  │ Actual REJECT│  FP = {fp:3d}  │  TN = {tn:3d}  │")
    print(f"  └──────────────┴────────────┴────────────┘")

    print(f"\n  Avg latency:    {avg_latency:.0f} ms")
    print(f"  Avg confidence: {avg_confidence:.2f}")

    # Per-category breakdown
    print(f"\n  Per-Category Results:")
    print(f"  {'Category':<50s} {'Exp':>6s} {'Act':>6s} {'OK?':>4s} {'Conf':>5s}")
    print(f"  {'-'*73}")

    # Group errors by category
    categories = {}
    for r in valid:
        cat = r.category or "uncategorized"
        if cat not in categories:
            categories[cat] = {"correct": 0, "total": 0}
        categories[cat]["total"] += 1
        if r.correct:
            categories[cat]["correct"] += 1

    for cat, stats in sorted(categories.items()):
        pct = stats["correct"] / stats["total"] * 100
        icon = "✓" if pct == 100 else "✗"
        print(f"  {icon} {cat:<48s} {stats['correct']}/{stats['total']} ({pct:.0f}%)")

    # List all failures
    failures = [r for r in valid if not r.correct]
    if failures:
        print(f"\n  ✗ FAILURES ({len(failures)}):")
        print(f"  {'─'*70}")
        for r in failures:
            print(f"  Name:     {r.name}")
            print(f"  Domain:   {r.domain}")
            print(f"  Expected: {r.expected} → Got: {r.actual}")
            print(f"  Reason:   {r.reason}")
            print(f"  Category: {r.category}")
            print(f"  Conf:     {r.confidence}")
            print(f"  {'─'*70}")

    if errors:
        print(f"\n  ⚠ PARSE ERRORS ({len(errors)}):")
        for r in errors:
            print(f"    {r.name}: {r.raw_response[:100]}")

    print(f"\n{'='*70}")

    # Return summary dict for programmatic use
    return {
        "accuracy": accuracy,
        "precision": precision,
        "recall": recall,
        "f1": f1,
        "ci_low": ci_low,
        "ci_high": ci_high,
        "tp": tp, "fp": fp, "tn": tn, "fn": fn,
        "errors": len(errors),
        "failures": len(failures),
        "total": total,
    }


# ──────────────────────────────────────────────────────────────
# Main
# ──────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description="Evaluate LLM company classifier")
    parser.add_argument("--url", default="http://127.0.0.1:8082", help="llama-server URL")
    parser.add_argument("--dataset", default="config/golden_dataset_v2.yaml", help="Golden dataset YAML")
    parser.add_argument("--output", default=None, help="Save JSON results to file")
    args = parser.parse_args()

    results = run_evaluation(args.url, args.dataset)
    summary = print_report(results)

    if args.output:
        output_data = {
            "summary": summary,
            "results": [
                {
                    "name": r.name, "domain": r.domain, "expected": r.expected,
                    "actual": r.actual, "correct": r.correct, "category": r.category,
                    "confidence": r.confidence, "reason": r.reason,
                    "is_manufacturer": r.is_manufacturer, "latency_ms": r.latency_ms,
                }
                for r in results
            ]
        }
        with open(args.output, "w") as f:
            json.dump(output_data, f, indent=2, ensure_ascii=False)
        print(f"\n  Results saved to {args.output}")

    # Exit with code 1 if accuracy < 90%
    if summary["accuracy"] < 0.90:
        print("\n  ⚠ FAIL: Accuracy below 90% threshold")
        sys.exit(1)
    else:
        print(f"\n  ✓ PASS: Accuracy {summary['accuracy']*100:.1f}% meets threshold")


if __name__ == "__main__":
    main()
