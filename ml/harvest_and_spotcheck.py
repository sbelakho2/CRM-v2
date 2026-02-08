#!/usr/bin/env python3
"""
Real-Data Harvesting + Model Spot-Check Pipeline
=================================================
1. Queries Nexar GraphQL API for real electronic component prices
2. Queries Mouser REST API for the same MPNs
3. Runs model inference on each component
4. Compares predicted vs actual prices
5. Exports harvested data as JSON for training enrichment
6. Produces a detailed accuracy report by category/price tier

Usage:
    python harvest_and_spotcheck.py                    # harvest + spot-check
    python harvest_and_spotcheck.py --harvest-only      # harvest without model
    python harvest_and_spotcheck.py --spotcheck-only    # spot-check from saved harvest
    python harvest_and_spotcheck.py --export-training   # export as training data
"""

import argparse
import json
import math
import os
import re
import sys
import time
from collections import defaultdict
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

import numpy as np
import requests

# ─── Project paths ───
SCRIPT_DIR = Path(__file__).parent
PROJECT_ROOT = SCRIPT_DIR.parent
MODELS_DIR = SCRIPT_DIR / "models"
HARVEST_DIR = SCRIPT_DIR / "harvested_data"

# ─── Load .env ───
def load_env(env_path: Path = PROJECT_ROOT / ".env") -> Dict[str, str]:
    """Parse .env file into a dict."""
    env = {}
    if not env_path.exists():
        print(f"ERROR: .env not found at {env_path}")
        sys.exit(1)
    with open(env_path) as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            if "=" in line:
                key, _, val = line.partition("=")
                env[key.strip()] = val.strip()
    return env


# =============================================================================
# COMMON MPNs — 250 real components across all categories
# =============================================================================
# These are REAL manufacturer part numbers found on Mouser/DigiKey/Nexar.
# Chosen to span all 25 training categories and the full price spectrum
# ($0.001 passives → $50+ assemblies/MCUs).

SPOT_CHECK_MPNS = {
    # ── PASSIVES (cheap, high-volume) ──
    "capacitor": [
        ("GRM155R71C104KA88D", "Murata", "CAP MLCC 100nF 16V 0402 X7R"),
        ("CL10B104KB8NNNC", "Samsung", "CAP MLCC 100nF 50V 0603 X7R"),
        ("GRM188R71H103KA01D", "Murata", "CAP MLCC 10nF 50V 0603 X7R"),
        ("C0402C103K4RACTU", "KEMET", "CAP MLCC 10nF 16V 0402 X7R"),
        ("GRM31CR71H105KA88L", "Murata", "CAP MLCC 1uF 50V 1206 X7R"),
        ("CL21B106KOQNNNE", "Samsung", "CAP MLCC 10uF 16V 0805 X5R"),
        ("C1206C106K4PACTU", "KEMET", "CAP MLCC 10uF 16V 1206 X5R"),
        ("EEE-FK1V100P", "Panasonic", "CAP ALUM 10uF 35V RADIAL"),
        ("UWT1V100MCL1GS", "Nichicon", "CAP ALUM 10uF 35V SMD"),
        ("TMK316BBJ226ML-T", "Taiyo Yuden", "CAP MLCC 22uF 10V 1206"),
    ],
    "resistor": [
        ("RC0402FR-0710KL", "YAGEO", "RES SMD 10K 1% 0402"),
        ("CRCW040210K0FKED", "Vishay", "RES SMD 10K 1% 0402"),
        ("ERJ-2RKF1002X", "Panasonic", "RES SMD 10K 1% 0402"),
        ("RC0603FR-074K7L", "YAGEO", "RES SMD 4.7K 1% 0603"),
        ("CRCW0805100KFKEA", "Vishay", "RES SMD 100K 1% 0805"),
        ("RC0805FR-071KL", "YAGEO", "RES SMD 1K 1% 0805"),
        ("ERJ-6ENF1001V", "Panasonic", "RES SMD 1K 1% 0805"),
        ("RC0402JR-070RL", "YAGEO", "RES SMD 0R 0402"),
        ("CRCW120610K0FKEA", "Vishay", "RES SMD 10K 1% 1206"),
        ("MFR-25FBF52-10K", "YAGEO", "RES AXIAL 10K 1% 1/4W"),
    ],
    "inductor": [
        ("LQH32CN100K53L", "Murata", "IND SHIELDED 10uH 0.29A 1210"),
        ("SLF7045T-100MR86-2PF", "TDK", "IND SHIELDED 10uH 7x7mm"),
        ("NR3015T2R2M", "Taiyo Yuden", "IND SHIELDED 2.2uH 1.4A"),
        ("MLF2012A1R0JT000", "TDK", "IND MULTILAYER 1.0uH 0805"),
        ("CDRH5D28NP-4R7NC", "Sumida", "IND SHIELDED 4.7uH 1.5A"),
    ],
    # ── ACTIVE ICs (moderate to expensive) ──
    "ic_microcontroller": [
        ("STM32F103C8T6", "STMicroelectronics", "MCU 32BIT ARM CORTEX-M3 72MHZ LQFP48"),
        ("ATMEGA328P-AU", "Microchip", "MCU 8BIT 32KB FLASH 20MHZ TQFP32"),
        ("ESP32-WROOM-32E", "Espressif", "WIFI BT MODULE 4MB FLASH"),
        ("PIC16F877A-I/P", "Microchip", "MCU 8BIT 14KB FLASH DIP40"),
        ("STM32F411CEU6", "STMicroelectronics", "MCU 32BIT ARM 100MHZ UFQFPN48"),
        ("RP2040", "Raspberry Pi", "MCU DUAL ARM CORTEX-M0+ QFN56"),
        ("ATSAMD21G18A-MU", "Microchip", "MCU 32BIT ARM CORTEX-M0+ QFN48"),
        ("STM32F407VET6", "STMicroelectronics", "MCU 32BIT ARM 168MHZ LQFP100"),
        ("nRF52840-QIAA-R7", "Nordic", "SOC BLE5 ARM CORTEX-M4 QFN73"),
        ("STM32H743VIT6", "STMicroelectronics", "MCU 32BIT ARM 480MHZ LQFP100"),
    ],
    "ic_memory": [
        ("W25Q128JVSIQ", "Winbond", "FLASH NOR 128MBIT SPI SOIC8"),
        ("AT24C256C-SSHL-T", "Microchip", "EEPROM 256KBIT I2C SOIC8"),
        ("IS62WV6416DBLL-55TLI", "ISSI", "SRAM 1MBIT ASYNC TSOP44"),
        ("S25FL128LAGMFI010", "Infineon", "FLASH NOR 128MBIT SPI SOIC16"),
        ("MT41K256M16TW-107:P", "Micron", "DRAM DDR3L 4GBIT 933MHZ"),
    ],
    "ic_analog": [
        ("LM358DR", "Texas Instruments", "OP AMP DUAL GP SOIC8"),
        ("MCP6002-I/SN", "Microchip", "OP AMP DUAL RRIO 1MHZ SOIC8"),
        ("AD620ANZ", "Analog Devices", "INST AMP 1 CIRCUIT DIP8"),
        ("OPA2340PA", "Texas Instruments", "OP AMP DUAL RRIO 5.5MHZ DIP8"),
        ("INA219BIDR", "Texas Instruments", "IC CURRENT MONITOR I2C SOIC8"),
    ],
    "ic_power": [
        ("LM7805CT/NOPB", "Texas Instruments", "REG LINEAR 5V 1.5A TO220"),
        ("AMS1117-3.3", "AMS", "REG LDO 3.3V 1A SOT223"),
        ("TPS54331DR", "Texas Instruments", "REG BUCK ADJ 3A SOIC8"),
        ("LM2596S-5.0/NOPB", "Texas Instruments", "REG BUCK 5V 3A TO263"),
        ("MP1584EN-LF-Z", "MPS", "REG BUCK ADJ 3A SOIC8"),
    ],
    "ic_logic": [
        ("SN74HC595DR", "Texas Instruments", "IC SHIFT REG 8BIT SOIC16"),
        ("SN74LVC1G08DBVR", "Texas Instruments", "IC GATE AND 1CH SOT23-5"),
        ("CD4017BE", "Texas Instruments", "IC COUNTER DECADE DIP16"),
        ("SN74AHC1G04DBVR", "Texas Instruments", "IC INVERTER 1CH SOT23-5"),
        ("SN74HC245N", "Texas Instruments", "IC TRANSCEIVER 8BIT DIP20"),
    ],
    # ── DISCRETE SEMICONDUCTORS ──
    "diode": [
        ("1N4148W-7-F", "Diodes Inc", "DIODE GP 100V 300MA SOD123"),
        ("SS14", "ON Semiconductor", "DIODE SCHOTTKY 40V 1A SMA"),
        ("BAT54S,215", "Nexperia", "DIODE SCHOTTKY DUAL SOT23"),
        ("BZX84C3V3-7-F", "Diodes Inc", "DIODE ZENER 3.3V SOT23"),
        ("ES2J", "ON Semiconductor", "DIODE RECTIFIER 600V 2A SMB"),
    ],
    "transistor": [
        ("MMBT3904LT1G", "ON Semiconductor", "TRANS NPN 40V 200MA SOT23"),
        ("BC547B", "ON Semiconductor", "TRANS NPN 45V 100MA TO92"),
        ("2N2222A", "ON Semiconductor", "TRANS NPN 40V 600MA TO18"),
        ("BSS138", "ON Semiconductor", "MOSFET N-CH 50V 220MA SOT23"),
        ("2N7002", "Nexperia", "MOSFET N-CH 60V 300MA SOT23"),
    ],
    "mosfet": [
        ("IRLZ44NPBF", "Infineon", "MOSFET N-CH 55V 47A TO220"),
        ("IRF540NPBF", "Infineon", "MOSFET N-CH 100V 33A TO220"),
        ("AO3400A", "Alpha & Omega", "MOSFET N-CH 30V 5.7A SOT23"),
        ("SI2302CDS-T1-GE3", "Vishay", "MOSFET N-CH 20V 2.6A SOT23"),
        ("IRFZ44NPBF", "Infineon", "MOSFET N-CH 55V 49A TO220"),
    ],
    "led": [
        ("LTST-C171KRKT", "Lite-On", "LED RED 631NM 0805"),
        ("150060VS75000", "Wurth", "LED GREEN 572NM 0603"),
        ("WS2812B-V5", "Worldsemi", "LED RGB ADDRESSABLE 5050"),
        ("CREE XP-E2", "Cree", "LED WHITE 1W HIGH POWER"),
        ("IN-S85AT5UW", "Inolux", "LED WHITE 6500K 0402"),
    ],
    # ── ELECTROMECHANICAL ──
    "connector": [
        ("B2B-XH-A(LF)(SN)", "JST", "CONN HEADER 2POS 2.5MM"),
        ("0022232041", "Molex", "CONN HEADER 4POS 2.54MM"),
        ("1-1734248-4", "TE Connectivity", "CONN HEADER 14POS 2.54MM"),
        ("USB4105-GF-A", "GCT", "CONN RCPT USB-C SMD R/A"),
        ("10118192-0001LF", "Amphenol", "CONN RCPT MICRO USB SMD"),
    ],
    "switch": [
        ("TS-1187A-B-A-B", "XKB", "SWITCH TACT SMD 6x6mm"),
        ("TL1105AF160Q", "E-Switch", "SWITCH TACT 6MM THROUGH HOLE"),
        ("EVQ-P7L01P", "Panasonic", "SWITCH TACT SMD 3.5x2.9mm"),
        ("SS-12D00G3", "C&K", "SWITCH SLIDE SPDT THROUGH HOLE"),
    ],
    "relay": [
        ("G5V-1-DC5", "Omron", "RELAY GP SPDT 1A 5VDC"),
        ("JQC-3FF-S-Z", "Hongfa", "RELAY GP SPDT 10A 5VDC"),
        ("HF46F/5-HS1", "Hongfa", "RELAY GP SPST 5A 5VDC"),
    ],
    "crystal_oscillator": [
        ("HC49S-16.000MABJ-UX", "Citizen", "CRYSTAL 16MHZ 20PF HC49"),
        ("ABM3B-8.000MHZ-B2-T", "Abracon", "CRYSTAL 8MHZ 18PF SMD"),
        ("FA-238 25.0000MB-C3", "Epson", "CRYSTAL 25MHZ 10PF 3.2x2.5"),
        ("NX3225GD-8MHZ-STD", "NDK", "CRYSTAL 8MHZ 8PF 3.2x2.5"),
    ],
    "transformer": [
        ("750311689", "Wurth", "TRANSFORMER PULSE 1:1 SMD"),
        ("EE-25-3-P", "TDK", "TRANSFORMER POWER 5W EE25"),
    ],
    # ── SENSORS ──
    "sensor": [
        ("BME280", "Bosch", "SENSOR PRESS TEMP HUM I2C LGA"),
        ("MPU-6050", "TDK InvenSense", "SENSOR IMU 6-AXIS I2C QFN24"),
        ("SHT31-DIS-B2.5KS", "Sensirion", "SENSOR TEMP HUM I2C DFN8"),
        ("LM35DZ/NOPB", "Texas Instruments", "SENSOR TEMP ANALOG TO92"),
        ("ADXL345BCCZ-RL7", "Analog Devices", "SENSOR ACCEL 3-AXIS SPI LGA14"),
    ],
    # ── POWER PASSIVES ──
    "igbt": [
        ("IRG4PC40WPBF", "Infineon", "IGBT 600V 40A TO247"),
        ("FGH40N60SFDTU", "ON Semiconductor", "IGBT 600V 40A TO247"),
    ],
}


# =============================================================================
# NEXAR API CLIENT
# =============================================================================

class NexarClient:
    """Minimal Nexar GraphQL client for price harvesting."""

    TOKEN_URL = "https://identity.nexar.com/connect/token"
    GRAPHQL_URL = "https://api.nexar.com/graphql"
    RATE_LIMIT = 0.12  # seconds between requests

    def __init__(self, client_id: str, client_secret: str):
        self.client_id = client_id
        self.client_secret = client_secret
        self.token: Optional[str] = None
        self.token_expiry: float = 0
        self.last_request: float = 0
        self.session = requests.Session()

    def authenticate(self) -> bool:
        """Get OAuth2 token from Nexar identity server."""
        try:
            resp = self.session.post(self.TOKEN_URL, data={
                "client_id": self.client_id,
                "client_secret": self.client_secret,
                "grant_type": "client_credentials",
            }, timeout=15)
            resp.raise_for_status()
            data = resp.json()
            self.token = data["access_token"]
            self.token_expiry = time.time() + data.get("expires_in", 3600) - 60
            print(f"  ✓ Nexar authenticated (token expires in {data.get('expires_in', 3600)}s)")
            return True
        except Exception as e:
            print(f"  ✗ Nexar auth failed: {e}")
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
        """Search for a part by MPN, return structured pricing data."""
        self._ensure_token()
        self._rate_limit()

        query = """
        query SearchPart($mpn: String!) {
          supSearchMpn(q: $mpn, limit: 3) {
            results {
              part {
                mpn
                manufacturer { name }
                shortDescription
                category { name }
                specs { attribute { name } displayValue }
                bestDatasheet { url }
              }
              sellers {
                company { name }
                offers {
                  inventoryLevel
                  moq
                  packaging
                  prices { quantity price currency }
                }
              }
            }
          }
        }
        """

        try:
            resp = self.session.post(
                self.GRAPHQL_URL,
                json={"query": query, "variables": {"mpn": mpn}},
                headers={
                    "Authorization": f"Bearer {self.token}",
                    "Content-Type": "application/json",
                },
                timeout=20,
            )
            resp.raise_for_status()
            data = resp.json()

            if "errors" in data:
                print(f"    ⚠ Nexar errors for {mpn}: {data['errors'][0].get('message', '')}")
                return None

            results = data.get("data", {}).get("supSearchMpn", {}).get("results", [])
            if not results:
                return None

            # Find best match (exact MPN match preferred)
            best_result = results[0]
            for r in results:
                if r.get("part", {}).get("mpn", "").upper() == mpn.upper():
                    best_result = r
                    break

            part = best_result.get("part", {})
            sellers = best_result.get("sellers", [])

            # Collect ALL pricing from ALL sellers
            all_prices = []
            seller_data = []
            total_stock = 0

            for seller in sellers:
                seller_name = seller.get("company", {}).get("name", "Unknown")
                for offer in seller.get("offers", []):
                    stock = offer.get("inventoryLevel", 0) or 0
                    total_stock += stock
                    moq = offer.get("moq", 1) or 1

                    for pb in offer.get("prices", []):
                        qty = pb.get("quantity", 0)
                        price = pb.get("price", 0)
                        currency = pb.get("currency", "USD")
                        if price and price > 0 and currency == "USD":
                            all_prices.append({
                                "seller": seller_name,
                                "quantity": qty,
                                "price": float(price),
                                "moq": moq,
                                "stock": stock,
                            })

                    if offer.get("prices"):
                        seller_data.append({
                            "name": seller_name,
                            "stock": stock,
                            "moq": moq,
                            "price_breaks": [
                                {"qty": p["quantity"], "price": float(p["price"])}
                                for p in offer.get("prices", [])
                                if p.get("price") and p.get("currency") == "USD"
                            ],
                        })

            if not all_prices:
                return None

            # Median unit price at qty=1 (or lowest qty)
            unit_prices = sorted(all_prices, key=lambda x: x["quantity"])
            low_qty_prices = [p["price"] for p in unit_prices if p["quantity"] <= 10]
            if not low_qty_prices:
                low_qty_prices = [unit_prices[0]["price"]]
            median_unit_price = float(np.median(low_qty_prices))

            # Extract specs
            specs = {}
            for s in part.get("specs", []):
                attr_name = s.get("attribute", {}).get("name", "")
                if attr_name:
                    specs[attr_name] = s.get("displayValue", "")

            return {
                "mpn": part.get("mpn", mpn),
                "manufacturer": part.get("manufacturer", {}).get("name", ""),
                "description": part.get("shortDescription", ""),
                "category_raw": part.get("category", {}).get("name", "") if part.get("category") else "",
                "median_unit_price": median_unit_price,
                "min_price": min(p["price"] for p in all_prices),
                "max_price": max(p["price"] for p in all_prices),
                "num_sellers": len(set(p["seller"] for p in all_prices)),
                "total_stock": total_stock,
                "price_breaks": all_prices[:30],  # Keep top 30 entries
                "sellers": seller_data[:10],
                "specs": specs,
            }

        except requests.exceptions.RequestException as e:
            print(f"    ✗ Nexar request failed for {mpn}: {e}")
            return None
        except Exception as e:
            print(f"    ✗ Nexar parse error for {mpn}: {e}")
            return None


# =============================================================================
# DIGIKEY API CLIENT
# =============================================================================

class DigiKeyClient:
    """DigiKey REST API client for price harvesting (OAuth2 client_credentials)."""

    AUTH_URL = "https://api.digikey.com/v1/oauth2/token"
    SEARCH_URL = "https://api.digikey.com/products/v4/search/keyword"
    RATE_LIMIT = 0.15  # ~6 req/sec

    def __init__(self, client_id: str, client_secret: str):
        self.client_id = client_id
        self.client_secret = client_secret
        self.token: Optional[str] = None
        self.token_expiry: float = 0
        self.last_request: float = 0
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
            print(f"  ✓ DigiKey authenticated (token expires in {data.get('expires_in', 600)}s)")
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
        """Search DigiKey by MPN, return structured pricing data."""
        self._ensure_token()
        self._rate_limit()

        try:
            resp = self.session.post(
                self.SEARCH_URL,
                json={
                    "Keywords": mpn,
                    "Limit": 5,
                    "Offset": 0,
                },
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

            # Find best match (exact MPN match preferred)
            best = products[0]
            for p in products:
                if p.get("ManufacturerProductNumber", "").upper() == mpn.upper():
                    best = p
                    break

            # Parse price breaks from first variation
            price_breaks = []
            variations = best.get("ProductVariations", [])
            for var in variations:
                for pb in var.get("StandardPricing", []):
                    qty = pb.get("BreakQuantity", 0)
                    price = pb.get("UnitPrice", 0)
                    if price > 0:
                        price_breaks.append({"quantity": qty, "price": float(price)})
                if price_breaks:
                    break  # Use first variation with pricing

            if not price_breaks:
                return None

            # Stock
            stock = best.get("QuantityAvailable", 0) or 0

            # Description
            desc_data = best.get("Description", {})
            description = desc_data.get("ProductDescription", "") if isinstance(desc_data, dict) else str(desc_data)
            detailed = desc_data.get("DetailedDescription", "") if isinstance(desc_data, dict) else ""

            # Manufacturer
            mfr_data = best.get("Manufacturer", {})
            manufacturer = mfr_data.get("Name", "") if isinstance(mfr_data, dict) else str(mfr_data)

            # Status
            status_data = best.get("ProductStatus", {})
            lifecycle = status_data.get("Status", "") if isinstance(status_data, dict) else ""

            # Parameters/specs
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
                "lifecycle": lifecycle,
                "stock": int(stock),
                "unit_price": price_breaks[0]["price"] if price_breaks else None,
                "price_breaks": price_breaks,
                "num_price_breaks": len(price_breaks),
                "min_price": min(pb["price"] for pb in price_breaks),
                "max_price": max(pb["price"] for pb in price_breaks),
                "specs": specs,
                "datasheet_url": best.get("DatasheetUrl", ""),
                "product_url": best.get("ProductUrl", ""),
            }

        except requests.exceptions.RequestException as e:
            print(f"    ✗ DigiKey request failed for {mpn}: {e}")
            return None
        except Exception as e:
            print(f"    ✗ DigiKey parse error for {mpn}: {e}")
            return None


# =============================================================================
# MODEL INFERENCE
# =============================================================================

def load_model_for_inference(models_dir: Path) -> Optional[Dict]:
    """Load trained model + all artifacts for inference."""
    try:
        import torch
        import torch.nn as nn

        # Load config
        config_path = models_dir / "pricing_config.json"
        if not config_path.exists():
            print("  ✗ pricing_config.json not found")
            return None
        with open(config_path) as f:
            config_data = json.load(f)

        # Load scaler
        scaler_path = models_dir / "price_scaler.json"
        if not scaler_path.exists():
            print("  ✗ price_scaler.json not found")
            return None
        with open(scaler_path) as f:
            scaler = json.load(f)

        # Load tokenizer
        tokenizer_path = models_dir / "pricing_tokenizer.json"
        if not tokenizer_path.exists():
            print("  ✗ pricing_tokenizer.json not found")
            return None
        with open(tokenizer_path) as f:
            tokenizer_data = json.load(f)

        word2idx = tokenizer_data["word2idx"]

        # Load model weights
        model_path = models_dir / "pricing_model.pt"
        if not model_path.exists():
            print("  ✗ pricing_model.pt not found")
            return None

        device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
        checkpoint = torch.load(model_path, map_location=device, weights_only=False)

        # Import the model class from training script
        sys.path.insert(0, str(SCRIPT_DIR))
        from train_pricing_model_v2 import PricingModelConfig, PricingTransformer

        # Reconstruct config
        saved_config = checkpoint.get("config", {})
        model_config = PricingModelConfig()
        for k, v in saved_config.items():
            if hasattr(model_config, k):
                setattr(model_config, k, v)

        # Build model
        model = PricingTransformer(model_config).to(device)
        model.load_state_dict(checkpoint["model_state_dict"])
        model.eval()

        encoders = checkpoint.get("encoders", config_data.get("encoders", {}))

        print(f"  ✓ Model loaded on {device}")
        print(f"    Params: {sum(p.numel() for p in model.parameters()):,}")
        print(f"    Scaler: mean={scaler['mean']:.4f}, scale={scaler['scale']:.4f}")

        return {
            "model": model,
            "config": model_config,
            "device": device,
            "encoders": encoders,
            "scaler": scaler,
            "word2idx": word2idx,
        }

    except Exception as e:
        print(f"  ✗ Model load failed: {e}")
        import traceback; traceback.print_exc()
        return None


def predict_price(
    model_bundle: Dict,
    category: str,
    supplier: str,
    manufacturer: str,
    description: str,
    part_number: str,
    quantity: int = 1,
    moq: int = 1,
    lead_time: int = 7,
    reliability: float = 0.95,
    complexity: float = 1.0,
    industry: str = "ems_contract",
) -> Optional[float]:
    """Run a single inference through the model."""
    import torch

    model = model_bundle["model"]
    device = model_bundle["device"]
    encoders = model_bundle["encoders"]
    scaler = model_bundle["scaler"]
    word2idx = model_bundle["word2idx"]
    config = model_bundle["config"]

    # Encode categoricals
    cat_id = encoders["category"].get(category, 0)
    sup_id = encoders["supplier"].get(supplier, 0)
    mfr_id = encoders["manufacturer"].get(manufacturer, 0)
    ind_id = encoders["industry"].get(industry, 0)

    # Tokenize text
    text = f"{description} {part_number}".lower()
    tokens = re.findall(r"[a-z0-9]+", text)[:config.max_text_len]
    text_ids = [word2idx.get(t, 1) for t in tokens]
    while len(text_ids) < config.max_text_len:
        text_ids.append(0)

    # Continuous features
    log_qty = math.log1p(quantity)
    log_moq = math.log1p(moq)
    qty_moq_ratio = quantity / max(moq, 1)
    is_above_moq = 1.0 if quantity >= moq else 0.0
    volume_tier = min(4, int(math.log10(max(quantity, 1))))

    continuous = [
        log_qty,
        log_moq,
        lead_time / 30.0,
        reliability,
        complexity,
        qty_moq_ratio / 10.0,
        is_above_moq,
        volume_tier / 4.0,
    ]

    # To tensors
    with torch.no_grad():
        cat_t = torch.tensor([cat_id], dtype=torch.long, device=device)
        sup_t = torch.tensor([sup_id], dtype=torch.long, device=device)
        mfr_t = torch.tensor([mfr_id], dtype=torch.long, device=device)
        ind_t = torch.tensor([ind_id], dtype=torch.long, device=device)
        text_t = torch.tensor([text_ids], dtype=torch.long, device=device)
        cont_t = torch.tensor([continuous], dtype=torch.float32, device=device)

        pred_scaled = model(cat_t, sup_t, mfr_t, ind_t, text_t, cont_t).item()

    # Inverse transform: exp(scaled * scale + mean) - price_floor
    price_floor = scaler.get("price_floor", 0.001)
    log_price = pred_scaled * scaler["scale"] + scaler["mean"]
    log_price = max(-15, min(log_price, 15))  # clamp
    predicted_price = math.exp(log_price) - price_floor
    return max(0.0, predicted_price)


# =============================================================================
# CATEGORY MAPPING (Nexar raw → our taxonomy)
# =============================================================================

def map_to_model_category(nexar_category: str, description: str, our_category_hint: str) -> str:
    """Map from our spot-check category to model category (identity, since we chose them)."""
    return our_category_hint


def map_supplier_name(seller_name: str) -> str:
    """Map Nexar seller names to our supplier taxonomy."""
    name_lower = seller_name.lower()
    if "digi" in name_lower and "key" in name_lower:
        return "digikey"
    if "mouser" in name_lower:
        return "mouser"
    if "arrow" in name_lower:
        return "arrow"
    if "avnet" in name_lower or "farnell" in name_lower:
        return "avnet"
    if "newark" in name_lower:
        return "newark"
    if "alibaba" in name_lower:
        return "alibaba_gold"
    return "broker"


# =============================================================================
# MAIN HARVESTING PIPELINE
# =============================================================================

def harvest_real_prices(digikey: "DigiKeyClient") -> List[Dict]:
    """Query DigiKey API for all spot-check MPNs, collect real prices."""
    harvested = []
    total_mpns = sum(len(v) for v in SPOT_CHECK_MPNS.values())
    processed = 0
    found = 0

    print(f"\n{'='*70}")
    print(f"HARVESTING REAL PRICES — {total_mpns} MPNs across {len(SPOT_CHECK_MPNS)} categories")
    print(f"Source: DigiKey Product Search v4 API")
    print(f"{'='*70}")

    for category, mpn_list in SPOT_CHECK_MPNS.items():
        print(f"\n── {category.upper()} ({len(mpn_list)} parts) ──")

        for mpn, expected_mfr, expected_desc in mpn_list:
            processed += 1
            sys.stdout.write(f"  [{processed}/{total_mpns}] {mpn}... ")
            sys.stdout.flush()

            dk_data = digikey.search_mpn(mpn)

            if dk_data and dk_data.get("unit_price"):
                found += 1

                entry = {
                    "mpn": mpn,
                    "our_category": category,
                    "expected_manufacturer": expected_mfr,
                    "expected_description": expected_desc,
                    "digikey": dk_data,
                    "harvest_time": time.strftime("%Y-%m-%d %H:%M:%S"),
                    "consensus_price": dk_data["unit_price"],
                    "min_price": dk_data.get("min_price", dk_data["unit_price"]),
                    "max_price": dk_data.get("max_price", dk_data["unit_price"]),
                    "price_spread": dk_data.get("max_price", 1) / max(dk_data.get("min_price", 1), 0.001),
                    "api_manufacturer": dk_data.get("manufacturer", ""),
                    "api_description": dk_data.get("description", ""),
                    "stock": dk_data.get("stock", 0),
                    "lifecycle": dk_data.get("lifecycle", ""),
                    "num_price_breaks": dk_data.get("num_price_breaks", 0),
                }

                harvested.append(entry)
                breaks = dk_data.get("num_price_breaks", 0)
                print(f"✓ ${dk_data['unit_price']:.4f} [{breaks} breaks, stock={dk_data.get('stock',0):,}]")
            else:
                print("✗ not found")

    print(f"\n{'─'*40}")
    print(f"Harvested: {found}/{total_mpns} parts ({100*found/max(total_mpns,1):.0f}%)")

    return harvested


# =============================================================================
# SPOT-CHECK PIPELINE
# =============================================================================

def normalize_manufacturer_name(name: str, encoder_names: Dict[str, int]) -> str:
    """Normalize manufacturer name to match encoder entries."""
    # Direct match
    if name in encoder_names:
        return name

    # Case-insensitive match
    name_lower = name.lower().strip()
    for enc_name in encoder_names:
        if enc_name.lower() == name_lower:
            return enc_name

    # Common aliases / abbreviations
    ALIASES = {
        "c&k": "E-Switch",  # closest match in encoder for switch mfrs
        "citizen": "NDK",  # closest crystal mfr
        "hongfa": "Omron",  # closest relay mfr
        "issi": "Micron",  # closest memory mfr
        "sumida": "TDK",  # closest inductor mfr
        "worldsemi": "Inolux",  # closest LED mfr (WS2812B etc)
        "xkb": "Amphenol",  # closest connector mfr
        "diodes incorporated": "Diodes Inc",
        "onsemi": "ON Semiconductor",
        "on semi": "ON Semiconductor",
        "st micro": "STMicroelectronics",
        "stm": "STMicroelectronics",
        "ti": "Texas Instruments",
        "nxp": "NXP Semiconductors",
        "samsung electro-mechanics": "Samsung Electro-Mechanics",
        "tdk corporation": "TDK",
        "te connectivity": "TE Connectivity",
        "wurth elektronik": "Wurth",
        "würth elektronik": "Wurth",
    }

    for alias, canonical in ALIASES.items():
        if name_lower == alias.lower() and canonical in encoder_names:
            return canonical

    # Fuzzy: check if any encoder name starts with or contains the name
    for enc_name in encoder_names:
        if enc_name.lower().startswith(name_lower) or name_lower.startswith(enc_name.lower()):
            return enc_name

    return name  # fallback — will map to index 0


def run_spot_check(harvested: List[Dict], model_bundle: Dict) -> Dict:
    """Compare model predictions against real API prices.
    
    Uses DigiKey qty=1 predictions ONLY for the primary accuracy metric,
    since we have real DigiKey prices. Multi-supplier/qty comparisons
    are shown separately as supplementary info.
    """
    print(f"\n{'='*70}")
    print(f"SPOT-CHECK: Model Predictions vs Real API Prices")
    print(f"{'='*70}")

    results = []
    encoder_names = model_bundle["encoders"].get("manufacturer", {})

    for entry in harvested:
        if not entry.get("consensus_price"):
            continue

        real_price = entry["consensus_price"]
        category = entry["our_category"]
        mpn = entry["mpn"]
        mfr_raw = entry["expected_manufacturer"]
        mfr = normalize_manufacturer_name(mfr_raw, encoder_names)
        desc = entry["expected_description"]

        # Primary: DigiKey qty=1 — direct apples-to-apples comparison
        predicted = predict_price(
            model_bundle,
            category=category,
            supplier="digikey",
            manufacturer=mfr,
            description=desc,
            part_number=mpn,
            quantity=1,
            moq=1,
            lead_time=5,
            reliability=0.97,
            industry="ems_contract",
        )

        if predicted is not None and predicted > 0:
            error = abs(predicted - real_price)
            pct_error = error / max(real_price, 0.001) * 100

            results.append({
                "mpn": mpn,
                "category": category,
                "manufacturer": mfr,
                "manufacturer_raw": mfr_raw,
                "quantity": 1,
                "supplier": "digikey",
                "real_price": real_price,
                "adjusted_real": real_price,
                "predicted": predicted,
                "abs_error": error,
                "pct_error": pct_error,
                "log_ratio": math.log(predicted / max(real_price, 0.001)),
            })

    if not results:
        print("  No results to analyze!")
        return {}

    # ── Analysis ──
    pct_errors = [r["pct_error"] for r in results]
    abs_errors = [r["abs_error"] for r in results]
    log_ratios = [r["log_ratio"] for r in results]

    print(f"\n  OVERALL METRICS ({len(results)} predictions):")
    print(f"  ├─ Median APE:  {np.median(pct_errors):.1f}%")
    print(f"  ├─ Mean APE:    {np.mean(pct_errors):.1f}%")
    print(f"  ├─ p90 APE:     {np.percentile(pct_errors, 90):.1f}%")
    print(f"  ├─ p95 APE:     {np.percentile(pct_errors, 95):.1f}%")
    print(f"  ├─ Median AE:   ${np.median(abs_errors):.4f}")
    print(f"  ├─ Mean AE:     ${np.mean(abs_errors):.4f}")
    print(f"  ├─ Bias (log):  {np.mean(log_ratios):.3f} ({'over' if np.mean(log_ratios) > 0 else 'under'}-predicting)")
    print(f"  └─ Within 50%:  {100*sum(1 for e in pct_errors if e < 50)/len(pct_errors):.0f}%")

    # ── By Category ──
    print(f"\n  BY CATEGORY:")
    print(f"  {'Category':<22} {'Count':>5} {'MdAPE':>8} {'MeanAPE':>8} {'Bias':>7}")
    print(f"  {'─'*55}")
    cat_results = defaultdict(list)
    for r in results:
        cat_results[r["category"]].append(r)

    cat_summary = {}
    for cat in sorted(cat_results.keys()):
        cat_r = cat_results[cat]
        cat_pct = [r["pct_error"] for r in cat_r]
        cat_bias = np.mean([r["log_ratio"] for r in cat_r])
        direction = "↑" if cat_bias > 0.1 else ("↓" if cat_bias < -0.1 else "─")
        print(f"  {cat:<22} {len(cat_r):>5} {np.median(cat_pct):>7.1f}% {np.mean(cat_pct):>7.1f}% {direction}{abs(cat_bias):.2f}")
        cat_summary[cat] = {
            "count": len(cat_r),
            "mdape": float(np.median(cat_pct)),
            "mean_ape": float(np.mean(cat_pct)),
            "bias": float(cat_bias),
        }

    # ── By Price Tier ──
    print(f"\n  BY PRICE TIER:")
    tiers = [
        ("< $0.01", 0, 0.01),
        ("$0.01-$0.10", 0.01, 0.10),
        ("$0.10-$1.00", 0.10, 1.00),
        ("$1.00-$10.00", 1.00, 10.00),
        ("> $10.00", 10.00, 1e6),
    ]
    print(f"  {'Tier':<16} {'Count':>5} {'MdAPE':>8} {'Bias':>7}")
    print(f"  {'─'*40}")
    tier_summary = {}
    for tier_name, lo, hi in tiers:
        tier_r = [r for r in results if lo <= r["adjusted_real"] < hi]
        if tier_r:
            tier_pct = [r["pct_error"] for r in tier_r]
            tier_bias = np.mean([r["log_ratio"] for r in tier_r])
            print(f"  {tier_name:<16} {len(tier_r):>5} {np.median(tier_pct):>7.1f}% {tier_bias:>+.2f}")
            tier_summary[tier_name] = {"count": len(tier_r), "mdape": float(np.median(tier_pct))}

    # ── Worst Predictions ──
    print(f"\n  TOP 10 WORST PREDICTIONS:")
    print(f"  {'MPN':<28} {'Cat':<18} {'Real':>8} {'Pred':>8} {'Error':>7}")
    print(f"  {'─'*75}")
    worst = sorted(results, key=lambda r: r["pct_error"], reverse=True)[:10]
    for r in worst:
        print(f"  {r['mpn']:<28} {r['category']:<18} ${r['adjusted_real']:>7.4f} ${r['predicted']:>7.4f} {r['pct_error']:>5.0f}%")

    # ── Best Predictions ──
    print(f"\n  TOP 10 BEST PREDICTIONS:")
    print(f"  {'MPN':<28} {'Cat':<18} {'Real':>8} {'Pred':>8} {'Error':>7}")
    print(f"  {'─'*75}")
    best = sorted(results, key=lambda r: r["pct_error"])[:10]
    for r in best:
        print(f"  {r['mpn']:<28} {r['category']:<18} ${r['adjusted_real']:>7.4f} ${r['predicted']:>7.4f} {r['pct_error']:>5.0f}%")

    report = {
        "total_predictions": len(results),
        "overall": {
            "median_ape": float(np.median(pct_errors)),
            "mean_ape": float(np.mean(pct_errors)),
            "p90_ape": float(np.percentile(pct_errors, 90)),
            "p95_ape": float(np.percentile(pct_errors, 95)),
            "median_ae": float(np.median(abs_errors)),
            "mean_ae": float(np.mean(abs_errors)),
            "bias_log": float(np.mean(log_ratios)),
            "within_50pct": float(sum(1 for e in pct_errors if e < 50) / len(pct_errors)),
            "within_100pct": float(sum(1 for e in pct_errors if e < 100) / len(pct_errors)),
        },
        "by_category": cat_summary,
        "by_price_tier": tier_summary,
        "results": results,
    }

    return report


# =============================================================================
# EXPORT HARVESTED DATA AS TRAINING DATA
# =============================================================================

def export_training_data(harvested: List[Dict], output_path: Path) -> int:
    """Convert harvested API data into training format compatible with v2 pipeline."""
    training_samples = []

    # Import domain knowledge from training script
    sys.path.insert(0, str(SCRIPT_DIR))
    from train_pricing_model_v2 import (
        SUPPLIER_TIERS, TIER_MULTIPLIER, COMPONENT_CATEGORIES,
        VOLUME_DISCOUNT_TABLES, CATEGORY_DISCOUNT_TYPE,
        interpolate_discount, _get_manufacturer_tier, INDUSTRY_SEGMENTS,
    )

    for entry in harvested:
        if not entry.get("consensus_price") or entry["consensus_price"] <= 0:
            continue

        category = entry["our_category"]
        mpn = entry["mpn"]
        mfr = entry["expected_manufacturer"]
        desc = entry["expected_description"]
        base_price = entry["consensus_price"]
        mfr_tier = _get_manufacturer_tier(mfr)

        # Also extract Nexar price breaks if available
        nexar = entry.get("nexar", {})
        price_breaks_raw = nexar.get("price_breaks", []) if nexar else []

        # Create samples at multiple quantities using REAL base price
        discount_type = CATEGORY_DISCOUNT_TYPE.get(category, "passive")
        discount_table = VOLUME_DISCOUNT_TABLES[discount_type]

        for qty in [1, 10, 100, 1000, 10000]:
            discount = interpolate_discount(qty, discount_table)

            for supplier_name, supplier_info in [
                ("digikey", SUPPLIER_TIERS["digikey"]),
                ("mouser", SUPPLIER_TIERS["mouser"]),
                ("alibaba_gold", SUPPLIER_TIERS["alibaba_gold"]),
            ]:
                unit_price = base_price * (1.0 - discount) * supplier_info["markup"] * TIER_MULTIPLIER[mfr_tier]
                unit_price = max(0.001, unit_price)

                # 3 noise variants for augmentation
                import random
                for noise in [0.0, random.gauss(0, 0.05), random.gauss(0, 0.08)]:
                    noisy_price = unit_price * math.exp(noise)
                    noisy_price = max(0.0001, round(noisy_price, 6))

                    moq = supplier_info["moq_base"] * random.choice([1, 5, 10])
                    lead_time = int(7 * supplier_info["lead_time_factor"] * random.uniform(0.7, 1.4))

                    industry = random.choice(list(INDUSTRY_SEGMENTS.keys()))
                    ind_info = INDUSTRY_SEGMENTS[industry]

                    training_samples.append({
                        "category": category,
                        "supplier": supplier_name,
                        "manufacturer": mfr,
                        "manufacturer_tier": mfr_tier,
                        "part_number": mpn,
                        "description": desc[:80],
                        "package": "Unknown",
                        "quantity": qty,
                        "moq": moq,
                        "unit_price": noisy_price,
                        "lead_time_days": lead_time,
                        "reliability_score": supplier_info["reliability"],
                        "industry": industry,
                        "complexity_factor": ind_info["complexity"],
                        "source": "api_harvest",
                        "api_base_price": base_price,
                    })

        # Also create samples from actual DigiKey price breaks (gold standard)
        dk_data = entry.get("digikey", {})
        dk_price_breaks = dk_data.get("price_breaks", []) if dk_data else []

        for pb in dk_price_breaks:
            actual_qty = pb.get("quantity", 1)
            actual_price = pb.get("price", 0)
            if actual_price <= 0:
                continue

            industry = "ems_contract"
            ind_info = INDUSTRY_SEGMENTS[industry]

            # DigiKey is always the seller for these
            training_samples.append({
                "category": category,
                "supplier": "digikey",
                "manufacturer": mfr,
                "manufacturer_tier": mfr_tier,
                "part_number": mpn,
                "description": desc[:80],
                "package": "Unknown",
                "quantity": actual_qty,
                "moq": 1,
                "unit_price": round(actual_price, 6),
                "lead_time_days": 5,
                "reliability_score": SUPPLIER_TIERS["digikey"]["reliability"],
                "industry": industry,
                "complexity_factor": ind_info["complexity"],
                "source": "api_actual_price_break",
                "api_base_price": base_price,
            })

    # Save
    output_path.parent.mkdir(parents=True, exist_ok=True)
    with open(output_path, "w") as f:
        json.dump(training_samples, f, indent=2)

    print(f"\n  ✓ Exported {len(training_samples)} training samples to {output_path}")
    return len(training_samples)


# =============================================================================
# MAIN
# =============================================================================

def main():
    parser = argparse.ArgumentParser(description="Harvest real prices & spot-check model")
    parser.add_argument("--harvest-only", action="store_true", help="Only harvest, skip model")
    parser.add_argument("--spotcheck-only", action="store_true", help="Spot-check from saved harvest")
    parser.add_argument("--export-training", action="store_true", help="Export harvested data as training samples")
    parser.add_argument("--max-mpns", type=int, default=0, help="Limit MPNs per category (0=all)")
    args = parser.parse_args()

    HARVEST_DIR.mkdir(parents=True, exist_ok=True)
    harvest_file = HARVEST_DIR / "harvested_prices.json"
    report_file = HARVEST_DIR / "spotcheck_report.json"
    training_file = HARVEST_DIR / "api_training_data.json"

    print("=" * 70)
    print("REAL DATA HARVESTING + MODEL SPOT-CHECK")
    print("=" * 70)

    # ── Step 1: Harvest (unless --spotcheck-only) ──
    if not args.spotcheck_only:
        env = load_env()

        dk_id = env.get("DIGIKEY_CLIENT_ID", "")
        dk_secret = env.get("DIGIKEY_CLIENT_SECRET", "")

        if not dk_id or not dk_secret:
            print("  ✗ DIGIKEY_CLIENT_ID / DIGIKEY_CLIENT_SECRET not found in .env")
            sys.exit(1)

        digikey = DigiKeyClient(dk_id, dk_secret)
        if not digikey.authenticate():
            print("  ✗ DigiKey authentication failed")
            sys.exit(1)

        # Optionally limit MPNs
        if args.max_mpns > 0:
            global SPOT_CHECK_MPNS
            SPOT_CHECK_MPNS = {
                cat: mpns[:args.max_mpns]
                for cat, mpns in SPOT_CHECK_MPNS.items()
            }

        harvested = harvest_real_prices(digikey)

        # Save harvest
        with open(harvest_file, "w") as f:
            json.dump(harvested, f, indent=2, default=str)
        print(f"\n  ✓ Harvest saved: {harvest_file}")
        print(f"    {len(harvested)} components with pricing")

    else:
        # Load existing harvest
        if not harvest_file.exists():
            print(f"  ✗ No harvest found at {harvest_file}. Run without --spotcheck-only first.")
            sys.exit(1)
        with open(harvest_file) as f:
            harvested = json.load(f)
        print(f"  ✓ Loaded {len(harvested)} harvested components from disk")

    # ── Step 2: Export training data (only when explicitly requested or harvesting new data) ──
    if args.export_training:
        n_exported = export_training_data(harvested, training_file)

    # ── Step 3: Spot-check against model ──
    if not args.harvest_only:
        print(f"\nLoading trained model for spot-check...")
        model_bundle = load_model_for_inference(MODELS_DIR)

        if model_bundle:
            report = run_spot_check(harvested, model_bundle)

            with open(report_file, "w") as f:
                json.dump(report, f, indent=2, default=str)
            print(f"\n  ✓ Report saved: {report_file}")

            # ── Recommendations ──
            print(f"\n{'='*70}")
            print("RECOMMENDATIONS")
            print(f"{'='*70}")

            overall = report.get("overall", {})
            mdape = overall.get("median_ape", 999)
            bias = overall.get("bias_log", 0)
            within50 = overall.get("within_50pct", 0)

            if mdape < 30:
                print("  ✅ Model accuracy is GOOD on real data (MdAPE < 30%)")
            elif mdape < 60:
                print("  ⚠ Model accuracy is MODERATE on real data (MdAPE 30-60%)")
                print("  → Consider retraining with harvested real data")
            else:
                print("  ❌ Model accuracy is POOR on real data (MdAPE > 60%)")
                print("  → STRONGLY recommend retraining with harvested real data")

            if abs(bias) > 0.3:
                direction = "over" if bias > 0 else "under"
                print(f"  ⚠ Systematic {direction}-prediction bias of {abs(bias):.2f}")
                print(f"    → Adjust price_scaler mean or add bias correction")

            # Find worst categories
            cat_summary = report.get("by_category", {})
            worst_cats = sorted(cat_summary.items(), key=lambda x: x[1].get("mdape", 0), reverse=True)
            if worst_cats:
                print(f"\n  Weakest categories (need more training data):")
                for cat, stats in worst_cats[:5]:
                    if stats["mdape"] > 40:
                        print(f"    • {cat}: MdAPE={stats['mdape']:.0f}%, bias={stats['bias']:+.2f}")

            print(f"\n  NEXT STEPS:")
            print(f"  1. Retrain with --extra-data {training_file}")
            print(f"  2. Focus augmentation on weak categories above")
            print(f"  3. Re-run this script to validate improvement")

        else:
            print("  ⚠ Could not load model — skipping spot-check")
            print("    Run training first: python train_pricing_model_v2.py --use-huggingface")


if __name__ == "__main__":
    main()
