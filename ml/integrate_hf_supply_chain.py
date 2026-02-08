#!/usr/bin/env python3
"""
Integrate HuggingFace `mdnh/electronic-components-supply-chain` dataset
into our harvested training data pipeline.

This dataset has 791 components with real multi-supplier pricing from Nexar/Octopart.
We extract DigiKey/Mouser/Arrow prices and map categories to our taxonomy.
"""

import json
import re
from collections import Counter, defaultdict
from pathlib import Path
from typing import Dict, List, Optional

import numpy as np

SCRIPT_DIR = Path(__file__).parent
HARVEST_DIR = SCRIPT_DIR / "harvested_data"

# ── Category mapping from Nexar/Octopart categories to our 25-category taxonomy ──
CATEGORY_MAP = {
    # Direct / obvious
    "capacitors": "capacitor",
    "ceramic capacitors": "capacitor",
    "aluminum capacitors": "capacitor",
    "tantalum capacitors": "capacitor",
    "film capacitors": "capacitor",
    "resistors": "resistor",
    "chip resistors": "resistor",
    "thin film resistors": "resistor",
    "thick film resistors": "resistor",
    "current sense resistors": "resistor",
    "resistor networks": "resistor",
    "inductors": "inductor",
    "power inductors": "inductor",
    "ferrite beads": "inductor",
    "leds": "led",
    "led indication": "led",
    "led lighting": "led",
    "standard leds": "led",
    "diodes": "diode",
    "schottky diodes": "diode",
    "zener diodes": "diode",
    "rectifiers": "diode",
    "tvs diodes": "diode",
    "esd protection diodes": "diode",
    "transistors": "transistor",
    "bipolar transistors": "transistor",
    "jfet transistors": "transistor",
    "mosfets": "mosfet",
    "power mosfets": "mosfet",
    "igbts": "igbt",
    "connectors": "connector",
    "board to board connectors": "connector",
    "d-sub connectors": "connector",
    "headers": "connector",
    "terminal blocks": "connector",
    "usb connectors": "connector",
    "switches": "switch",
    "pushbutton switches": "switch",
    "toggle switches": "switch",
    "tactile switches": "switch",
    "relays": "relay",
    "signal relays": "relay",
    "power relays": "relay",
    "crystals": "crystal_oscillator",
    "crystal oscillators": "crystal_oscillator",
    "oscillators": "crystal_oscillator",
    "sensors": "sensor",
    "temperature sensors": "sensor",
    "pressure sensors": "sensor",
    "humidity sensors": "sensor",
    "accelerometers": "sensor",
    "gyroscopes": "sensor",
    "inertial measurement units": "sensor",
    "current sensors": "sensor",
    "magnetic sensors": "sensor",
    "hall effect sensors": "sensor",
    "transformers": "transformer",
    "gate drivers": "transformer",
    # ICs — by keyword matching
    "microcontrollers": "ic_microcontroller",
    "microprocessors": "ic_microcontroller",
    "fpgas": "ic_logic",
    "cplds": "ic_logic",
    "logic ics": "ic_logic",
    "logic gates": "ic_logic",
    "flip flops": "ic_logic",
    "counters": "ic_logic",
    "shift registers": "ic_logic",
    "buffers": "ic_logic",
    "voltage regulators": "ic_power",
    "dc-dc converters": "ic_power",
    "ldo regulators": "ic_power",
    "switching regulators": "ic_power",
    "pmic": "ic_power",
    "power management ics": "ic_power",
    "battery management": "ic_power",
    "memory": "ic_memory",
    "flash memory": "ic_memory",
    "sram": "ic_memory",
    "dram": "ic_memory",
    "eeprom": "ic_memory",
    "operational amplifiers": "ic_analog",
    "op amps": "ic_analog",
    "comparators": "ic_analog",
    "adc": "ic_analog",
    "dac": "ic_analog",
    "analog to digital converters": "ic_analog",
    "digital to analog converters": "ic_analog",
    "interface ics": "ic_analog",
    "data converters": "ic_analog",
    "amplifiers": "ic_analog",
    "rf receivers, transceivers": "ic_analog",
    "rf transceivers": "ic_analog",
    "wireless ics": "ic_analog",
    "embedded processors": "ic_microcontroller",
    "digital signal processors": "ic_microcontroller",
    "system on chip": "ic_microcontroller",
    # Additional mappings from unmapped categories
    "nfc / rfid components": "ic_analog",
    "rf transceiver modules and modems": "ic_analog",
    "integrated circuits (ics)": "ic_analog",
    "motor drivers": "ic_power",
    "industrial control": "ic_power",
    "linear ics": "ic_analog",
    "voltage references": "ic_analog",
    "rf semiconductors and devices": "ic_analog",
    "touch screen controllers": "ic_analog",
    "clock generators, plls, frequency synthesizers": "crystal_oscillator",
    "multivibrators": "ic_logic",
    "power factor correction (pfc) controllers": "ic_power",
    "power over ethernet (poe) controllers": "ic_power",
}


def map_category(nexar_cat: str, description: str, manufacturer: str) -> Optional[str]:
    """Map Nexar category + description to our taxonomy."""
    if not nexar_cat:
        return None
    
    cat_lower = nexar_cat.lower().strip()
    
    # Direct lookup
    if cat_lower in CATEGORY_MAP:
        return CATEGORY_MAP[cat_lower]
    
    # Substring matching
    for key, val in CATEGORY_MAP.items():
        if key in cat_lower or cat_lower in key:
            return val
    
    # Keyword-based fallback using description
    desc_lower = (description or "").lower()
    
    keyword_map = [
        (["capacitor", "mlcc", "ceramic cap", "electrolytic"], "capacitor"),
        (["resistor", "ohm", "smd res"], "resistor"),
        (["inductor", "choke", "ferrite"], "inductor"),
        (["led", "light emitting"], "led"),
        (["diode", "rectifier", "schottky", "zener", "tvs"], "diode"),
        (["transistor", "bjt", "npn", "pnp"], "transistor"),
        (["mosfet", "n-channel", "p-channel"], "mosfet"),
        (["igbt"], "igbt"),
        (["connector", "header", "socket", "jack", "plug", "terminal block"], "connector"),
        (["switch", "pushbutton", "toggle", "tactile"], "switch"),
        (["relay"], "relay"),
        (["crystal", "oscillator", "xtal"], "crystal_oscillator"),
        (["sensor", "accelerometer", "gyro", "temp sensor", "humidity"], "sensor"),
        (["transformer"], "transformer"),
        (["microcontroller", "mcu", "arm cortex", "stm32", "esp32", "esp8266", "pic", "avr", "atmega", "attiny"], "ic_microcontroller"),
        (["fpga", "cpld", "logic gate", "flip flop", "buffer", "decoder"], "ic_logic"),
        (["voltage regulator", "ldo", "dc-dc", "buck", "boost", "pmic", "power management"], "ic_power"),
        (["flash", "eeprom", "sram", "dram", "memory"], "ic_memory"),
        (["op amp", "operational amp", "comparator", "adc", "dac", "analog"], "ic_analog"),
    ]
    
    for keywords, category in keyword_map:
        for kw in keywords:
            if kw in desc_lower or kw in cat_lower:
                return category
    
    return None


def map_supplier_name(seller_name: str) -> Optional[str]:
    """Map seller name to our supplier taxonomy."""
    name_lower = seller_name.lower().strip()
    
    if "digikey" in name_lower or "digi-key" in name_lower:
        return "digikey"
    if "mouser" in name_lower:
        return "mouser"
    if "arrow" in name_lower:
        return "arrow"
    if "avnet" in name_lower or "farnell" in name_lower or "element14" in name_lower:
        return "avnet"
    if "newark" in name_lower:
        return "newark"
    if "alibaba" in name_lower:
        return "alibaba_gold"
    
    return None  # skip unknown suppliers


def extract_prices(suppliers_list: list) -> Dict[str, float]:
    """Extract best price per known supplier."""
    prices = {}
    if not suppliers_list:
        return prices
    for supplier in suppliers_list:
        sup_name = map_supplier_name(supplier.get("name", ""))
        if not sup_name:
            continue
        price = supplier.get("price")
        if price and price > 0 and price < 1000:  # sanity check
            if sup_name not in prices or price < prices[sup_name]:
                prices[sup_name] = price
    return prices


def load_hf_dataset():
    """Load and process the HuggingFace supply chain dataset."""
    from datasets import load_dataset
    
    ds = load_dataset("mdnh/electronic-components-supply-chain", split="train")
    print(f"Loaded {len(ds)} components from HuggingFace")
    
    processed = []
    category_counts = Counter()
    unmapped = Counter()
    
    for row in ds:
        mpn = row["mpn"]
        manufacturer = row["manufacturer"]
        description = row["description"] or ""
        nexar_cat = row["category"]
        suppliers = row["suppliers"]
        
        # Map category
        our_cat = map_category(nexar_cat, description, manufacturer)
        if not our_cat:
            unmapped[nexar_cat] += 1
            continue
        
        # Extract prices from known suppliers
        prices = extract_prices(suppliers)
        if not prices:
            continue
        
        # Use DigiKey price as primary, or any available
        primary_price = prices.get("digikey") or prices.get("mouser") or list(prices.values())[0]
        
        # Price sanity filter — exclude eval boards, kits, and obvious outliers
        MAX_PRICE_BY_CAT = {
            "capacitor": 5.0, "resistor": 2.0, "inductor": 10.0,
            "led": 5.0, "diode": 5.0, "transistor": 5.0,
            "mosfet": 20.0, "igbt": 30.0, "connector": 15.0,
            "switch": 10.0, "relay": 20.0, "crystal_oscillator": 10.0,
            "sensor": 30.0, "transformer": 20.0,
            "ic_microcontroller": 50.0, "ic_memory": 30.0,
            "ic_analog": 50.0, "ic_power": 30.0, "ic_logic": 15.0,
        }
        max_price = MAX_PRICE_BY_CAT.get(our_cat, 50.0)
        if primary_price > max_price:
            continue  # likely an eval board/module, skip
        
        component = {
            "mpn": mpn,
            "manufacturer": manufacturer,
            "category": our_cat,
            "description": description,
            "unit_price_usd": primary_price,
            "source": "hf_supply_chain",
            "supplier_prices": prices,
        }
        
        processed.append(component)
        category_counts[our_cat] += 1
    
    print(f"\nMapped {len(processed)}/{len(ds)} components")
    print(f"\nCategory distribution:")
    for cat, count in sorted(category_counts.items(), key=lambda x: -x[1]):
        print(f"  {cat:25s}: {count}")
    
    if unmapped:
        print(f"\nUnmapped categories (top 20):")
        for cat, count in unmapped.most_common(20):
            print(f"  {cat:40s}: {count}")
    
    return processed


def generate_training_samples(components: List[Dict]) -> List[Dict]:
    """Generate training samples from processed components."""
    samples = []
    
    supplier_configs = {
        "digikey":   {"reliability": 0.98, "lead_time": 5,  "moq": 1},
        "mouser":    {"reliability": 0.97, "lead_time": 5,  "moq": 1},
        "arrow":     {"reliability": 0.95, "lead_time": 10, "moq": 25},
        "avnet":     {"reliability": 0.95, "lead_time": 8,  "moq": 10},
        "newark":    {"reliability": 0.93, "lead_time": 7,  "moq": 1},
    }
    
    for comp in components:
        mpn = comp["mpn"]
        manufacturer = comp["manufacturer"]
        category = comp["category"]
        description = comp["description"]
        base_price = comp["unit_price_usd"]
        
        # Generate samples for each supplier with known prices
        for supplier, price in comp["supplier_prices"].items():
            if supplier not in supplier_configs:
                continue
            cfg = supplier_configs[supplier]
            
            # Generate at multiple quantities
            for qty in [1, 10, 100, 1000, 5000]:
                # Apply quantity discount
                if qty <= 1:
                    qty_price = price
                elif qty <= 10:
                    qty_price = price * 0.95
                elif qty <= 100:
                    qty_price = price * 0.85
                elif qty <= 1000:
                    qty_price = price * 0.70
                else:
                    qty_price = price * 0.55
                
                samples.append({
                    "mpn": mpn,
                    "manufacturer": manufacturer,
                    "category": category,
                    "description": description,
                    "supplier": supplier,
                    "quantity": qty,
                    "moq": cfg["moq"],
                    "lead_time_days": cfg["lead_time"],
                    "reliability_score": cfg["reliability"],
                    "complexity_score": 1.0,
                    "industry": "ems_contract",
                    "unit_price_usd": qty_price,
                    "is_actual_price_break": qty == 1,  # Only qty=1 is actual
                    "source": "hf_supply_chain",
                })
        
        # Also add actual price breaks from the primary (DigiKey) price
        samples.append({
            "mpn": mpn,
            "manufacturer": manufacturer,
            "category": category,
            "description": description,
            "supplier": "digikey",
            "quantity": 1,
            "moq": 1,
            "lead_time_days": 5,
            "reliability_score": 0.98,
            "complexity_score": 1.0,
            "industry": "ems_contract",
            "unit_price_usd": base_price,
            "is_actual_price_break": True,
            "source": "hf_supply_chain",
        })
    
    return samples


def merge_with_existing(new_samples: List[Dict], existing_path: Path) -> List[Dict]:
    """Merge new samples with existing training data, deduplicating by MPN."""
    existing = []
    if existing_path.exists():
        with open(existing_path) as f:
            existing = json.load(f)
        print(f"Loaded {len(existing)} existing training samples")
    
    # Get existing MPNs
    existing_mpns = set()
    for s in existing:
        existing_mpns.add(s.get("mpn", ""))
    
    # Filter new samples to exclude already-existing MPNs
    new_unique = [s for s in new_samples if s["mpn"] not in existing_mpns]
    added_mpns = set(s["mpn"] for s in new_unique)
    
    print(f"New unique MPNs: {len(added_mpns)} (skipped {len(set(s['mpn'] for s in new_samples)) - len(added_mpns)} duplicates)")
    
    merged = existing + new_unique
    print(f"Total training samples: {len(merged)}")
    
    return merged


def main():
    print("=" * 70)
    print("INTEGRATING HF SUPPLY CHAIN DATASET")
    print("=" * 70)
    
    # Load and process
    components = load_hf_dataset()
    
    # Generate training samples
    samples = generate_training_samples(components)
    print(f"\nGenerated {len(samples)} training samples from {len(components)} components")
    
    # Merge with existing
    existing_path = HARVEST_DIR / "api_training_data.json"
    merged = merge_with_existing(samples, existing_path)
    
    # Save
    HARVEST_DIR.mkdir(exist_ok=True)
    output_path = HARVEST_DIR / "api_training_data.json"
    with open(output_path, "w") as f:
        json.dump(merged, f, indent=2)
    print(f"\nSaved {len(merged)} total training samples to {output_path}")
    
    # Also save just the HF components for reference
    hf_components_path = HARVEST_DIR / "hf_supply_chain_components.json"
    with open(hf_components_path, "w") as f:
        json.dump(components, f, indent=2)
    print(f"Saved {len(components)} HF components to {hf_components_path}")
    
    # Summary stats
    print(f"\n{'='*70}")
    print("PRICE STATISTICS BY CATEGORY")
    print(f"{'='*70}")
    cat_prices = defaultdict(list)
    for c in components:
        cat_prices[c["category"]].append(c["unit_price_usd"])
    
    for cat in sorted(cat_prices.keys()):
        prices = cat_prices[cat]
        print(f"  {cat:25s}: n={len(prices):3d}, median=${np.median(prices):8.4f}, "
              f"range=[${min(prices):.4f} - ${max(prices):.4f}]")


if __name__ == "__main__":
    main()
