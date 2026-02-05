#!/usr/bin/env python3
"""
Lead Classifier v4 - Enhanced Architecture & Statistical Analysis
================================================================
Major improvements:
1. Multi-head attention model architecture
2. Comprehensive statistical analysis (cross-validation, calibration, ROC)
3. Data augmentation (synonym replacement, word dropout)
4. Learning rate scheduling with warmup
5. Enhanced domain-disjoint splitting
6. Gradient accumulation for larger effective batch sizes
7. Extended external dataset support
"""

import os
import sys
import json
import re
import random
import argparse
import math
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import List, Tuple, Optional, Dict, Any
from urllib.parse import urlparse
from collections import Counter, defaultdict

try:
    import torch
    import torch.nn as nn
    import torch.nn.functional as F
    from torch.utils.data import Dataset, DataLoader, Subset
    import onnxruntime as ort
    import numpy as np
except ImportError as e:
    print(f"Missing required package: {e}")
    print("Install with: pip install torch onnxruntime numpy")
    sys.exit(1)

# Optional advanced imports
try:
    from sklearn.model_selection import StratifiedKFold
    from sklearn.calibration import calibration_curve
    from sklearn.metrics import roc_curve, auc, precision_recall_curve, average_precision_score
    HAS_SKLEARN = True
except ImportError:
    HAS_SKLEARN = False
    print("Warning: scikit-learn not available, some analysis features disabled")


# =============================================================================
# CONFIG
# =============================================================================

@dataclass
class ModelConfig:
    # Architecture
    vocab_size: int = 50000  # Increased for more tokens
    embedding_dim: int = 192  # Increased from 128
    hidden_dim: int = 384     # Increased from 256
    num_attention_heads: int = 4  # NEW: Multi-head attention
    num_transformer_layers: int = 2  # NEW: Transformer encoder layers
    
    # Input lengths
    max_title_len: int = 64   # Increased from 48
    max_snippet_len: int = 128  # Increased from 96
    max_url_len: int = 64     # Increased from 48
    max_domain_len: int = 32  # Increased from 24
    
    # Regularization
    dropout: float = 0.3       # Slightly increased
    attention_dropout: float = 0.1
    label_smoothing: float = 0.1  # NEW: Label smoothing
    
    # Training
    batch_size: int = 48       # Slightly reduced for larger model
    gradient_accumulation_steps: int = 2  # Effective batch = 96
    learning_rate: float = 1e-4  # Reduced for stability
    weight_decay: float = 1e-3  # Increased regularization
    num_epochs: int = 15
    patience: int = 5
    warmup_epochs: int = 2     # NEW: LR warmup
    
    num_classes: int = 2


# =============================================================================
# DATA AUGMENTATION
# =============================================================================

class DataAugmenter:
    """Text augmentation for training robustness."""
    
    def __init__(self, seed: int = 42):
        self.rng = random.Random(seed)
        
        # Synonym dictionary for business terms
        self.synonyms = {
            'company': ['corporation', 'enterprise', 'firm', 'business', 'organization'],
            'manufacturing': ['production', 'fabrication', 'assembly', 'making'],
            'services': ['solutions', 'offerings', 'products'],
            'global': ['worldwide', 'international', 'multinational'],
            'leading': ['top', 'premier', 'major', 'best'],
            'solutions': ['services', 'products', 'systems', 'offerings'],
            'technology': ['tech', 'technologies', 'systems'],
            'electronics': ['electronic', 'electrical', 'circuits'],
            'industrial': ['industry', 'commercial', 'enterprise'],
            'automotive': ['auto', 'vehicle', 'motor'],
            'aerospace': ['aviation', 'aircraft', 'space'],
            'semiconductor': ['chip', 'ic', 'processor'],
            'data': ['information', 'digital'],
            'center': ['centre', 'facility', 'hub'],
            'components': ['parts', 'elements', 'modules'],
            'distribution': ['supply', 'logistics', 'delivery'],
        }
        
        # Common typos
        self.typo_map = {
            'the': 'teh',
            'and': 'adn',
            'services': 'sevices',
            'solutions': 'solutons',
            'company': 'compnay',
        }
    
    def augment(self, text: str, p: float = 0.3) -> str:
        """Apply random augmentations with probability p."""
        if self.rng.random() > p:
            return text
        
        augmentations = [
            self._synonym_replace,
            self._word_dropout,
            self._char_swap,
            self._case_change,
        ]
        
        aug_fn = self.rng.choice(augmentations)
        return aug_fn(text)
    
    def _synonym_replace(self, text: str) -> str:
        """Replace random words with synonyms."""
        words = text.lower().split()
        new_words = []
        for word in words:
            if word in self.synonyms and self.rng.random() < 0.3:
                new_words.append(self.rng.choice(self.synonyms[word]))
            else:
                new_words.append(word)
        return ' '.join(new_words)
    
    def _word_dropout(self, text: str) -> str:
        """Randomly drop words."""
        words = text.split()
        if len(words) <= 3:
            return text
        new_words = [w for w in words if self.rng.random() > 0.15]
        return ' '.join(new_words) if new_words else text
    
    def _char_swap(self, text: str) -> str:
        """Swap adjacent characters occasionally."""
        if len(text) < 4:
            return text
        text_list = list(text)
        pos = self.rng.randint(1, len(text) - 2)
        text_list[pos], text_list[pos + 1] = text_list[pos + 1], text_list[pos]
        return ''.join(text_list)
    
    def _case_change(self, text: str) -> str:
        """Random case changes."""
        if self.rng.random() < 0.5:
            return text.lower()
        elif self.rng.random() < 0.5:
            return text.upper()
        else:
            return text.title()


# =============================================================================
# EXTERNAL DATA LOADER
# =============================================================================

def load_all_external_data(data_dir: str = "external_data") -> List[Tuple[str, str, str, str, int]]:
    """Load all external training data from JSON files."""
    data_path = Path(data_dir)
    if not data_path.exists():
        print(f"External data directory not found: {data_path}")
        return []
    
    # Look for specific training data files first, then all JSON
    priority_files = [
        data_path / "extended_training_data.json",
        data_path / "external_training_data.json",
    ]
    
    # Get all JSON files
    json_files = [f for f in priority_files if f.exists()]
    
    # Also add any other JSON files (excluding split files)
    for f in data_path.glob("*.json"):
        if f not in json_files and not any(x in f.name for x in ['train.json', 'val.json', 'test.json']):
            json_files.append(f)
    
    # Known domain patterns for generating synthetic domains
    lead_domain_patterns = [
        "{word}-systems.com", "{word}-solutions.com", "{word}-corp.com",
        "{word}-inc.com", "{word}-group.com", "{word}tech.com",
        "{word}llc.com", "{word}-industries.com", "{word}works.com",
        "{word}-manufacturing.com", "{word}electronics.com", "{word}-ems.com",
    ]
    non_lead_domain_patterns = [
        "news-{word}.com", "{word}-news.org", "blog-{word}.net",
        "spam-{word}.xyz", "{word}-offers.com", "free-{word}.net",
        "get-{word}.info", "{word}-win.com", "social-{word}.io",
        "{word}-research.com", "{word}analysis.org", "market-{word}.net",
    ]
    
    all_data = []
    
    for json_file in json_files:
            
        try:
            with open(json_file, 'r', encoding='utf-8') as f:
                items = json.load(f)
            
            for item in items:
                title = item.get('title', '')
                snippet = item.get('snippet', '')
                label = item.get('label', 0)
                url = item.get('url', '')
                domain = item.get('domain', '')
                
                if not url or not domain:
                    words = re.sub(r'[^a-z0-9\s]', '', title.lower()).split()
                    base_word = words[0] if words else 'unknown'
                    base_word = base_word[:12]
                    
                    if label == 1:
                        pattern = random.choice(lead_domain_patterns)
                    else:
                        pattern = random.choice(non_lead_domain_patterns)
                    
                    domain = pattern.format(word=base_word)
                    url = f"https://www.{domain}/"
                
                all_data.append((title, snippet, url, domain, label))
            
            print(f"  Loaded {len(items)} samples from {json_file.name}")
            
        except Exception as e:
            print(f"  Error loading {json_file}: {e}")
    
    leads = sum(1 for d in all_data if d[4] == 1)
    print(f"Total external samples: {len(all_data)} ({leads} leads)")
    return all_data


# =============================================================================
# TOKENIZER
# =============================================================================

class EnhancedTokenizer:
    """Tokenizer with subword-like handling for better OOV handling."""
    
    def __init__(self, vocab_size: int = 50000):
        self.vocab_size = vocab_size
        self.word2idx = {"<PAD>": 0, "<UNK>": 1, "<CLS>": 2, "<SEP>": 3}
        self.idx2word = {v: k for k, v in self.word2idx.items()}
        self.word_counts = Counter()
    
    def _tokenize(self, text: str) -> List[str]:
        text = text.lower()
        # Keep more punctuation that might be informative
        text = re.sub(r"[^a-z0-9\-\.\s/]", " ", text)
        tokens = []
        for t in text.split():
            if t:
                # Split on common delimiters while preserving them
                parts = re.split(r'([./\-])', t)
                tokens.extend([p for p in parts if p])
        return tokens
    
    def fit(self, texts: List[str]):
        for text in texts:
            self.word_counts.update(self._tokenize(text))
        
        # Add most frequent tokens
        for tok, _ in self.word_counts.most_common(self.vocab_size - 4):
            idx = len(self.word2idx)
            if idx >= self.vocab_size:
                break
            self.word2idx[tok] = idx
            self.idx2word[idx] = tok
        
        print(f"Vocabulary size: {len(self.word2idx)}")
    
    def encode(self, text: str, max_len: int) -> List[int]:
        tokens = self._tokenize(text)[:max_len - 1]  # Leave room for CLS
        ids = [2]  # <CLS>
        ids.extend([self.word2idx.get(t, 1) for t in tokens])
        while len(ids) < max_len:
            ids.append(0)  # <PAD>
        return ids[:max_len]
    
    def save(self, path: str):
        with open(path, "w") as f:
            json.dump({"word2idx": self.word2idx}, f)


# =============================================================================
# ATTENTION MODEL
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
        pe[0, :, 1::2] = torch.cos(position * div_term)
        self.register_buffer('pe', pe)
    
    def forward(self, x: torch.Tensor) -> torch.Tensor:
        x = x + self.pe[:, :x.size(1)]
        return self.dropout(x)


class AttentionPooling(nn.Module):
    """Attention-based pooling for sequence representations."""
    
    def __init__(self, hidden_dim: int, dropout: float = 0.1):
        super().__init__()
        self.attention = nn.Sequential(
            nn.Linear(hidden_dim, hidden_dim // 2),
            nn.Tanh(),
            nn.Linear(hidden_dim // 2, 1),
        )
        self.dropout = nn.Dropout(dropout)
    
    def forward(self, x: torch.Tensor, mask: torch.Tensor) -> torch.Tensor:
        # x: (batch, seq_len, hidden_dim)
        # mask: (batch, seq_len) - 1 for valid, 0 for padding
        
        attn_weights = self.attention(x).squeeze(-1)  # (batch, seq_len)
        attn_weights = attn_weights.masked_fill(mask == 0, -1e9)
        attn_weights = F.softmax(attn_weights, dim=-1)
        attn_weights = self.dropout(attn_weights)
        
        # Weighted sum
        pooled = torch.bmm(attn_weights.unsqueeze(1), x).squeeze(1)  # (batch, hidden_dim)
        return pooled


class EnhancedLeadClassifier(nn.Module):
    """
    Enhanced lead classifier with:
    - Transformer encoder for each input
    - Multi-head self-attention
    - Attention-based pooling
    - Cross-field attention
    """
    
    def __init__(self, config: ModelConfig):
        super().__init__()
        self.config = config
        
        # Shared embedding with positional encoding
        self.embedding = nn.Embedding(config.vocab_size, config.embedding_dim, padding_idx=0)
        self.pos_encoding = PositionalEncoding(
            config.embedding_dim, 
            max_len=max(config.max_title_len, config.max_snippet_len, 
                       config.max_url_len, config.max_domain_len) + 10,
            dropout=config.dropout
        )
        
        # Projection to hidden dim
        self.input_proj = nn.Linear(config.embedding_dim, config.hidden_dim)
        
        # Transformer encoder for processing sequences
        encoder_layer = nn.TransformerEncoderLayer(
            d_model=config.hidden_dim,
            nhead=config.num_attention_heads,
            dim_feedforward=config.hidden_dim * 4,
            dropout=config.attention_dropout,
            activation='gelu',
            batch_first=True
        )
        self.transformer = nn.TransformerEncoder(encoder_layer, num_layers=config.num_transformer_layers)
        
        # Attention pooling for each field
        self.attn_pool = AttentionPooling(config.hidden_dim, config.dropout)
        
        # Field-specific projections
        self.field_projs = nn.ModuleDict({
            'title': nn.Linear(config.hidden_dim, config.hidden_dim),
            'snippet': nn.Linear(config.hidden_dim, config.hidden_dim),
            'url': nn.Linear(config.hidden_dim, config.hidden_dim),
            'domain': nn.Linear(config.hidden_dim, config.hidden_dim),
        })
        
        # Layer norm
        self.layer_norm = nn.LayerNorm(config.hidden_dim * 4)
        
        # Classifier head
        self.dropout = nn.Dropout(config.dropout)
        self.classifier = nn.Sequential(
            nn.Linear(config.hidden_dim * 4, config.hidden_dim * 2),
            nn.GELU(),
            nn.Dropout(config.dropout),
            nn.Linear(config.hidden_dim * 2, config.hidden_dim),
            nn.GELU(),
            nn.Dropout(config.dropout),
            nn.Linear(config.hidden_dim, config.num_classes),
        )
        
        # Initialize weights
        self._init_weights()
    
    def _init_weights(self):
        for module in self.modules():
            if isinstance(module, nn.Linear):
                nn.init.xavier_uniform_(module.weight)
                if module.bias is not None:
                    nn.init.zeros_(module.bias)
            elif isinstance(module, nn.Embedding):
                nn.init.normal_(module.weight, mean=0, std=0.02)
                if module.padding_idx is not None:
                    module.weight.data[module.padding_idx].zero_()
    
    def _encode_field(self, x: torch.Tensor, field_name: str) -> torch.Tensor:
        # x: (batch, seq_len)
        mask = (x != 0).float()  # (batch, seq_len)
        
        # Embedding + positional
        emb = self.embedding(x)  # (batch, seq_len, emb_dim)
        emb = self.pos_encoding(emb)
        
        # Project to hidden dim
        h = self.input_proj(emb)  # (batch, seq_len, hidden_dim)
        
        # Transformer encoding
        padding_mask = (x == 0)  # True where padding
        h = self.transformer(h, src_key_padding_mask=padding_mask)
        
        # Attention pooling
        pooled = self.attn_pool(h, mask)  # (batch, hidden_dim)
        
        # Field-specific projection
        pooled = self.field_projs[field_name](pooled)
        pooled = F.gelu(pooled)
        
        return pooled
    
    def forward(self, title, snippet, url, domain):
        t = self._encode_field(title, 'title')
        s = self._encode_field(snippet, 'snippet')
        u = self._encode_field(url, 'url')
        d = self._encode_field(domain, 'domain')
        
        # Concatenate all field representations
        x = torch.cat([t, s, u, d], dim=-1)
        x = self.layer_norm(x)
        x = self.dropout(x)
        
        return self.classifier(x)


# Fallback simpler model for comparison/faster training
class SimpleLeadClassifier(nn.Module):
    """Original simple mean-pooling classifier for comparison."""
    
    def __init__(self, config: ModelConfig):
        super().__init__()
        self.embedding = nn.Embedding(config.vocab_size, config.embedding_dim, padding_idx=0)
        
        self.fc_title = nn.Linear(config.embedding_dim, config.hidden_dim)
        self.fc_snippet = nn.Linear(config.embedding_dim, config.hidden_dim)
        self.fc_url = nn.Linear(config.embedding_dim, config.hidden_dim)
        self.fc_domain = nn.Linear(config.embedding_dim, config.hidden_dim)
        
        self.dropout = nn.Dropout(config.dropout)
        self.classifier = nn.Sequential(
            nn.Linear(config.hidden_dim * 4, config.hidden_dim),
            nn.ReLU(),
            nn.Dropout(config.dropout),
            nn.Linear(config.hidden_dim, config.num_classes),
        )
    
    def _mean_pool(self, x: torch.Tensor) -> torch.Tensor:
        mask = (x != 0).unsqueeze(-1)
        emb = self.embedding(x) * mask
        summed = emb.sum(dim=1)
        counts = mask.sum(dim=1).clamp(min=1)
        return summed / counts
    
    def forward(self, title, snippet, url, domain):
        t = torch.relu(self.fc_title(self._mean_pool(title)))
        s = torch.relu(self.fc_snippet(self._mean_pool(snippet)))
        u = torch.relu(self.fc_url(self._mean_pool(url)))
        d = torch.relu(self.fc_domain(self._mean_pool(domain)))
        
        x = torch.cat([t, s, u, d], dim=-1)
        x = self.dropout(x)
        return self.classifier(x)


# =============================================================================
# DATASET
# =============================================================================

class LeadDataset(Dataset):
    def __init__(self, data: List[Tuple], tokenizer: EnhancedTokenizer, config: ModelConfig, 
                 augmenter: Optional[DataAugmenter] = None, augment_prob: float = 0.0):
        self.data = data
        self.tokenizer = tokenizer
        self.config = config
        self.augmenter = augmenter
        self.augment_prob = augment_prob
    
    def __len__(self):
        return len(self.data)
    
    def __getitem__(self, idx):
        title, snippet, url, domain, label = self.data[idx]
        
        # Apply augmentation during training
        if self.augmenter and random.random() < self.augment_prob:
            title = self.augmenter.augment(title)
            snippet = self.augmenter.augment(snippet)
        
        return {
            "title": torch.tensor(self.tokenizer.encode(title, self.config.max_title_len), dtype=torch.long),
            "snippet": torch.tensor(self.tokenizer.encode(snippet, self.config.max_snippet_len), dtype=torch.long),
            "url": torch.tensor(self.tokenizer.encode(url, self.config.max_url_len), dtype=torch.long),
            "domain": torch.tensor(self.tokenizer.encode(domain, self.config.max_domain_len), dtype=torch.long),
            "label": torch.tensor(label, dtype=torch.long),
        }


# =============================================================================
# TRAINING DATA GENERATOR (same as v3 but with more variety)
# =============================================================================

class TrainingDataGenerator:
    """Generate synthetic training data with extended variety."""
    
    def __init__(self, seed: int = 42, scale_multiplier: int = 3):
        self.rng = random.Random(seed)
        self.scale_multiplier = max(1, scale_multiplier)
        self.data: List[Tuple[str, str, str, str, int]] = []
        
        # Expanded regions
        self.regions = [
            ("morocco", "Morocco"),
            ("us_east", "US East"),
            ("us_west", "US West"),
            ("us_texas", "US Texas"),
            ("us_midwest", "US Midwest"),
            ("eu_core", "EU Core"),
            ("eu_nordics", "EU Nordics"),
            ("eu_cee", "EU CEE"),
            ("eu_southern", "EU Southern"),
            ("uk", "UK"),
            ("asia_east", "Asia East"),
            ("asia_south", "Asia South"),
            ("latam", "LATAM"),
        ]
        
        self.sectors = [
            "automotive", "industrial", "medical", "data_center", "semiconductor",
            "electronics", "cloud", "distribution", "it", "pcb", "ems",
            "manufacturing", "telecom", "energy", "aerospace", "defense",
            "logistics", "chemicals", "packaging", "textiles", "food_processing",
        ]
        
        self.company_suffixes = ["Inc", "LLC", "Ltd", "GmbH", "SA", "PLC", "Group", "Co", "Corp"]
        self.company_prefixes = [
            "Advanced", "Precision", "Global", "National", "Premier", "Apex",
            "Vertex", "Omega", "Summit", "Atlas", "Falcon", "Nova", "Prime",
            "Blue", "Nordic", "Atlantic", "Pacific", "Continental", "Horizon",
            "United", "First", "Central", "Metro", "Valley", "Mountain",
        ]
        
        self.market_research_domains = [
            "kenresearch.com", "grandviewresearch.com", "mordorintelligence.com",
            "marketsandmarkets.com", "statista.com", "ibisworld.com", "technavio.com",
            "alliedmarketresearch.com", "fortunebusinessinsights.com", "researchandmarkets.com",
            "gminsights.com", "precedenceresearch.com", "factmr.com", "insightaceanalytic.com",
        ]
        
        self.news_domains = [
            "reuters.com", "bloomberg.com", "businesswire.com", "prnewswire.com",
            "cnbc.com", "marketwatch.com", "wsj.com", "ft.com",
            "techcrunch.com", "electronicdesign.com", "electronicsweekly.com", "eenewseurope.com",
        ]
        
        self.directory_domains = [
            "thomasnet.com", "yellowpages.com", "manta.com", "kompass.com",
            "dnb.com", "zoominfo.com", "globalsources.com", "made-in-china.com", 
            "europages.com", "industrynet.com",
        ]
        
        self.jobs_domains = [
            "indeed.com", "linkedin.com", "glassdoor.com", "ziprecruiter.com",
            "monster.com", "careerbuilder.com", "wellfound.com",
        ]
        
        # Well-known lead companies (extended)
        self.known_company_domains = [
            # EMS / Manufacturing
            "jabil.com", "flex.com", "celestica.com", "sanmina.com", "benchmark.com",
            "plexus.com", "ttelectronics.com", "kimballelectronics.com", "creationtech.com",
            "zollner.de", "note.eu", "gpv.dk", "kitron.com", "asteelflash.com",
            "lacroix-electronics.com", "neways.com", "cicor.com", "katek-group.de",
            "pegatroncorp.com", "wistron.com", "foxconn.com", "quanta.com.tw",
            # Semiconductors
            "amd.com", "intel.com", "nvidia.com", "qualcomm.com", "ti.com", "nxp.com",
            "microchip.com", "infineon.com", "onsemi.com", "analog.com", "broadcom.com",
            "micron.com", "marvell.com", "renesas.com", "stmicroelectronics.com",
            # Distributors
            "arrow.com", "avnet.com", "digikey.com", "mouser.com", "tti.com", "futureelectronics.com",
            "newark.com", "farnell.com", "rs-online.com", "wuerth-elektronik.com",
            # Data centers
            "digitalrealty.com", "equinix.com", "cyrusone.com", "coresite.com", "qts.com",
            "databank.com", "compassdatacenters.com", "ussignal.com", "flexential.com",
            "vantage-dc.com", "switch.com", "stackinfra.com", "aligneddc.com",
            # Automotive / Aerospace
            "bosch.com", "denso.com", "continental.com", "aptiv.com", "magna.com",
            "lear.com", "yazaki.com", "valeo.com", "forvia.com",
            "thalesgroup.com", "safran.com", "airbus.com", "boeing.com", "lockheedmartin.com",
            "rtx.com", "l3harris.com", "northropgrumman.com", "baesystems.com", "rolls-royce.com",
            # IT / Enterprise
            "ibm.com", "hpe.com", "dell.com", "cisco.com", "oracle.com", "sap.com",
            "servicenow.com", "shi.com", "cdw.com", "insight.com", "softwareone.com",
        ]
        
        self.known_junk_domains = [
            *self.market_research_domains,
            *self.news_domains[:8],
            *self.directory_domains,
            *self.jobs_domains,
            "wikipedia.org", "youtube.com", "reddit.com", "twitter.com", "facebook.com",
            "slideshare.net", "issuu.com", "scribd.com", "docplayer.net",
            "gov.uk", "europa.eu", "usa.gov", "trade.gov",
        ]
    
    def _add(self, title: str, snippet: str, url: str, domain: str, label: int):
        self.data.append((title, snippet, url, domain, label))
        # Add noisy variant
        if self.rng.random() < 0.12:
            noisy_title, noisy_snippet = self._noisify(title, snippet)
            self.data.append((noisy_title, noisy_snippet, url, domain, label))
    
    def _noisify(self, title: str, snippet: str) -> Tuple[str, str]:
        prefixes = ["Official", "Home", "Welcome", ""]
        suffixes = ["| Official Site", "- Global", "| Services", ""]
        title = f"{self.rng.choice(prefixes)} {title}".strip()
        title = title + self.rng.choice(suffixes)
        return title, snippet
    
    def _gen_company_name(self, sector: str) -> str:
        patterns = [
            f"{self.rng.choice(self.company_prefixes)} {sector.title()} {self.rng.choice(self.company_suffixes)}",
            f"{self.rng.choice(self.company_prefixes)}{sector.title()[:4]} {self.rng.choice(self.company_suffixes)}",
            f"{sector.title()} {self.rng.choice(self.company_prefixes)} {self.rng.choice(self.company_suffixes)}",
        ]
        return self.rng.choice(patterns)
    
    def _gen_company_domain(self, name: str) -> str:
        clean = re.sub(r'[^a-z0-9]', '', name.lower())[:20]
        tlds = [".com", ".net", ".io", ".co"]
        return clean + self.rng.choice(tlds)
    
    def generate_all_data(self) -> List[Tuple[str, str, str, str, int]]:
        """Generate all synthetic training data."""
        self._generate_leads()
        self._generate_non_leads()
        self.rng.shuffle(self.data)
        
        leads = sum(1 for d in self.data if d[4] == 1)
        print(f"Generated {len(self.data)} samples ({leads} leads, {len(self.data) - leads} non-leads)")
        return self.data
    
    def _generate_leads(self):
        """Generate lead (company) samples."""
        # From known company domains
        for domain in self.known_company_domains:
            company = domain.replace('.com', '').replace('.', ' ').title()
            for _ in range(8 * self.scale_multiplier):
                page_types = [
                    (f"About {company}", f"{company} is a leading provider of solutions."),
                    (f"Contact {company}", f"Get in touch with {company} for inquiries."),
                    (f"{company} - Services", f"Explore our comprehensive service offerings."),
                    (f"Products | {company}", f"Browse our product catalog and capabilities."),
                    (f"Locations - {company}", f"Find {company} offices and facilities worldwide."),
                    (f"{company} Solutions", f"Enterprise solutions for your business needs."),
                    (f"Industries | {company}", f"Serving automotive, aerospace, and more."),
                ]
                title, snippet = self.rng.choice(page_types)
                url = f"https://www.{domain}/"
                self._add(title, snippet, url, domain, 1)
        
        # Generated companies
        for region_key, region_name in self.regions:
            for sector in self.sectors:
                for _ in range(15 * self.scale_multiplier):
                    company = self._gen_company_name(sector)
                    domain = self._gen_company_domain(company)
                    
                    titles = [
                        f"{company} | {sector.title()} Solutions",
                        f"About Us - {company}",
                        f"{company} - {region_name}",
                        f"Contact {company}",
                        f"{company} Services",
                    ]
                    snippets = [
                        f"{company} provides {sector} solutions in {region_name}.",
                        f"Leading {sector} company serving global markets.",
                        f"Contact our team for {sector} inquiries.",
                    ]
                    
                    title = self.rng.choice(titles)
                    snippet = self.rng.choice(snippets)
                    url = f"https://www.{domain}/"
                    self._add(title, snippet, url, domain, 1)
    
    def _generate_non_leads(self):
        """Generate non-lead samples."""
        # Market research reports
        for domain in self.market_research_domains:
            for _ in range(40 * self.scale_multiplier):
                sector = self.rng.choice(self.sectors)
                region = self.rng.choice(self.regions)[1]
                
                titles = [
                    f"{region} {sector.title()} Market Report 2025",
                    f"Global {sector.title()} Industry Analysis",
                    f"{sector.title()} Market Size & Forecast",
                    f"{region} {sector.title()} Market Trends",
                ]
                snippets = [
                    "Market research report with industry analysis and forecasts.",
                    "Comprehensive market study covering key players and trends.",
                    "Download the full report for detailed market insights.",
                ]
                
                title = self.rng.choice(titles)
                snippet = self.rng.choice(snippets)
                url = f"https://www.{domain}/report/"
                self._add(title, snippet, url, domain, 0)
        
        # News articles
        for domain in self.news_domains:
            for _ in range(30 * self.scale_multiplier):
                company = self.rng.choice(self.known_company_domains).replace('.com', '').title()
                
                titles = [
                    f"{company} Announces Expansion Plans",
                    f"{company} Reports Q3 Earnings",
                    f"Breaking: {company} to Acquire Competitor",
                    f"{company} CEO Interview",
                ]
                snippets = [
                    "Read the latest news and updates about industry developments.",
                    "Press release and company announcements.",
                    "Industry news coverage and analysis.",
                ]
                
                title = self.rng.choice(titles)
                snippet = self.rng.choice(snippets)
                url = f"https://www.{domain}/article/"
                self._add(title, snippet, url, domain, 0)
        
        # Directory listings
        for domain in self.directory_domains:
            for _ in range(25 * self.scale_multiplier):
                sector = self.rng.choice(self.sectors)
                
                titles = [
                    f"{sector.title()} Suppliers - Directory",
                    f"Find {sector.title()} Companies",
                    f"Top {sector.title()} Manufacturers",
                    f"{sector.title()} Company Listings",
                ]
                snippets = [
                    "Browse our directory of suppliers and manufacturers.",
                    "Find and compare companies in this industry.",
                    "Business directory with company profiles.",
                ]
                
                title = self.rng.choice(titles)
                snippet = self.rng.choice(snippets)
                url = f"https://www.{domain}/search/"
                self._add(title, snippet, url, domain, 0)
        
        # Job listings
        for domain in self.jobs_domains:
            for _ in range(20 * self.scale_multiplier):
                sector = self.rng.choice(self.sectors)
                company = self.rng.choice(self.known_company_domains).replace('.com', '').title()
                
                titles = [
                    f"Jobs at {company}",
                    f"{sector.title()} Engineer - {company}",
                    f"Careers in {sector.title()}",
                    f"{company} is Hiring",
                ]
                snippets = [
                    "View open positions and apply online.",
                    "Career opportunities in this field.",
                    "Join our team - multiple openings available.",
                ]
                
                title = self.rng.choice(titles)
                snippet = self.rng.choice(snippets)
                url = f"https://www.{domain}/jobs/"
                self._add(title, snippet, url, domain, 0)
        
        # Wikipedia/educational
        wiki_domains = ["wikipedia.org", "en.wikipedia.org", "britannica.com"]
        for domain in wiki_domains:
            for _ in range(30 * self.scale_multiplier):
                sector = self.rng.choice(self.sectors)
                
                titles = [
                    f"{sector.title()} - Wikipedia",
                    f"History of {sector.title()}",
                    f"{sector.title()} Industry Overview",
                ]
                snippets = [
                    "From Wikipedia, the free encyclopedia.",
                    "This article covers the history and development of the industry.",
                    "Encyclopedia article about this topic.",
                ]
                
                title = self.rng.choice(titles)
                snippet = self.rng.choice(snippets)
                url = f"https://{domain}/wiki/"
                self._add(title, snippet, url, domain, 0)


# =============================================================================
# METRICS AND ANALYSIS
# =============================================================================

def compute_metrics(y_true: List[int], y_pred: List[int], y_prob: Optional[List[float]] = None) -> Dict[str, Any]:
    """Compute comprehensive classification metrics."""
    tp = sum(1 for yt, yp in zip(y_true, y_pred) if yt == 1 and yp == 1)
    tn = sum(1 for yt, yp in zip(y_true, y_pred) if yt == 0 and yp == 0)
    fp = sum(1 for yt, yp in zip(y_true, y_pred) if yt == 0 and yp == 1)
    fn = sum(1 for yt, yp in zip(y_true, y_pred) if yt == 1 and yp == 0)
    
    total = tp + tn + fp + fn
    accuracy = (tp + tn) / total if total else 0.0
    precision = tp / (tp + fp) if (tp + fp) else 0.0
    recall = tp / (tp + fn) if (tp + fn) else 0.0
    f1 = (2 * precision * recall / (precision + recall)) if (precision + recall) else 0.0
    specificity = tn / (tn + fp) if (tn + fp) else 0.0
    balanced_acc = (recall + specificity) / 2.0
    
    denom = ((tp + fp) * (tp + fn) * (tn + fp) * (tn + fn)) ** 0.5
    mcc = ((tp * tn) - (fp * fn)) / denom if denom else 0.0
    
    metrics = {
        "tp": tp, "tn": tn, "fp": fp, "fn": fn,
        "accuracy": accuracy,
        "precision": precision,
        "recall": recall,
        "f1": f1,
        "specificity": specificity,
        "balanced_acc": balanced_acc,
        "mcc": mcc,
        "total": total,
    }
    
    # ROC AUC if probabilities available
    if y_prob is not None and HAS_SKLEARN:
        try:
            fpr, tpr, _ = roc_curve(y_true, y_prob)
            metrics['roc_auc'] = auc(fpr, tpr)
            metrics['avg_precision'] = average_precision_score(y_true, y_prob)
        except:
            pass
    
    return metrics


def bootstrap_metrics(y_true: List[int], y_pred: List[int], 
                     y_prob: Optional[List[float]] = None,
                     n_samples: int = 1000, seed: int = 42) -> Dict[str, Dict[str, float]]:
    """Bootstrap confidence intervals for metrics."""
    rng = np.random.default_rng(seed)
    indices = np.arange(len(y_true))
    all_metrics = []
    
    for _ in range(n_samples):
        sample_idx = rng.choice(indices, size=len(indices), replace=True)
        yt = [y_true[i] for i in sample_idx]
        yp = [y_pred[i] for i in sample_idx]
        yprob = [y_prob[i] for i in sample_idx] if y_prob else None
        all_metrics.append(compute_metrics(yt, yp, yprob))
    
    def ci(values):
        return float(np.percentile(values, 2.5)), float(np.percentile(values, 97.5))
    
    summary = {}
    for key in ["accuracy", "precision", "recall", "f1", "specificity", "balanced_acc", "mcc", "roc_auc", "avg_precision"]:
        vals = [m.get(key) for m in all_metrics if m.get(key) is not None]
        if vals:
            low, high = ci(vals)
            summary[key] = {
                "mean": float(np.mean(vals)),
                "std": float(np.std(vals)),
                "low": low,
                "high": high,
            }
    return summary


def analyze_calibration(y_true: List[int], y_prob: List[float], n_bins: int = 10) -> Dict[str, Any]:
    """Analyze model calibration."""
    if not HAS_SKLEARN:
        return {"error": "sklearn not available"}
    
    try:
        prob_true, prob_pred = calibration_curve(y_true, y_prob, n_bins=n_bins, strategy='uniform')
        
        # Expected Calibration Error
        bin_counts = np.histogram(y_prob, bins=n_bins, range=(0, 1))[0]
        ece = np.sum(np.abs(prob_true - prob_pred) * bin_counts / len(y_prob))
        
        return {
            "ece": float(ece),
            "prob_true": prob_true.tolist(),
            "prob_pred": prob_pred.tolist(),
            "bin_counts": bin_counts.tolist(),
        }
    except Exception as e:
        return {"error": str(e)}


class FocalLoss(nn.Module):
    """Focal loss to reduce overconfident errors on hard negatives."""

    def __init__(self, weight: Optional[torch.Tensor] = None, gamma: float = 2.0):
        super().__init__()
        self.weight = weight
        self.gamma = gamma

    def forward(self, logits: torch.Tensor, targets: torch.Tensor) -> torch.Tensor:
        ce = F.cross_entropy(logits, targets, weight=self.weight, reduction="none")
        pt = torch.exp(-ce)
        loss = ((1.0 - pt) ** self.gamma) * ce
        return loss.mean()


def cross_validate(model_class, data: List[Tuple], tokenizer: EnhancedTokenizer, 
                  config: ModelConfig, device: torch.device, n_folds: int = 5,
                  seed: int = 42) -> Dict[str, Any]:
    """Perform k-fold cross-validation."""
    if not HAS_SKLEARN:
        print("Skipping cross-validation (sklearn not available)")
        return {}
    
    print(f"\nPerforming {n_folds}-fold cross-validation...")
    
    labels = [d[4] for d in data]
    skf = StratifiedKFold(n_splits=n_folds, shuffle=True, random_state=seed)
    
    fold_metrics = []
    
    for fold, (train_idx, val_idx) in enumerate(skf.split(data, labels)):
        print(f"  Fold {fold + 1}/{n_folds}...", end=" ")
        
        train_data = [data[i] for i in train_idx]
        val_data = [data[i] for i in val_idx]
        
        train_dataset = LeadDataset(train_data, tokenizer, config)
        val_dataset = LeadDataset(val_data, tokenizer, config)
        
        train_loader = DataLoader(train_dataset, batch_size=config.batch_size, shuffle=True)
        val_loader = DataLoader(val_dataset, batch_size=config.batch_size)
        
        # Create and train model
        model = model_class(config).to(device)
        optimizer = torch.optim.AdamW(model.parameters(), lr=config.learning_rate, weight_decay=config.weight_decay)
        criterion = nn.CrossEntropyLoss()
        
        # Quick training (fewer epochs for CV)
        for epoch in range(3):  # Reduced epochs for speed
            model.train()
            for batch in train_loader:
                title = batch["title"].to(device)
                snippet = batch["snippet"].to(device)
                url = batch["url"].to(device)
                domain_ids = batch["domain"].to(device)
                labels_batch = batch["label"].to(device)
                
                optimizer.zero_grad()
                logits = model(title, snippet, url, domain_ids)
                loss = criterion(logits, labels_batch)
                loss.backward()
                optimizer.step()
        
        # Evaluate
        model.eval()
        y_true, y_pred, y_prob = [], [], []
        with torch.no_grad():
            for batch in val_loader:
                title = batch["title"].to(device)
                snippet = batch["snippet"].to(device)
                url = batch["url"].to(device)
                domain_ids = batch["domain"].to(device)
                labels_batch = batch["label"].to(device)
                
                logits = model(title, snippet, url, domain_ids)
                probs = F.softmax(logits, dim=-1)
                preds = logits.argmax(dim=-1)
                
                y_true.extend(labels_batch.cpu().tolist())
                y_pred.extend(preds.cpu().tolist())
                y_prob.extend(probs[:, 1].cpu().tolist())
        
        metrics = compute_metrics(y_true, y_pred, y_prob)
        fold_metrics.append(metrics)
        print(f"Acc: {metrics['accuracy']:.4f}, F1: {metrics['f1']:.4f}")
        
        del model
        torch.cuda.empty_cache() if torch.cuda.is_available() else None
    
    # Aggregate CV results
    cv_summary = {}
    for key in ["accuracy", "precision", "recall", "f1", "mcc", "balanced_acc"]:
        vals = [m[key] for m in fold_metrics]
        cv_summary[key] = {
            "mean": float(np.mean(vals)),
            "std": float(np.std(vals)),
            "min": float(np.min(vals)),
            "max": float(np.max(vals)),
        }
    
    print(f"\nCV Results (mean ± std):")
    for key, stats in cv_summary.items():
        print(f"  {key:<14}: {stats['mean']:.4f} ± {stats['std']:.4f}")
    
    return cv_summary


# =============================================================================
# WEBCRAWLER EVALUATION - EXPANDED 100+ TEST CASES
# =============================================================================

def evaluate_webcrawler_examples(model, tokenizer, config, device):
    """Evaluate on real-world webcrawler examples - comprehensive test suite."""
    webcrawler_tests = [
        # ======================================================================
        # LEADS - REAL COMPANIES (should be classified as COMPANY = 1)
        # ======================================================================
        
        # Data Centers
        ("Digital Realty", "Data center solutions", "https://www.digitalrealty.com", "digitalrealty.com", 1),
        ("Compass Datacenters", "Purpose-built data centers", "https://www.compassdatacenters.com", "compassdatacenters.com", 1),
        ("US Signal: Enterprise Cloud, Data Center & IT Services", "Cloud services", "https://ussignal.com", "ussignal.com", 1),
        ("DataBank", "Data center solutions", "https://www.databank.com", "databank.com", 1),
        ("Colocation Services", "Enterprise colocation", "https://www.equinix.com", "equinix.com", 1),
        ("Cloud Infrastructure", "Global data centers", "https://www.cyrusone.com", "cyrusone.com", 1),
        ("QTS Data Centers", "Colocation and cloud services", "https://www.qtsdatacenters.com", "qtsdatacenters.com", 1),
        ("CoreSite", "Data center solutions", "https://www.coresite.com", "coresite.com", 1),
        ("Vantage Data Centers", "Hyperscale data centers", "https://www.vantage-dc.com", "vantage-dc.com", 1),
        ("Switch", "Enterprise data centers", "https://www.switch.com", "switch.com", 1),
        
        # Semiconductors
        ("Corporate Locations", "AMD offices worldwide", "https://www.amd.com", "amd.com", 1),
        ("NXP Semiconductors | Automotive", "Automotive processors", "https://www.nxp.com", "nxp.com", 1),
        ("Products", "Industrial microcontrollers", "https://www.microchip.com", "microchip.com", 1),
        ("Intel Corporation", "Semiconductor solutions", "https://www.intel.com", "intel.com", 1),
        ("Texas Instruments | Analog ICs", "Analog semiconductors", "https://www.ti.com", "ti.com", 1),
        ("Infineon Technologies", "Power semiconductors", "https://www.infineon.com", "infineon.com", 1),
        ("STMicroelectronics", "Semiconductor solutions", "https://www.st.com", "st.com", 1),
        ("ON Semiconductor", "Intelligent power solutions", "https://www.onsemi.com", "onsemi.com", 1),
        ("Analog Devices", "Analog, mixed-signal ICs", "https://www.analog.com", "analog.com", 1),
        ("Renesas Electronics", "Automotive MCUs", "https://www.renesas.com", "renesas.com", 1),
        
        # EMS / Contract Manufacturing
        ("Jabil | Global Manufacturing Solutions", "Manufacturing services", "https://www.jabil.com", "jabil.com", 1),
        ("Flex | Manufacturing Partner", "Global manufacturing", "https://www.flex.com", "flex.com", 1),
        ("Celestica | Manufacturing and Design", "Supply chain solutions", "https://www.celestica.com", "celestica.com", 1),
        ("Sanmina Corporation | Integrated Manufacturing", "Electronics manufacturing", "https://www.sanmina.com", "sanmina.com", 1),
        ("Plexus Corp | Product Development", "Manufacturing services", "https://www.plexus.com", "plexus.com", 1),
        ("Benchmark Electronics", "Manufacturing and engineering", "https://www.bench.com", "bench.com", 1),
        ("Fabrinet", "Optical and electronic manufacturing", "https://www.fabrinet.com", "fabrinet.com", 1),
        ("Venture Corporation", "Electronics manufacturing", "https://www.venture.com.sg", "venture.com.sg", 1),
        ("SMTC Corporation", "EMS provider", "https://www.smtc.com", "smtc.com", 1),
        ("NEO Tech", "EMS solutions", "https://www.neotech.com", "neotech.com", 1),
        
        # Component Distributors
        ("CAPABILITIES OVERVIEW", "TTI electronic components", "https://www.tti.com", "tti.com", 1),
        ("Arrow Electronics: Connect with Electronic Components", "Distributor", "https://www.arrow.com", "arrow.com", 1),
        ("Avnet | Technology Solutions", "Electronic components", "https://www.avnet.com", "avnet.com", 1),
        ("Digi-Key Electronics", "Electronic components", "https://www.digikey.com", "digikey.com", 1),
        ("Mouser Electronics", "Electronic components", "https://www.mouser.com", "mouser.com", 1),
        ("Future Electronics", "Component distributor", "https://www.futureelectronics.com", "futureelectronics.com", 1),
        ("Newark Electronics", "Electronic components", "https://www.newark.com", "newark.com", 1),
        ("Heilind Electronics", "Connector distributor", "https://www.heilind.com", "heilind.com", 1),
        ("Master Electronics", "Electronic components", "https://www.masterelectronics.com", "masterelectronics.com", 1),
        ("WPG Holdings", "Electronics distribution", "https://www.wpgholdings.com", "wpgholdings.com", 1),
        
        # IT/Technology Solutions
        ("SHI", "IT Solutions Provider", "https://www.shi.com", "shi.com", 1),
        ("CDW | Technology Solutions", "Enterprise IT solutions", "https://www.cdw.com", "cdw.com", 1),
        ("Insight Enterprises", "IT solutions", "https://www.insight.com", "insight.com", 1),
        ("PC Connection", "IT products and services", "https://www.connection.com", "connection.com", 1),
        ("Softchoice", "IT solutions provider", "https://www.softchoice.com", "softchoice.com", 1),
        ("Regional availability for services and features", "IBM Cloud regions", "https://www.ibm.com", "ibm.com", 1),
        
        # Automotive
        ("Yazaki Morocco", "Automotive wiring systems", "https://www.yazaki.com", "yazaki.com", 1),
        ("Lear Corporation | Morocco", "Automotive seating", "https://www.lear.com", "lear.com", 1),
        ("Bosch Romania | Manufacturing", "Industrial systems", "https://www.bosch.com", "bosch.com", 1),
        ("Continental | Automotive", "Automotive technologies", "https://www.continental.com", "continental.com", 1),
        ("Denso Corporation", "Automotive components", "https://www.denso.com", "denso.com", 1),
        ("Aptiv | Advanced Mobility", "Automotive electronics", "https://www.aptiv.com", "aptiv.com", 1),
        ("Magna International", "Automotive supplier", "https://www.magna.com", "magna.com", 1),
        ("Valeo", "Automotive components", "https://www.valeo.com", "valeo.com", 1),
        ("ZF Friedrichshafen", "Automotive systems", "https://www.zf.com", "zf.com", 1),
        ("BorgWarner", "Powertrain solutions", "https://www.borgwarner.com", "borgwarner.com", 1),
        
        # Aerospace/Defense
        ("Thales UK | Aerospace", "Defense systems", "https://www.thalesgroup.com", "thalesgroup.com", 1),
        ("Safran", "Aerospace equipment", "https://www.safran-group.com", "safran-group.com", 1),
        ("Collins Aerospace", "Aerospace systems", "https://www.collinsaerospace.com", "collinsaerospace.com", 1),
        ("Leonardo", "Aerospace and defense", "https://www.leonardo.com", "leonardo.com", 1),
        ("BAE Systems", "Defense technology", "https://www.baesystems.com", "baesystems.com", 1),
        ("Northrop Grumman", "Defense systems", "https://www.northropgrumman.com", "northropgrumman.com", 1),
        ("General Dynamics", "Defense solutions", "https://www.gd.com", "gd.com", 1),
        ("L3Harris Technologies", "Defense electronics", "https://www.l3harris.com", "l3harris.com", 1),
        
        # Industrial/Manufacturing
        ("Siemens Industry", "Industrial automation", "https://www.siemens.com", "siemens.com", 1),
        ("Rockwell Automation", "Industrial solutions", "https://www.rockwellautomation.com", "rockwellautomation.com", 1),
        ("ABB | Industrial Automation", "Robotics and automation", "https://www.abb.com", "abb.com", 1),
        ("Emerson", "Industrial automation", "https://www.emerson.com", "emerson.com", 1),
        ("Honeywell", "Industrial technology", "https://www.honeywell.com", "honeywell.com", 1),
        ("Schneider Electric", "Energy management", "https://www.se.com", "se.com", 1),
        ("Parker Hannifin", "Motion and control", "https://www.parker.com", "parker.com", 1),
        ("Eaton", "Power management", "https://www.eaton.com", "eaton.com", 1),
        
        # ======================================================================
        # NON-LEADS - JUNK (should be classified as JUNK = 0)
        # ======================================================================
        
        # Market Research Sites
        ("Morocco Automotive Market Report 2025", "Market research report on automotive industry in Morocco", "https://www.mordorintelligence.com/industry-reports/morocco-automotive-industry", "mordorintelligence.com", 0),
        ("EU Electronics Manufacturing Outlook", "Industry analysis and market forecast", "https://www.statista.com/outlook/electronics-manufacturing", "statista.com", 0),
        ("US Texas Manufacturing Report", "Comprehensive market analysis report", "https://www.grandviewresearch.com/industry-analysis/texas-manufacturing", "grandviewresearch.com", 0),
        ("UK EMS Market Size", "Forecast report and market analysis", "https://www.technavio.com/report/uk-ems-market", "technavio.com", 0),
        ("Global Semiconductor Market 2025", "Market size and forecast", "https://www.marketsandmarkets.com/semiconductor-report", "marketsandmarkets.com", 0),
        ("Data Center Market Analysis", "Industry research report", "https://www.fortunebusinessinsights.com/data-center-market", "fortunebusinessinsights.com", 0),
        ("PCB Assembly Market Report", "Market research and trends", "https://www.alliedmarketresearch.com/pcb-assembly", "alliedmarketresearch.com", 0),
        ("Automotive Electronics Market Size", "Global market forecast", "https://www.researchandmarkets.com/automotive-electronics", "researchandmarkets.com", 0),
        ("Industrial Automation Market Trends", "Industry analysis report", "https://www.gminsights.com/industrial-automation", "gminsights.com", 0),
        ("EMS Industry Report 2025-2030", "Market research analysis", "https://www.precedenceresearch.com/ems-market", "precedenceresearch.com", 0),
        ("Global Semiconductor And Electronic Parts Manufacturing Market", "Market research report", "https://www.kenresearch.com/semiconductor-electronics-manufacturing", "kenresearch.com", 0),
        ("IoT Market Research Report", "Industry analysis and forecast", "https://www.factmr.com/iot-market", "factmr.com", 0),
        ("5G Infrastructure Market Size", "Market forecast report", "https://www.transparencymarketresearch.com/5g-market", "transparencymarketresearch.com", 0),
        ("Medical Device Manufacturing Market", "Industry research report", "https://www.coherentmarketinsights.com/medical-devices", "coherentmarketinsights.com", 0),
        ("Battery Technology Market Analysis", "Global market research", "https://www.databridgemarketresearch.com/battery-market", "databridgemarketresearch.com", 0),
        ("Contract Manufacturing Market Report", "Industry analysis", "https://www.futuremarketinsights.com/contract-manufacturing", "futuremarketinsights.com", 0),
        ("Cloud Computing Market Size 2025", "Market research report", "https://www.idc.com/cloud-computing-market", "idc.com", 0),
        ("IT Services Market Analysis", "Industry forecast report", "https://www.gartner.com/it-services-market", "gartner.com", 0),
        ("Aerospace Manufacturing Market", "Market research analysis", "https://www.frost.com/aerospace-manufacturing", "frost.com", 0),
        ("Renewable Energy Market Report", "Industry analysis", "https://www.kbvresearch.com/renewable-energy", "kbvresearch.com", 0),
        
        # News and Press Release Sites
        ("Jabil Announces Expansion", "Press release from Business Wire", "https://www.businesswire.com/news/jabil-expansion", "businesswire.com", 0),
        ("Flex Reports Q4 Earnings", "Press release announcement", "https://www.prnewswire.com/flex-earnings", "prnewswire.com", 0),
        ("Intel CEO Discusses Strategy", "News article about Intel", "https://www.reuters.com/technology/intel-ceo", "reuters.com", 0),
        ("AMD Stock Rises on Earnings", "Financial news article", "https://www.bloomberg.com/news/amd-stock", "bloomberg.com", 0),
        ("NVIDIA Data Center Revenue Surges", "Tech news coverage", "https://www.cnbc.com/nvidia-data-center", "cnbc.com", 0),
        ("Semiconductor Shortage Update", "Industry news article", "https://www.marketwatch.com/semiconductor-shortage", "marketwatch.com", 0),
        ("Tesla Partners with New Supplier", "Automotive news", "https://www.wsj.com/articles/tesla-supplier", "wsj.com", 0),
        ("Apple Manufacturing Shifts", "Technology news", "https://www.ft.com/content/apple-manufacturing", "ft.com", 0),
        ("Tech Giants Expand Data Centers", "Technology coverage", "https://www.techcrunch.com/data-centers", "techcrunch.com", 0),
        ("AI Chip Demand Increases", "Industry news", "https://www.venturebeat.com/ai-chips", "venturebeat.com", 0),
        ("Cloud Infrastructure Trends", "Tech analysis", "https://www.zdnet.com/cloud-infrastructure", "zdnet.com", 0),
        ("New Server Processor Launch", "Hardware news", "https://www.cnet.com/server-processors", "cnet.com", 0),
        ("Semiconductor Industry Update", "Industry coverage", "https://www.theverge.com/semiconductors", "theverge.com", 0),
        ("Manufacturing Innovation News", "Technology article", "https://www.wired.com/manufacturing", "wired.com", 0),
        ("Electronics Industry Report", "Industry news", "https://www.eetimes.com/electronics-industry", "eetimes.com", 0),
        ("Chip Fab Investment News", "Semiconductor news", "https://www.semiengineering.com/chip-fab", "semiengineering.com", 0),
        ("Data Center Expansion Plans", "Industry coverage", "https://www.datacenterknowledge.com/expansion", "datacenterknowledge.com", 0),
        ("Supply Chain Disruption", "Supply chain news", "https://www.supplychaindive.com/disruption", "supplychaindive.com", 0),
        ("Manufacturing Automation Trends", "Industry article", "https://www.manufacturingdive.com/automation", "manufacturingdive.com", 0),
        ("EV Battery Production Update", "Automotive news", "https://www.electrek.co/ev-battery", "electrek.co", 0),
        
        # Directory and Listing Sites
        ("Top EMS Companies", "Industry ranking and directory", "https://www.electronicdesign.com/top-ems-companies", "electronicdesign.com", 0),
        ("PCB Assembly Suppliers", "Directory listing of suppliers", "https://www.thomasnet.com/pcb-assembly-suppliers", "thomasnet.com", 0),
        ("Electronics Manufacturers Directory", "Find manufacturers", "https://www.industrynet.com/electronics-manufacturers", "industrynet.com", 0),
        ("Component Distributors List", "Supplier directory", "https://www.globalsources.com/component-distributors", "globalsources.com", 0),
        ("Contract Manufacturing Companies", "Business directory", "https://www.kompass.com/contract-manufacturing", "kompass.com", 0),
        ("Semiconductor Companies Directory", "Industry listing", "https://www.europages.com/semiconductor-companies", "europages.com", 0),
        ("EMS Providers in Asia", "Regional directory", "https://www.made-in-china.com/ems-providers", "made-in-china.com", 0),
        ("Data Center Providers List", "Service providers directory", "https://www.yellowpages.com/data-center-providers", "yellowpages.com", 0),
        ("IT Solutions Companies", "Business directory listing", "https://www.manta.com/it-solutions", "manta.com", 0),
        ("Manufacturing Companies Database", "Company listings", "https://www.dnb.com/manufacturing-companies", "dnb.com", 0),
        
        # Job Sites
        ("Electronics Manufacturing Jobs", "Job listings in electronics", "https://www.indeed.com/electronics-manufacturing-jobs", "indeed.com", 0),
        ("Engineering Jobs at Jabil", "Career opportunities", "https://www.linkedin.com/jobs/jabil", "linkedin.com", 0),
        ("Semiconductor Engineer Positions", "Job openings", "https://www.glassdoor.com/semiconductor-engineer", "glassdoor.com", 0),
        ("Data Center Technician Jobs", "Job listings", "https://www.ziprecruiter.com/data-center-jobs", "ziprecruiter.com", 0),
        ("Manufacturing Manager Careers", "Job opportunities", "https://www.monster.com/manufacturing-manager", "monster.com", 0),
        ("PCB Design Engineer Jobs", "Engineering careers", "https://www.dice.com/pcb-design-engineer", "dice.com", 0),
        ("Supply Chain Jobs at Intel", "Career opportunities", "https://www.careerbuilder.com/intel-supply-chain", "careerbuilder.com", 0),
        ("Flex is Hiring Engineers", "Job announcement", "https://www.hired.com/flex-engineering", "hired.com", 0),
        ("Automotive Manufacturing Careers", "Job listings", "https://www.simplyhired.com/automotive-manufacturing", "simplyhired.com", 0),
        ("Tech Jobs in Data Centers", "Technology careers", "https://www.builtin.com/data-center-jobs", "builtin.com", 0),
        
        # Wikipedia and Educational
        ("Electronics Manufacturing - Wikipedia", "Encyclopedia article", "https://en.wikipedia.org/wiki/Electronics_manufacturing", "en.wikipedia.org", 0),
        ("Semiconductor Industry - Wikipedia", "Encyclopedia entry", "https://en.wikipedia.org/wiki/Semiconductor_industry", "en.wikipedia.org", 0),
        ("What is Contract Manufacturing?", "Educational article", "https://www.investopedia.com/contract-manufacturing", "investopedia.com", 0),
        ("History of Data Centers", "Encyclopedia content", "https://www.britannica.com/data-centers", "britannica.com", 0),
        ("Introduction to PCB Assembly", "Learning resource", "https://www.coursera.org/pcb-assembly", "coursera.org", 0),
        ("Electronics Manufacturing Course", "Online course", "https://www.udemy.com/electronics-manufacturing", "udemy.com", 0),
        ("Supply Chain Management Basics", "Educational content", "https://www.edx.org/supply-chain", "edx.org", 0),
        ("Semiconductor Physics", "Academic content", "https://www.khanacademy.org/semiconductor-physics", "khanacademy.org", 0),
        
        # Social Media and Forums
        ("r/electronics - Industry Discussion", "Reddit community discussion", "https://www.reddit.com/r/electronics", "reddit.com", 0),
        ("Manufacturing Professionals Group", "LinkedIn group", "https://www.linkedin.com/groups/manufacturing", "linkedin.com", 0),
        ("Best EMS companies to work for?", "Quora discussion", "https://www.quora.com/ems-companies-work", "quora.com", 0),
        ("Data Center Engineering Forum", "Community discussion", "https://www.stackoverflow.com/data-center", "stackoverflow.com", 0),
        ("Semiconductor Industry Trends Discussion", "Blog post", "https://www.medium.com/semiconductor-trends", "medium.com", 0),
        
        # Review and Comparison Sites
        ("Jabil Reviews - Employee Reviews", "Company reviews", "https://www.glassdoor.com/reviews/jabil", "glassdoor.com", 0),
        ("Best EMS Software Comparison", "Software reviews", "https://www.g2.com/ems-software", "g2.com", 0),
        ("Manufacturing ERP Reviews", "Software comparison", "https://www.capterra.com/manufacturing-erp", "capterra.com", 0),
        ("Flex Company Reviews", "Employee reviews", "https://www.trustpilot.com/flex-reviews", "trustpilot.com", 0),
        ("Data Center Solutions Comparison", "Product reviews", "https://www.trustradius.com/data-center", "trustradius.com", 0),
        
        # Events and Conferences
        ("Electronics Manufacturing Expo 2025", "Trade show event", "https://www.eventbrite.com/electronics-expo", "eventbrite.com", 0),
        ("Semiconductor Conference 2025", "Industry conference", "https://www.10times.com/semiconductor-conference", "10times.com", 0),
        ("Data Center World 2025", "Trade show", "https://www.cvent.com/data-center-world", "cvent.com", 0),
        ("EMS Summit 2025", "Industry event", "https://www.tradeshowplus.com/ems-summit", "tradeshowplus.com", 0),
        ("Manufacturing Technology Meetup", "Networking event", "https://www.meetup.com/manufacturing-tech", "meetup.com", 0),
        
        # Government and Trade Sites
        ("US Electronics Trade Data", "Government statistics", "https://www.trade.gov/electronics-trade", "trade.gov", 0),
        ("Semiconductor Industry Employment", "Labor statistics", "https://www.bls.gov/semiconductor-employment", "bls.gov", 0),
        ("Export Data for Electronics", "Trade information", "https://www.census.gov/electronics-exports", "census.gov", 0),
        ("EU Manufacturing Statistics", "Official statistics", "https://ec.europa.eu/manufacturing-stats", "ec.europa.eu", 0),
        ("UK Industrial Production", "Government data", "https://www.gov.uk/industrial-production", "gov.uk", 0),
        
        # Document Sharing Sites
        ("Electronics Manufacturing Presentation", "SlideShare presentation", "https://www.slideshare.net/electronics-manufacturing", "slideshare.net", 0),
        ("Semiconductor Industry Report PDF", "Document sharing", "https://www.scribd.com/semiconductor-report", "scribd.com", 0),
        ("Data Center Best Practices Guide", "Document download", "https://www.docplayer.net/data-center-guide", "docplayer.net", 0),
        ("EMS Industry Whitepaper", "Document sharing", "https://www.issuu.com/ems-whitepaper", "issuu.com", 0),
    ]
    
    model.eval()
    correct = 0
    company_correct = 0
    company_total = 0
    junk_correct = 0
    junk_total = 0
    results = []
    
    misclassified_companies = []
    misclassified_junk = []
    
    for title, snippet, url, domain, expected in webcrawler_tests:
        title_ids = torch.tensor([tokenizer.encode(title, config.max_title_len)]).to(device)
        snippet_ids = torch.tensor([tokenizer.encode(snippet, config.max_snippet_len)]).to(device)
        url_ids = torch.tensor([tokenizer.encode(url, config.max_url_len)]).to(device)
        domain_ids = torch.tensor([tokenizer.encode(domain, config.max_domain_len)]).to(device)
        
        with torch.no_grad():
            logits = model(title_ids, snippet_ids, url_ids, domain_ids)
            probs = F.softmax(logits, dim=-1)
            pred = logits.argmax(dim=-1).item()
            conf = probs[0, pred].item()
        
        label = "COMPANY" if pred == 1 else "JUNK"
        expected_label = "COMPANY" if expected == 1 else "JUNK"
        
        if expected == 1:
            company_total += 1
        else:
            junk_total += 1
        
        if pred == expected:
            correct += 1
            if expected == 1:
                company_correct += 1
            else:
                junk_correct += 1
        else:
            if expected == 1:
                misclassified_companies.append((domain, title[:40], conf))
            else:
                misclassified_junk.append((domain, title[:40], conf))
        
        results.append({
            "title": title[:50],
            "domain": domain,
            "pred": label,
            "expected": expected_label,
            "conf": conf,
            "correct": pred == expected,
        })
    
    # Print summary
    accuracy = correct / len(webcrawler_tests)
    company_acc = company_correct / company_total if company_total > 0 else 0
    junk_acc = junk_correct / junk_total if junk_total > 0 else 0
    
    print(f"\nWebcrawler Test Results:")
    print(f"  Total:    {correct}/{len(webcrawler_tests)} ({accuracy*100:.1f}%)")
    print(f"  Companies: {company_correct}/{company_total} ({company_acc*100:.1f}%)")
    print(f"  Junk:      {junk_correct}/{junk_total} ({junk_acc*100:.1f}%)")
    
    if misclassified_companies:
        print(f"\n  Misclassified as JUNK (should be COMPANY):")
        for domain, title, conf in misclassified_companies[:10]:
            print(f"    - {domain}: {title} ({conf*100:.1f}%)")
    
    if misclassified_junk:
        print(f"\n  Misclassified as COMPANY (should be JUNK):")
        for domain, title, conf in misclassified_junk[:10]:
            print(f"    - {domain}: {title} ({conf*100:.1f}%)")
    
    return accuracy, results


# =============================================================================
# LEARNING RATE SCHEDULER
# =============================================================================

class WarmupCosineScheduler:
    """Learning rate scheduler with linear warmup and cosine decay."""
    
    def __init__(self, optimizer, warmup_steps: int, total_steps: int, min_lr: float = 1e-7):
        self.optimizer = optimizer
        self.warmup_steps = warmup_steps
        self.total_steps = total_steps
        self.min_lr = min_lr
        self.base_lrs = [pg['lr'] for pg in optimizer.param_groups]
        self.current_step = 0
    
    def step(self):
        self.current_step += 1
        if self.current_step <= self.warmup_steps:
            # Linear warmup
            lr_mult = self.current_step / max(1, self.warmup_steps)
        else:
            # Cosine decay
            progress = (self.current_step - self.warmup_steps) / max(1, self.total_steps - self.warmup_steps)
            lr_mult = max(0.0, 0.5 * (1.0 + math.cos(math.pi * progress)))
        
        for i, pg in enumerate(self.optimizer.param_groups):
            pg['lr'] = max(self.min_lr, self.base_lrs[i] * lr_mult)
    
    def get_lr(self):
        return [pg['lr'] for pg in self.optimizer.param_groups]


# =============================================================================
# MAIN TRAINING FUNCTION
# =============================================================================

def train_model(
    seed: int = 42,
    scale_multiplier: int = 5,
    require_gpu: bool = False,
    bootstrap_samples: int = 1000,
    external_data_dir: Optional[str] = "external_data",
    use_external_only: bool = False,
    use_attention_model: bool = True,
    do_cross_validation: bool = True,
    augment_prob: float = 0.3,
    use_focal_loss: bool = True,
    focal_gamma: float = 1.5,
    neg_weight_multiplier: float = 1.5,
):
    print("=" * 70)
    print("Lead Classifier v4 - Enhanced Architecture & Statistical Analysis")
    print("=" * 70)
    
    # Set seeds
    random.seed(seed)
    np.random.seed(seed)
    torch.manual_seed(seed)
    if torch.cuda.is_available():
        torch.cuda.manual_seed_all(seed)
        torch.backends.cudnn.deterministic = True
        torch.backends.cudnn.benchmark = False
    
    config = ModelConfig()
    
    if require_gpu and not torch.cuda.is_available():
        raise SystemExit("GPU required but CUDA is not available.")
    
    device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
    if device.type == "cuda":
        print(f"Device: {device} ({torch.cuda.get_device_name(0)})")
        print(f"CUDA Memory: {torch.cuda.get_device_properties(0).total_memory / 1e9:.1f} GB")
    else:
        print(f"Device: {device}")
    
    print(f"\nConfiguration:")
    print(f"  Embedding dim: {config.embedding_dim}")
    print(f"  Hidden dim: {config.hidden_dim}")
    print(f"  Attention heads: {config.num_attention_heads}")
    print(f"  Transformer layers: {config.num_transformer_layers}")
    print(f"  Dropout: {config.dropout}")
    print(f"  Label smoothing: {config.label_smoothing}")
    print(f"  Batch size: {config.batch_size} (effective: {config.batch_size * config.gradient_accumulation_steps})")
    print(f"  Model: {'Attention' if use_attention_model else 'Simple'}")
    print(f"  Augmentation probability: {augment_prob}")
    print(f"  Loss: {'Focal' if use_focal_loss else 'CrossEntropy'} (gamma={focal_gamma})")
    print(f"  Neg weight multiplier: {neg_weight_multiplier}")
    
    # Generate/load training data
    print("\n" + "=" * 70)
    print("DATA LOADING")
    print("=" * 70)
    
    if use_external_only:
        print("Using external data only")
        all_data = []
    else:
        print(f"Generating synthetic data (scale={scale_multiplier})...")
        generator = TrainingDataGenerator(seed=seed, scale_multiplier=scale_multiplier)
        all_data = generator.generate_all_data()
    
    # Load external datasets
    if external_data_dir:
        print(f"\nLoading external datasets from {external_data_dir}...")
        external_data = load_all_external_data(external_data_dir)
        if external_data:
            all_data.extend(external_data)
            random.shuffle(all_data)
            print(f"Combined total: {len(all_data)} samples")
    
    if len(all_data) == 0:
        raise SystemExit("No training data available!")
    
    # Domain-disjoint split
    print("\nCreating domain-disjoint splits...")
    domain_map = defaultdict(list)
    for item in all_data:
        domain_map[item[3]].append(item)
    
    domains = list(domain_map.keys())
    random.shuffle(domains)
    
    train_cut = int(len(domains) * 0.80)
    val_cut = int(len(domains) * 0.90)
    
    train_domains = set(domains[:train_cut])
    val_domains = set(domains[train_cut:val_cut])
    test_domains = set(domains[val_cut:])
    
    train_data = [item for d in train_domains for item in domain_map[d]]
    val_data = [item for d in val_domains for item in domain_map[d]]
    test_data = [item for d in test_domains for item in domain_map[d]]
    
    random.shuffle(train_data)
    random.shuffle(val_data)
    random.shuffle(test_data)
    
    train_leads = sum(1 for d in train_data if d[4] == 1)
    val_leads = sum(1 for d in val_data if d[4] == 1)
    test_leads = sum(1 for d in test_data if d[4] == 1)
    
    print(f"Train: {len(train_data):,} samples ({train_leads:,} leads, {len(train_domains)} domains)")
    print(f"Val:   {len(val_data):,} samples ({val_leads:,} leads, {len(val_domains)} domains)")
    print(f"Test:  {len(test_data):,} samples ({test_leads:,} leads, {len(test_domains)} domains)")
    
    # Tokenizer
    print("\nBuilding tokenizer...")
    tokenizer = EnhancedTokenizer(config.vocab_size)
    all_texts = []
    for title, snippet, url, domain, _ in all_data:
        all_texts.extend([title, snippet, url, domain])
    tokenizer.fit(all_texts)
    
    # Data augmenter
    augmenter = DataAugmenter(seed=seed)
    
    # Datasets
    train_dataset = LeadDataset(train_data, tokenizer, config, augmenter, augment_prob)
    val_dataset = LeadDataset(val_data, tokenizer, config)
    test_dataset = LeadDataset(test_data, tokenizer, config)
    
    train_loader = DataLoader(train_dataset, batch_size=config.batch_size, shuffle=True, num_workers=0)
    val_loader = DataLoader(val_dataset, batch_size=config.batch_size)
    test_loader = DataLoader(test_dataset, batch_size=config.batch_size)
    
    # Cross-validation (optional)
    if do_cross_validation and len(train_data) > 1000:
        model_class = EnhancedLeadClassifier if use_attention_model else SimpleLeadClassifier
        cv_results = cross_validate(model_class, train_data[:20000], tokenizer, config, device, n_folds=5, seed=seed)
    
    # Model
    print("\n" + "=" * 70)
    print("MODEL TRAINING")
    print("=" * 70)
    
    if use_attention_model:
        print("Creating Enhanced Attention Model...")
        model = EnhancedLeadClassifier(config).to(device)
    else:
        print("Creating Simple Mean-Pooling Model...")
        model = SimpleLeadClassifier(config).to(device)
    
    total_params = sum(p.numel() for p in model.parameters())
    trainable_params = sum(p.numel() for p in model.parameters() if p.requires_grad)
    print(f"Total parameters: {total_params:,}")
    print(f"Trainable parameters: {trainable_params:,}")
    
    # Optimizer
    optimizer = torch.optim.AdamW(
        model.parameters(), 
        lr=config.learning_rate, 
        weight_decay=config.weight_decay,
        betas=(0.9, 0.999),
    )
    
    # Learning rate scheduler
    total_steps = len(train_loader) * config.num_epochs // config.gradient_accumulation_steps
    warmup_steps = len(train_loader) * config.warmup_epochs // config.gradient_accumulation_steps
    scheduler = WarmupCosineScheduler(optimizer, warmup_steps, total_steps)
    
    # Loss with class weighting and label smoothing
    pos_count = sum(1 for d in train_data if d[4] == 1)
    neg_count = len(train_data) - pos_count
    class_weights = torch.tensor([1.0, neg_count / max(1, pos_count)]).to(device)
    class_weights[0] = class_weights[0] * neg_weight_multiplier

    if use_focal_loss:
        criterion = FocalLoss(weight=class_weights, gamma=focal_gamma)
    else:
        criterion = nn.CrossEntropyLoss(weight=class_weights, label_smoothing=config.label_smoothing)

    print(f"Class weights: [0: {class_weights[0]:.2f}, 1: {class_weights[1]:.2f}]")
    
    best_val_acc = 0.0
    best_val_f1 = 0.0
    patience_counter = 0
    training_history = []
    
    print("\nStarting training...")
    start_time = time.time()
    
    for epoch in range(config.num_epochs):
        model.train()
        train_loss = 0.0
        train_correct = 0
        train_total = 0
        optimizer.zero_grad()
        
        for step, batch in enumerate(train_loader):
            title = batch["title"].to(device)
            snippet = batch["snippet"].to(device)
            url = batch["url"].to(device)
            domain = batch["domain"].to(device)
            labels = batch["label"].to(device)
            
            logits = model(title, snippet, url, domain)
            loss = criterion(logits, labels) / config.gradient_accumulation_steps
            loss.backward()
            
            if (step + 1) % config.gradient_accumulation_steps == 0:
                torch.nn.utils.clip_grad_norm_(model.parameters(), 1.0)
                optimizer.step()
                scheduler.step()
                optimizer.zero_grad()
            
            train_loss += loss.item() * config.gradient_accumulation_steps
            preds = logits.argmax(dim=-1)
            train_correct += (preds == labels).sum().item()
            train_total += labels.size(0)
        
        train_acc = train_correct / train_total
        avg_train_loss = train_loss / len(train_loader)
        
        # Validation
        model.eval()
        val_correct = 0
        val_total = 0
        val_y_true, val_y_pred, val_y_prob = [], [], []
        
        with torch.no_grad():
            for batch in val_loader:
                title = batch["title"].to(device)
                snippet = batch["snippet"].to(device)
                url = batch["url"].to(device)
                domain = batch["domain"].to(device)
                labels = batch["label"].to(device)
                
                logits = model(title, snippet, url, domain)
                probs = F.softmax(logits, dim=-1)
                preds = logits.argmax(dim=-1)
                
                val_correct += (preds == labels).sum().item()
                val_total += labels.size(0)
                val_y_true.extend(labels.cpu().tolist())
                val_y_pred.extend(preds.cpu().tolist())
                val_y_prob.extend(probs[:, 1].cpu().tolist())
        
        val_acc = val_correct / val_total
        val_metrics = compute_metrics(val_y_true, val_y_pred, val_y_prob)
        
        current_lr = scheduler.get_lr()[0]
        print(f"Epoch {epoch+1:3d} | Loss: {avg_train_loss:.4f} | Train Acc: {train_acc:.4f} | "
              f"Val Acc: {val_acc:.4f} | Val F1: {val_metrics['f1']:.4f} | LR: {current_lr:.2e}")
        
        training_history.append({
            "epoch": epoch + 1,
            "train_loss": avg_train_loss,
            "train_acc": train_acc,
            "val_acc": val_acc,
            "val_f1": val_metrics['f1'],
            "lr": current_lr,
        })
        
        # Save best model (by F1 score for better balance)
        if val_metrics['f1'] > best_val_f1:
            best_val_f1 = val_metrics['f1']
            best_val_acc = val_acc
            patience_counter = 0
            torch.save(model.state_dict(), "models/best_model_v4.pt")
        else:
            patience_counter += 1
            if patience_counter >= config.patience:
                print(f"\nEarly stopping at epoch {epoch + 1}")
                break
    
    training_time = time.time() - start_time
    print(f"\nTraining completed in {training_time / 60:.1f} minutes")
    print(f"Best validation accuracy: {best_val_acc:.4f}")
    print(f"Best validation F1: {best_val_f1:.4f}")
    
    # Load best model
    model.load_state_dict(torch.load("models/best_model_v4.pt"))
    
    # ==========================================================================
    # TEST SET EVALUATION
    # ==========================================================================
    print("\n" + "=" * 70)
    print("TEST SET EVALUATION")
    print("=" * 70)
    
    model.eval()
    y_true, y_pred, y_prob = [], [], []
    
    with torch.no_grad():
        for batch in test_loader:
            title = batch["title"].to(device)
            snippet = batch["snippet"].to(device)
            url = batch["url"].to(device)
            domain = batch["domain"].to(device)
            labels = batch["label"].to(device)
            
            logits = model(title, snippet, url, domain)
            probs = F.softmax(logits, dim=-1)
            preds = logits.argmax(dim=-1)
            
            y_true.extend(labels.cpu().tolist())
            y_pred.extend(preds.cpu().tolist())
            y_prob.extend(probs[:, 1].cpu().tolist())
    
    metrics = compute_metrics(y_true, y_pred, y_prob)
    
    print("\nTest Set Metrics:")
    print(f"  Accuracy:     {metrics['accuracy']:.4f}")
    print(f"  Precision:    {metrics['precision']:.4f}")
    print(f"  Recall:       {metrics['recall']:.4f}")
    print(f"  F1 Score:     {metrics['f1']:.4f}")
    print(f"  Specificity:  {metrics['specificity']:.4f}")
    print(f"  Balanced Acc: {metrics['balanced_acc']:.4f}")
    print(f"  MCC:          {metrics['mcc']:.4f}")
    if 'roc_auc' in metrics:
        print(f"  ROC AUC:      {metrics['roc_auc']:.4f}")
        print(f"  Avg Precision:{metrics['avg_precision']:.4f}")
    print(f"\nConfusion Matrix:")
    print(f"  TP={metrics['tp']:,}  FP={metrics['fp']:,}")
    print(f"  FN={metrics['fn']:,}  TN={metrics['tn']:,}")
    
    # Bootstrap confidence intervals
    if bootstrap_samples > 0:
        print(f"\nBootstrap 95% CI ({bootstrap_samples} samples):")
        ci = bootstrap_metrics(y_true, y_pred, y_prob, n_samples=bootstrap_samples, seed=seed)
        for key, stats in ci.items():
            print(f"  {key:<14}: {stats['mean']:.4f} [{stats['low']:.4f}, {stats['high']:.4f}]")
    
    # Calibration analysis
    print("\nCalibration Analysis:")
    cal = analyze_calibration(y_true, y_prob)
    if 'ece' in cal:
        print(f"  Expected Calibration Error (ECE): {cal['ece']:.4f}")
    
    # ==========================================================================
    # WEBCRAWLER EXAMPLES
    # ==========================================================================
    print("\n" + "=" * 70)
    print("WEBCRAWLER EXAMPLES TEST")
    print("=" * 70)
    
    wc_acc, wc_results = evaluate_webcrawler_examples(model, tokenizer, config, device)
    
    # ==========================================================================
    # ONNX EXPORT
    # ==========================================================================
    print("\n" + "=" * 70)
    print("ONNX EXPORT")
    print("=" * 70)
    
    model.eval()
    model.cpu()
    
    dummy_title = torch.zeros(1, config.max_title_len, dtype=torch.long)
    dummy_snippet = torch.zeros(1, config.max_snippet_len, dtype=torch.long)
    dummy_url = torch.zeros(1, config.max_url_len, dtype=torch.long)
    dummy_domain = torch.zeros(1, config.max_domain_len, dtype=torch.long)
    
    onnx_path = "models/lead_classifier.onnx"
    
    torch.onnx.export(
        model,
        (dummy_title, dummy_snippet, dummy_url, dummy_domain),
        onnx_path,
        input_names=["title", "snippet", "url", "domain"],
        output_names=["logits"],
        dynamic_axes={
            "title": {0: "batch"},
            "snippet": {0: "batch"},
            "url": {0: "batch"},
            "domain": {0: "batch"},
            "logits": {0: "batch"},
        },
        opset_version=14,
    )
    
    # Save tokenizer and config
    tokenizer.save("models/vocab.json")
    with open("models/config.json", "w") as f:
        json.dump({
            "max_title_len": config.max_title_len,
            "max_snippet_len": config.max_snippet_len,
            "max_url_len": config.max_url_len,
            "max_domain_len": config.max_domain_len,
            "version": "v4",
            "model_type": "attention" if use_attention_model else "simple",
        }, f, indent=2)
    
    print(f"ONNX model saved to {onnx_path}")
    
    # Verify ONNX
    print("\nVerifying ONNX model...")
    ort_session = ort.InferenceSession(onnx_path)
    ort_outputs = ort_session.run(None, {
        "title": dummy_title.numpy(),
        "snippet": dummy_snippet.numpy(),
        "url": dummy_url.numpy(),
        "domain": dummy_domain.numpy(),
    })
    print(f"ONNX output shape: {ort_outputs[0].shape}")
    
    # Save training report
    report = {
        "version": "v4",
        "model_type": "attention" if use_attention_model else "simple",
        "seed": seed,
        "scale_multiplier": scale_multiplier,
        "training_samples": len(train_data),
        "validation_samples": len(val_data),
        "test_samples": len(test_data),
        "training_time_minutes": training_time / 60,
        "test_metrics": metrics,
        "webcrawler_accuracy": wc_acc,
        "training_history": training_history,
        "config": {
            "vocab_size": config.vocab_size,
            "embedding_dim": config.embedding_dim,
            "hidden_dim": config.hidden_dim,
            "num_attention_heads": config.num_attention_heads,
            "num_transformer_layers": config.num_transformer_layers,
            "dropout": config.dropout,
            "batch_size": config.batch_size,
            "learning_rate": config.learning_rate,
        }
    }
    
    with open("models/training_report_v4.json", "w") as f:
        json.dump(report, f, indent=2)
    
    print("\n" + "=" * 70)
    print("✓ TRAINING COMPLETE")
    print("=" * 70)
    print(f"Test Accuracy: {metrics['accuracy']:.4f}")
    print(f"Test F1: {metrics['f1']:.4f}")
    print(f"Webcrawler Accuracy: {wc_acc*100:.0f}%")
    print(f"Model saved to: {onnx_path}")


if __name__ == "__main__":
    os.chdir(Path(__file__).parent)
    os.makedirs("models", exist_ok=True)
    
    parser = argparse.ArgumentParser(description="Train lead classifier v4 with enhanced features")
    parser.add_argument("--seed", type=int, default=42, help="Random seed")
    parser.add_argument("--scale-multiplier", type=int, default=5, help="Scale training data size")
    parser.add_argument("--require-gpu", action="store_true", help="Fail if CUDA GPU not available")
    parser.add_argument("--bootstrap-samples", type=int, default=1000, help="Bootstrap samples for CI")
    parser.add_argument("--external-data-dir", type=str, default="external_data", 
                        help="Directory with external training data")
    parser.add_argument("--no-external", action="store_true", help="Skip external datasets")
    parser.add_argument("--external-only", action="store_true", help="Use only external data")
    parser.add_argument("--simple-model", action="store_true", help="Use simple model instead of attention")
    parser.add_argument("--no-cv", action="store_true", help="Skip cross-validation")
    parser.add_argument("--augment-prob", type=float, default=0.3, help="Data augmentation probability")
    parser.add_argument("--no-focal", action="store_true", help="Disable focal loss (use cross-entropy)")
    parser.add_argument("--focal-gamma", type=float, default=1.5, help="Focal loss gamma")
    parser.add_argument("--neg-weight-multiplier", type=float, default=1.5, help="Extra weight for negative class")
    
    args = parser.parse_args()
    
    train_model(
        seed=args.seed,
        scale_multiplier=args.scale_multiplier,
        require_gpu=args.require_gpu,
        bootstrap_samples=args.bootstrap_samples,
        external_data_dir=None if args.no_external else args.external_data_dir,
        use_external_only=args.external_only,
        use_attention_model=not args.simple_model,
        do_cross_validation=not args.no_cv,
        augment_prob=args.augment_prob,
        use_focal_loss=not args.no_focal,
        focal_gamma=args.focal_gamma,
        neg_weight_multiplier=args.neg_weight_multiplier,
    )
