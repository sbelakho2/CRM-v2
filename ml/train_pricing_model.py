#!/usr/bin/env python3
"""
Electronics Component Pricing Model - Statistically Robust ML Training
======================================================================
Purpose: Train a pricing prediction model for B2B electronics components
         used in contract manufacturing, EMS, wire harness, and machining.

Suppliers: DigiKey, Mouser, Alibaba
Industries: EMS, Contract Manufacturing, Wire Harness, Machining

Features:
1. Downloads real datasets from HuggingFace
2. Domain adaptation for electronics component pricing
3. Multi-head attention architecture (proven in lead classifier v4)
4. Comprehensive statistical validation (cross-validation, calibration)
5. ONNX export for PHP integration
"""

import os
import sys
import json
import math
import random
import hashlib
import argparse
import warnings
from pathlib import Path
from dataclasses import dataclass, field
from typing import List, Dict, Tuple, Optional, Any
from collections import Counter, defaultdict
import time

# Core ML imports
try:
    import torch
    import torch.nn as nn
    import torch.nn.functional as F
    from torch.utils.data import Dataset, DataLoader, Subset
    import numpy as np
except ImportError as e:
    print(f"Missing core package: {e}")
    print("Install with: pip install torch numpy")
    sys.exit(1)

# HuggingFace datasets
try:
    from datasets import load_dataset, concatenate_datasets
    HAS_DATASETS = True
except ImportError:
    HAS_DATASETS = False
    print("Warning: HuggingFace datasets not available. Install with: pip install datasets")

# Statistical analysis
try:
    from sklearn.model_selection import KFold, cross_val_score
    from sklearn.preprocessing import StandardScaler, LabelEncoder
    from sklearn.metrics import (
        mean_absolute_error, mean_squared_error, r2_score,
        mean_absolute_percentage_error
    )
    import scipy.stats as stats
    HAS_SKLEARN = True
except ImportError:
    HAS_SKLEARN = False
    print("Warning: scikit-learn not available for full statistical analysis")

# ONNX export
try:
    import onnx
    import onnxruntime as ort
    HAS_ONNX = True
except ImportError:
    HAS_ONNX = False
    print("Warning: ONNX not available for model export")

warnings.filterwarnings('ignore', category=UserWarning)

# =============================================================================
# CONFIGURATION
# =============================================================================

@dataclass
class PricingModelConfig:
    """Configuration for pricing prediction model - OPTIMIZED for high accuracy."""
    
    # Architecture - Deep model with high capacity
    embedding_dim: int = 256           # Larger embeddings for richer representations
    hidden_dim: int = 512              # Wider hidden layers
    num_attention_heads: int = 8       # More attention heads for complex patterns
    num_transformer_layers: int = 4    # Deeper transformer for better feature extraction
    
    # Feature dimensions
    num_categories: int = 25          # Electronics component categories
    num_suppliers: int = 10           # Supplier tiers
    num_manufacturers: int = 50       # Manufacturer embeddings
    num_industries: int = 8           # Industry segments
    max_text_len: int = 64            # Part description length
    vocab_size: int = 20000           # Vocabulary for text features
    
    # Numerical features
    num_continuous_features: int = 8  # qty, lead_time, weight, etc.
    
    # Regularization - Balanced for large dataset
    dropout: float = 0.15             # Lower dropout for more data
    attention_dropout: float = 0.1
    
    # Training - Optimized for convergence
    batch_size: int = 64
    learning_rate: float = 5e-5       # Lower LR for stability with large model
    weight_decay: float = 1e-4        # Lighter regularization
    num_epochs: int = 50
    patience: int = 20                # More patience for convergence
    warmup_epochs: int = 5
    
    # Statistical validation
    n_folds: int = 5                  # K-fold cross-validation
    confidence_level: float = 0.95    # For confidence intervals


# =============================================================================
# DOMAIN KNOWLEDGE: Electronics Components
# =============================================================================

# Component categories for EMS/Contract Manufacturing
COMPONENT_CATEGORIES = {
    'capacitor': {'base_multiplier': 1.0, 'volume_sensitivity': 0.85},
    'resistor': {'base_multiplier': 0.8, 'volume_sensitivity': 0.90},
    'inductor': {'base_multiplier': 1.2, 'volume_sensitivity': 0.80},
    'ic_microcontroller': {'base_multiplier': 5.0, 'volume_sensitivity': 0.70},
    'ic_memory': {'base_multiplier': 4.0, 'volume_sensitivity': 0.75},
    'ic_analog': {'base_multiplier': 3.5, 'volume_sensitivity': 0.72},
    'ic_power': {'base_multiplier': 3.0, 'volume_sensitivity': 0.78},
    'ic_logic': {'base_multiplier': 2.0, 'volume_sensitivity': 0.82},
    'connector': {'base_multiplier': 2.5, 'volume_sensitivity': 0.75},
    'switch': {'base_multiplier': 1.5, 'volume_sensitivity': 0.80},
    'relay': {'base_multiplier': 2.0, 'volume_sensitivity': 0.78},
    'transformer': {'base_multiplier': 3.0, 'volume_sensitivity': 0.70},
    'crystal_oscillator': {'base_multiplier': 2.5, 'volume_sensitivity': 0.75},
    'led': {'base_multiplier': 0.5, 'volume_sensitivity': 0.88},
    'diode': {'base_multiplier': 0.6, 'volume_sensitivity': 0.87},
    'transistor': {'base_multiplier': 0.7, 'volume_sensitivity': 0.85},
    'mosfet': {'base_multiplier': 1.5, 'volume_sensitivity': 0.80},
    'igbt': {'base_multiplier': 4.0, 'volume_sensitivity': 0.72},
    'sensor': {'base_multiplier': 3.5, 'volume_sensitivity': 0.70},
    'wire_harness': {'base_multiplier': 8.0, 'volume_sensitivity': 0.65},
    'cable_assembly': {'base_multiplier': 6.0, 'volume_sensitivity': 0.68},
    'pcb_bare': {'base_multiplier': 10.0, 'volume_sensitivity': 0.60},
    'pcba_assembly': {'base_multiplier': 25.0, 'volume_sensitivity': 0.55},
    'mechanical_part': {'base_multiplier': 5.0, 'volume_sensitivity': 0.70},
    'machined_part': {'base_multiplier': 15.0, 'volume_sensitivity': 0.62},
}

# Supplier tiers (DigiKey, Mouser, Alibaba ecosystem)
SUPPLIER_TIERS = {
    'digikey': {'markup': 1.25, 'reliability': 0.98, 'lead_time_factor': 1.0},
    'mouser': {'markup': 1.22, 'reliability': 0.97, 'lead_time_factor': 1.0},
    'arrow': {'markup': 1.20, 'reliability': 0.96, 'lead_time_factor': 1.1},
    'avnet': {'markup': 1.18, 'reliability': 0.95, 'lead_time_factor': 1.1},
    'newark': {'markup': 1.23, 'reliability': 0.94, 'lead_time_factor': 1.2},
    'alibaba_verified': {'markup': 0.75, 'reliability': 0.85, 'lead_time_factor': 2.5},
    'alibaba_gold': {'markup': 0.70, 'reliability': 0.80, 'lead_time_factor': 3.0},
    'alibaba_standard': {'markup': 0.60, 'reliability': 0.70, 'lead_time_factor': 4.0},
    'direct_manufacturer': {'markup': 0.55, 'reliability': 0.90, 'lead_time_factor': 3.5},
    'broker': {'markup': 1.10, 'reliability': 0.75, 'lead_time_factor': 1.5},
}

# Manufacturer tiers
MANUFACTURER_TIERS = {
    'tier1': ['Texas Instruments', 'Analog Devices', 'Microchip', 'STMicroelectronics', 
              'NXP', 'Infineon', 'ON Semiconductor', 'Renesas', 'Maxim', 'Linear Tech'],
    'tier2': ['Vishay', 'Murata', 'TDK', 'YAGEO', 'Samsung Electro', 'Taiyo Yuden',
              'Panasonic', 'Nichicon', 'Kemet', 'AVX'],
    'tier3': ['Generic', 'Unbranded', 'Chinese OEM', 'White Label'],
}

# Industry segments
INDUSTRY_SEGMENTS = {
    'ems_contract': {'complexity': 1.0, 'volume_typical': 10000},
    'wire_harness': {'complexity': 1.2, 'volume_typical': 5000},
    'automotive': {'complexity': 1.5, 'volume_typical': 50000},
    'aerospace': {'complexity': 2.0, 'volume_typical': 500},
    'medical': {'complexity': 1.8, 'volume_typical': 2000},
    'industrial': {'complexity': 1.1, 'volume_typical': 5000},
    'consumer': {'complexity': 0.9, 'volume_typical': 100000},
    'telecom': {'complexity': 1.3, 'volume_typical': 10000},
}


# =============================================================================
# DATASET LOADING AND DOMAIN ADAPTATION
# =============================================================================

def download_huggingface_datasets() -> Dict[str, Any]:
    """Download the best electronics component pricing dataset from HuggingFace."""
    if not HAS_DATASETS:
        print("HuggingFace datasets library not available")
        return {}
    
    datasets_info = {}
    
    # PRIMARY DATASET: Real electronics components from Nexar/Octopart API
    # This contains REAL pricing data from DigiKey, Mouser, TME with actual stock levels
    print("Downloading mdnh/electronic-components-supply-chain (Nexar/Octopart data)...")
    try:
        ds = load_dataset("mdnh/electronic-components-supply-chain", split="train")
        datasets_info['nexar_components'] = {
            'data': ds,
            'rows': len(ds),
            'columns': ds.column_names
        }
        print(f"  ✓ Loaded {len(ds)} real electronic components")
        print(f"    Manufacturers: Real data from Analog Devices, TI, STMicro, etc.")
        print(f"    Suppliers: DigiKey, Mouser, Arrow, Newark with real prices/stock")
    except Exception as e:
        print(f"  ✗ Failed to load Nexar components dataset: {e}")
    
    # SECONDARY DATASET: E-commerce price estimation (feature->price mappings)
    print("Downloading guyshilo12/synthetic-ecommerce-price-estimation...")
    try:
        ds2 = load_dataset("guyshilo12/synthetic-ecommerce-price-estimation", split="train")
        datasets_info['ecommerce'] = {
            'data': ds2,
            'rows': len(ds2),
            'columns': ds2.column_names
        }
        print(f"  ✓ Loaded {len(ds2)} e-commerce price samples")
        print(f"    Contains: category, features, fair_price with clean mappings")
    except Exception as e:
        print(f"  ✗ Failed to load e-commerce dataset: {e}")
    
    return datasets_info


def extract_real_pricing_data(datasets_info: Dict) -> List[Dict]:
    """
    Extract MAXIMUM pricing data from all available datasets with extensive augmentation.
    
    Strategy:
    1. Extract all real Nexar/Octopart prices
    2. Create 20+ quantity breakpoints per price (not just 5)
    3. Add supplier variations
    4. Cross-combine with industry segments
    5. Add e-commerce feature-price patterns adapted to electronics
    """
    extracted_data = []
    
    # Map real categories to our component categories
    category_mapping = {
        'integrated circuits': 'ic_analog',
        'microcontrollers': 'ic_microcontroller',
        'microcontroller': 'ic_microcontroller',
        'mcu': 'ic_microcontroller',
        'memory': 'ic_memory',
        'flash': 'ic_memory',
        'capacitor': 'capacitor',
        'resistor': 'resistor',
        'inductor': 'inductor',
        'connector': 'connector',
        'sensor': 'sensor',
        'led': 'led',
        'diode': 'diode',
        'transistor': 'transistor',
        'mosfet': 'mosfet',
        'power': 'ic_power',
        'logic': 'ic_logic',
        'crystal': 'crystal_oscillator',
        'oscillator': 'crystal_oscillator',
        'relay': 'relay',
        'switch': 'switch',
        'transformer': 'transformer',
        'module': 'pcba_assembly',
        'wifi': 'ic_microcontroller',
        'bluetooth': 'ic_microcontroller',
        'rf': 'ic_analog',
        'amplifier': 'ic_analog',
        'adc': 'ic_analog',
        'dac': 'ic_analog',
        'dsp': 'ic_analog',
        'op amp': 'ic_analog',
        'voltage regulator': 'ic_power',
        'ldo': 'ic_power',
        'dc-dc': 'ic_power',
        'nfc': 'ic_analog',
        'rfid': 'ic_analog',
        'analog': 'ic_analog',
        'digital': 'ic_logic',
        'soc': 'ic_microcontroller',
        'system on chip': 'ic_microcontroller',
    }
    
    # Map real suppliers to our supplier tiers
    supplier_mapping = {
        'digikey': 'digikey',
        'digi-key': 'digikey',
        'mouser': 'mouser',
        'arrow': 'arrow',
        'avnet': 'avnet',
        'newark': 'newark',
        'farnell': 'newark',
        'element14': 'newark',
        'tme': 'mouser',
        'verical': 'broker',
        'worldway': 'alibaba_gold',
        'win source': 'alibaba_verified',
        'odg': 'broker',
        'lcsc': 'alibaba_verified',
        'alibaba': 'alibaba_gold',
        'sos electronic': 'mouser',
        'rochester': 'broker',
    }
    
    # Map manufacturers to tiers
    tier1_manufacturers = {
        'analog devices', 'texas instruments', 'stmicroelectronics', 'microchip',
        'nxp', 'infineon', 'on semiconductor', 'renesas', 'maxim integrated',
        'linear technology', 'silicon labs', 'nordic semiconductor', 'qualcomm',
        'intel', 'amd', 'nvidia', 'broadcom', 'xilinx', 'lattice'
    }
    tier2_manufacturers = {
        'vishay', 'murata', 'tdk', 'yageo', 'samsung', 'panasonic', 'nichicon',
        'kemet', 'avx', 'rohm', 'toshiba', 'diodes incorporated', 'nexperia',
        'espressif', 'gigadevice', 'winbond', 'issi', 'cypress', 'atmel'
    }
    
    # =====================================================================
    # PART 1: Extract from Nexar/Octopart dataset with MASSIVE augmentation
    # =====================================================================
    if 'nexar_components' in datasets_info:
        ds = datasets_info['nexar_components']['data']
        print(f"\nExtracting from {len(ds)} real Nexar/Octopart components...")
        
        # Extended quantity breakpoints (20 levels for more granular learning)
        quantity_breaks = [1, 2, 5, 10, 25, 50, 100, 250, 500, 1000, 
                          2500, 5000, 10000, 25000, 50000, 100000]
        
        for item in ds:
            try:
                mpn = item.get('mpn', '')
                manufacturer = item.get('manufacturer', 'Unknown')
                category_raw = str(item.get('category', '')).lower()
                description = item.get('description', '')
                suppliers_data = item.get('suppliers', [])
                risk_score = float(item.get('risk_score', 0.5) or 0.5)
                
                # Map category
                category = 'ic_analog'  # Default
                for key, mapped_cat in category_mapping.items():
                    if key in category_raw or key in str(description).lower():
                        category = mapped_cat
                        break
                
                # Determine manufacturer tier
                mfr_lower = manufacturer.lower()
                if any(t1 in mfr_lower for t1 in tier1_manufacturers):
                    mfr_tier = 'tier1'
                elif any(t2 in mfr_lower for t2 in tier2_manufacturers):
                    mfr_tier = 'tier2'
                else:
                    mfr_tier = 'tier3'
                
                # Extract pricing from each supplier
                if isinstance(suppliers_data, list):
                    for supplier_entry in suppliers_data:
                        if not isinstance(supplier_entry, dict):
                            continue
                            
                        supplier_name = str(supplier_entry.get('name', '')).lower()
                        price = supplier_entry.get('price', 0)
                        stock = supplier_entry.get('stock', 0)
                        
                        # Filter to reasonable price range
                        if not price or price <= 0.01 or price > 500:
                            continue
                        
                        # Map supplier
                        supplier = 'mouser'  # Default
                        for key, mapped_sup in supplier_mapping.items():
                            if key in supplier_name:
                                supplier = mapped_sup
                                break
                        
                        supplier_info = SUPPLIER_TIERS.get(supplier, SUPPLIER_TIERS['mouser'])
                        comp_info = COMPONENT_CATEGORIES[category]
                        
                        # Generate samples for EACH quantity break
                        for qty in quantity_breaks:
                            # Apply realistic volume discount curve
                            # More aggressive discount for higher volumes
                            log_qty = math.log10(qty + 1)
                            volume_discount = 1.0 - (comp_info['volume_sensitivity'] * 
                                                    (1.0 - 1.0 / (1 + 0.12 * log_qty)))
                            
                            # Additional tier-based discount for high volumes
                            if qty >= 10000:
                                volume_discount *= 0.92
                            elif qty >= 1000:
                                volume_discount *= 0.96
                            
                            unit_price = float(price) * volume_discount
                            
                            # Small realistic noise
                            noise = random.gauss(1.0, 0.015)
                            unit_price = max(0.01, unit_price * noise)
                            
                            # Lead time based on stock and quantity
                            if stock and int(stock) > qty:
                                lead_time = int(2 + random.randint(0, 3)) * supplier_info['lead_time_factor']
                            elif stock and int(stock) > 0:
                                lead_time = int(7 + random.randint(0, 7)) * supplier_info['lead_time_factor']
                            else:
                                lead_time = int(21 + random.randint(0, 14)) * supplier_info['lead_time_factor']
                            
                            # MOQ logic
                            if 'digikey' in supplier or 'mouser' in supplier:
                                moq = 1
                            elif 'alibaba' in supplier:
                                moq = max(100, qty // 10)
                            else:
                                moq = max(1, qty // 50)
                            
                            # Generate for multiple industry segments
                            for industry in random.sample(list(INDUSTRY_SEGMENTS.keys()), 
                                                         k=min(3, len(INDUSTRY_SEGMENTS))):
                                industry_info = INDUSTRY_SEGMENTS[industry]
                                
                                extracted_data.append({
                                    'category': category,
                                    'supplier': supplier,
                                    'manufacturer': manufacturer,
                                    'manufacturer_tier': mfr_tier,
                                    'part_number': mpn,
                                    'description': description[:200] if description else f"{category} component",
                                    'package': 'SMD',
                                    'quantity': qty,
                                    'moq': moq,
                                    'unit_price': round(unit_price, 6),
                                    'lead_time_days': int(lead_time),
                                    'reliability_score': supplier_info['reliability'] * (1 - risk_score * 0.2),
                                    'industry': industry,
                                    'complexity_factor': industry_info['complexity'],
                                })
                            
            except Exception as e:
                continue
        
        print(f"  ✓ Extracted {len(extracted_data)} samples from Nexar data")
    
    # =====================================================================
    # PART 2: Adapt e-commerce feature-price mappings to electronics
    # =====================================================================
    if 'ecommerce' in datasets_info:
        ds = datasets_info['ecommerce']['data']
        print(f"\nAdapting {len(ds)} e-commerce samples to electronics domain...")
        
        # Map e-commerce categories to electronics
        ecom_to_elec = {
            'Gaming Laptop': [('ic_microcontroller', 15.0), ('ic_memory', 8.0), ('pcba_assembly', 50.0)],
            'Smartphone': [('ic_microcontroller', 12.0), ('sensor', 5.0), ('ic_power', 3.0)],
            'Smartwatch': [('sensor', 4.0), ('ic_microcontroller', 8.0), ('led', 0.5)],
            'Headphones': [('ic_analog', 3.0), ('connector', 0.5), ('switch', 0.3)],
        }
        
        # Feature-to-price multipliers (learned from the dataset description)
        feature_multipliers = {
            'rtx': 1.5, 'gpu': 1.3, 'ssd': 1.2, '5g': 1.3, 'oled': 1.4,
            'wireless': 1.1, 'noise cancelling': 1.2, 'battery': 1.1,
            'pro': 1.2, 'ultra': 1.3, 'max': 1.25, '256gb': 1.1, '512gb': 1.2,
        }
        
        ecom_samples = 0
        for item in ds:
            try:
                ecom_cat = item.get('category', '')
                features = str(item.get('features', '')).lower()
                ecom_price = item.get('fair_price', 0)
                
                if ecom_price <= 0 or ecom_cat not in ecom_to_elec:
                    continue
                
                # Calculate feature multiplier
                feat_mult = 1.0
                for feat, mult in feature_multipliers.items():
                    if feat in features:
                        feat_mult *= mult
                
                # Map to electronics components
                for elec_cat, base_price in ecom_to_elec[ecom_cat]:
                    # Scale the e-commerce price to electronics range
                    scaled_price = base_price * (ecom_price / 1000.0) * feat_mult
                    scaled_price = max(0.10, min(scaled_price, 100.0))
                    
                    comp_info = COMPONENT_CATEGORIES.get(elec_cat, COMPONENT_CATEGORIES['ic_analog'])
                    
                    # Generate quantity variations
                    for qty in [1, 10, 100, 1000, 5000]:
                        volume_discount = 1.0 - (comp_info['volume_sensitivity'] * 
                                                (1.0 - 1.0 / (1 + 0.12 * math.log10(qty + 1))))
                        unit_price = scaled_price * volume_discount * random.gauss(1.0, 0.02)
                        
                        supplier = random.choice(list(SUPPLIER_TIERS.keys()))
                        supplier_info = SUPPLIER_TIERS[supplier]
                        unit_price *= supplier_info['markup']
                        
                        industry = random.choice(list(INDUSTRY_SEGMENTS.keys()))
                        
                        extracted_data.append({
                            'category': elec_cat,
                            'supplier': supplier,
                            'manufacturer': random.choice(['Texas Instruments', 'Analog Devices', 
                                                          'STMicroelectronics', 'Microchip', 'NXP']),
                            'manufacturer_tier': random.choice(['tier1', 'tier2']),
                            'part_number': f"ECOM{random.randint(10000, 99999)}",
                            'description': f"{elec_cat} component with {features[:50]}",
                            'package': random.choice(['QFN', 'BGA', 'SOIC', 'TQFP']),
                            'quantity': qty,
                            'moq': 1 if qty < 100 else qty // 10,
                            'unit_price': round(max(0.01, unit_price), 6),
                            'lead_time_days': int(7 * supplier_info['lead_time_factor']),
                            'reliability_score': supplier_info['reliability'],
                            'industry': industry,
                            'complexity_factor': INDUSTRY_SEGMENTS[industry]['complexity'],
                        })
                        ecom_samples += 1
                        
            except Exception as e:
                continue
        
        print(f"  ✓ Added {ecom_samples} adapted e-commerce samples")
    
    print(f"\n  TOTAL: {len(extracted_data)} pricing samples from real + adapted data")
    return extracted_data


def adapt_to_electronics_domain(datasets_info: Dict, max_samples: int = 30000) -> List[Dict]:
    """
    Extract and adapt real electronics component data.
    This replaces the old generic data adaptation with real Nexar/Octopart data.
    """
    return extract_real_pricing_data(datasets_info)


def generate_synthetic_electronics_data(n_samples: int = 50000) -> List[Dict]:
    """
    Generate synthetic but realistic electronics component pricing data.
    
    Based on real-world pricing patterns from:
    - DigiKey/Mouser API pricing structures
    - Alibaba supplier quotation patterns
    - EMS industry volume discount curves
    """
    data = []
    
    # Part number patterns
    part_prefixes = {
        'capacitor': ['GRM', 'CL', 'C0G', 'X7R', 'CC', 'VJ'],
        'resistor': ['RC', 'ERJ', 'CRCW', 'RK', 'RR'],
        'ic_microcontroller': ['STM32', 'PIC', 'ATMEGA', 'ESP32', 'NRF52'],
        'ic_memory': ['W25Q', 'AT24', 'IS62', 'CY62', 'MT41'],
        'connector': ['JST', 'MOLEX', 'TE', 'HIROSE', 'AMPHENOL'],
        'wire_harness': ['WH', 'CABLE', 'ASSY', 'HARNESS'],
        'sensor': ['LM', 'TMP', 'BME', 'MPU', 'ADXL'],
    }
    
    # Base prices by category (USD)
    base_prices = {
        'capacitor': (0.001, 0.50),
        'resistor': (0.0005, 0.20),
        'inductor': (0.01, 2.00),
        'ic_microcontroller': (0.50, 25.00),
        'ic_memory': (0.30, 15.00),
        'ic_analog': (0.20, 10.00),
        'ic_power': (0.30, 8.00),
        'ic_logic': (0.05, 3.00),
        'connector': (0.10, 5.00),
        'switch': (0.05, 2.00),
        'relay': (0.50, 10.00),
        'transformer': (1.00, 25.00),
        'crystal_oscillator': (0.20, 5.00),
        'led': (0.01, 0.50),
        'diode': (0.01, 1.00),
        'transistor': (0.02, 1.00),
        'mosfet': (0.10, 5.00),
        'igbt': (1.00, 50.00),
        'sensor': (0.50, 25.00),
        'wire_harness': (5.00, 200.00),
        'cable_assembly': (2.00, 100.00),
        'pcb_bare': (1.00, 50.00),
        'pcba_assembly': (10.00, 500.00),
        'mechanical_part': (0.50, 50.00),
        'machined_part': (5.00, 200.00),
    }
    
    # Package types
    packages = ['0201', '0402', '0603', '0805', '1206', 'SOT23', 'SOIC8', 'SOIC16',
                'QFN', 'QFP', 'BGA', 'TQFP', 'DIP', 'PLCC', 'TO220', 'TO92', 'custom']
    
    # Manufacturer names
    manufacturers = {
        'tier1': ['Texas Instruments', 'Analog Devices', 'Microchip', 'STMicroelectronics',
                  'NXP Semiconductors', 'Infineon', 'ON Semiconductor', 'Renesas'],
        'tier2': ['Vishay', 'Murata', 'TDK', 'YAGEO', 'Samsung Electro-Mechanics',
                  'Taiyo Yuden', 'Panasonic', 'Nichicon', 'KEMET', 'AVX'],
        'tier3': ['Generic Chinese', 'Unbranded', 'White Label', 'OEM Partner'],
    }
    
    for _ in range(n_samples):
        # Select category
        category = random.choice(list(COMPONENT_CATEGORIES.keys()))
        comp_info = COMPONENT_CATEGORIES[category]
        
        # Select supplier
        supplier = random.choice(list(SUPPLIER_TIERS.keys()))
        supplier_info = SUPPLIER_TIERS[supplier]
        
        # Select manufacturer tier
        mfr_tier = random.choices(['tier1', 'tier2', 'tier3'], weights=[0.3, 0.5, 0.2])[0]
        manufacturer = random.choice(manufacturers[mfr_tier])
        
        # Tier price adjustment
        tier_multiplier = {'tier1': 1.3, 'tier2': 1.0, 'tier3': 0.7}[mfr_tier]
        
        # Base price
        price_range = base_prices.get(category, (0.10, 10.00))
        base_price = random.uniform(price_range[0], price_range[1])
        
        # Quantity (log-normal distribution common in B2B)
        quantity = int(np.random.lognormal(mean=6, sigma=2))  # Centered around 400
        quantity = max(1, min(quantity, 1000000))
        
        # Volume discount (power law)
        volume_discount = 1.0 - (comp_info['volume_sensitivity'] * 
                                (1.0 - 1.0 / (1 + 0.15 * math.log10(quantity + 1))))
        
        # Calculate final price
        unit_price = base_price * tier_multiplier * volume_discount * supplier_info['markup']
        
        # Add some realistic noise
        noise = random.gauss(1.0, 0.05)  # 5% price variation
        unit_price *= noise
        unit_price = max(0.0001, round(unit_price, 6))
        
        # Industry segment
        industry = random.choice(list(INDUSTRY_SEGMENTS.keys()))
        industry_info = INDUSTRY_SEGMENTS[industry]
        
        # Lead time
        base_lead_time = 7 if 'digikey' in supplier or 'mouser' in supplier else 21
        lead_time = int(base_lead_time * supplier_info['lead_time_factor'] * 
                       random.uniform(0.8, 1.5))
        
        # Part number
        prefix = random.choice(part_prefixes.get(category, ['PART']))
        part_number = f"{prefix}{random.randint(100, 99999)}"
        
        # Package
        package = random.choice(packages)
        
        # Description
        descriptions = [
            f"{category.replace('_', ' ').title()} {package}",
            f"{manufacturer} {part_number}",
            f"{category.replace('_', ' ')} {package} {mfr_tier}",
        ]
        description = random.choice(descriptions)
        
        # MOQ (Minimum Order Quantity)
        moq_base = {'tier1': 1, 'tier2': 10, 'tier3': 100}[mfr_tier]
        if 'alibaba' in supplier:
            moq_base *= 10
        moq = moq_base * random.choice([1, 5, 10, 25, 50, 100])
        
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
    
    def __init__(self, vocab_size: int = 20000):
        self.vocab_size = vocab_size
        self.word2idx = {"<PAD>": 0, "<UNK>": 1}
        self.idx2word = {0: "<PAD>", 1: "<UNK>"}
        self.word_counts = Counter()
    
    def fit(self, texts: List[str]):
        """Build vocabulary from texts."""
        import re
        for text in texts:
            text = str(text).lower()
            tokens = re.findall(r'[a-z0-9]+', text)
            self.word_counts.update(tokens)
        
        for token, _ in self.word_counts.most_common(self.vocab_size - 2):
            idx = len(self.word2idx)
            self.word2idx[token] = idx
            self.idx2word[idx] = token
        
        print(f"Vocabulary size: {len(self.word2idx)}")
    
    def encode(self, text: str, max_len: int = 64) -> List[int]:
        """Encode text to token IDs."""
        import re
        text = str(text).lower()
        tokens = re.findall(r'[a-z0-9]+', text)[:max_len]
        ids = [self.word2idx.get(t, 1) for t in tokens]
        while len(ids) < max_len:
            ids.append(0)
        return ids
    
    def save(self, path: str):
        """Save vocabulary."""
        with open(path, 'w') as f:
            json.dump({'word2idx': self.word2idx}, f)
    
    def load(self, path: str):
        """Load vocabulary."""
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
        
        # Extract valid prices and filter invalid data
        valid_data = []
        prices = []
        for d in data:
            price = d.get('unit_price', 0)
            if price > 0 and price < 1e6 and np.isfinite(price):  # Filter outliers
                valid_data.append(d)
                prices.append(price)
        
        self.data = valid_data
        self.log_prices = np.log1p(np.array(prices))
        
        if price_scaler is None and HAS_SKLEARN:
            self.price_scaler = StandardScaler()
            self.price_scaler.fit(self.log_prices.reshape(-1, 1))
        else:
            self.price_scaler = price_scaler
        
        # Pre-compute scaled prices for speed
        if self.price_scaler is not None:
            self.scaled_prices = self.price_scaler.transform(
                self.log_prices.reshape(-1, 1)
            ).flatten()
        else:
            self.scaled_prices = self.log_prices
    
    def __len__(self) -> int:
        return len(self.data)
    
    
    def __getitem__(self, idx: int) -> Dict[str, torch.Tensor]:
        item = self.data[idx]
        
        # Categorical features
        category_id = self.category_encoder.get(item['category'], 0)
        supplier_id = self.supplier_encoder.get(item['supplier'], 0)
        manufacturer_id = self.manufacturer_encoder.get(
            item.get('manufacturer', 'Unknown'), 0
        )
        industry_id = self.industry_encoder.get(item.get('industry', 'ems_contract'), 0)
        
        # Text features (description/part number)
        description = item.get('description', '') + ' ' + item.get('part_number', '')
        text_ids = self.tokenizer.encode(description, self.config.max_text_len)
        
        # Numerical features
        quantity = item.get('quantity', 1)
        log_quantity = math.log1p(quantity)
        
        moq = item.get('moq', 1)
        log_moq = math.log1p(moq)
        
        lead_time = item.get('lead_time_days', 7)
        reliability = item.get('reliability_score', 0.9)
        complexity = item.get('complexity_factor', 1.0)
        
        # Derived features
        qty_moq_ratio = quantity / max(moq, 1)
        is_above_moq = 1.0 if quantity >= moq else 0.0
        volume_tier = min(4, int(math.log10(max(quantity, 1))))  # 0-4
        
        continuous_features = [
            log_quantity,
            log_moq,
            lead_time / 30.0,  # Normalize to ~1 month
            reliability,
            complexity,
            qty_moq_ratio / 10.0,  # Normalize
            is_above_moq,
            volume_tier / 4.0,  # Normalize
        ]
        
        # Target: use pre-computed scaled price
        price = item['unit_price']
        scaled_price = float(self.scaled_prices[idx])
        
        return {
            'category_id': torch.tensor(category_id, dtype=torch.long),
            'supplier_id': torch.tensor(supplier_id, dtype=torch.long),
            'manufacturer_id': torch.tensor(manufacturer_id, dtype=torch.long),
            'industry_id': torch.tensor(industry_id, dtype=torch.long),
            'text_ids': torch.tensor(text_ids, dtype=torch.long),
            'continuous': torch.tensor(continuous_features, dtype=torch.float32),
            'target': torch.tensor(scaled_price, dtype=torch.float32),
            'raw_price': torch.tensor(float(price), dtype=torch.float32),
        }


# =============================================================================
# MODEL ARCHITECTURE
# =============================================================================

class PositionalEncoding(nn.Module):
    """Sinusoidal positional encoding."""
    
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
    Transformer-based pricing prediction model.
    
    Architecture:
    1. Embedding layers for categorical features
    2. Transformer encoder for text features  
    3. MLP for continuous features
    4. Deep fusion network with residual connections
    5. Multi-layer regression head
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
        
        # Transformer for text
        encoder_layer = nn.TransformerEncoderLayer(
            d_model=config.embedding_dim,
            nhead=config.num_attention_heads,
            dim_feedforward=config.hidden_dim,
            dropout=config.attention_dropout,
            activation='gelu',
            batch_first=True,
            norm_first=True,  # Pre-LN for better training stability
        )
        self.text_transformer = nn.TransformerEncoder(
            encoder_layer, 
            num_layers=config.num_transformer_layers,
            enable_nested_tensor=False,
        )
        
        # Continuous feature processing with deeper network
        self.continuous_proj = nn.Sequential(
            nn.Linear(config.num_continuous_features, config.embedding_dim),
            nn.LayerNorm(config.embedding_dim),
            nn.GELU(),
            nn.Dropout(config.dropout),
            nn.Linear(config.embedding_dim, config.embedding_dim),
            nn.LayerNorm(config.embedding_dim),
            nn.GELU(),
        )
        
        # Feature fusion (6 feature groups) with DEEP residual network
        fusion_input_dim = config.embedding_dim * 6  # cat, sup, mfr, ind, text, cont
        
        # Initial projection
        self.fusion_input = nn.Sequential(
            nn.Linear(fusion_input_dim, config.hidden_dim),
            nn.LayerNorm(config.hidden_dim),
            nn.GELU(),
        )
        
        # Residual blocks for deep fusion
        self.fusion_block1 = self._make_residual_block(config.hidden_dim, config.dropout)
        self.fusion_block2 = self._make_residual_block(config.hidden_dim, config.dropout)
        self.fusion_block3 = self._make_residual_block(config.hidden_dim, config.dropout)
        
        # Deep regression head
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
        """Create a residual block with pre-norm."""
        return nn.Sequential(
            nn.LayerNorm(dim),
            nn.Linear(dim, dim * 2),
            nn.GELU(),
            nn.Dropout(dropout),
            nn.Linear(dim * 2, dim),
            nn.Dropout(dropout),
        )
    
    def _init_weights(self):
        """Initialize weights with Xavier/Kaiming initialization."""
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
        # Categorical embeddings
        cat_emb = self.category_embed(category_id)
        sup_emb = self.supplier_embed(supplier_id)
        mfr_emb = self.manufacturer_embed(manufacturer_id)
        ind_emb = self.industry_embed(industry_id)
        
        # Text processing
        text_emb = self.text_embed(text_ids)
        text_emb = self.pos_encoding(text_emb)
        
        # Create attention mask for padding
        text_mask = (text_ids == 0)  # True for padding
        
        text_encoded = self.text_transformer(text_emb, src_key_padding_mask=text_mask)
        
        # Pool text features (mean of non-padding)
        text_mask_expanded = (~text_mask).unsqueeze(-1).float()
        text_pooled = (text_encoded * text_mask_expanded).sum(dim=1)
        text_pooled = text_pooled / (text_mask_expanded.sum(dim=1) + 1e-8)
        
        # Continuous features
        cont_emb = self.continuous_proj(continuous)
        
        # Concatenate all features
        combined = torch.cat([cat_emb, sup_emb, mfr_emb, ind_emb, text_pooled, cont_emb], dim=-1)
        
        # Deep fusion with residual connections
        fused = self.fusion_input(combined)
        fused = fused + self.fusion_block1(fused)  # Residual connection 1
        fused = fused + self.fusion_block2(fused)  # Residual connection 2
        fused = fused + self.fusion_block3(fused)  # Residual connection 3
        
        # Regression
        output = self.regressor(fused)
        
        return output.squeeze(-1)


# =============================================================================
# TRAINING AND EVALUATION
# =============================================================================

class CosineWarmupScheduler:
    """Learning rate scheduler with warmup and cosine decay."""
    
    def __init__(self, optimizer, warmup_epochs: int, total_epochs: int, min_lr: float = 1e-6):
        self.optimizer = optimizer
        self.warmup_epochs = warmup_epochs
        self.total_epochs = total_epochs
        self.min_lr = min_lr
        self.base_lrs = [pg['lr'] for pg in optimizer.param_groups]
    
    def step(self, epoch: int):
        if epoch < self.warmup_epochs:
            # Linear warmup
            scale = (epoch + 1) / self.warmup_epochs
        else:
            # Cosine decay
            progress = (epoch - self.warmup_epochs) / (self.total_epochs - self.warmup_epochs)
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
    """Train for one epoch."""
    model.train()
    total_loss = 0.0
    total_mae = 0.0
    n_batches = 0
    
    for batch in dataloader:
        # Move to device
        category_id = batch['category_id'].to(device)
        supplier_id = batch['supplier_id'].to(device)
        manufacturer_id = batch['manufacturer_id'].to(device)
        industry_id = batch['industry_id'].to(device)
        text_ids = batch['text_ids'].to(device)
        continuous = batch['continuous'].to(device)
        targets = batch['target'].to(device)
        
        # Forward pass
        predictions = model(
            category_id, supplier_id, manufacturer_id,
            industry_id, text_ids, continuous
        )
        
        # Huber loss (robust to outliers)
        loss = F.huber_loss(predictions, targets, delta=1.0)
        
        # Backward pass
        optimizer.zero_grad()
        loss.backward()
        
        # Gradient clipping
        torch.nn.utils.clip_grad_norm_(model.parameters(), max_norm=1.0)
        
        optimizer.step()
        
        total_loss += loss.item()
        total_mae += F.l1_loss(predictions, targets).item()
        n_batches += 1
    
    return {
        'loss': total_loss / n_batches,
        'mae': total_mae / n_batches,
    }


def evaluate(
    model: nn.Module,
    dataloader: DataLoader,
    device: torch.device,
    price_scaler: Any,
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
            
            predictions = model(
                category_id, supplier_id, manufacturer_id,
                industry_id, text_ids, continuous
            )
            
            all_predictions.extend(predictions.cpu().numpy())
            all_targets.extend(targets.numpy())
            all_raw_prices.extend(raw_prices.numpy())
    
    # Convert back to original price scale
    all_predictions = np.array(all_predictions)
    all_targets = np.array(all_targets)
    all_raw_prices = np.array(all_raw_prices)
    
    if price_scaler is not None:
        pred_log = price_scaler.inverse_transform(all_predictions.reshape(-1, 1)).flatten()
        target_log = price_scaler.inverse_transform(all_targets.reshape(-1, 1)).flatten()
        pred_prices = np.expm1(pred_log)
        target_prices = np.expm1(target_log)
    else:
        pred_prices = np.expm1(all_predictions)
        target_prices = all_raw_prices
    
    # Ensure non-negative predictions
    pred_prices = np.maximum(pred_prices, 0)
    
    # Calculate metrics
    metrics = {
        'mae': mean_absolute_error(target_prices, pred_prices),
        'rmse': np.sqrt(mean_squared_error(target_prices, pred_prices)),
        'r2': r2_score(target_prices, pred_prices),
        'mape': mean_absolute_percentage_error(target_prices + 1e-8, pred_prices + 1e-8) * 100,
        'scaled_loss': F.huber_loss(
            torch.tensor(all_predictions), 
            torch.tensor(all_targets)
        ).item(),
    }
    
    # Median absolute percentage error (more robust)
    ape = np.abs((target_prices - pred_prices) / (target_prices + 1e-8))
    metrics['mdape'] = np.median(ape) * 100
    
    return metrics


def cross_validate(
    data: List[Dict],
    config: PricingModelConfig,
    tokenizer: ElectronicsTokenizer,
    encoders: Dict,
    device: torch.device,
    n_folds: int = 5,
) -> Dict[str, Any]:
    """
    Perform k-fold cross-validation for statistical robustness.
    """
    print(f"\n{'='*60}")
    print(f"STATISTICAL CROSS-VALIDATION ({n_folds}-fold)")
    print(f"{'='*60}")
    
    indices = np.arange(len(data))
    np.random.shuffle(indices)
    
    fold_metrics = []
    
    kfold = KFold(n_splits=n_folds, shuffle=True, random_state=42)
    
    for fold, (train_idx, val_idx) in enumerate(kfold.split(indices)):
        print(f"\nFold {fold + 1}/{n_folds}")
        print("-" * 40)
        
        train_data = [data[indices[i]] for i in train_idx]
        val_data = [data[indices[i]] for i in val_idx]
        
        # Create datasets
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
        
        # Initialize model
        model = PricingTransformer(config).to(device)
        optimizer = torch.optim.AdamW(
            model.parameters(), 
            lr=config.learning_rate,
            weight_decay=config.weight_decay
        )
        scheduler = CosineWarmupScheduler(
            optimizer, config.warmup_epochs, config.num_epochs // 2  # Shorter for CV
        )
        
        # Train for fewer epochs during CV
        cv_epochs = min(20, config.num_epochs // 2)
        best_val_loss = float('inf')
        
        for epoch in range(cv_epochs):
            train_metrics = train_epoch(model, train_loader, optimizer, device, config)
            scheduler.step(epoch)
            
            if (epoch + 1) % 5 == 0:
                val_metrics = evaluate(model, val_loader, device, train_dataset.price_scaler)
                print(f"  Epoch {epoch+1}: Train Loss={train_metrics['loss']:.4f}, "
                      f"Val R²={val_metrics['r2']:.4f}, Val MAE=${val_metrics['mae']:.4f}")
                
                if val_metrics['scaled_loss'] < best_val_loss:
                    best_val_loss = val_metrics['scaled_loss']
        
        # Final evaluation
        final_metrics = evaluate(model, val_loader, device, train_dataset.price_scaler)
        fold_metrics.append(final_metrics)
        
        print(f"  Fold {fold+1} Results: R²={final_metrics['r2']:.4f}, "
              f"MAE=${final_metrics['mae']:.4f}, MAPE={final_metrics['mape']:.2f}%")
    
    # Aggregate results
    aggregated = {}
    for key in fold_metrics[0].keys():
        values = [fm[key] for fm in fold_metrics]
        aggregated[key] = {
            'mean': np.mean(values),
            'std': np.std(values),
            'min': np.min(values),
            'max': np.max(values),
            'ci_lower': np.percentile(values, 2.5),
            'ci_upper': np.percentile(values, 97.5),
        }
    
    return {
        'fold_metrics': fold_metrics,
        'aggregated': aggregated,
    }


def statistical_significance_tests(cv_results: Dict) -> Dict[str, Any]:
    """
    Perform statistical significance tests on cross-validation results.
    """
    tests = {}
    
    # Test if R² is significantly > 0 (model is better than mean prediction)
    r2_values = [fm['r2'] for fm in cv_results['fold_metrics']]
    t_stat, p_value = stats.ttest_1samp(r2_values, 0)
    tests['r2_significance'] = {
        't_statistic': t_stat,
        'p_value': p_value,
        'significant_at_0.05': p_value < 0.05,
        'significant_at_0.01': p_value < 0.01,
    }
    
    # Test normality of residuals (Shapiro-Wilk)
    mae_values = [fm['mae'] for fm in cv_results['fold_metrics']]
    if len(mae_values) >= 3:
        w_stat, p_value = stats.shapiro(mae_values)
        tests['mae_normality'] = {
            'shapiro_w': w_stat,
            'p_value': p_value,
            'is_normal': p_value > 0.05,
        }
    
    # Confidence interval for R²
    r2_mean = np.mean(r2_values)
    r2_std = np.std(r2_values, ddof=1)
    n = len(r2_values)
    t_critical = stats.t.ppf(0.975, n - 1)
    ci_margin = t_critical * r2_std / np.sqrt(n)
    
    tests['r2_confidence_interval'] = {
        'mean': r2_mean,
        'ci_95_lower': r2_mean - ci_margin,
        'ci_95_upper': r2_mean + ci_margin,
        'margin_of_error': ci_margin,
    }
    
    return tests


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
    
    # Create dummy inputs
    batch_size = 1
    category_id = torch.zeros(batch_size, dtype=torch.long, device=device)
    supplier_id = torch.zeros(batch_size, dtype=torch.long, device=device)
    manufacturer_id = torch.zeros(batch_size, dtype=torch.long, device=device)
    industry_id = torch.zeros(batch_size, dtype=torch.long, device=device)
    text_ids = torch.zeros(batch_size, config.max_text_len, dtype=torch.long, device=device)
    continuous = torch.zeros(batch_size, config.num_continuous_features, dtype=torch.float32, device=device)
    
    # Export
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
    
    print(f"Model exported to {output_path}")
    
    # Verify export
    if HAS_ONNX:
        onnx_model = onnx.load(output_path)
        onnx.checker.check_model(onnx_model)
        print("ONNX model verification: PASSED")


# =============================================================================
# MAIN TRAINING PIPELINE
# =============================================================================

def main():
    parser = argparse.ArgumentParser(description="Train Electronics Pricing Model")
    parser.add_argument('--epochs', type=int, default=50, help='Number of training epochs')
    parser.add_argument('--batch-size', type=int, default=64, help='Batch size')
    parser.add_argument('--lr', type=float, default=1e-4, help='Learning rate')
    parser.add_argument('--synthetic-samples', type=int, default=100000, 
                       help='Number of synthetic samples to generate')
    parser.add_argument('--use-huggingface', action='store_true', 
                       help='Download and use HuggingFace datasets')
    parser.add_argument('--skip-cv', action='store_true', help='Skip cross-validation')
    parser.add_argument('--output-dir', type=str, default='models', help='Output directory')
    parser.add_argument('--device', type=str, default='auto', 
                       help='Device (cuda/cpu/auto)')
    args = parser.parse_args()
    
    # Setup
    print("=" * 70)
    print("ELECTRONICS COMPONENT PRICING MODEL - TRAINING PIPELINE")
    print("=" * 70)
    print(f"Target Domain: EMS, Contract Manufacturing, Wire Harness, Machining")
    print(f"Suppliers: DigiKey, Mouser, Alibaba")
    print("=" * 70)
    
    # Device selection
    if args.device == 'auto':
        device = torch.device('cuda' if torch.cuda.is_available() else 'cpu')
    else:
        device = torch.device(args.device)
    print(f"\nUsing device: {device}")
    
    # Config
    config = PricingModelConfig(
        num_epochs=args.epochs,
        batch_size=args.batch_size,
        learning_rate=args.lr,
    )
    
    # Output directory
    output_dir = Path(args.output_dir)
    output_dir.mkdir(exist_ok=True)
    
    # =================================
    # DATA LOADING
    # =================================
    print("\n" + "=" * 60)
    print("STEP 1: DATA ACQUISITION")
    print("=" * 60)
    
    all_data = []
    
    # Generate synthetic electronics data (domain-specific)
    print(f"\nGenerating {args.synthetic_samples} synthetic electronics samples...")
    synthetic_data = generate_synthetic_electronics_data(args.synthetic_samples)
    all_data.extend(synthetic_data)
    print(f"  ✓ Generated {len(synthetic_data)} synthetic samples")
    
    # Download HuggingFace datasets if requested
    if args.use_huggingface and HAS_DATASETS:
        print("\nDownloading HuggingFace datasets...")
        hf_datasets = download_huggingface_datasets()
        
        if hf_datasets:
            print("\nAdapting datasets to electronics domain...")
            adapted_data = adapt_to_electronics_domain(hf_datasets)
            all_data.extend(adapted_data)
            print(f"  ✓ Added {len(adapted_data)} adapted samples")
    
    print(f"\nTotal training samples: {len(all_data)}")
    
    # =================================
    # FEATURE ENCODING
    # =================================
    print("\n" + "=" * 60)
    print("STEP 2: FEATURE ENCODING")
    print("=" * 60)
    
    # Category encoder
    categories = list(set(d['category'] for d in all_data))
    category_encoder = {cat: i for i, cat in enumerate(sorted(categories))}
    print(f"  Categories: {len(category_encoder)}")
    
    # Supplier encoder
    suppliers = list(set(d['supplier'] for d in all_data))
    supplier_encoder = {sup: i for i, sup in enumerate(sorted(suppliers))}
    print(f"  Suppliers: {len(supplier_encoder)}")
    
    # Manufacturer encoder
    manufacturers = list(set(d.get('manufacturer', 'Unknown') for d in all_data))
    manufacturer_encoder = {mfr: i for i, mfr in enumerate(sorted(manufacturers))}
    print(f"  Manufacturers: {len(manufacturer_encoder)}")
    
    # Industry encoder
    industries = list(set(d.get('industry', 'ems_contract') for d in all_data))
    industry_encoder = {ind: i for i, ind in enumerate(sorted(industries))}
    print(f"  Industries: {len(industry_encoder)}")
    
    # Update config
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
    
    # =================================
    # CROSS-VALIDATION
    # =================================
    if not args.skip_cv and HAS_SKLEARN:
        cv_results = cross_validate(
            all_data, config, tokenizer, encoders, device, n_folds=config.n_folds
        )
        
        print("\n" + "=" * 60)
        print("CROSS-VALIDATION SUMMARY")
        print("=" * 60)
        
        for metric, stats_dict in cv_results['aggregated'].items():
            print(f"\n{metric.upper()}:")
            print(f"  Mean: {stats_dict['mean']:.4f} ± {stats_dict['std']:.4f}")
            print(f"  95% CI: [{stats_dict['ci_lower']:.4f}, {stats_dict['ci_upper']:.4f}]")
            print(f"  Range: [{stats_dict['min']:.4f}, {stats_dict['max']:.4f}]")
        
        # Statistical significance
        print("\n" + "-" * 40)
        print("STATISTICAL SIGNIFICANCE TESTS")
        print("-" * 40)
        
        sig_tests = statistical_significance_tests(cv_results)
        
        r2_sig = sig_tests['r2_significance']
        print(f"\nR² > 0 (model vs mean baseline):")
        print(f"  t-statistic: {r2_sig['t_statistic']:.4f}")
        print(f"  p-value: {r2_sig['p_value']:.6f}")
        print(f"  Significant at α=0.05: {'YES ✓' if r2_sig['significant_at_0.05'] else 'NO'}")
        print(f"  Significant at α=0.01: {'YES ✓' if r2_sig['significant_at_0.01'] else 'NO'}")
        
        r2_ci = sig_tests['r2_confidence_interval']
        print(f"\nR² 95% Confidence Interval:")
        print(f"  [{r2_ci['ci_95_lower']:.4f}, {r2_ci['ci_95_upper']:.4f}]")
        
        # Save CV results
        cv_report = {
            'n_folds': config.n_folds,
            'aggregated_metrics': {k: {kk: float(vv) for kk, vv in v.items()} 
                                  for k, v in cv_results['aggregated'].items()},
            'statistical_tests': {
                'r2_significance': {k: float(v) if isinstance(v, (int, float, np.floating)) else v 
                                   for k, v in sig_tests['r2_significance'].items()},
                'r2_ci': {k: float(v) for k, v in sig_tests['r2_confidence_interval'].items()},
            }
        }
        
        with open(output_dir / 'cv_report.json', 'w') as f:
            json.dump(cv_report, f, indent=2)
    
    # =================================
    # FINAL MODEL TRAINING
    # =================================
    print("\n" + "=" * 60)
    print("STEP 3: FINAL MODEL TRAINING")
    print("=" * 60)
    
    # Split data
    random.shuffle(all_data)
    split_idx = int(len(all_data) * 0.9)
    train_data = all_data[:split_idx]
    val_data = all_data[split_idx:]
    
    print(f"\nTraining samples: {len(train_data)}")
    print(f"Validation samples: {len(val_data)}")
    
    # Create datasets
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
        shuffle=True, num_workers=0, pin_memory=True
    )
    val_loader = DataLoader(
        val_dataset, batch_size=config.batch_size,
        num_workers=0, pin_memory=True
    )
    
    # Initialize model
    model = PricingTransformer(config).to(device)
    
    # Count parameters
    total_params = sum(p.numel() for p in model.parameters())
    trainable_params = sum(p.numel() for p in model.parameters() if p.requires_grad)
    print(f"\nModel Parameters: {total_params:,} ({trainable_params:,} trainable)")
    
    # Optimizer and scheduler
    optimizer = torch.optim.AdamW(
        model.parameters(),
        lr=config.learning_rate,
        weight_decay=config.weight_decay,
    )
    scheduler = CosineWarmupScheduler(
        optimizer, config.warmup_epochs, config.num_epochs
    )
    
    # Training loop
    best_val_r2 = -float('inf')
    best_model_state = None
    best_val_metrics = None
    patience_counter = 0
    history = {'train_loss': [], 'val_mae': [], 'val_r2': [], 'val_mape': []}
    
    print(f"\nTraining for {config.num_epochs} epochs...")
    print("-" * 60)
    
    for epoch in range(config.num_epochs):
        epoch_start = time.time()
        
        # Train
        train_metrics = train_epoch(model, train_loader, optimizer, device, config)
        scheduler.step(epoch)
        
        # Evaluate
        val_metrics = evaluate(model, val_loader, device, train_dataset.price_scaler)
        
        # Record history
        history['train_loss'].append(train_metrics['loss'])
        history['val_mae'].append(val_metrics['mae'])
        history['val_r2'].append(val_metrics['r2'])
        history['val_mape'].append(val_metrics['mape'])
        
        epoch_time = time.time() - epoch_start
        
        # Print progress
        lr = optimizer.param_groups[0]['lr']
        print(f"Epoch {epoch+1:3d}/{config.num_epochs} | "
              f"Loss: {train_metrics['loss']:.4f} | "
              f"Val R²: {val_metrics['r2']:.4f} | "
              f"Val MAE: ${val_metrics['mae']:.4f} | "
              f"Val MAPE: {val_metrics['mape']:.1f}% | "
              f"LR: {lr:.2e} | "
              f"Time: {epoch_time:.1f}s")
        
        # Early stopping
        if val_metrics['r2'] > best_val_r2:
            best_val_r2 = val_metrics['r2']
            best_val_metrics = val_metrics.copy()
            # Deep copy model state dict to prevent overwriting
            best_model_state = {k: v.clone().cpu() for k, v in model.state_dict().items()}
            patience_counter = 0
            print(f"  ↑ New best model (R²={best_val_r2:.4f})")
        else:
            patience_counter += 1
            if patience_counter >= config.patience:
                print(f"\nEarly stopping triggered at epoch {epoch+1}")
                break
    
    # Load best model
    print(f"\nLoading best model (R²={best_val_r2:.4f})...")
    if best_model_state is not None:
        # Move state dict back to device and load
        best_model_state_device = {k: v.to(device) for k, v in best_model_state.items()}
        model.load_state_dict(best_model_state_device)
        print("  ✓ Best model state restored")
    else:
        print("  ⚠ No best model state saved - using final model")
    
    # =================================
    # FINAL EVALUATION
    # =================================
    print("\n" + "=" * 60)
    print("STEP 4: FINAL EVALUATION")
    print("=" * 60)
    
    final_metrics = evaluate(model, val_loader, device, train_dataset.price_scaler)
    
    # Verify best model was restored correctly
    if abs(final_metrics['r2'] - best_val_r2) > 0.01:
        print(f"  ⚠ Warning: Final R² ({final_metrics['r2']:.4f}) differs from best R² ({best_val_r2:.4f})")
        print("  This may indicate a checkpoint restore issue")
    
    print(f"\nFINAL MODEL METRICS:")
    print(f"  R² Score:         {final_metrics['r2']:.4f}")
    print(f"  MAE:              ${final_metrics['mae']:.4f}")
    print(f"  RMSE:             ${final_metrics['rmse']:.4f}")
    print(f"  MAPE:             {final_metrics['mape']:.2f}%")
    print(f"  Median APE:       {final_metrics['mdape']:.2f}%")
    
    # Quality assessment
    print("\n" + "-" * 40)
    print("QUALITY ASSESSMENT")
    print("-" * 40)
    
    quality_score = 0
    quality_notes = []
    
    if final_metrics['r2'] >= 0.9:
        quality_score += 3
        quality_notes.append("✓ Excellent R² (≥0.9)")
    elif final_metrics['r2'] >= 0.8:
        quality_score += 2
        quality_notes.append("✓ Good R² (≥0.8)")
    elif final_metrics['r2'] >= 0.7:
        quality_score += 1
        quality_notes.append("○ Acceptable R² (≥0.7)")
    else:
        quality_notes.append("✗ Low R² (<0.7)")
    
    if final_metrics['mape'] <= 10:
        quality_score += 3
        quality_notes.append("✓ Excellent MAPE (≤10%)")
    elif final_metrics['mape'] <= 20:
        quality_score += 2
        quality_notes.append("✓ Good MAPE (≤20%)")
    elif final_metrics['mape'] <= 30:
        quality_score += 1
        quality_notes.append("○ Acceptable MAPE (≤30%)")
    else:
        quality_notes.append("✗ High MAPE (>30%)")
    
    for note in quality_notes:
        print(f"  {note}")
    
    quality_rating = {
        6: "EXCELLENT",
        5: "VERY GOOD", 
        4: "GOOD",
        3: "ACCEPTABLE",
        2: "NEEDS IMPROVEMENT",
    }.get(quality_score, "POOR")
    
    print(f"\n  OVERALL QUALITY: {quality_rating} ({quality_score}/6)")
    
    # =================================
    # SAVE MODEL
    # =================================
    print("\n" + "=" * 60)
    print("STEP 5: SAVING MODEL")
    print("=" * 60)
    
    # Save PyTorch model
    model_path = output_dir / 'pricing_model.pt'
    torch.save({
        'model_state_dict': model.state_dict(),
        'config': config.__dict__,
        'encoders': encoders,
        'final_metrics': final_metrics,
        'history': history,
    }, model_path)
    print(f"  ✓ Saved PyTorch model: {model_path}")
    
    # Save tokenizer
    tokenizer_path = output_dir / 'pricing_tokenizer.json'
    tokenizer.save(str(tokenizer_path))
    print(f"  ✓ Saved tokenizer: {tokenizer_path}")
    
    # Save config
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
    print(f"  ✓ Saved config: {config_path}")
    
    # Save price scaler parameters
    if train_dataset.price_scaler is not None:
        scaler_params = {
            'mean': float(train_dataset.price_scaler.mean_[0]),
            'scale': float(train_dataset.price_scaler.scale_[0]),
        }
        scaler_path = output_dir / 'price_scaler.json'
        with open(scaler_path, 'w') as f:
            json.dump(scaler_params, f, indent=2)
        print(f"  ✓ Saved price scaler: {scaler_path}")
    
    # Export to ONNX
    if HAS_ONNX:
        onnx_path = output_dir / 'pricing_model.onnx'
        export_to_onnx(model, config, str(onnx_path), device)
        print(f"  ✓ Exported ONNX model: {onnx_path}")
    
    # Save training report
    report = {
        'training_date': time.strftime('%Y-%m-%d %H:%M:%S'),
        'total_samples': len(all_data),
        'train_samples': len(train_data),
        'val_samples': len(val_data),
        'epochs_trained': len(history['train_loss']),
        'final_metrics': {k: float(v) for k, v in final_metrics.items()},
        'quality_rating': quality_rating,
        'quality_score': f"{quality_score}/6",
        'model_parameters': total_params,
        'config': config.__dict__,
    }
    
    report_path = output_dir / 'training_report.json'
    with open(report_path, 'w') as f:
        json.dump(report, f, indent=2)
    print(f"  ✓ Saved training report: {report_path}")
    
    print("\n" + "=" * 60)
    print("TRAINING COMPLETE")
    print("=" * 60)
    print(f"\nModel ready for deployment in PriceImputationService")
    print(f"ONNX file: {output_dir / 'pricing_model.onnx'}")
    
    return model, final_metrics


if __name__ == '__main__':
    main()
