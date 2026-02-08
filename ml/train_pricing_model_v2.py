#!/usr/bin/env python3
"""
Electronics Component Pricing Model v2 — Training Pipeline
============================================================
Major improvements over v1:
  1. Removed garbage proxy datasets (Nigerian retail, Amazon consumer products)
  2. Conservative Nexar augmentation (no explosive cross-product)
  3. Vastly improved synthetic data with realistic pricing curves
  4. Mixed-precision training (AMP) for RTX 4050 Tensor Cores
  5. Disk checkpoint saving every N epochs + best model
  6. Smaller model (less overfitting) with stronger regularization
  7. Gradient accumulation for effective larger batches
  8. Proper noise injection (5-15% vs old 1.5%)
  9. Log-normal price distributions matching real component markets
 10. Supplier-correlated lead times and MOQs
"""

import argparse
import json
import math
import os
import random
import sys
import time
import warnings
from collections import Counter
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

import numpy as np
import torch
import torch.nn as nn
import torch.nn.functional as F
from torch.utils.data import DataLoader, Dataset

# ── Deterministic seeding for reproducibility ──
SEED = 42
random.seed(SEED)
np.random.seed(SEED)
torch.manual_seed(SEED)
if torch.cuda.is_available():
    torch.cuda.manual_seed_all(SEED)
    torch.backends.cudnn.deterministic = True
    torch.backends.cudnn.benchmark = False

# Optional imports
try:
    from sklearn.preprocessing import StandardScaler
    from sklearn.model_selection import KFold
    from sklearn.metrics import mean_absolute_error, mean_squared_error, r2_score, mean_absolute_percentage_error
    from scipy import stats
    HAS_SKLEARN = True
except ImportError:
    HAS_SKLEARN = False
    print("Warning: scikit-learn not available. Some features will be disabled.")

try:
    from datasets import load_dataset
    HAS_DATASETS = True
except ImportError:
    HAS_DATASETS = False
    print("Warning: HuggingFace datasets not available.")

try:
    import onnx
    HAS_ONNX = True
except ImportError:
    HAS_ONNX = False
    print("Warning: ONNX not available. Model will not be exported to ONNX.")

warnings.filterwarnings('ignore', category=FutureWarning)
warnings.filterwarnings('ignore', category=UserWarning, module='torch')


# =============================================================================
# CONFIGURATION
# =============================================================================

@dataclass
class PricingModelConfig:
    """Model and training configuration — tuned for electronics pricing."""

    # Architecture — smaller to reduce overfitting on limited real data
    embedding_dim: int = 128
    hidden_dim: int = 256
    num_attention_heads: int = 4
    num_transformer_layers: int = 2        # 4→2: text is short ("CAP 0402")
    vocab_size: int = 15000
    max_text_len: int = 48                 # 64→48: part descriptions are short
    num_continuous_features: int = 8
    dropout: float = 0.20                  # 0.15→0.20: stronger regularization
    attention_dropout: float = 0.15        # 0.1→0.15

    # Training — tuned for ~200K samples
    learning_rate: float = 3e-4            # 5e-5→3e-4: higher with cosine warmup
    weight_decay: float = 0.05             # 0.01→0.05: stronger L2 reg
    batch_size: int = 256                  # 64→256: more stable gradients
    num_epochs: int = 120
    warmup_epochs: int = 4
    patience: int = 25                     # more patience with larger LR swings
    grad_accum_steps: int = 2              # effective batch = 512
    use_amp: bool = True                   # mixed precision (bfloat16)

    # Checkpointing
    checkpoint_every: int = 5              # save to disk every N epochs
    keep_last_n_checkpoints: int = 3       # keep only last N checkpoints

    # Data counts (updated dynamically)
    num_categories: int = 25
    num_suppliers: int = 10
    num_manufacturers: int = 50
    num_industries: int = 8

    # Validation
    n_folds: int = 5
    val_split: float = 0.10


# =============================================================================
# DOMAIN KNOWLEDGE — Electronics Components
# =============================================================================

# Base price distributions: (median_usd, sigma_log) for log-normal sampling
# Calibrated against DigiKey/Mouser 2024-2025 catalog data
# sigma = std-dev of ln(price) WITHIN a (category, package, mfr_tier) group
#   0.30 → 95% CI spans ~3× range (tight, commodity passives)
#   0.45 → 95% CI spans ~6× range (moderate, ICs)
#   0.55 → 95% CI spans ~9× range (wide, custom assemblies)
COMPONENT_CATEGORIES = {
    'capacitor':         {'median_price': 0.02,  'sigma': 0.30, 'volume_sensitivity': 0.45, 'base_multiplier': 1.0},
    'resistor':          {'median_price': 0.006, 'sigma': 0.35, 'volume_sensitivity': 0.50, 'base_multiplier': 0.8},
    'inductor':          {'median_price': 0.08,  'sigma': 0.35, 'volume_sensitivity': 0.40, 'base_multiplier': 1.2},
    'ic_microcontroller':{'median_price': 3.50,  'sigma': 0.50, 'volume_sensitivity': 0.25, 'base_multiplier': 3.0},
    'ic_memory':         {'median_price': 2.00,  'sigma': 0.45, 'volume_sensitivity': 0.28, 'base_multiplier': 2.5},
    'ic_analog':         {'median_price': 1.20,  'sigma': 0.50, 'volume_sensitivity': 0.30, 'base_multiplier': 2.0},
    'ic_power':          {'median_price': 1.60,  'sigma': 0.50, 'volume_sensitivity': 0.30, 'base_multiplier': 2.2},
    'ic_logic':          {'median_price': 0.22,  'sigma': 0.45, 'volume_sensitivity': 0.35, 'base_multiplier': 1.5},
    'connector':         {'median_price': 0.45,  'sigma': 0.50, 'volume_sensitivity': 0.35, 'base_multiplier': 1.8},
    'switch':            {'median_price': 0.35,  'sigma': 0.45, 'volume_sensitivity': 0.35, 'base_multiplier': 1.3},
    'relay':             {'median_price': 2.80,  'sigma': 0.50, 'volume_sensitivity': 0.25, 'base_multiplier': 2.5},
    'transformer':       {'median_price': 4.00,  'sigma': 0.45, 'volume_sensitivity': 0.20, 'base_multiplier': 3.0},
    'crystal_oscillator':{'median_price': 0.60,  'sigma': 0.45, 'volume_sensitivity': 0.30, 'base_multiplier': 1.5},
    'led':               {'median_price': 0.10,  'sigma': 0.50, 'volume_sensitivity': 0.45, 'base_multiplier': 0.9},
    'diode':             {'median_price': 0.04,  'sigma': 0.40, 'volume_sensitivity': 0.40, 'base_multiplier': 1.0},
    'transistor':        {'median_price': 0.04,  'sigma': 0.50, 'volume_sensitivity': 0.40, 'base_multiplier': 1.1},
    'mosfet':            {'median_price': 1.20,  'sigma': 0.55, 'volume_sensitivity': 0.30, 'base_multiplier': 1.8},
    'igbt':              {'median_price': 5.00,  'sigma': 0.40, 'volume_sensitivity': 0.20, 'base_multiplier': 4.0},
    'sensor':            {'median_price': 2.80,  'sigma': 0.55, 'volume_sensitivity': 0.25, 'base_multiplier': 3.0},
    'wire_harness':      {'median_price': 25.00, 'sigma': 0.55, 'volume_sensitivity': 0.35, 'base_multiplier': 5.0},
    'cable_assembly':    {'median_price': 12.00, 'sigma': 0.50, 'volume_sensitivity': 0.35, 'base_multiplier': 4.0},
    'pcb_bare':          {'median_price': 8.00,  'sigma': 0.50, 'volume_sensitivity': 0.40, 'base_multiplier': 3.5},
    'pcba_assembly':     {'median_price': 35.00, 'sigma': 0.55, 'volume_sensitivity': 0.30, 'base_multiplier': 6.0},
    'mechanical_part':   {'median_price': 3.00,  'sigma': 0.55, 'volume_sensitivity': 0.35, 'base_multiplier': 2.5},
    'machined_part':     {'median_price': 15.00, 'sigma': 0.50, 'volume_sensitivity': 0.30, 'base_multiplier': 4.0},
}

# Supplier characteristics — realistic markup/reliability/lead-time profiles
SUPPLIER_TIERS = {
    'digikey':            {'markup': 1.00, 'reliability': 0.98, 'lead_time_factor': 1.0,  'moq_base': 1},
    'mouser':             {'markup': 0.98, 'reliability': 0.97, 'lead_time_factor': 1.0,  'moq_base': 1},
    'arrow':              {'markup': 0.88, 'reliability': 0.95, 'lead_time_factor': 1.3,  'moq_base': 25},
    'avnet':              {'markup': 0.90, 'reliability': 0.95, 'lead_time_factor': 1.2,  'moq_base': 10},
    'newark':             {'markup': 0.95, 'reliability': 0.93, 'lead_time_factor': 1.1,  'moq_base': 1},
    'alibaba_gold':       {'markup': 0.50, 'reliability': 0.80, 'lead_time_factor': 3.0,  'moq_base': 500},
    'alibaba_verified':   {'markup': 0.55, 'reliability': 0.85, 'lead_time_factor': 2.5,  'moq_base': 200},
    'alibaba_standard':   {'markup': 0.42, 'reliability': 0.70, 'lead_time_factor': 3.5,  'moq_base': 1000},
    'direct_manufacturer':{'markup': 0.60, 'reliability': 0.90, 'lead_time_factor': 2.0,  'moq_base': 1000},
    'broker':             {'markup': 1.15, 'reliability': 0.75, 'lead_time_factor': 0.8,  'moq_base': 1},
}

MANUFACTURER_TIERS = {
    'tier1': ['Texas Instruments', 'Analog Devices', 'Microchip', 'STMicroelectronics',
              'NXP Semiconductors', 'Infineon', 'ON Semiconductor', 'Renesas',
              'Maxim Integrated', 'Broadcom'],
    'tier2': ['Vishay', 'Murata', 'TDK', 'YAGEO', 'Samsung Electro-Mechanics',
              'Taiyo Yuden', 'Panasonic', 'Nichicon', 'KEMET', 'AVX',
              'Bourns', 'Littelfuse', 'Rohm', 'Nexperia'],
    'tier3': ['Shenzhen Generic', 'Unbranded', 'White Label', 'OEM China',
              'HuaQiang', 'Sunlord', 'Fenghua', 'Cjiang'],
}

TIER_MULTIPLIER = {'tier1': 1.30, 'tier2': 1.00, 'tier3': 0.55}

INDUSTRY_SEGMENTS = {
    'ems_contract':    {'complexity': 1.0,  'volume_typical': 10000},
    'automotive':      {'complexity': 1.5,  'volume_typical': 50000},
    'aerospace':       {'complexity': 2.0,  'volume_typical': 500},
    'medical':         {'complexity': 1.8,  'volume_typical': 2000},
    'consumer':        {'complexity': 0.8,  'volume_typical': 100000},
    'industrial':      {'complexity': 1.2,  'volume_typical': 5000},
    'telecom':         {'complexity': 1.3,  'volume_typical': 20000},
    'military':        {'complexity': 2.2,  'volume_typical': 200},
}

# Realistic volume discount break tables (qty → discount_pct off list)
# Different curves for passives vs actives vs assemblies
VOLUME_DISCOUNT_TABLES = {
    'passive': {     # capacitors, resistors, inductors, LEDs, diodes
        1: 0.00, 10: 0.08, 25: 0.15, 100: 0.28, 250: 0.35,
        500: 0.40, 1000: 0.48, 2500: 0.53, 5000: 0.58,
        10000: 0.62, 25000: 0.66, 50000: 0.70, 100000: 0.74,
    },
    'active': {      # ICs, MCUs, FPGAs
        1: 0.00, 10: 0.05, 25: 0.10, 100: 0.18, 250: 0.24,
        500: 0.30, 1000: 0.36, 2500: 0.41, 5000: 0.45,
        10000: 0.49, 25000: 0.52, 50000: 0.55, 100000: 0.58,
    },
    'electromech': {  # connectors, switches, relays, transformers
        1: 0.00, 10: 0.06, 25: 0.12, 100: 0.22, 250: 0.28,
        500: 0.33, 1000: 0.38, 2500: 0.43, 5000: 0.47,
        10000: 0.51, 25000: 0.54, 50000: 0.57, 100000: 0.60,
    },
    'assembly': {     # wire harness, PCBA, cable assembly, machined
        1: 0.00, 10: 0.10, 25: 0.18, 100: 0.30, 250: 0.38,
        500: 0.44, 1000: 0.50, 2500: 0.55, 5000: 0.59,
        10000: 0.63, 25000: 0.66, 50000: 0.69, 100000: 0.72,
    },
}

# Map categories to discount curve type
CATEGORY_DISCOUNT_TYPE = {
    'capacitor': 'passive', 'resistor': 'passive', 'inductor': 'passive',
    'led': 'passive', 'diode': 'passive', 'transistor': 'passive',
    'ic_microcontroller': 'active', 'ic_memory': 'active', 'ic_analog': 'active',
    'ic_power': 'active', 'ic_logic': 'active', 'mosfet': 'active', 'igbt': 'active',
    'sensor': 'active', 'crystal_oscillator': 'active',
    'connector': 'electromech', 'switch': 'electromech', 'relay': 'electromech',
    'transformer': 'electromech',
    'wire_harness': 'assembly', 'cable_assembly': 'assembly', 'pcb_bare': 'assembly',
    'pcba_assembly': 'assembly', 'mechanical_part': 'assembly', 'machined_part': 'assembly',
}

# Package types with size-correlated price adjustments
PACKAGES = {
    '0201': 0.90, '0402': 0.95, '0603': 1.00, '0805': 1.05, '1206': 1.10,
    '1210': 1.15, '2010': 1.20, '2512': 1.25,
    'SOT23': 1.0, 'SOT223': 1.1, 'SOT89': 1.05,
    'SOIC8': 1.0, 'SOIC16': 1.15, 'SSOP': 1.1, 'TSSOP': 1.1,
    'QFN16': 1.1, 'QFN32': 1.2, 'QFN48': 1.3,
    'QFP44': 1.2, 'QFP64': 1.3, 'QFP100': 1.5, 'TQFP': 1.25,
    'BGA': 1.5, 'WLCSP': 1.6,
    'DIP8': 0.9, 'DIP14': 0.95, 'DIP16': 1.0,
    'TO220': 1.1, 'TO92': 0.9, 'TO252': 1.05, 'TO263': 1.15,
    'PLCC': 1.15, 'custom': 1.3, 'Module': 1.5,
}


def interpolate_discount(qty: int, table: dict) -> float:
    """Interpolate volume discount from break table using log-linear interpolation."""
    breaks = sorted(table.keys())
    if qty <= breaks[0]:
        return table[breaks[0]]
    if qty >= breaks[-1]:
        return table[breaks[-1]]

    for i in range(len(breaks) - 1):
        if breaks[i] <= qty <= breaks[i + 1]:
            lo_q, hi_q = breaks[i], breaks[i + 1]
            lo_d, hi_d = table[lo_q], table[hi_q]
            # Log-linear interpolation (quantities are log-spaced)
            t = math.log(qty / lo_q) / math.log(hi_q / lo_q) if hi_q > lo_q else 0
            return lo_d + t * (hi_d - lo_d)

    return table[breaks[-1]]


# =============================================================================
# DATA ACQUISITION — HuggingFace (Nexar + E-commerce only)
# =============================================================================

def download_huggingface_datasets() -> Dict[str, Any]:
    """Download ONLY high-quality datasets. No garbage proxies."""
    datasets_info = {}

    # 1. Nexar/Octopart — REAL electronics component pricing (791 components)
    print("  Downloading Nexar electronic components supply chain data...")
    try:
        ds = load_dataset("mdnh/electronic-components-supply-chain", split="train")
        datasets_info['nexar'] = ds
        print(f"    ✓ Nexar: {len(ds)} real electronic components")
    except Exception as e:
        print(f"    ✗ Nexar download failed: {e}")

    # 2. E-commerce pricing — used ONLY for price regression signal training
    print("  Downloading e-commerce pricing data...")
    try:
        ds = load_dataset("guyshilo12/synthetic-ecommerce-price-estimation", split="train")
        datasets_info['ecommerce'] = ds
        print(f"    ✓ E-commerce: {len(ds)} samples")
    except Exception as e:
        print(f"    ✗ E-commerce download failed: {e}")

    return datasets_info


# =============================================================================
# REAL DATA EXTRACTION — Conservative augmentation
# =============================================================================

def extract_real_pricing_data(datasets_info: Dict) -> List[Dict]:
    """
    Extract real pricing data with CONSERVATIVE augmentation.
    
    v1 problem: 791 components × 16 qty × 10 suppliers × 3 industries = 388K
    v2 fix: 791 components × 5 qty × 3 suppliers × 1 industry = ~12K real-anchored
    Plus noise jitter variants = ~36K total from real data
    """
    extracted_data = []

    # --- Source 1: Nexar/Octopart (REAL electronics data) ---
    if 'nexar' in datasets_info:
        nexar_ds = datasets_info['nexar']
        nexar_samples = 0

        # Only 5 representative quantity breaks (not 16)
        qty_breaks = [1, 10, 100, 1000, 10000]
        # Only 3 representative suppliers per sample (not all 10)
        representative_suppliers = ['digikey', 'mouser', 'alibaba_gold']

        for item in nexar_ds:
            try:
                # Extract base price from suppliers list
                # Nexar schema: suppliers = [{'name': 'DigiKey', 'price': 1.6, ...}, ...]
                base_price = None
                suppliers_data = item.get('suppliers')
                if suppliers_data and isinstance(suppliers_data, list):
                    valid_prices = []
                    for s in suppliers_data:
                        if isinstance(s, dict) and s.get('price') is not None:
                            try:
                                p = float(s['price'])
                                if 0 < p < 10000:
                                    valid_prices.append(p)
                            except (ValueError, TypeError):
                                pass
                    if valid_prices:
                        base_price = float(np.median(valid_prices))

                # Fallback: check nexar_data for best_price_usd
                if base_price is None:
                    nexar_extra = item.get('nexar_data')
                    if isinstance(nexar_extra, dict) and nexar_extra.get('best_price_usd'):
                        try:
                            base_price = float(nexar_extra['best_price_usd'])
                        except (ValueError, TypeError):
                            pass

                if base_price is None or base_price <= 0 or base_price > 10000:
                    continue

                # Determine category
                cat = (item.get('category', '') or '').lower().strip()
                category = _map_nexar_category(cat, item.get('description', ''))

                # Determine manufacturer tier
                mfr_name = item.get('manufacturer', '') or 'Unknown'
                mfr_tier = _get_manufacturer_tier(mfr_name)

                part_number = item.get('mpn', '') or item.get('part_number', '') or f"NXR{random.randint(10000,99999)}"
                description = (item.get('description', '') or item.get('short_description', '') or '')[:80]
                package = item.get('package', '') or item.get('case_package', '') or 'Unknown'

                comp_info = COMPONENT_CATEGORIES[category]
                discount_type = CATEGORY_DISCOUNT_TYPE[category]
                discount_table = VOLUME_DISCOUNT_TABLES[discount_type]

                # Generate samples: 5 qty × 3 suppliers × 3 noise variants = 45 per component
                for qty in qty_breaks:
                    discount = interpolate_discount(qty, discount_table)

                    for supplier in representative_suppliers:
                        supplier_info = SUPPLIER_TIERS[supplier]

                        # Base calculation
                        unit_price = base_price * (1.0 - discount) * supplier_info['markup'] * TIER_MULTIPLIER[mfr_tier]

                        industry = random.choice(list(INDUSTRY_SEGMENTS.keys()))
                        ind_info = INDUSTRY_SEGMENTS[industry]

                        # 3 noise variants per combination
                        for noise_level in [0.0, random.gauss(0, 0.08), random.gauss(0, 0.12)]:
                            noisy_price = unit_price * math.exp(noise_level)  # log-normal noise
                            noisy_price = max(0.0001, round(noisy_price, 6))

                            moq = supplier_info['moq_base'] * random.choice([1, 5, 10])
                            lead_time = int(7 * supplier_info['lead_time_factor'] * random.uniform(0.7, 1.4))

                            extracted_data.append({
                                'category': category,
                                'supplier': supplier,
                                'manufacturer': mfr_name,
                                'manufacturer_tier': mfr_tier,
                                'part_number': part_number,
                                'description': description,
                                'package': package,
                                'quantity': qty,
                                'moq': moq,
                                'unit_price': noisy_price,
                                'lead_time_days': lead_time,
                                'reliability_score': supplier_info['reliability'],
                                'industry': industry,
                                'complexity_factor': ind_info['complexity'],
                            })
                            nexar_samples += 1

            except Exception:
                continue

        print(f"  ✓ Nexar: {nexar_samples} real-anchored samples (from {len(nexar_ds)} components)")

    # --- Source 2: E-commerce (light adaptation for price regression signal) ---
    if 'ecommerce' in datasets_info:
        ecommerce_ds = datasets_info['ecommerce']
        ecom_samples = 0

        # Simple mapping: use e-commerce data to add price regression variety
        # Actual dataset fields: category, features, fair_price, generated_text
        ecom_category_map = {
            'Gaming Laptop': 'ic_microcontroller',
            'Smartphone': 'ic_memory',
            'Wireless Earbuds': 'sensor',
            'Smartwatch': 'ic_analog',
            'Smart TV': 'ic_logic',
            'Tablet': 'ic_memory',
        }

        for item in ecommerce_ds:
            try:
                product_cat = item.get('category', '')
                if product_cat not in ecom_category_map:
                    continue

                category = ecom_category_map[product_cat]
                base_price = float(item.get('fair_price', 0))
                if base_price <= 0:
                    continue

                # Scale to electronics range: $500 laptop → $5.00 IC
                base_price *= 0.01
                base_price = max(0.10, min(base_price, 100.0))

                supplier = random.choice(['digikey', 'mouser', 'arrow'])
                supplier_info = SUPPLIER_TIERS[supplier]
                mfr_tier = random.choices(['tier1', 'tier2', 'tier3'], weights=[0.3, 0.5, 0.2])[0]

                for qty in [1, 100, 1000]:
                    discount_table = VOLUME_DISCOUNT_TABLES[CATEGORY_DISCOUNT_TYPE[category]]
                    discount = interpolate_discount(qty, discount_table)
                    unit_price = base_price * (1.0 - discount) * supplier_info['markup'] * TIER_MULTIPLIER[mfr_tier]
                    unit_price *= math.exp(random.gauss(0, 0.10))  # 10% noise
                    unit_price = max(0.01, round(unit_price, 6))

                    industry = random.choice(list(INDUSTRY_SEGMENTS.keys()))
                    moq = supplier_info['moq_base'] * random.choice([1, 5, 10])

                    extracted_data.append({
                        'category': category,
                        'supplier': supplier,
                        'manufacturer': random.choice(MANUFACTURER_TIERS[mfr_tier]),
                        'manufacturer_tier': mfr_tier,
                        'part_number': f"ECM{random.randint(10000, 99999)}",
                        'description': (item.get('generated_text', '') or '')[:60],
                        'package': random.choice(['QFN32', 'SOIC8', 'BGA', 'TQFP']),
                        'quantity': qty,
                        'moq': moq,
                        'unit_price': unit_price,
                        'lead_time_days': int(7 * supplier_info['lead_time_factor'] * random.uniform(0.8, 1.3)),
                        'reliability_score': supplier_info['reliability'],
                        'industry': industry,
                        'complexity_factor': INDUSTRY_SEGMENTS[industry]['complexity'],
                    })
                    ecom_samples += 1

            except Exception:
                continue

        print(f"  ✓ E-commerce: {ecom_samples} adapted samples")

    print(f"\n  TOTAL real/adapted: {len(extracted_data)} pricing samples")
    return extracted_data


def _map_nexar_category(cat_str: str, desc_str: str = '') -> str:
    """Map Nexar category strings to our category taxonomy."""
    cat_str = cat_str.lower()
    desc_str = (desc_str or '').lower()
    combined = f"{cat_str} {desc_str}"

    mapping = [
        (['capacitor', 'cap ', 'mlcc'], 'capacitor'),
        (['resistor', 'res '], 'resistor'),
        (['inductor', 'coil', 'choke'], 'inductor'),
        (['microcontroller', 'mcu', 'stm32', 'esp32', 'pic', 'atmega'], 'ic_microcontroller'),
        (['memory', 'sram', 'dram', 'flash', 'eeprom', 'rom'], 'ic_memory'),
        (['analog', 'op-amp', 'opamp', 'adc', 'dac', 'comparator'], 'ic_analog'),
        (['power', 'regulator', 'ldo', 'dc-dc', 'buck', 'boost'], 'ic_power'),
        (['logic', 'gate', 'flip-flop', 'counter', 'buffer'], 'ic_logic'),
        (['connector', 'header', 'socket', 'plug', 'jack'], 'connector'),
        (['switch', 'button', 'toggle'], 'switch'),
        (['relay'], 'relay'),
        (['transformer', 'xfmr'], 'transformer'),
        (['crystal', 'oscillator', 'xtal', 'resonator'], 'crystal_oscillator'),
        (['led', 'light emitting'], 'led'),
        (['diode', 'zener', 'schottky', 'rectifier'], 'diode'),
        (['transistor', 'bjt', 'npn', 'pnp'], 'transistor'),
        (['mosfet', 'fet'], 'mosfet'),
        (['igbt'], 'igbt'),
        (['sensor', 'accelerometer', 'gyro', 'temperature', 'pressure', 'humidity'], 'sensor'),
        (['wire harness', 'harness'], 'wire_harness'),
        (['cable', 'assembly'], 'cable_assembly'),
        (['pcb'], 'pcb_bare'),
        (['pcba'], 'pcba_assembly'),
    ]

    for keywords, category in mapping:
        if any(kw in combined for kw in keywords):
            return category

    # Default: random passive/active
    return random.choice(['capacitor', 'resistor', 'ic_analog', 'connector'])


def _get_manufacturer_tier(mfr_name: str) -> str:
    """Determine manufacturer tier from name."""
    mfr_lower = mfr_name.lower()
    for tier, names in MANUFACTURER_TIERS.items():
        if any(n.lower() in mfr_lower for n in names):
            return tier
    # Heuristic: known western = tier2, unknown = tier3
    western_indicators = ['semi', 'tech', 'electronic', 'corp', 'inc', 'ltd']
    if any(w in mfr_lower for w in western_indicators):
        return 'tier2'
    return random.choices(['tier2', 'tier3'], weights=[0.3, 0.7])[0]


# =============================================================================
# SYNTHETIC DATA — Vastly improved realism
# =============================================================================

def generate_synthetic_electronics_data(n_samples: int = 200000) -> List[Dict]:
    """
    Generate high-quality synthetic electronics pricing data.

    Improvements over v1:
    - Log-normal price distributions (not uniform) — matches real markets
    - Realistic volume discount tables with interpolation (not formula)
    - Package-size correlated pricing
    - Supplier/manufacturer interaction effects
    - Proper noise levels (5-15%, not 1.5%)
    - Correlated features (lead time ~ supplier, MOQ ~ supplier × tier)
    """
    data = []

    # Part number prefix patterns by category
    part_prefixes = {
        'capacitor': ['GRM', 'CL', 'C0G', 'X7R', 'CC', 'VJ', 'GCM', 'EMK'],
        'resistor': ['RC', 'ERJ', 'CRCW', 'RK', 'RR', 'CR', 'WR'],
        'inductor': ['LQH', 'SLF', 'NR', 'CDRH', 'MLF', 'BRL'],
        'ic_microcontroller': ['STM32', 'PIC', 'ATMEGA', 'ESP32', 'NRF52', 'RP2040', 'SAMD'],
        'ic_memory': ['W25Q', 'AT24', 'IS62', 'CY62', 'MT41', 'S25FL', 'MX25'],
        'ic_analog': ['LM', 'AD', 'MCP', 'OPA', 'INA', 'TLV', 'ADS'],
        'ic_power': ['LM78', 'TPS', 'LTC', 'MP', 'RT', 'NCV', 'AP'],
        'ic_logic': ['SN74', 'CD40', 'NC7', 'HEF', 'MC14'],
        'connector': ['JST', 'MOLEX', 'TE', 'HIROSE', 'AMPHENOL', 'FCI'],
        'switch': ['SW', 'TS', 'EVQ', 'TL', 'B3F'],
        'relay': ['G5V', 'G6K', 'HF', 'JQC', 'SRD'],
        'transformer': ['750', 'LPR', 'PA', 'EE', 'WE'],
        'crystal_oscillator': ['ABM', 'NX', 'FA', 'TSX', 'DST'],
        'led': ['LNJ', 'IN', 'WS2812', 'CREE', 'LM', 'LTST'],
        'diode': ['1N', 'BAT', 'BAS', 'SS', 'MBR', 'ES'],
        'transistor': ['2N', 'BC', 'MMBT', 'BSS', 'FDN'],
        'mosfet': ['IRF', 'SI', 'AO', 'DMN', 'FDN', 'BSS'],
        'igbt': ['IRG', 'FGH', 'IKW', 'STGW'],
        'sensor': ['LM35', 'TMP', 'BME', 'MPU', 'ADXL', 'BMP', 'SHT'],
        'wire_harness': ['WH', 'CABLE', 'ASSY'],
        'cable_assembly': ['CA', 'USB', 'SMA', 'MMCX'],
        'pcb_bare': ['PCB', 'FR4'],
        'pcba_assembly': ['PCBA', 'ASSY', 'MOD'],
        'mechanical_part': ['BRK', 'ENC', 'HSK', 'MNT'],
        'machined_part': ['CNC', 'ALU', 'STS', 'BRS'],
    }

    # Description templates by category
    desc_templates = {
        'capacitor': ['{pkg} {val} {mat} {volt}', 'CAP MLCC {val} {volt} {pkg}', 'CAPACITOR {mat} {val}'],
        'resistor': ['{pkg} {val} 1% {pwr}', 'RES SMD {val} {pkg}', 'RESISTOR THICK FILM {val}'],
        'inductor': ['IND {val} {pkg} {curr}', 'INDUCTOR SHIELDED {val}', 'CHOKE SMD {val} {pkg}'],
        'ic_microcontroller': ['MCU {bits} {freq} {mem} {pkg}', '{mfr} MCU ARM {pkg}', 'MICROCONTROLLER {bits} {pkg}'],
        'ic_memory': ['{type} {size} {pkg}', 'MEMORY {type} {size}', '{type} FLASH {size} {pkg}'],
        'connector': ['CONN {type} {pins}P {pitch}', 'HEADER {pins}P {pitch}', 'CONNECTOR {type} {pins}POS'],
        'sensor': ['SENSOR {type} {iface} {pkg}', '{type} SENSOR DIGITAL {pkg}', 'IC SENSOR {type}'],
    }

    categories = list(COMPONENT_CATEGORIES.keys())
    suppliers = list(SUPPLIER_TIERS.keys())

    for _ in range(n_samples):
        # Select category with realistic distribution (passives dominate real BOMs)
        cat_weights = {
            'capacitor': 15, 'resistor': 15, 'inductor': 6,
            'ic_microcontroller': 5, 'ic_memory': 4, 'ic_analog': 5, 'ic_power': 5, 'ic_logic': 4,
            'connector': 8, 'switch': 3, 'relay': 2, 'transformer': 2, 'crystal_oscillator': 3,
            'led': 5, 'diode': 4, 'transistor': 3, 'mosfet': 4, 'igbt': 1,
            'sensor': 3, 'wire_harness': 2, 'cable_assembly': 2,
            'pcb_bare': 1, 'pcba_assembly': 1, 'mechanical_part': 1, 'machined_part': 1,
        }
        category = random.choices(
            list(cat_weights.keys()),
            weights=list(cat_weights.values()),
        )[0]
        comp_info = COMPONENT_CATEGORIES[category]

        # --- BASE PRICE: log-normal distribution ---
        # log-normal parameterized by median and sigma
        log_median = math.log(comp_info['median_price'])
        sigma = comp_info['sigma']
        base_price = math.exp(random.gauss(log_median, sigma))
        # Clip to realistic range (floor $0.001 to avoid extreme % errors)
        base_price = max(0.001, min(base_price, 2000.0))

        # --- SUPPLIER ---
        supplier = random.choice(suppliers)
        supplier_info = SUPPLIER_TIERS[supplier]

        # --- MANUFACTURER TIER ---
        mfr_tier = random.choices(['tier1', 'tier2', 'tier3'], weights=[0.25, 0.50, 0.25])[0]
        manufacturer = random.choice(MANUFACTURER_TIERS[mfr_tier])
        tier_mult = TIER_MULTIPLIER[mfr_tier]

        # --- QUANTITY: log-normal centered on industry typical ---
        industry = random.choice(list(INDUSTRY_SEGMENTS.keys()))
        industry_info = INDUSTRY_SEGMENTS[industry]
        qty_center = math.log(industry_info['volume_typical'])
        quantity = int(math.exp(random.gauss(qty_center, 1.5)))
        quantity = max(1, min(quantity, 1_000_000))

        # --- VOLUME DISCOUNT from break table ---
        discount_type = CATEGORY_DISCOUNT_TYPE[category]
        discount_table = VOLUME_DISCOUNT_TABLES[discount_type]
        discount = interpolate_discount(quantity, discount_table)

        # --- PACKAGE with size-correlated price ---
        pkg_candidates = list(PACKAGES.keys())
        # For passives, prefer small SMD; for ICs, prefer QFN/BGA
        if category in ('capacitor', 'resistor', 'inductor', 'led'):
            pkg_candidates = ['0201', '0402', '0603', '0805', '1206', '1210']
        elif category.startswith('ic_'):
            pkg_candidates = ['SOIC8', 'SOIC16', 'QFN16', 'QFN32', 'QFN48', 'TQFP', 'BGA', 'WLCSP', 'DIP8', 'DIP16']
        elif category in ('mosfet', 'igbt', 'transistor', 'diode'):
            pkg_candidates = ['SOT23', 'SOT223', 'TO220', 'TO92', 'TO252', 'TO263', 'DIP8']
        elif category in ('wire_harness', 'cable_assembly', 'pcba_assembly', 'mechanical_part', 'machined_part'):
            pkg_candidates = ['custom', 'Module']

        package = random.choice(pkg_candidates)
        pkg_mult = PACKAGES.get(package, 1.0)

        # --- COMPUTE FINAL PRICE ---
        unit_price = base_price * (1.0 - discount) * supplier_info['markup'] * tier_mult * pkg_mult

        # Industry complexity premium (aerospace/military parts cost more)
        unit_price *= (1.0 + (industry_info['complexity'] - 1.0) * 0.15)

        # Add realistic noise: 3-8% log-normal (tighter than before)
        noise_sigma = random.uniform(0.03, 0.08)
        unit_price *= math.exp(random.gauss(0, noise_sigma))
        unit_price = max(0.001, round(unit_price, 6))

        # --- MOQ (correlated with supplier and tier) ---
        moq = supplier_info['moq_base']
        if mfr_tier == 'tier3':
            moq *= random.choice([5, 10, 25, 50])
        elif mfr_tier == 'tier2':
            moq *= random.choice([1, 2, 5, 10])
        else:
            moq *= random.choice([1, 1, 2, 5])
        moq = max(1, moq)

        # --- LEAD TIME (correlated with supplier) ---
        base_lt = 5 if supplier in ('digikey', 'mouser') else 14
        lead_time = int(base_lt * supplier_info['lead_time_factor'] * random.uniform(0.6, 1.6))
        lead_time = max(1, lead_time)

        # --- PART NUMBER ---
        prefix = random.choice(part_prefixes.get(category, ['PART']))
        part_number = f"{prefix}{random.randint(100, 99999)}"

        # --- DESCRIPTION ---
        description = f"{category.replace('_', ' ').title()} {package} {manufacturer}"

        data.append({
            'category': category,
            'supplier': supplier,
            'manufacturer': manufacturer,
            'manufacturer_tier': mfr_tier,
            'part_number': part_number,
            'description': description,
            'package': package,
            'quantity': quantity,
            'moq': moq,
            'unit_price': unit_price,
            'lead_time_days': lead_time,
            'reliability_score': supplier_info['reliability'],
            'industry': industry,
            'complexity_factor': industry_info['complexity'],
        })

    return data


# =============================================================================
# PYTORCH DATASET
# =============================================================================

class ElectronicsTokenizer:
    """Tokenizer for electronics part descriptions."""

    def __init__(self, vocab_size: int = 15000):
        self.vocab_size = vocab_size
        self.word2idx = {"<PAD>": 0, "<UNK>": 1}
        self.idx2word = {0: "<PAD>", 1: "<UNK>"}
        self.word_counts = Counter()

    def fit(self, texts: List[str]):
        import re
        for text in texts:
            text = str(text).lower()
            tokens = re.findall(r'[a-z0-9]+', text)
            self.word_counts.update(tokens)

        for token, _ in self.word_counts.most_common(self.vocab_size - 2):
            idx = len(self.word2idx)
            self.word2idx[token] = idx
            self.idx2word[idx] = token

        print(f"  Vocabulary size: {len(self.word2idx)}")

    def encode(self, text: str, max_len: int = 48) -> List[int]:
        import re
        text = str(text).lower()
        tokens = re.findall(r'[a-z0-9]+', text)[:max_len]
        ids = [self.word2idx.get(t, 1) for t in tokens]
        while len(ids) < max_len:
            ids.append(0)
        return ids

    def save(self, path: str):
        with open(path, 'w') as f:
            json.dump({'word2idx': self.word2idx}, f)

    def load(self, path: str):
        with open(path, 'r') as f:
            data = json.load(f)
            self.word2idx = data['word2idx']
            self.idx2word = {int(v): k for k, v in self.word2idx.items()}


class ElectronicsPricingDataset(Dataset):
    """PyTorch dataset for electronics component pricing."""

    def __init__(
        self,
        data: List[Dict],
        tokenizer: ElectronicsTokenizer,
        category_encoder: Dict[str, int],
        supplier_encoder: Dict[str, int],
        manufacturer_encoder: Dict[str, int],
        industry_encoder: Dict[str, int],
        config: PricingModelConfig,
        price_scaler: Optional[Any] = None,
        is_training: bool = True,
    ):
        self.data = data
        self.tokenizer = tokenizer
        self.category_encoder = category_encoder
        self.supplier_encoder = supplier_encoder
        self.manufacturer_encoder = manufacturer_encoder
        self.industry_encoder = industry_encoder
        self.config = config
        self.is_training = is_training

        # Filter invalid data
        valid_data = []
        prices = []
        for d in data:
            price = d.get('unit_price', 0)
            if price > 0 and price < 1e6 and np.isfinite(price):
                valid_data.append(d)
                prices.append(price)

        self.data = valid_data
        self.raw_prices = np.array(prices)

        # Use log(price + eps) instead of log1p(price).
        # log1p(x) ≈ x for small x, destroying % error uniformity:
        #   30% error at $0.01 → 0.003 loss (87x less than $50)
        # log(x + eps) gives CONSTANT loss for equal % errors:
        #   30% error → log(1.3) = 0.2624 at ANY price level.
        PRICE_FLOOR = 0.001  # matches our data generator floor
        self.log_prices = np.log(self.raw_prices + PRICE_FLOOR)

        if price_scaler is None and HAS_SKLEARN:
            self.price_scaler = StandardScaler()
            self.price_scaler.fit(self.log_prices.reshape(-1, 1))
        else:
            self.price_scaler = price_scaler

        if self.price_scaler is not None:
            self.scaled_prices = self.price_scaler.transform(
                self.log_prices.reshape(-1, 1)
            ).flatten()
        else:
            self.scaled_prices = self.log_prices

        # Precompute per-sample weights for CRM-aware loss.
        # Gently up-weight expensive parts that affect quote margins.
        # w=1.0 at median price, rising to ~1.8 for $200+ parts,
        # ~0.7 for sub-cent parts. Uses log scale so it's smooth.
        median_log = np.median(self.log_prices)
        self.sample_weights = np.clip(
            1.0 + 0.15 * (self.log_prices - median_log),
            0.5, 2.5
        ).astype(np.float32)

    def __len__(self) -> int:
        return len(self.data)

    def __getitem__(self, idx: int) -> Dict[str, torch.Tensor]:
        item = self.data[idx]

        # Categorical features
        category_id = self.category_encoder.get(item['category'], 0)
        supplier_id = self.supplier_encoder.get(item['supplier'], 0)
        manufacturer_id = self.manufacturer_encoder.get(item.get('manufacturer', 'Unknown'), 0)
        industry_id = self.industry_encoder.get(item.get('industry', 'ems_contract'), 0)

        # Text features
        description = item.get('description', '') + ' ' + item.get('part_number', '')
        text_ids = self.tokenizer.encode(description, self.config.max_text_len)

        # Numerical features (8 total — same interface as v1 for ONNX compatibility)
        quantity = item.get('quantity', 1)
        log_quantity = math.log1p(quantity)
        moq = item.get('moq', 1)
        log_moq = math.log1p(moq)
        lead_time = item.get('lead_time_days', 7)
        reliability = item.get('reliability_score', 0.9)
        complexity = item.get('complexity_factor', 1.0)
        qty_moq_ratio = quantity / max(moq, 1)
        is_above_moq = 1.0 if quantity >= moq else 0.0
        volume_tier = min(4, int(math.log10(max(quantity, 1))))

        continuous_features = [
            log_quantity,
            log_moq,
            lead_time / 30.0,
            reliability,
            complexity,
            qty_moq_ratio / 10.0,
            is_above_moq,
            volume_tier / 4.0,
        ]

        scaled_price = float(self.scaled_prices[idx])
        sample_weight = float(self.sample_weights[idx])

        return {
            'category_id': torch.tensor(category_id, dtype=torch.long),
            'supplier_id': torch.tensor(supplier_id, dtype=torch.long),
            'manufacturer_id': torch.tensor(manufacturer_id, dtype=torch.long),
            'industry_id': torch.tensor(industry_id, dtype=torch.long),
            'text_ids': torch.tensor(text_ids, dtype=torch.long),
            'continuous': torch.tensor(continuous_features, dtype=torch.float32),
            'target': torch.tensor(scaled_price, dtype=torch.float32),
            'raw_price': torch.tensor(float(item['unit_price']), dtype=torch.float32),
            'weight': torch.tensor(sample_weight, dtype=torch.float32),
        }


# =============================================================================
# MODEL ARCHITECTURE — Optimized for limited real data
# =============================================================================

class PositionalEncoding(nn.Module):
    def __init__(self, d_model: int, max_len: int = 256, dropout: float = 0.1):
        super().__init__()
        self.dropout = nn.Dropout(p=dropout)
        position = torch.arange(max_len).unsqueeze(1)
        div_term = torch.exp(torch.arange(0, d_model, 2) * (-math.log(10000.0) / d_model))
        pe = torch.zeros(1, max_len, d_model)
        pe[0, :, 0::2] = torch.sin(position * div_term)
        if d_model % 2 == 1:
            pe[0, :, 1::2] = torch.cos(position * div_term[:-1])
        else:
            pe[0, :, 1::2] = torch.cos(position * div_term)
        self.register_buffer('pe', pe)

    def forward(self, x: torch.Tensor) -> torch.Tensor:
        x = x + self.pe[:, :x.size(1)]
        return self.dropout(x)


class PricingTransformer(nn.Module):
    """
    Transformer-based pricing model v2.

    Changes from v1:
    - Smaller embeddings (128 vs 256) to reduce overfitting
    - 2 transformer layers (vs 4) — text descriptions are short
    - 2 residual fusion blocks (vs 3)
    - Same input/output interface for ONNX compatibility
    """

    def __init__(self, config: PricingModelConfig):
        super().__init__()
        self.config = config

        # Categorical embeddings
        self.category_embed = nn.Embedding(config.num_categories + 1, config.embedding_dim)
        self.supplier_embed = nn.Embedding(config.num_suppliers + 1, config.embedding_dim)
        self.manufacturer_embed = nn.Embedding(config.num_manufacturers + 1, config.embedding_dim)
        self.industry_embed = nn.Embedding(config.num_industries + 1, config.embedding_dim)

        # Text processing
        self.text_embed = nn.Embedding(config.vocab_size, config.embedding_dim, padding_idx=0)
        self.pos_encoding = PositionalEncoding(config.embedding_dim, config.max_text_len)

        encoder_layer = nn.TransformerEncoderLayer(
            d_model=config.embedding_dim,
            nhead=config.num_attention_heads,
            dim_feedforward=config.hidden_dim,
            dropout=config.attention_dropout,
            activation='gelu',
            batch_first=True,
            norm_first=True,
        )
        self.text_transformer = nn.TransformerEncoder(
            encoder_layer,
            num_layers=config.num_transformer_layers,
            enable_nested_tensor=False,
        )

        # Continuous feature processing
        self.continuous_proj = nn.Sequential(
            nn.Linear(config.num_continuous_features, config.embedding_dim),
            nn.LayerNorm(config.embedding_dim),
            nn.GELU(),
            nn.Dropout(config.dropout),
            nn.Linear(config.embedding_dim, config.embedding_dim),
            nn.LayerNorm(config.embedding_dim),
            nn.GELU(),
        )

        # Feature fusion: 6 feature groups
        fusion_input_dim = config.embedding_dim * 6

        self.fusion_input = nn.Sequential(
            nn.Linear(fusion_input_dim, config.hidden_dim),
            nn.LayerNorm(config.hidden_dim),
            nn.GELU(),
        )

        # 2 residual blocks (vs 3 in v1)
        self.fusion_block1 = self._make_residual_block(config.hidden_dim, config.dropout)
        self.fusion_block2 = self._make_residual_block(config.hidden_dim, config.dropout)

        # Regression head
        self.regressor = nn.Sequential(
            nn.Linear(config.hidden_dim, config.hidden_dim),
            nn.LayerNorm(config.hidden_dim),
            nn.GELU(),
            nn.Dropout(config.dropout),
            nn.Linear(config.hidden_dim, config.hidden_dim // 2),
            nn.LayerNorm(config.hidden_dim // 2),
            nn.GELU(),
            nn.Dropout(config.dropout / 2),
            nn.Linear(config.hidden_dim // 2, config.hidden_dim // 4),
            nn.GELU(),
            nn.Linear(config.hidden_dim // 4, 1),
        )

        self._init_weights()

    def _make_residual_block(self, dim: int, dropout: float) -> nn.Module:
        return nn.Sequential(
            nn.LayerNorm(dim),
            nn.Linear(dim, dim * 2),
            nn.GELU(),
            nn.Dropout(dropout),
            nn.Linear(dim * 2, dim),
            nn.Dropout(dropout),
        )

    def _init_weights(self):
        for module in self.modules():
            if isinstance(module, nn.Linear):
                nn.init.xavier_uniform_(module.weight)
                if module.bias is not None:
                    nn.init.zeros_(module.bias)
            elif isinstance(module, nn.Embedding):
                nn.init.normal_(module.weight, mean=0, std=0.02)

    def forward(
        self,
        category_id: torch.Tensor,
        supplier_id: torch.Tensor,
        manufacturer_id: torch.Tensor,
        industry_id: torch.Tensor,
        text_ids: torch.Tensor,
        continuous: torch.Tensor,
    ) -> torch.Tensor:
        cat_emb = self.category_embed(category_id)
        sup_emb = self.supplier_embed(supplier_id)
        mfr_emb = self.manufacturer_embed(manufacturer_id)
        ind_emb = self.industry_embed(industry_id)

        text_emb = self.text_embed(text_ids)
        text_emb = self.pos_encoding(text_emb)
        text_mask = (text_ids == 0)
        text_encoded = self.text_transformer(text_emb, src_key_padding_mask=text_mask)

        # Mean pooling over non-padding tokens
        text_mask_expanded = (~text_mask).unsqueeze(-1).float()
        text_pooled = (text_encoded * text_mask_expanded).sum(dim=1)
        text_pooled = text_pooled / (text_mask_expanded.sum(dim=1) + 1e-8)

        cont_emb = self.continuous_proj(continuous)

        combined = torch.cat([cat_emb, sup_emb, mfr_emb, ind_emb, text_pooled, cont_emb], dim=-1)

        fused = self.fusion_input(combined)
        fused = fused + self.fusion_block1(fused)
        fused = fused + self.fusion_block2(fused)

        output = self.regressor(fused)
        return output.squeeze(-1)


# =============================================================================
# TRAINING UTILITIES
# =============================================================================

class CosineWarmupScheduler:
    """LR scheduler: linear warmup → cosine decay."""

    def __init__(self, optimizer, warmup_epochs: int, total_epochs: int, min_lr: float = 1e-6):
        self.optimizer = optimizer
        self.warmup_epochs = warmup_epochs
        self.total_epochs = total_epochs
        self.min_lr = min_lr
        self.base_lrs = [pg['lr'] for pg in optimizer.param_groups]

    def step(self, epoch: int):
        if epoch < self.warmup_epochs:
            scale = (epoch + 1) / self.warmup_epochs
        else:
            progress = (epoch - self.warmup_epochs) / max(1, self.total_epochs - self.warmup_epochs)
            scale = 0.5 * (1 + math.cos(math.pi * progress))
        for i, pg in enumerate(self.optimizer.param_groups):
            pg['lr'] = max(self.min_lr, self.base_lrs[i] * scale)


def train_epoch(
    model: nn.Module,
    dataloader: DataLoader,
    optimizer: torch.optim.Optimizer,
    device: torch.device,
    config: PricingModelConfig,
) -> Dict[str, float]:
    """Train one epoch with optional AMP and gradient accumulation."""
    model.train()
    total_loss = 0.0
    total_mae = 0.0
    n_batches = 0
    optimizer.zero_grad()

    for i, batch in enumerate(dataloader):
        category_id = batch['category_id'].to(device)
        supplier_id = batch['supplier_id'].to(device)
        manufacturer_id = batch['manufacturer_id'].to(device)
        industry_id = batch['industry_id'].to(device)
        text_ids = batch['text_ids'].to(device)
        continuous = batch['continuous'].to(device)
        targets = batch['target'].to(device)
        weights = batch['weight'].to(device)

        # Mixed precision forward pass (bfloat16 has same exponent range as fp32)
        amp_dtype = torch.bfloat16 if device.type == 'cuda' else torch.float32
        with torch.amp.autocast('cuda', enabled=config.use_amp and device.type == 'cuda', dtype=amp_dtype):
            predictions = model(
                category_id, supplier_id, manufacturer_id,
                industry_id, text_ids, continuous
            )
            # Price-magnitude weighted Huber loss:
            # - log(price+eps) makes equal % errors → equal raw errors
            # - weights gently up-weight expensive parts (CRM quote margin)
            per_sample_loss = F.huber_loss(predictions, targets, reduction='none', delta=1.0)
            loss = (per_sample_loss * weights).mean()
            loss = loss / config.grad_accum_steps  # Scale for accumulation

        # Backward pass (no GradScaler needed for bfloat16)
        loss.backward()

        # Step optimizer every grad_accum_steps
        if (i + 1) % config.grad_accum_steps == 0 or (i + 1) == len(dataloader):
            torch.nn.utils.clip_grad_norm_(model.parameters(), max_norm=1.0)
            optimizer.step()
            optimizer.zero_grad()

        loss_val = loss.item() * config.grad_accum_steps
        if not math.isfinite(loss_val):
            continue  # Skip NaN batch
        total_loss += loss_val
        with torch.no_grad():
            total_mae += F.l1_loss(predictions, targets).item()
        n_batches += 1

    return {
        'loss': total_loss / max(n_batches, 1),
        'mae': total_mae / max(n_batches, 1),
    }


def evaluate(
    model: nn.Module,
    dataloader: DataLoader,
    device: torch.device,
    price_scaler: Any,
    use_amp: bool = False,
) -> Dict[str, float]:
    """Evaluate model and compute metrics."""
    model.eval()
    all_predictions = []
    all_targets = []
    all_raw_prices = []

    with torch.no_grad():
        for batch in dataloader:
            category_id = batch['category_id'].to(device)
            supplier_id = batch['supplier_id'].to(device)
            manufacturer_id = batch['manufacturer_id'].to(device)
            industry_id = batch['industry_id'].to(device)
            text_ids = batch['text_ids'].to(device)
            continuous = batch['continuous'].to(device)
            targets = batch['target']
            raw_prices = batch['raw_price']

            amp_dtype = torch.bfloat16 if device.type == 'cuda' else torch.float32
            with torch.amp.autocast('cuda', enabled=use_amp and device.type == 'cuda', dtype=amp_dtype):
                predictions = model(
                    category_id, supplier_id, manufacturer_id,
                    industry_id, text_ids, continuous
                )

            all_predictions.extend(predictions.cpu().float().numpy())
            all_targets.extend(targets.numpy())
            all_raw_prices.extend(raw_prices.numpy())

    all_predictions = np.array(all_predictions)
    all_targets = np.array(all_targets)
    all_raw_prices = np.array(all_raw_prices)

    # Inverse transform: we used log(price + PRICE_FLOOR) encoding
    PRICE_FLOOR = 0.001
    if price_scaler is not None:
        pred_log = price_scaler.inverse_transform(all_predictions.reshape(-1, 1)).flatten()
        target_log = price_scaler.inverse_transform(all_targets.reshape(-1, 1)).flatten()
        # Clamp log values to prevent overflow in exp
        pred_log = np.clip(pred_log, -15, 15)
        target_log = np.clip(target_log, -15, 15)
        pred_prices = np.exp(pred_log) - PRICE_FLOOR
        target_prices = np.exp(target_log) - PRICE_FLOOR
    else:
        pred_prices = np.exp(np.clip(all_predictions, -15, 15)) - PRICE_FLOOR
        target_prices = all_raw_prices

    pred_prices = np.maximum(pred_prices, 0)
    # Replace any remaining NaN/Inf
    pred_prices = np.nan_to_num(pred_prices, nan=0.0, posinf=1e6, neginf=0.0)
    target_prices = np.nan_to_num(target_prices, nan=0.0, posinf=1e6, neginf=0.0)

    metrics = {
        'mae': float(mean_absolute_error(target_prices, pred_prices)),
        'rmse': float(np.sqrt(mean_squared_error(target_prices, pred_prices))),
        'r2': float(r2_score(target_prices, pred_prices)),
        'mape': float(mean_absolute_percentage_error(target_prices + 1e-8, pred_prices + 1e-8) * 100),
        'scaled_loss': float(F.huber_loss(
            torch.tensor(all_predictions),
            torch.tensor(all_targets)
        ).item()),
    }

    ape = np.abs((target_prices - pred_prices) / (target_prices + 1e-8))
    metrics['mdape'] = float(np.median(ape) * 100)

    return metrics


# =============================================================================
# CROSS-VALIDATION
# =============================================================================

def cross_validate(
    data: List[Dict],
    config: PricingModelConfig,
    tokenizer: ElectronicsTokenizer,
    encoders: Dict,
    device: torch.device,
    n_folds: int = 5,
) -> Dict[str, Any]:
    """K-fold cross-validation."""
    print(f"\n{'='*60}")
    print(f"CROSS-VALIDATION ({n_folds}-fold)")
    print(f"{'='*60}")

    indices = np.arange(len(data))
    np.random.shuffle(indices)
    fold_metrics = []
    kfold = KFold(n_splits=n_folds, shuffle=True, random_state=42)

    for fold, (train_idx, val_idx) in enumerate(kfold.split(indices)):
        print(f"\nFold {fold + 1}/{n_folds}")

        train_data = [data[indices[i]] for i in train_idx]
        val_data = [data[indices[i]] for i in val_idx]

        train_dataset = ElectronicsPricingDataset(
            train_data, tokenizer,
            encoders['category'], encoders['supplier'],
            encoders['manufacturer'], encoders['industry'],
            config, is_training=True
        )
        val_dataset = ElectronicsPricingDataset(
            val_data, tokenizer,
            encoders['category'], encoders['supplier'],
            encoders['manufacturer'], encoders['industry'],
            config, price_scaler=train_dataset.price_scaler, is_training=False
        )

        train_loader = DataLoader(train_dataset, batch_size=config.batch_size, shuffle=True)
        val_loader = DataLoader(val_dataset, batch_size=config.batch_size)

        model = PricingTransformer(config).to(device)
        optimizer = torch.optim.AdamW(model.parameters(), lr=config.learning_rate, weight_decay=config.weight_decay)
        scheduler = CosineWarmupScheduler(optimizer, config.warmup_epochs, config.num_epochs // 2)

        cv_epochs = min(20, config.num_epochs // 2)
        for epoch in range(cv_epochs):
            train_metrics = train_epoch(model, train_loader, optimizer, device, config)
            scheduler.step(epoch)
            if (epoch + 1) % 5 == 0:
                val_metrics = evaluate(model, val_loader, device, train_dataset.price_scaler, config.use_amp)
                print(f"  Epoch {epoch+1}: Loss={train_metrics['loss']:.4f}, "
                      f"R²={val_metrics['r2']:.4f}, MAE=${val_metrics['mae']:.4f}")

        final_metrics = evaluate(model, val_loader, device, train_dataset.price_scaler, config.use_amp)
        fold_metrics.append(final_metrics)
        print(f"  → Fold {fold+1}: R²={final_metrics['r2']:.4f}, MAE=${final_metrics['mae']:.4f}")

    aggregated = {}
    for key in fold_metrics[0].keys():
        values = [fm[key] for fm in fold_metrics]
        aggregated[key] = {
            'mean': float(np.mean(values)),
            'std': float(np.std(values)),
            'min': float(np.min(values)),
            'max': float(np.max(values)),
        }

    return {'fold_metrics': fold_metrics, 'aggregated': aggregated}


# =============================================================================
# CHECKPOINT MANAGEMENT
# =============================================================================

def save_checkpoint(
    model: nn.Module,
    optimizer: torch.optim.Optimizer,
    epoch: int,
    val_metrics: Dict,
    config: PricingModelConfig,
    encoders: Dict,
    output_dir: Path,
    is_best: bool = False,
):
    """Save a training checkpoint to disk."""
    checkpoint = {
        'epoch': epoch,
        'model_state_dict': model.state_dict(),
        'optimizer_state_dict': optimizer.state_dict(),
        'val_metrics': val_metrics,
        'config': config.__dict__,
        'encoders': encoders,
    }

    # Save periodic checkpoint
    ckpt_path = output_dir / f'checkpoint_epoch_{epoch:03d}.pt'
    torch.save(checkpoint, ckpt_path)
    print(f"  💾 Checkpoint saved: {ckpt_path.name}")

    # Save best model separately
    if is_best:
        best_path = output_dir / 'checkpoint_best.pt'
        torch.save(checkpoint, best_path)
        print(f"  ⭐ Best model saved: {best_path.name} (R²={val_metrics['r2']:.4f})")

    # Cleanup old checkpoints (keep last N)
    ckpt_files = sorted(output_dir.glob('checkpoint_epoch_*.pt'))
    while len(ckpt_files) > config.keep_last_n_checkpoints:
        old = ckpt_files.pop(0)
        old.unlink()


# =============================================================================
# ONNX EXPORT
# =============================================================================

def export_to_onnx(
    model: nn.Module,
    config: PricingModelConfig,
    output_path: str,
    device: torch.device,
):
    """Export model to ONNX format for PHP integration."""
    model.eval()
    batch_size = 1
    category_id = torch.zeros(batch_size, dtype=torch.long, device=device)
    supplier_id = torch.zeros(batch_size, dtype=torch.long, device=device)
    manufacturer_id = torch.zeros(batch_size, dtype=torch.long, device=device)
    industry_id = torch.zeros(batch_size, dtype=torch.long, device=device)
    text_ids = torch.zeros(batch_size, config.max_text_len, dtype=torch.long, device=device)
    continuous = torch.zeros(batch_size, config.num_continuous_features, dtype=torch.float32, device=device)

    torch.onnx.export(
        model,
        (category_id, supplier_id, manufacturer_id, industry_id, text_ids, continuous),
        output_path,
        input_names=['category_id', 'supplier_id', 'manufacturer_id',
                    'industry_id', 'text_ids', 'continuous'],
        output_names=['price_prediction'],
        dynamic_axes={
            'category_id': {0: 'batch'},
            'supplier_id': {0: 'batch'},
            'manufacturer_id': {0: 'batch'},
            'industry_id': {0: 'batch'},
            'text_ids': {0: 'batch'},
            'continuous': {0: 'batch'},
            'price_prediction': {0: 'batch'},
        },
        opset_version=14,
    )
    print(f"  ✓ ONNX exported: {output_path}")

    if HAS_ONNX:
        onnx_model = onnx.load(output_path)
        onnx.checker.check_model(onnx_model)
        print("  ✓ ONNX verification: PASSED")


# =============================================================================
# MAIN TRAINING PIPELINE
# =============================================================================

def main():
    parser = argparse.ArgumentParser(description="Train Electronics Pricing Model v2")
    parser.add_argument('--epochs', type=int, default=120, help='Training epochs')
    parser.add_argument('--batch-size', type=int, default=256, help='Batch size')
    parser.add_argument('--lr', type=float, default=3e-4, help='Learning rate')
    parser.add_argument('--synthetic-samples', type=int, default=200000,
                       help='Number of synthetic samples')
    parser.add_argument('--use-huggingface', action='store_true',
                       help='Download and use HuggingFace datasets')
    parser.add_argument('--skip-cv', action='store_true', help='Skip cross-validation')
    parser.add_argument('--output-dir', type=str, default='models', help='Output directory')
    parser.add_argument('--device', type=str, default='auto', help='Device (cuda/cpu/auto)')
    parser.add_argument('--no-amp', action='store_true', help='Disable mixed precision')
    parser.add_argument('--checkpoint-every', type=int, default=5, help='Checkpoint frequency')
    parser.add_argument('--extra-data', type=str, default=None,
                       help='Path to extra training data JSON (e.g. from API harvest)')
    parser.add_argument('--extra-data-upsample', type=int, default=10,
                       help='Upsample factor for extra data (default 10x)')
    args = parser.parse_args()

    # =========================================
    print("=" * 70)
    print("ELECTRONICS PRICING MODEL v2 — TRAINING PIPELINE")
    print("=" * 70)
    print("Improvements: Better data, AMP, checkpoints, smaller model, more regularization")
    print(f"Target: EMS, Contract Manufacturing, Wire Harness, Machining")
    print(f"Suppliers: DigiKey, Mouser, Alibaba")
    print("=" * 70)

    # Device
    if args.device == 'auto':
        device = torch.device('cuda' if torch.cuda.is_available() else 'cpu')
    else:
        device = torch.device(args.device)
    print(f"\nDevice: {device}")
    if device.type == 'cuda':
        print(f"  GPU: {torch.cuda.get_device_name()}")
        print(f"  Memory: {torch.cuda.get_device_properties(0).total_memory / 1024**3:.1f} GB")

    # Config
    config = PricingModelConfig(
        num_epochs=args.epochs,
        batch_size=args.batch_size,
        learning_rate=args.lr,
        checkpoint_every=args.checkpoint_every,
    )
    if args.no_amp:
        config.use_amp = False

    output_dir = Path(args.output_dir)
    output_dir.mkdir(exist_ok=True)

    # =========================================
    # STEP 1: DATA ACQUISITION
    # =========================================
    print(f"\n{'='*60}")
    print("STEP 1: DATA ACQUISITION")
    print(f"{'='*60}")

    all_data = []

    # High-quality synthetic data
    print(f"\nGenerating {args.synthetic_samples:,} synthetic electronics samples...")
    synthetic_data = generate_synthetic_electronics_data(args.synthetic_samples)
    all_data.extend(synthetic_data)
    print(f"  ✓ {len(synthetic_data):,} synthetic samples")

    # Real data from HuggingFace
    if args.use_huggingface and HAS_DATASETS:
        print("\nDownloading real datasets...")
        hf_datasets = download_huggingface_datasets()
        if hf_datasets:
            print("\nExtracting real pricing data (conservative augmentation)...")
            real_data = extract_real_pricing_data(hf_datasets)
            all_data.extend(real_data)
            print(f"  ✓ {len(real_data):,} real/adapted samples added")

    # Extra data from API harvest (real prices)
    if args.extra_data:
        extra_path = Path(args.extra_data)
        if extra_path.exists():
            print(f"\nLoading extra training data from {extra_path}...")
            with open(extra_path) as f:
                extra_data = json.load(f)
            # Upsample real data to give it more weight
            upsample = args.extra_data_upsample
            print(f"  Raw samples: {len(extra_data):,}")
            # Normalize key names
            for sample in extra_data:
                if 'unit_price_usd' in sample and 'unit_price' not in sample:
                    sample['unit_price'] = sample.pop('unit_price_usd')
            print(f"  Upsampling {upsample}x → {len(extra_data) * upsample:,} samples")
            for _ in range(upsample):
                for sample in extra_data:
                    # Add slight noise to each upsampled copy
                    noisy = sample.copy()
                    if noisy.get('source') != 'api_actual_price_break':
                        noisy['unit_price'] *= math.exp(random.gauss(0, 0.03))
                    all_data.append(noisy)
            print(f"  ✓ {len(extra_data) * upsample:,} real-data samples added")
        else:
            print(f"  ⚠ Extra data file not found: {extra_path}")

    # Data summary
    print(f"\n{'─'*40}")
    print(f"TOTAL SAMPLES: {len(all_data):,}")

    # Price distribution stats
    prices = [d['unit_price'] for d in all_data if d['unit_price'] > 0]
    print(f"  Price range: ${min(prices):.4f} — ${max(prices):.2f}")
    print(f"  Median price: ${np.median(prices):.4f}")
    print(f"  Mean price: ${np.mean(prices):.4f}")

    # Category distribution
    cat_counts = Counter(d['category'] for d in all_data)
    print(f"  Categories: {len(cat_counts)}")
    for cat, count in cat_counts.most_common(5):
        print(f"    {cat}: {count:,} ({100*count/len(all_data):.1f}%)")

    # =========================================
    # STEP 2: FEATURE ENCODING
    # =========================================
    print(f"\n{'='*60}")
    print("STEP 2: FEATURE ENCODING")
    print(f"{'='*60}")

    categories = sorted(set(d['category'] for d in all_data))
    category_encoder = {cat: i for i, cat in enumerate(categories)}
    print(f"  Categories: {len(category_encoder)}")

    suppliers = sorted(set(d['supplier'] for d in all_data))
    supplier_encoder = {sup: i for i, sup in enumerate(suppliers)}
    print(f"  Suppliers: {len(supplier_encoder)}")

    manufacturers = sorted(set(d.get('manufacturer', 'Unknown') for d in all_data))
    manufacturer_encoder = {mfr: i for i, mfr in enumerate(manufacturers)}
    print(f"  Manufacturers: {len(manufacturer_encoder)}")

    industries = sorted(set(d.get('industry', 'ems_contract') for d in all_data))
    industry_encoder = {ind: i for i, ind in enumerate(industries)}
    print(f"  Industries: {len(industry_encoder)}")

    config.num_categories = len(category_encoder)
    config.num_suppliers = len(supplier_encoder)
    config.num_manufacturers = len(manufacturer_encoder)
    config.num_industries = len(industry_encoder)

    encoders = {
        'category': category_encoder,
        'supplier': supplier_encoder,
        'manufacturer': manufacturer_encoder,
        'industry': industry_encoder,
    }

    # Tokenizer
    print("\nBuilding tokenizer...")
    tokenizer = ElectronicsTokenizer(config.vocab_size)
    texts = [d.get('description', '') + ' ' + d.get('part_number', '') for d in all_data]
    tokenizer.fit(texts)

    # =========================================
    # CROSS-VALIDATION (optional)
    # =========================================
    if not args.skip_cv and HAS_SKLEARN:
        cv_results = cross_validate(all_data, config, tokenizer, encoders, device, n_folds=config.n_folds)

        print(f"\n{'='*60}")
        print("CROSS-VALIDATION SUMMARY")
        print(f"{'='*60}")
        for metric, s in cv_results['aggregated'].items():
            print(f"  {metric}: {s['mean']:.4f} ± {s['std']:.4f}")

        with open(output_dir / 'cv_report.json', 'w') as f:
            json.dump(cv_results['aggregated'], f, indent=2)

    # =========================================
    # STEP 3: FINAL MODEL TRAINING
    # =========================================
    print(f"\n{'='*60}")
    print("STEP 3: FINAL MODEL TRAINING")
    print(f"{'='*60}")

    # Split data
    random.shuffle(all_data)
    split_idx = int(len(all_data) * (1.0 - config.val_split))
    train_data = all_data[:split_idx]
    val_data = all_data[split_idx:]

    print(f"\n  Training samples: {len(train_data):,}")
    print(f"  Validation samples: {len(val_data):,}")

    train_dataset = ElectronicsPricingDataset(
        train_data, tokenizer,
        encoders['category'], encoders['supplier'],
        encoders['manufacturer'], encoders['industry'],
        config, is_training=True
    )
    val_dataset = ElectronicsPricingDataset(
        val_data, tokenizer,
        encoders['category'], encoders['supplier'],
        encoders['manufacturer'], encoders['industry'],
        config, price_scaler=train_dataset.price_scaler, is_training=False
    )

    train_loader = DataLoader(
        train_dataset, batch_size=config.batch_size,
        shuffle=True, num_workers=2, pin_memory=True, persistent_workers=True,
    )
    val_loader = DataLoader(
        val_dataset, batch_size=config.batch_size,
        num_workers=2, pin_memory=True, persistent_workers=True,
    )

    # Model
    model = PricingTransformer(config).to(device)
    total_params = sum(p.numel() for p in model.parameters())
    trainable_params = sum(p.numel() for p in model.parameters() if p.requires_grad)
    print(f"\n  Model params: {total_params:,} ({trainable_params:,} trainable)")

    # Optimizer
    optimizer = torch.optim.AdamW(
        model.parameters(),
        lr=config.learning_rate,
        weight_decay=config.weight_decay,
        betas=(0.9, 0.999),
    )
    scheduler = CosineWarmupScheduler(optimizer, config.warmup_epochs, config.num_epochs)

    # AMP info
    if config.use_amp and device.type == 'cuda':
        print("  ⚡ Mixed precision (bfloat16) enabled")

    # ── Training Loop ──
    best_val_r2 = -float('inf')
    best_val_metrics = None
    patience_counter = 0
    history = {'train_loss': [], 'val_mae': [], 'val_r2': [], 'val_mape': [], 'val_mdape': [], 'lr': []}

    print(f"\n  Training for {config.num_epochs} epochs (effective batch={config.batch_size * config.grad_accum_steps})")
    print(f"  Checkpoints every {config.checkpoint_every} epochs → {output_dir}/")
    print("─" * 80)

    training_start = time.time()

    for epoch in range(config.num_epochs):
        epoch_start = time.time()

        # Train
        train_metrics = train_epoch(model, train_loader, optimizer, device, config)
        scheduler.step(epoch)

        # Evaluate
        val_metrics = evaluate(model, val_loader, device, train_dataset.price_scaler, config.use_amp)

        # Record history
        lr = optimizer.param_groups[0]['lr']
        history['train_loss'].append(train_metrics['loss'])
        history['val_mae'].append(val_metrics['mae'])
        history['val_r2'].append(val_metrics['r2'])
        history['val_mape'].append(val_metrics['mape'])
        history['val_mdape'].append(val_metrics['mdape'])
        history['lr'].append(lr)

        epoch_time = time.time() - epoch_start
        is_best = val_metrics['r2'] > best_val_r2

        # Print progress
        marker = " ⭐" if is_best else ""
        print(f"Epoch {epoch+1:3d}/{config.num_epochs} │ "
              f"Loss: {train_metrics['loss']:.4f} │ "
              f"R²: {val_metrics['r2']:.4f} │ "
              f"MAE: ${val_metrics['mae']:.4f} │ "
              f"MdAPE: {val_metrics['mdape']:.1f}% │ "
              f"LR: {lr:.2e} │ "
              f"{epoch_time:.1f}s{marker}")

        # Track best
        if is_best:
            best_val_r2 = val_metrics['r2']
            best_val_metrics = val_metrics.copy()
            patience_counter = 0
        else:
            patience_counter += 1

        # ── Checkpoint saving ──
        if (epoch + 1) % config.checkpoint_every == 0 or is_best:
            save_checkpoint(
                model, optimizer, epoch + 1, val_metrics,
                config, encoders, output_dir, is_best=is_best,
            )

        # Early stopping
        if patience_counter >= config.patience:
            print(f"\n⏹ Early stopping at epoch {epoch+1} (patience={config.patience})")
            # Save final checkpoint
            save_checkpoint(
                model, optimizer, epoch + 1, val_metrics,
                config, encoders, output_dir, is_best=False,
            )
            break

    training_time = time.time() - training_start
    print(f"\n  Training time: {training_time/60:.1f} minutes")

    # ── Load best model ──
    best_ckpt = output_dir / 'checkpoint_best.pt'
    if best_ckpt.exists():
        print(f"\nLoading best checkpoint (R²={best_val_r2:.4f})...")
        checkpoint = torch.load(best_ckpt, map_location=device, weights_only=False)
        model.load_state_dict(checkpoint['model_state_dict'])
        print("  ✓ Best model restored")
    else:
        print("  ⚠ No best checkpoint found — using final model")

    # =========================================
    # STEP 4: FINAL EVALUATION
    # =========================================
    print(f"\n{'='*60}")
    print("STEP 4: FINAL EVALUATION")
    print(f"{'='*60}")

    final_metrics = evaluate(model, val_loader, device, train_dataset.price_scaler, config.use_amp)

    print(f"\n  FINAL MODEL METRICS:")
    print(f"  ├─ R² Score:    {final_metrics['r2']:.4f}")
    print(f"  ├─ MAE:         ${final_metrics['mae']:.4f}")
    print(f"  ├─ RMSE:        ${final_metrics['rmse']:.4f}")
    print(f"  ├─ MAPE:        {final_metrics['mape']:.2f}%")
    print(f"  └─ Median APE:  {final_metrics['mdape']:.2f}%")

    # Quality assessment
    quality_score = 0
    if final_metrics['r2'] >= 0.9: quality_score += 3
    elif final_metrics['r2'] >= 0.8: quality_score += 2
    elif final_metrics['r2'] >= 0.7: quality_score += 1

    if final_metrics['mape'] <= 10: quality_score += 3
    elif final_metrics['mape'] <= 20: quality_score += 2
    elif final_metrics['mape'] <= 30: quality_score += 1

    quality_rating = {6: "EXCELLENT", 5: "VERY GOOD", 4: "GOOD",
                     3: "ACCEPTABLE", 2: "NEEDS IMPROVEMENT"}.get(quality_score, "POOR")
    print(f"\n  QUALITY: {quality_rating} ({quality_score}/6)")

    # =========================================
    # STEP 5: SAVE FINAL MODEL
    # =========================================
    print(f"\n{'='*60}")
    print("STEP 5: SAVING FINAL MODEL")
    print(f"{'='*60}")

    # PyTorch model
    model_path = output_dir / 'pricing_model.pt'
    torch.save({
        'model_state_dict': model.state_dict(),
        'config': config.__dict__,
        'encoders': encoders,
        'final_metrics': {k: float(v) for k, v in final_metrics.items()},
        'history': history,
    }, model_path)
    print(f"  ✓ PyTorch model: {model_path}")

    # Tokenizer
    tokenizer_path = output_dir / 'pricing_tokenizer.json'
    tokenizer.save(str(tokenizer_path))
    print(f"  ✓ Tokenizer: {tokenizer_path}")

    # Config
    config_path = output_dir / 'pricing_config.json'
    config_data = {
        **config.__dict__,
        'encoders': encoders,
        'component_categories': list(COMPONENT_CATEGORIES.keys()),
        'supplier_tiers': list(SUPPLIER_TIERS.keys()),
        'industries': list(INDUSTRY_SEGMENTS.keys()),
    }
    with open(config_path, 'w') as f:
        json.dump(config_data, f, indent=2)
    print(f"  ✓ Config: {config_path}")

    # Price scaler — encodes log(price + PRICE_FLOOR) transform
    if train_dataset.price_scaler is not None:
        scaler_params = {
            'mean': float(train_dataset.price_scaler.mean_[0]),
            'scale': float(train_dataset.price_scaler.scale_[0]),
            'encoding': 'log',
            'price_floor': 0.001,
            'note': 'target = (log(price + price_floor) - mean) / scale',
        }
        with open(output_dir / 'price_scaler.json', 'w') as f:
            json.dump(scaler_params, f, indent=2)
        print(f"  ✓ Price scaler: {output_dir / 'price_scaler.json'}")

    # ONNX export
    if HAS_ONNX:
        onnx_path = output_dir / 'pricing_model.onnx'
        # Need float32 for ONNX export
        model_cpu = model.float()
        export_to_onnx(model_cpu, config, str(onnx_path), device)

    # Training report
    report = {
        'training_date': time.strftime('%Y-%m-%d %H:%M:%S'),
        'version': 'v2',
        'total_samples': len(all_data),
        'train_samples': len(train_data),
        'val_samples': len(val_data),
        'epochs_trained': len(history['train_loss']),
        'training_time_minutes': round(training_time / 60, 1),
        'final_metrics': {k: float(v) for k, v in final_metrics.items()},
        'best_val_r2': float(best_val_r2),
        'quality_rating': quality_rating,
        'quality_score': f"{quality_score}/6",
        'model_parameters': total_params,
        'config': config.__dict__,
        'improvements': [
            'Removed garbage proxy datasets (Nigerian retail, Amazon)',
            'Conservative Nexar augmentation (5 qty × 3 suppliers × 3 noise)',
            'Log-normal price distributions matching real markets',
            'Realistic volume discount break tables',
            'Mixed precision training (AMP)',
            'Disk checkpoint saving every N epochs',
            'Smaller model with stronger regularization',
            'Gradient accumulation (effective batch=512)',
        ],
    }

    with open(output_dir / 'training_report.json', 'w') as f:
        json.dump(report, f, indent=2)
    print(f"  ✓ Training report: {output_dir / 'training_report.json'}")

    print(f"\n{'='*60}")
    print("TRAINING COMPLETE")
    print(f"{'='*60}")
    print(f"  Best R²: {best_val_r2:.4f}")
    print(f"  Model: {model_path}")
    if HAS_ONNX:
        print(f"  ONNX: {output_dir / 'pricing_model.onnx'}")
    print(f"  Checkpoints: {output_dir}/checkpoint_*.pt")

    return model, final_metrics


if __name__ == '__main__':
    main()
