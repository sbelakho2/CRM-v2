#!/usr/bin/env python3
"""
Extended DigiKey harvester: collect 150+ additional MPNs for weak categories.
Focuses on: resistor, relay, LED, crystal_oscillator, connector, diode, transistor, sensor.
Also adds more ic_logic, ic_power, mosfet samples.
"""

import json
import os
import sys
import time
from pathlib import Path
from typing import Dict, List, Optional

import requests

SCRIPT_DIR = Path(__file__).parent
PROJECT_ROOT = SCRIPT_DIR.parent
HARVEST_DIR = SCRIPT_DIR / "harvested_data"

def load_env():
    env = {}
    with open(PROJECT_ROOT / ".env") as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            if "=" in line:
                k, _, v = line.partition("=")
                env[k.strip()] = v.strip()
    return env

class DigiKeyClient:
    AUTH_URL = "https://api.digikey.com/v1/oauth2/token"
    SEARCH_URL = "https://api.digikey.com/products/v4/search/keyword"
    RATE_LIMIT = 0.18

    def __init__(self, client_id: str, client_secret: str):
        self.client_id = client_id
        self.client_secret = client_secret
        self.token = None
        self.token_expiry = 0
        self.last_request = 0
        self.session = requests.Session()

    def authenticate(self) -> bool:
        try:
            resp = self.session.post(self.AUTH_URL, data={
                "client_id": self.client_id,
                "client_secret": self.client_secret,
                "grant_type": "client_credentials",
            }, timeout=15)
            resp.raise_for_status()
            data = resp.json()
            self.token = data["access_token"]
            self.token_expiry = time.time() + data.get("expires_in", 600) - 30
            print(f"  ✓ DigiKey authenticated")
            return True
        except Exception as e:
            print(f"  ✗ DigiKey auth failed: {e}")
            return False

    def _ensure_token(self):
        if not self.token or time.time() > self.token_expiry:
            self.authenticate()

    def _rate_limit(self):
        elapsed = time.time() - self.last_request
        if elapsed < self.RATE_LIMIT:
            time.sleep(self.RATE_LIMIT - elapsed)
        self.last_request = time.time()

    def search_mpn(self, mpn: str) -> Optional[Dict]:
        self._ensure_token()
        self._rate_limit()
        try:
            resp = self.session.post(
                self.SEARCH_URL,
                json={"Keywords": mpn, "Limit": 5, "Offset": 0},
                headers={
                    "Authorization": f"Bearer {self.token}",
                    "X-DIGIKEY-Client-Id": self.client_id,
                    "Content-Type": "application/json",
                    "X-DIGIKEY-Locale-Site": "US",
                    "X-DIGIKEY-Locale-Language": "en",
                    "X-DIGIKEY-Locale-Currency": "USD",
                },
                timeout=20,
            )
            resp.raise_for_status()
            data = resp.json()
            products = data.get("Products", [])
            if not products:
                return None

            best = products[0]
            for p in products:
                if p.get("ManufacturerProductNumber", "").upper() == mpn.upper():
                    best = p
                    break

            price_breaks = []
            variations = best.get("ProductVariations", [])
            for var in variations:
                for pb in var.get("StandardPricing", []):
                    qty = pb.get("BreakQuantity", 0)
                    price = pb.get("UnitPrice", 0)
                    if price > 0:
                        price_breaks.append({"quantity": qty, "price": float(price)})
                if price_breaks:
                    break

            if not price_breaks:
                return None

            stock = best.get("QuantityAvailable", 0) or 0
            desc_data = best.get("Description", {})
            description = desc_data.get("ProductDescription", "") if isinstance(desc_data, dict) else str(desc_data)
            detailed = desc_data.get("DetailedDescription", "") if isinstance(desc_data, dict) else ""
            mfr_data = best.get("Manufacturer", {})
            manufacturer = mfr_data.get("Name", "") if isinstance(mfr_data, dict) else str(mfr_data)

            specs = {}
            for param in best.get("Parameters", []):
                pname = param.get("ParameterText", "")
                pval = param.get("ValueText", "")
                if pname and pval and pval != "-":
                    specs[pname] = pval

            return {
                "mpn": best.get("ManufacturerProductNumber", mpn),
                "manufacturer": manufacturer,
                "description": description,
                "detailed_description": detailed,
                "stock": int(stock),
                "unit_price_usd": price_breaks[0]["price"],
                "price_breaks": price_breaks,
                "specs": specs,
            }
        except Exception as e:
            print(f"    ✗ {mpn}: {e}")
            return None


# =============================================================================
# EXTENDED MPN LIST — 170+ new parts for weak categories
# =============================================================================

EXTENDED_MPNS = {
    # ── RESISTORS (biggest weakness: MdAPE=46%) ──
    # Need wider value/package/power diversity
    "resistor": [
        # 0201 (ultra-tiny, cheap)
        ("RC0201FR-070RL", "YAGEO", "RES SMD 0R 0201"),
        ("RC0201FR-0710KL", "YAGEO", "RES SMD 10K 1% 0201"),
        # 0402 (most common, cheap)
        ("RC0402FR-071KL", "YAGEO", "RES SMD 1K 1% 0402"),
        ("RC0402FR-07100KL", "YAGEO", "RES SMD 100K 1% 0402"),
        ("RC0402FR-071ML", "YAGEO", "RES SMD 1M 1% 0402"),
        ("RC0402FR-07100RL", "YAGEO", "RES SMD 100R 1% 0402"),
        # 0603
        ("CRCW06031K00FKEA", "Vishay", "RES SMD 1K 1% 0603"),
        ("CRCW0603100KFKEA", "Vishay", "RES SMD 100K 1% 0603"),
        ("ERJ-3EKF1001V", "Panasonic", "RES SMD 1K 1% 0603"),
        # 0805 more values
        ("CRCW08051K00FKEA", "Vishay", "RES SMD 1K 1% 0805"),
        ("RC0805FR-07100RL", "YAGEO", "RES SMD 100R 1% 0805"),
        # 1206
        ("RC1206FR-0710KL", "YAGEO", "RES SMD 10K 1% 1206"),
        ("RC1206FR-071KL", "YAGEO", "RES SMD 1K 1% 1206"),
        # Through-hole
        ("MFR-25FBF52-1K", "YAGEO", "RES AXIAL 1K 1% 1/4W"),
        ("MFR-25FBF52-100K", "YAGEO", "RES AXIAL 100K 1% 1/4W"),
        ("CFR-25JB-52-10K", "YAGEO", "RES AXIAL 10K 5% 1/4W"),
        # Power resistors (more expensive)
        ("CRCW25122K00JNEGHP", "Vishay", "RES SMD 2K 5% 2512 1W"),
        ("WSL2512R0100FEA", "Vishay", "RES SMD CURRENT SENSE 0.01R 2512"),
        ("ERJ-1TYJ100U", "Panasonic", "RES SMD 10R 5% 2512 1W"),
    ],

    # ── CONNECTORS (MdAPE=36%, bias=-0.43 under-predicting) ──
    # Need more pin-count diversity, different types
    "connector": [
        # JST - varying pin counts
        ("B3B-XH-A(LF)(SN)", "JST", "CONN HEADER 3POS 2.5MM"),
        ("B4B-XH-A(LF)(SN)", "JST", "CONN HEADER 4POS 2.5MM"),
        ("B6B-XH-A(LF)(SN)", "JST", "CONN HEADER 6POS 2.5MM"),
        ("B8B-XH-A(LF)(SN)", "JST", "CONN HEADER 8POS 2.5MM"),
        ("B10B-XH-A(LF)(SN)", "JST", "CONN HEADER 10POS 2.5MM"),
        # Molex KK
        ("0022232021", "Molex", "CONN HEADER 2POS 2.54MM"),
        ("0022232061", "Molex", "CONN HEADER 6POS 2.54MM"),
        ("0022232081", "Molex", "CONN HEADER 8POS 2.54MM"),
        ("0022232101", "Molex", "CONN HEADER 10POS 2.54MM"),
        # TE pin headers
        ("640456-2", "TE Connectivity", "CONN HEADER 2POS 2.54MM TH"),
        ("640456-4", "TE Connectivity", "CONN HEADER 4POS 2.54MM TH"),
        ("640456-8", "TE Connectivity", "CONN HEADER 8POS 2.54MM TH"),
        # Different types
        ("1-1462037-0", "TE Connectivity", "CONN RCPT 10POS 2.54MM IDC"),
        ("SJ1-3523N", "CUI", "CONN JACK STEREO 3.5MM"),
        ("PJ-002A", "CUI", "CONN JACK DC POWER 2.1MM"),
        ("UJ2-MIBH2-4-SMT-TR", "CUI", "CONN RCPT MICRO USB B SMD"),
    ],

    # ── LEDs (MdAPE=37%, bias=-0.50 under-predicting) ──
    "led": [
        # Standard indicator LEDs - different colors/packages
        ("LTST-C171GKT", "Lite-On", "LED GREEN 572NM 0805"),
        ("LTST-C191TBKT", "Lite-On", "LED BLUE 470NM 0603"),
        ("LTST-C190KRKT", "Lite-On", "LED RED 630NM 0603"),
        ("150060RS75000", "Wurth", "LED RED 625NM 0603"),
        ("150060GS75000", "Wurth", "LED GREEN 525NM 0603"),
        ("150060BS75000", "Wurth", "LED BLUE 470NM 0603"),
        ("150060YS75000", "Wurth", "LED YELLOW 590NM 0603"),
        # 0402 LEDs (tiny, cheap)
        ("IN-S85AT5R", "Inolux", "LED RED 630NM 0402"),
        ("IN-S85AT5G", "Inolux", "LED GREEN 525NM 0402"),
        # Through-hole
        ("HLMP-4700", "Broadcom", "LED RED T1-3/4 5MM"),
        ("SSL-LX5093GD", "Lumex", "LED GREEN T1-3/4 5MM"),
        # High-power / specialty
        ("XPEBWT-L1-0000-00D01", "Cree", "LED WHITE 1W XP-E2"),
        ("XHP50B-00-0000-0D0BJ40E5", "Cree", "LED WHITE 6W XHP50.2"),
    ],

    # ── RELAYS (MdAPE=39%, bias=-0.66 severely under-predicting) ──
    "relay": [
        ("G5V-2-H1-DC5", "Omron", "RELAY GP DPDT 0.5A 5VDC"),
        ("G6K-2F-Y DC5", "Omron", "RELAY SIGNAL DPDT 1A 5VDC"),
        ("G5NB-1A-E-DC5", "Omron", "RELAY GP SPST 5A 5VDC"),
        ("SRD-05VDC-SL-C", "Songle", "RELAY GP SPDT 10A 5VDC"),
        ("HF46F-G/5-HS1", "Hongfa", "RELAY GP SPST 5A 5VDC"),
        ("841-1C-S-5VDC", "Song Chuan", "RELAY AUTOMOTIVE SPDT 20A 5VDC"),
        ("RT314005", "TE Connectivity", "RELAY GP SPDT 16A 5VDC"),
        ("JW2SN-DC5V", "Panasonic", "RELAY GP DPDT 5A 5VDC"),
        ("APAN3105", "Panasonic", "RELAY GP SPDT 10A 5VDC"),
    ],

    # ── CRYSTAL OSCILLATORS (MdAPE=36%, bias=-0.48 under-predicting) ──
    "crystal_oscillator": [
        ("FA-238 16.0000MB-C3", "Epson", "CRYSTAL 16MHZ 10PF 3.2x2.5"),
        ("ECS-.327-12.5-34B-C-TR", "ECS", "CRYSTAL 32.768KHZ 12.5PF SMD"),
        ("ABM8-16.000MHZ-B2-T", "Abracon", "CRYSTAL 16MHZ 18PF 3.2x2.5"),
        ("TSX-3225 16.0000MF09Z-AC3", "Epson", "CRYSTAL 16MHZ 9PF 3.2x2.5"),
        ("ABLS-8.000MHZ-B2-T", "Abracon", "CRYSTAL 8MHZ 18PF HC49S"),
        ("ABS07-32.768KHZ-T", "Abracon", "CRYSTAL 32.768KHZ 12.5PF 3.2x1.5"),
        # Oscillator modules (more expensive)
        ("SIT8008BI-82-33E-16.000000G", "SiTime", "OSC MEMS 16MHZ 3.3V SOT23-5"),
        ("ECS-2520MV-250-BN-TR", "ECS", "OSC MEMS 25MHZ 1.8V 2.5x2.0"),
    ],

    # ── DIODES (MdAPE=30%, could be tighter) ──
    "diode": [
        ("BAV99,215", "Nexperia", "DIODE GP DUAL 70V SOT23"),
        ("1N5819", "ON Semiconductor", "DIODE SCHOTTKY 40V 1A DO41"),
        ("US1M", "Diodes Inc", "DIODE RECTIFIER 1000V 1A SMA"),
        ("MMSZ5231B-7-F", "Diodes Inc", "DIODE ZENER 5.1V SOD123"),
        ("BZX84C5V1-7-F", "Diodes Inc", "DIODE ZENER 5.1V SOT23"),
        ("SMBJ5.0A", "Littelfuse", "TVS DIODE 5V 9.2V SMB"),
        ("SMAJ5.0A", "Littelfuse", "TVS DIODE 5V SMA UNIDIRECTIONAL"),
        ("PESD5V0S1BA,115", "Nexperia", "TVS DIODE 5V SOD323"),
        ("MBR0520LT1G", "ON Semiconductor", "DIODE SCHOTTKY 20V 0.5A SOD123"),
        ("SK14", "ON Semiconductor", "DIODE SCHOTTKY 40V 1A DO-214AC"),
    ],

    # ── TRANSISTORS (MdAPE=29%) ──
    "transistor": [
        ("BC547BTA", "ON Semiconductor", "TRANS NPN 45V 100MA TO92"),
        ("2N3904BU", "ON Semiconductor", "TRANS NPN 40V 200MA TO92"),
        ("2N3906BU", "ON Semiconductor", "TRANS PNP 40V 200MA TO92"),
        ("MMBT2222ALT1G", "ON Semiconductor", "TRANS NPN 40V 600MA SOT23"),
        ("MMBT3906LT1G", "ON Semiconductor", "TRANS PNP 40V 200MA SOT23"),
        ("BC817-40,215", "Nexperia", "TRANS NPN 45V 500MA SOT23"),
        ("BC857B,215", "Nexperia", "TRANS PNP 45V 100MA SOT23"),
        ("TIP122", "STMicroelectronics", "TRANS DARLINGTON NPN 100V 5A TO220"),
        ("TIP31C", "STMicroelectronics", "TRANS NPN 100V 3A TO220"),
    ],

    # ── MOSFETs (MdAPE=28%) ──
    "mosfet": [
        ("IRF3205PBF", "Infineon", "MOSFET N-CH 55V 110A TO220"),
        ("IRLML6344TRPBF", "Infineon", "MOSFET N-CH 30V 5A SOT23"),
        ("FQP30N06L", "ON Semiconductor", "MOSFET N-CH 60V 32A TO220"),
        ("IRLML2502TRPBF", "Infineon", "MOSFET N-CH 20V 4.2A SOT23"),
        ("DMN2075U-7", "Diodes Inc", "MOSFET N-CH 20V 4.2A SOT23"),
        ("IRF9540NPBF", "Infineon", "MOSFET P-CH 100V 23A TO220"),
        ("SI2301CDS-T1-GE3", "Vishay", "MOSFET P-CH 20V 2.8A SOT23"),
        ("FDS6898A", "ON Semiconductor", "MOSFET N-CH DUAL 20V SOIC8"),
    ],

    # ── SENSORS (MdAPE=25%) ──
    "sensor": [
        ("TMP36GT9Z", "Analog Devices", "SENSOR TEMP ANALOG TO92"),
        ("DHT22", "Aosong", "SENSOR TEMP HUM DIGITAL SIP4"),
        ("BMP280", "Bosch", "SENSOR PRESS TEMP I2C LGA8"),
        ("MAX30102EFD+T", "Maxim", "SENSOR OPTICAL SPO2 HR OLGA14"),
        ("APDS-9960", "Broadcom", "SENSOR PROXIMITY GESTURE I2C"),
        ("VL53L0CXV0DH/1", "STMicroelectronics", "SENSOR TOF RANGING I2C"),
        ("INA226AIDGSR", "Texas Instruments", "IC CURRENT MONITOR I2C MSOP10"),
    ],

    # ── IC_POWER (MdAPE=28%) ──
    "ic_power": [
        ("AMS1117-5.0", "AMS", "REG LDO 5.0V 1A SOT223"),
        ("MCP1700-3302E/TO", "Microchip", "REG LDO 3.3V 250MA TO92"),
        ("LT1083CP#PBF", "Analog Devices", "REG LINEAR ADJ 7.5A TO3"),
        ("AP2112K-3.3TRG1", "Diodes Inc", "REG LDO 3.3V 600MA SOT23-5"),
        ("NCP1117ST33T3G", "ON Semiconductor", "REG LDO 3.3V 1A SOT223"),
        ("TLV1117-33CDCYR", "Texas Instruments", "REG LDO 3.3V 800MA SOT223"),
        ("RT8059GJ5", "Richtek", "REG BUCK 1.2V 600MA SOT23-5"),
        ("XL6009E1", "XLSEMI", "REG BOOST ADJ 4A SOP8"),
    ],

    # ── IC_LOGIC (MdAPE=23% but high bias=+0.20 over-predicting cheap gates) ──
    "ic_logic": [
        ("SN74HC00DR", "Texas Instruments", "IC GATE NAND 4CH SOIC14"),
        ("SN74HC04DR", "Texas Instruments", "IC INVERTER 6CH SOIC14"),
        ("SN74HC14DR", "Texas Instruments", "IC SCHMITT TRIGGER 6CH SOIC14"),
        ("SN74HC74DR", "Texas Instruments", "IC D-TYPE FF 2CH SOIC14"),
        ("SN74HC138DR", "Texas Instruments", "IC DECODER 3-TO-8 SOIC16"),
        ("SN74HC164DR", "Texas Instruments", "IC SHIFT REG 8BIT SOIC14"),
        ("SN74LVC2G00DBVR", "Texas Instruments", "IC GATE NAND 2CH SOT23-6"),
        ("SN74LVC2G04DBVR", "Texas Instruments", "IC INVERTER 2CH SOT23-6"),
        ("74HC4051D,653", "Nexperia", "IC MUX 8:1 SOIC16"),
        ("CD74HC4067M96", "Texas Instruments", "IC MUX 16:1 SOIC24"),
    ],

    # ── IC_MICROCONTROLLER (more variety) ──
    "ic_microcontroller": [
        ("PIC16F1455-I/SL", "Microchip", "MCU 8BIT 14KB FLASH SOIC14"),
        ("ATTINY85-20SU", "Microchip", "MCU 8BIT 8KB FLASH SOIC8"),
        ("STM32G031K8T6", "STMicroelectronics", "MCU 32BIT ARM 64MHZ LQFP32"),
        ("STM32L031K6T6", "STMicroelectronics", "MCU 32BIT ARM LP 32MHZ LQFP32"),
        ("CY8C4245AXI-483", "Infineon", "MCU 32BIT ARM 48MHZ TQFP44"),
    ],

    # ── IC_ANALOG (more variety) ──
    "ic_analog": [
        ("TL074CDR", "Texas Instruments", "OP AMP QUAD GP SOIC14"),
        ("NE555DR", "Texas Instruments", "IC TIMER 555 SOIC8"),
        ("LM324DR", "Texas Instruments", "OP AMP QUAD GP SOIC14"),
        ("LM393DR", "Texas Instruments", "COMP DUAL GP SOIC8"),
        ("MCP3008-I/SL", "Microchip", "ADC 10BIT 8CH SPI SOIC16"),
        ("ADS1115IDGSR", "Texas Instruments", "ADC 16BIT 4CH I2C MSOP10"),
    ],

    # ── SWITCHES (MdAPE=24%) ──
    "switch": [
        ("B3F-1000", "Omron", "SWITCH TACT 6MM THROUGH HOLE"),
        ("TL1105AF250Q", "E-Switch", "SWITCH TACT 6MM THROUGH HOLE"),
        ("PTS645SL50SMTR92LFS", "C&K", "SWITCH TACT SMD 6x6mm"),
        ("KSA0A211LFTR", "C&K", "SWITCH TACT SMD 3x3mm"),
        ("SS12D07-VG 4 NS", "C&K", "SWITCH SLIDE SPDT TH"),
    ],

    # ── IGBTs ──
    "igbt": [
        ("IKW40N60DTP", "Infineon", "IGBT 600V 40A TO247"),
        ("STGW30NC60WD", "STMicroelectronics", "IGBT 600V 30A TO247"),
    ],

    # ── INDUCTORS (more variety) ──
    "inductor": [
        ("SRN4018-100M", "Bourns", "IND SHIELDED 10uH 1.1A 4x4mm"),
        ("SRR1260-100M", "Bourns", "IND SHIELDED 10uH 3.5A 12.5mm"),
        ("CDRH4D28NP-2R2NC", "Sumida", "IND SHIELDED 2.2uH 2.5A 4mm"),
        ("XAL6060-222MEC", "Coilcraft", "IND SHIELDED 2.2uH 12A 6mm"),
        ("MSS1260-103MLD", "Coilcraft", "IND SHIELDED 10uH 4.5A 12.6mm"),
    ],
}


def harvest_extended(dk: DigiKeyClient) -> List[Dict]:
    """Harvest all extended MPNs."""
    results = []
    total = sum(len(v) for v in EXTENDED_MPNS.values())
    done = 0
    found = 0

    for category, mpns in EXTENDED_MPNS.items():
        print(f"\n  ── {category.upper()} ({len(mpns)} MPNs) ──")
        for mpn, mfr, desc in mpns:
            done += 1
            data = dk.search_mpn(mpn)
            if data:
                data["category"] = category
                data["expected_manufacturer"] = mfr
                data["expected_description"] = desc
                results.append(data)
                found += 1
                price = data["unit_price_usd"]
                print(f"    [{done}/{total}] ✓ {mpn:30s} ${price:.4f}  ({data.get('description', '')[:50]})")
            else:
                print(f"    [{done}/{total}] ✗ {mpn:30s} NOT FOUND")

    print(f"\n  HARVEST COMPLETE: {found}/{total} found ({100*found/total:.0f}%)")
    return results


def merge_with_existing(new_data: List[Dict], existing_path: Path) -> List[Dict]:
    """Merge new harvested data with existing, avoiding duplicates."""
    if existing_path.exists():
        with open(existing_path) as f:
            existing = json.load(f)
        print(f"  Loaded {len(existing)} existing components")
    else:
        existing = []

    existing_mpns = {h["mpn"].upper() for h in existing}
    added = 0
    for item in new_data:
        if item["mpn"].upper() not in existing_mpns:
            existing.append(item)
            existing_mpns.add(item["mpn"].upper())
            added += 1
        else:
            # Update existing with fresh data
            for i, e in enumerate(existing):
                if e["mpn"].upper() == item["mpn"].upper():
                    existing[i] = item
                    break

    print(f"  Added {added} new, updated {len(new_data) - added} existing → {len(existing)} total")
    return existing


def export_training_data(harvested: List[Dict], output_path: Path):
    """Export harvested data in training-ready format with multi-qty/supplier augmentation."""
    training_samples = []

    SUPPLIER_ADJUSTMENTS = {
        "digikey": {"markup": 1.0, "reliability": 0.98, "lead_time": 5, "moq": 1},
        "mouser":  {"markup": 0.98, "reliability": 0.97, "lead_time": 5, "moq": 1},
        "arrow":   {"markup": 0.88, "reliability": 0.95, "lead_time": 7, "moq": 25},
        "alibaba_gold": {"markup": 0.50, "reliability": 0.80, "lead_time": 21, "moq": 500},
    }

    for h in harvested:
        mpn = h.get("mpn", "")
        cat = h.get("category", "unknown")
        mfr = h.get("manufacturer", "") or h.get("expected_manufacturer", "")
        desc = h.get("description", "") or h.get("expected_description", "")
        base_price = h.get("unit_price_usd")

        if not base_price or base_price <= 0:
            continue

        # For each supplier adjustment
        for sup_name, sup_info in SUPPLIER_ADJUSTMENTS.items():
            # For each quantity point
            for qty in [1, 10, 100, 1000, 10000]:
                adj_price = base_price * sup_info["markup"]
                # Volume discount
                if qty >= 10000: adj_price *= 0.55
                elif qty >= 1000: adj_price *= 0.65
                elif qty >= 100: adj_price *= 0.78
                elif qty >= 10: adj_price *= 0.92

                training_samples.append({
                    "category": cat,
                    "supplier": sup_name,
                    "manufacturer": mfr,
                    "part_number": mpn,
                    "description": f"{desc} {mpn}",
                    "quantity": qty,
                    "moq": sup_info["moq"],
                    "unit_price": round(adj_price, 6),
                    "lead_time_days": sup_info["lead_time"],
                    "reliability_score": sup_info["reliability"],
                    "industry": "ems_contract",
                    "complexity_factor": 1.0,
                    "is_real_price": sup_name == "digikey" and qty == 1,
                })

        # Also add ACTUAL price breaks from DigiKey
        for pb in h.get("price_breaks", []):
            training_samples.append({
                "category": cat,
                "supplier": "digikey",
                "manufacturer": mfr,
                "part_number": mpn,
                "description": f"{desc} {mpn}",
                "quantity": pb["quantity"],
                "moq": 1,
                "unit_price": pb["price"],
                "lead_time_days": 5,
                "reliability_score": 0.98,
                "industry": "ems_contract",
                "complexity_factor": 1.0,
                "is_real_price": True,
            })

    with open(output_path, "w") as f:
        json.dump(training_samples, f, indent=2)
    print(f"  ✓ Exported {len(training_samples)} training samples to {output_path}")
    return training_samples


if __name__ == "__main__":
    env = load_env()
    dk_id = env.get("DIGIKEY_CLIENT_ID", "")
    dk_secret = env.get("DIGIKEY_CLIENT_SECRET", "")

    if not dk_id or not dk_secret:
        print("ERROR: DIGIKEY_CLIENT_ID / DIGIKEY_CLIENT_SECRET not in .env")
        sys.exit(1)

    dk = DigiKeyClient(dk_id, dk_secret)
    if not dk.authenticate():
        sys.exit(1)

    print("\n" + "=" * 60)
    print("EXTENDED DIGIKEY HARVEST (170+ new MPNs)")
    print("=" * 60)

    new_data = harvest_extended(dk)

    # Merge with existing harvest
    print("\n  Merging with existing harvest data...")
    merged = merge_with_existing(new_data, HARVEST_DIR / "harvested_prices.json")

    # Save merged
    with open(HARVEST_DIR / "harvested_prices.json", "w") as f:
        json.dump(merged, f, indent=2)
    print(f"  ✓ Saved {len(merged)} total components")

    # Export training data
    print("\n  Exporting training data...")
    export_training_data(merged, HARVEST_DIR / "api_training_data.json")

    # Summary
    from collections import Counter
    cat_counts = Counter(h["category"] for h in merged)
    print("\n  CATEGORY BREAKDOWN:")
    for cat, count in sorted(cat_counts.items(), key=lambda x: -x[1]):
        print(f"    {cat:25s} {count:3d} components")
