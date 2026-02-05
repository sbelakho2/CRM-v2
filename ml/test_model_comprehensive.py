#!/usr/bin/env python3
"""
Comprehensive Model Testing Script
Tests the lead classifier on a wide variety of real-world examples.
"""

import json
import torch
import torch.nn.functional as F
import onnxruntime as ort
import numpy as np
from pathlib import Path
from typing import List, Tuple, Dict
from collections import defaultdict


def load_vocab(path: str) -> dict:
    """Load vocabulary from JSON file."""
    with open(path, 'r') as f:
        data = json.load(f)
    return data['word2idx']


def load_config(path: str) -> dict:
    """Load model config from JSON file."""
    with open(path, 'r') as f:
        return json.load(f)


def tokenize(text: str) -> List[str]:
    """Simple tokenization."""
    import re
    text = text.lower()
    text = re.sub(r"[^a-z0-9\-\.\s/]", " ", text)
    tokens = []
    for t in text.split():
        if t:
            parts = re.split(r'([./\-])', t)
            tokens.extend([p for p in parts if p])
    return tokens


def encode(text: str, word2idx: dict, max_len: int) -> List[int]:
    """Encode text to token IDs."""
    tokens = tokenize(text)[:max_len - 1]
    ids = [2]  # <CLS>
    ids.extend([word2idx.get(t, 1) for t in tokens])
    while len(ids) < max_len:
        ids.append(0)
    return ids[:max_len]


def predict(session: ort.InferenceSession, word2idx: dict, config: dict,
            title: str, snippet: str, url: str, domain: str) -> Tuple[int, float]:
    """Make a prediction using the ONNX model."""
    title_ids = np.array([encode(title, word2idx, config['max_title_len'])], dtype=np.int64)
    snippet_ids = np.array([encode(snippet, word2idx, config['max_snippet_len'])], dtype=np.int64)
    url_ids = np.array([encode(url, word2idx, config['max_url_len'])], dtype=np.int64)
    domain_ids = np.array([encode(domain, word2idx, config['max_domain_len'])], dtype=np.int64)
    
    outputs = session.run(None, {
        "title": title_ids,
        "snippet": snippet_ids,
        "url": url_ids,
        "domain": domain_ids,
    })
    
    logits = outputs[0][0]
    probs = np.exp(logits) / np.sum(np.exp(logits))
    pred = int(np.argmax(logits))
    conf = float(probs[pred])
    
    return pred, conf


# =============================================================================
# COMPREHENSIVE TEST DATA
# =============================================================================

# REAL COMPANIES (Label = 1) - Should be classified as COMPANY
REAL_COMPANIES = [
    # EMS / Contract Manufacturing
    ("Jabil | Global Manufacturing Services", "Manufacturing solutions for electronics", "https://www.jabil.com/about", "jabil.com"),
    ("Flex Ltd | Design & Manufacturing", "Global supply chain solutions", "https://www.flex.com", "flex.com"),
    ("Celestica | Manufacturing and Design", "End-to-end product lifecycle solutions", "https://www.celestica.com", "celestica.com"),
    ("Sanmina Corporation", "Integrated manufacturing solutions", "https://www.sanmina.com", "sanmina.com"),
    ("Benchmark Electronics", "Engineering and manufacturing services", "https://www.benchmark.com", "benchmark.com"),
    ("Plexus Corp | Product Development", "Design, manufacturing, and supply chain", "https://www.plexus.com", "plexus.com"),
    ("TTI, Inc | Electronic Components", "Passive components distributor", "https://www.tti.com", "tti.com"),
    ("Kimball Electronics | Manufacturing", "Contract electronics manufacturing", "https://www.kimballelectronics.com", "kimballelectronics.com"),
    ("Creation Technologies | EMS", "Electronics manufacturing services", "https://www.creationtech.com", "creationtech.com"),
    ("Zollner Elektronik AG", "Electronic manufacturing services", "https://www.zollner.de", "zollner.de"),
    ("NOTE | Electronics Manufacturing", "Swedish EMS provider", "https://www.note.eu", "note.eu"),
    ("GPV International", "Electronics manufacturing", "https://www.gpv.dk", "gpv.dk"),
    ("Kitron ASA | Electronics", "Scandinavian EMS company", "https://www.kitron.com", "kitron.com"),
    ("Lacroix Electronics", "French EMS provider", "https://www.lacroix-electronics.com", "lacroix-electronics.com"),
    ("Neways Electronics", "Dutch EMS company", "https://www.neways.com", "neways.com"),
    
    # Semiconductor Companies
    ("AMD | High-Performance Computing", "Processors and graphics", "https://www.amd.com", "amd.com"),
    ("Intel Corporation | Data Center", "Processors and memory", "https://www.intel.com", "intel.com"),
    ("NVIDIA | AI Computing", "GPUs and AI platforms", "https://www.nvidia.com", "nvidia.com"),
    ("Qualcomm | Mobile Technology", "Wireless semiconductors", "https://www.qualcomm.com", "qualcomm.com"),
    ("Texas Instruments | Analog", "Semiconductors and embedded", "https://www.ti.com", "ti.com"),
    ("NXP Semiconductors | Automotive", "Automotive and IoT chips", "https://www.nxp.com", "nxp.com"),
    ("Microchip Technology", "Microcontrollers and analog", "https://www.microchip.com", "microchip.com"),
    ("Infineon Technologies", "Automotive and power semiconductors", "https://www.infineon.com", "infineon.com"),
    ("ON Semiconductor", "Power and sensing solutions", "https://www.onsemi.com", "onsemi.com"),
    ("Analog Devices", "High-performance analog", "https://www.analog.com", "analog.com"),
    ("Broadcom Inc", "Semiconductor solutions", "https://www.broadcom.com", "broadcom.com"),
    ("Micron Technology", "Memory and storage", "https://www.micron.com", "micron.com"),
    ("Marvell Technology", "Data infrastructure semiconductors", "https://www.marvell.com", "marvell.com"),
    ("Renesas Electronics", "Automotive and industrial MCUs", "https://www.renesas.com", "renesas.com"),
    ("STMicroelectronics", "European semiconductor company", "https://www.st.com", "st.com"),
    
    # Electronic Component Distributors
    ("Arrow Electronics | Global", "Electronic components distributor", "https://www.arrow.com", "arrow.com"),
    ("Avnet | Technology Solutions", "Global electronics distributor", "https://www.avnet.com", "avnet.com"),
    ("Digi-Key Electronics", "Electronic component distributor", "https://www.digikey.com", "digikey.com"),
    ("Mouser Electronics", "Electronic components distributor", "https://www.mouser.com", "mouser.com"),
    ("Future Electronics", "Global electronic distributor", "https://www.futureelectronics.com", "futureelectronics.com"),
    ("Newark | An Avnet Company", "Electronic components", "https://www.newark.com", "newark.com"),
    ("Farnell | Electronic Components", "Electronic distributor UK", "https://www.farnell.com", "farnell.com"),
    ("RS Components", "Industrial and electronics", "https://www.rs-online.com", "rs-online.com"),
    ("Würth Elektronik", "Electronic components manufacturer", "https://www.we-online.com", "we-online.com"),
    
    # Data Center Companies
    ("Digital Realty | Data Centers", "Global data center provider", "https://www.digitalrealty.com", "digitalrealty.com"),
    ("Equinix | Data Centers", "Colocation and interconnection", "https://www.equinix.com", "equinix.com"),
    ("CyrusOne | Data Centers", "Enterprise data center solutions", "https://www.cyrusone.com", "cyrusone.com"),
    ("CoreSite | Data Centers", "Colocation services", "https://www.coresite.com", "coresite.com"),
    ("QTS Data Centers", "Hyperscale data centers", "https://www.qtsdatacenters.com", "qtsdatacenters.com"),
    ("DataBank | Data Centers", "Enterprise colocation", "https://www.databank.com", "databank.com"),
    ("Compass Datacenters", "Custom data centers", "https://www.compassdatacenters.com", "compassdatacenters.com"),
    ("US Signal | IT Services", "Cloud and data center", "https://www.ussignal.com", "ussignal.com"),
    ("Flexential | Data Centers", "Hybrid IT solutions", "https://www.flexential.com", "flexential.com"),
    ("Vantage Data Centers", "Hyperscale campuses", "https://www.vantage-dc.com", "vantage-dc.com"),
    ("Switch | Data Centers", "Technology infrastructure", "https://www.switch.com", "switch.com"),
    ("Stack Infrastructure", "Data center developer", "https://www.stackinfra.com", "stackinfra.com"),
    ("Aligned Data Centers", "Adaptive colocation", "https://www.aligneddc.com", "aligneddc.com"),
    ("T5 Data Centers", "Mission critical facilities", "https://www.t5datacenters.com", "t5datacenters.com"),
    ("Colovore | Data Centers", "High-density colocation", "https://www.colovore.com", "colovore.com"),
    ("Cologix | Data Centers", "Network-dense colocation", "https://www.cologix.com", "cologix.com"),
    
    # Automotive Suppliers
    ("Bosch | Automotive Technology", "Automotive systems and components", "https://www.bosch.com", "bosch.com"),
    ("Denso Corporation | Automotive", "Automotive components supplier", "https://www.denso.com", "denso.com"),
    ("Continental AG | Automotive", "Automotive technology company", "https://www.continental.com", "continental.com"),
    ("Aptiv | Automotive Technology", "Vehicle architecture and software", "https://www.aptiv.com", "aptiv.com"),
    ("Magna International", "Automotive supplier", "https://www.magna.com", "magna.com"),
    ("Lear Corporation | Seating", "Automotive seating and electrical", "https://www.lear.com", "lear.com"),
    ("Yazaki Corporation | Wiring", "Automotive wiring harnesses", "https://www.yazaki.com", "yazaki.com"),
    ("Valeo | Automotive", "Automotive technology supplier", "https://www.valeo.com", "valeo.com"),
    ("Forvia | Automotive", "Automotive technology group", "https://www.forvia.com", "forvia.com"),
    ("ZF Friedrichshafen", "Automotive driveline technology", "https://www.zf.com", "zf.com"),
    
    # Aerospace & Defense
    ("Thales Group | Aerospace", "Aerospace and defense systems", "https://www.thalesgroup.com", "thalesgroup.com"),
    ("Safran | Aerospace", "Aircraft engines and equipment", "https://www.safran-group.com", "safran-group.com"),
    ("Airbus | Aerospace", "Commercial aircraft manufacturer", "https://www.airbus.com", "airbus.com"),
    ("Boeing | Aerospace", "Aerospace and defense", "https://www.boeing.com", "boeing.com"),
    ("Lockheed Martin | Defense", "Defense and aerospace", "https://www.lockheedmartin.com", "lockheedmartin.com"),
    ("RTX Corporation | Defense", "Aerospace and defense", "https://www.rtx.com", "rtx.com"),
    ("L3Harris Technologies", "Defense technology", "https://www.l3harris.com", "l3harris.com"),
    ("Northrop Grumman | Defense", "Defense and security", "https://www.northropgrumman.com", "northropgrumman.com"),
    ("BAE Systems | Defense", "Defense and security", "https://www.baesystems.com", "baesystems.com"),
    ("Rolls-Royce | Aerospace", "Aero engines and power systems", "https://www.rolls-royce.com", "rolls-royce.com"),
    
    # IT / Enterprise Technology
    ("IBM | Enterprise Solutions", "Enterprise technology and cloud", "https://www.ibm.com", "ibm.com"),
    ("HPE | Enterprise Technology", "Servers and enterprise solutions", "https://www.hpe.com", "hpe.com"),
    ("Dell Technologies | Enterprise", "Servers, storage, networking", "https://www.dell.com", "dell.com"),
    ("Cisco Systems | Networking", "Networking and security", "https://www.cisco.com", "cisco.com"),
    ("Oracle | Enterprise Software", "Database and cloud applications", "https://www.oracle.com", "oracle.com"),
    ("SAP | Enterprise Software", "Enterprise applications", "https://www.sap.com", "sap.com"),
    ("ServiceNow | IT Management", "IT service management", "https://www.servicenow.com", "servicenow.com"),
    ("SHI International | IT Solutions", "IT solutions provider", "https://www.shi.com", "shi.com"),
    ("CDW | Technology Solutions", "Technology solutions provider", "https://www.cdw.com", "cdw.com"),
    ("Insight | IT Services", "Technology solutions provider", "https://www.insight.com", "insight.com"),
    ("SoftwareONE | Software", "Software and cloud solutions", "https://www.softwareone.com", "softwareone.com"),
    
    # Industrial / Manufacturing Companies
    ("Siemens | Industrial Automation", "Industrial automation and digitalization", "https://www.siemens.com", "siemens.com"),
    ("ABB | Industrial Technology", "Robotics and automation", "https://www.abb.com", "abb.com"),
    ("Schneider Electric | Energy", "Energy management and automation", "https://www.se.com", "se.com"),
    ("Honeywell | Industrial", "Industrial technology and automation", "https://www.honeywell.com", "honeywell.com"),
    ("Emerson | Automation", "Automation solutions", "https://www.emerson.com", "emerson.com"),
    ("Rockwell Automation", "Industrial automation", "https://www.rockwellautomation.com", "rockwellautomation.com"),
    ("Parker Hannifin | Motion", "Motion and control technologies", "https://www.parker.com", "parker.com"),
    ("Eaton Corporation | Power", "Power management solutions", "https://www.eaton.com", "eaton.com"),
    ("General Electric | Industrial", "Industrial technology", "https://www.ge.com", "ge.com"),
    ("3M Company | Industrial", "Industrial and consumer products", "https://www.3m.com", "3m.com"),
    
    # Regional / Smaller Companies
    ("Sumitomo Electric Morocco", "Wiring harnesses Morocco", "https://www.sumitomo-electric.ma", "sumitomo-electric.ma"),
    ("TE Connectivity | Sensors", "Connectivity and sensors", "https://www.te.com", "te.com"),
    ("Amphenol | Connectors", "Interconnect solutions", "https://www.amphenol.com", "amphenol.com"),
    ("Molex | Connectors", "Electronic connectors", "https://www.molex.com", "molex.com"),
    ("Murata Manufacturing", "Electronic components Japan", "https://www.murata.com", "murata.com"),
    ("TDK Corporation | Electronics", "Electronic components", "https://www.tdk.com", "tdk.com"),
    ("Vishay Intertechnology", "Discrete semiconductors and passives", "https://www.vishay.com", "vishay.com"),
    ("Kemet | Capacitors", "Capacitors and sensors", "https://www.kemet.com", "kemet.com"),
    ("AVX Corporation | Components", "Advanced electronic components", "https://www.avx.com", "avx.com"),
    
    # Generic company pages
    ("About Us | Precision Manufacturing Inc", "Leading manufacturer since 1985", "https://www.precisionmfg.com/about", "precisionmfg.com"),
    ("Contact | Atlas Industrial Group", "Get in touch with our team", "https://www.atlasindustrial.com/contact", "atlasindustrial.com"),
    ("Services | Summit Technology Solutions", "IT services for enterprise", "https://www.summittech.com/services", "summittech.com"),
    ("Careers | Global Electronics Corp", "Join our team", "https://www.globalelectronics.com/careers", "globalelectronics.com"),
    ("Products | Apex Semiconductor Ltd", "View our product catalog", "https://www.apexsemi.com/products", "apexsemi.com"),
    ("Home | Nordic Manufacturing AS", "Welcome to Nordic Manufacturing", "https://www.nordicmfg.no", "nordicmfg.no"),
    ("Locations | Pacific Components Inc", "Find us worldwide", "https://www.pacificcomponents.com/locations", "pacificcomponents.com"),
]

# JUNK / NON-LEADS (Label = 0) - Should be classified as JUNK
JUNK_EXAMPLES = [
    # Market Research Reports
    ("Morocco Automotive Market Report 2025", "Market research and analysis", "https://www.mordorintelligence.com/report", "mordorintelligence.com"),
    ("Global EMS Market Size & Share Analysis", "Industry research report", "https://www.grandviewresearch.com/report", "grandviewresearch.com"),
    ("Electronics Manufacturing Market Forecast", "Market size and forecast to 2030", "https://www.marketsandmarkets.com/report", "marketsandmarkets.com"),
    ("Semiconductor Industry Analysis 2025", "Market trends and analysis", "https://www.statista.com/study", "statista.com"),
    ("Data Center Market Report", "Global data center industry outlook", "https://www.technavio.com/report", "technavio.com"),
    ("PCB Assembly Market Growth Analysis", "Market research report", "https://www.kenresearch.com/report", "kenresearch.com"),
    ("Automotive Electronics Market Trends", "Industry analysis and forecast", "https://www.alliedmarketresearch.com/report", "alliedmarketresearch.com"),
    ("Global Contract Manufacturing Report", "EMS industry analysis", "https://www.fortunebusinessinsights.com/report", "fortunebusinessinsights.com"),
    ("US Electronics Manufacturing Outlook", "Market research study", "https://www.ibisworld.com/report", "ibisworld.com"),
    ("EU Semiconductor Market Analysis", "Industry forecast report", "https://www.researchandmarkets.com/report", "researchandmarkets.com"),
    ("Asia Pacific EMS Market Size", "Regional market analysis", "https://www.gminsights.com/report", "gminsights.com"),
    ("Industrial Automation Market Report", "Technology market research", "https://www.precedenceresearch.com/report", "precedenceresearch.com"),
    ("5G Infrastructure Market Forecast", "Telecom market analysis", "https://www.factmr.com/report", "factmr.com"),
    ("Electric Vehicle Market Report 2026", "EV industry analysis", "https://www.transparencymarketresearch.com/report", "transparencymarketresearch.com"),
    ("IoT Semiconductor Market Study", "Connected device chips analysis", "https://www.coherentmarketinsights.com/report", "coherentmarketinsights.com"),
    
    # News and Press Releases
    ("Jabil Announces Q3 Earnings", "Press release", "https://www.businesswire.com/news", "businesswire.com"),
    ("Intel to Acquire AI Startup", "Breaking news", "https://www.reuters.com/technology", "reuters.com"),
    ("NVIDIA Stock Surges on AI Demand", "Financial news", "https://www.bloomberg.com/news", "bloomberg.com"),
    ("AMD Reports Record Revenue", "Earnings announcement", "https://www.prnewswire.com/news", "prnewswire.com"),
    ("Semiconductor Shortage Update", "Industry news", "https://www.cnbc.com/technology", "cnbc.com"),
    ("Tech Stocks Rally on Strong Earnings", "Market update", "https://www.marketwatch.com/story", "marketwatch.com"),
    ("Apple Supplier Faces Production Issues", "Technology news", "https://www.wsj.com/tech", "wsj.com"),
    ("Data Center Investment Boom", "Industry coverage", "https://www.ft.com/content", "ft.com"),
    ("Top 10 EMS Companies Ranking 2025", "Industry analysis", "https://www.electronicdesign.com/article", "electronicdesign.com"),
    ("European Electronics Industry Update", "Regional news", "https://www.electronicsweekly.com/news", "electronicsweekly.com"),
    ("Semiconductor News Europe", "Industry news", "https://www.eenewseurope.com/article", "eenewseurope.com"),
    ("EE Times: Chip Shortage Analysis", "Industry commentary", "https://www.eetimes.com/analysis", "eetimes.com"),
    ("Startup Raises $50M for Data Centers", "Venture news", "https://www.techcrunch.com/article", "techcrunch.com"),
    ("Data Center Trends 2025", "Industry insights", "https://www.datacenterknowledge.com/article", "datacenterknowledge.com"),
    ("New Data Center Opens in Texas", "Industry news", "https://www.datacenterdynamics.com/news", "datacenterdynamics.com"),
    
    # Directory and Listing Sites
    ("Electronics Manufacturing Suppliers", "Directory listing", "https://www.thomasnet.com/suppliers", "thomasnet.com"),
    ("Find PCB Assembly Companies", "Supplier directory", "https://www.kompass.com/search", "kompass.com"),
    ("EMS Companies Directory", "Business listings", "https://www.manta.com/search", "manta.com"),
    ("Manufacturing Companies Yellow Pages", "Business directory", "https://www.yellowpages.com/search", "yellowpages.com"),
    ("Electronics Industry Database", "Company profiles", "https://www.dnb.com/business", "dnb.com"),
    ("Technology Companies Directory", "Business database", "https://www.zoominfo.com/companies", "zoominfo.com"),
    ("Global Electronics Suppliers", "Supplier directory", "https://www.globalsources.com/suppliers", "globalsources.com"),
    ("China Electronics Manufacturers", "Factory directory", "https://www.made-in-china.com/manufacturers", "made-in-china.com"),
    ("European Suppliers Directory", "B2B platform", "https://www.europages.com/companies", "europages.com"),
    ("Industrial Suppliers Network", "Supplier database", "https://www.industrynet.com/search", "industrynet.com"),
    ("Company Credit Reports", "Business data", "https://www.hoovers.com/company", "hoovers.com"),
    ("Supply Chain Company Profiles", "Directory", "https://www.supplychain247.com/directory", "supplychain247.com"),
    
    # Job Posting Sites
    ("Jobs at Jabil Inc", "Career opportunities", "https://www.indeed.com/jobs", "indeed.com"),
    ("Electronics Engineer Positions", "Job listings", "https://www.linkedin.com/jobs", "linkedin.com"),
    ("Manufacturing Jobs in Texas", "Employment opportunities", "https://www.glassdoor.com/Jobs", "glassdoor.com"),
    ("Data Center Technician Jobs", "Job search", "https://www.ziprecruiter.com/jobs", "ziprecruiter.com"),
    ("Semiconductor Industry Careers", "Job listings", "https://www.monster.com/jobs", "monster.com"),
    ("Engineering Positions Available", "Career site", "https://www.careerbuilder.com/jobs", "careerbuilder.com"),
    ("Tech Jobs at Startups", "Startup careers", "https://www.wellfound.com/jobs", "wellfound.com"),
    ("IT Jobs Near Me", "Local job search", "https://www.simplyhired.com/jobs", "simplyhired.com"),
    ("Technology Jobs - Dice", "Tech job board", "https://www.dice.com/jobs", "dice.com"),
    
    # Wikipedia and Educational
    ("Electronics Manufacturing - Wikipedia", "Encyclopedia article", "https://en.wikipedia.org/wiki/Electronics", "en.wikipedia.org"),
    ("Semiconductor Industry Overview", "Wikipedia", "https://wikipedia.org/wiki/Semiconductor", "wikipedia.org"),
    ("What is Contract Manufacturing?", "Educational content", "https://www.investopedia.com/terms", "investopedia.com"),
    ("History of Data Centers", "Encyclopedia", "https://www.britannica.com/technology", "britannica.com"),
    ("PCB Assembly Process Explained", "Educational", "https://www.sciencedirect.com/topics", "sciencedirect.com"),
    
    # Government and Trade Sites
    ("US Electronics Trade Data", "Government statistics", "https://www.trade.gov/data", "trade.gov"),
    ("Export Regulations Electronics", "Trade compliance", "https://www.export.gov/article", "export.gov"),
    ("Small Business Manufacturing Guide", "Government resource", "https://www.sba.gov/guide", "sba.gov"),
    ("US Manufacturing Census Data", "Statistics", "https://www.census.gov/manufacturing", "census.gov"),
    ("Labor Statistics Manufacturing", "Employment data", "https://www.bls.gov/industries", "bls.gov"),
    
    # Aggregators and Comparison Sites
    ("Compare EMS Providers", "Comparison site", "https://www.g2.com/categories/ems", "g2.com"),
    ("Top Contract Manufacturers Reviewed", "Review site", "https://www.capterra.com/categories/ems", "capterra.com"),
    ("Best Data Center Providers 2025", "Comparison", "https://www.gartner.com/reviews", "gartner.com"),
    ("Electronics Supplier Reviews", "User reviews", "https://www.trustpilot.com/categories", "trustpilot.com"),
    
    # Academic and Research
    ("IEEE: Electronics Manufacturing Research", "Academic paper", "https://www.ieee.org/publications", "ieee.org"),
    ("MIT: Future of Manufacturing", "University research", "https://www.mit.edu/research", "mit.edu"),
    ("Stanford: Semiconductor Research", "Academic", "https://www.stanford.edu/research", "stanford.edu"),
    ("Industry 4.0 Research Paper", "Academic publication", "https://www.researchgate.net/publication", "researchgate.net"),
    
    # Social Media and Forums
    ("r/electronics Discussion", "Reddit forum", "https://www.reddit.com/r/electronics", "reddit.com"),
    ("Electronics Engineering Forum", "Discussion board", "https://www.quora.com/topic/Electronics", "quora.com"),
    ("YouTube: PCB Assembly Tutorial", "Video content", "https://www.youtube.com/watch", "youtube.com"),
    ("Electronics Twitter Feed", "Social media", "https://www.twitter.com/electronics", "twitter.com"),
    ("Facebook: Manufacturing Group", "Social network", "https://www.facebook.com/groups", "facebook.com"),
    
    # Document Sharing
    ("EMS Industry Presentation", "SlideShare", "https://www.slideshare.net/presentation", "slideshare.net"),
    ("Manufacturing Whitepaper PDF", "Document", "https://www.scribd.com/document", "scribd.com"),
    ("Electronics Industry Report PDF", "Document hosting", "https://www.docplayer.net/document", "docplayer.net"),
    ("Semiconductor Analysis PDF", "Document", "https://www.issuu.com/docs", "issuu.com"),
    
    # Event and Conference Sites
    ("Electronics Trade Show 2025", "Event", "https://www.eventbrite.com/e/electronics", "eventbrite.com"),
    ("Manufacturing Summit Registration", "Conference", "https://www.cvent.com/events", "cvent.com"),
    ("SEMICON West 2025", "Trade show", "https://www.semiconwest.org", "semiconwest.org"),
    ("Electronica Munich 2025", "Trade fair", "https://www.electronica.de", "electronica.de"),
    ("CES 2025 Exhibitors", "Consumer electronics show", "https://www.ces.tech/exhibitors", "ces.tech"),
]


def run_comprehensive_test():
    """Run comprehensive model testing."""
    print("=" * 80)
    print("COMPREHENSIVE LEAD CLASSIFIER TEST")
    print("=" * 80)
    
    # Load model and config
    model_path = "models/lead_classifier.onnx"
    vocab_path = "models/vocab.json"
    config_path = "models/config.json"
    
    print(f"\nLoading model from {model_path}...")
    session = ort.InferenceSession(model_path)
    word2idx = load_vocab(vocab_path)
    config = load_config(config_path)
    
    print(f"Vocabulary size: {len(word2idx)}")
    print(f"Config: {config}")
    
    results = {
        "companies": {"correct": 0, "total": 0, "details": []},
        "junk": {"correct": 0, "total": 0, "details": []},
    }
    
    # Test real companies
    print("\n" + "=" * 80)
    print("TESTING REAL COMPANIES (Expected: COMPANY)")
    print("=" * 80)
    
    for title, snippet, url, domain in REAL_COMPANIES:
        pred, conf = predict(session, word2idx, config, title, snippet, url, domain)
        is_correct = pred == 1
        label = "COMPANY" if pred == 1 else "JUNK"
        status = "✓" if is_correct else "✗"
        
        results["companies"]["total"] += 1
        if is_correct:
            results["companies"]["correct"] += 1
        
        results["companies"]["details"].append({
            "title": title[:50],
            "domain": domain,
            "pred": label,
            "conf": conf,
            "correct": is_correct,
        })
        
        if not is_correct:
            print(f"{status} {title[:55]:<55} | {domain:<30} → {label} ({conf*100:.1f}%)")
    
    company_acc = results["companies"]["correct"] / results["companies"]["total"]
    company_errors = results["companies"]["total"] - results["companies"]["correct"]
    print(f"\nCompany detection: {results['companies']['correct']}/{results['companies']['total']} ({company_acc*100:.1f}%)")
    if company_errors > 0:
        print(f"  Errors: {company_errors} companies misclassified as JUNK")
    
    # Test junk examples
    print("\n" + "=" * 80)
    print("TESTING JUNK / NON-LEADS (Expected: JUNK)")
    print("=" * 80)
    
    for title, snippet, url, domain in JUNK_EXAMPLES:
        pred, conf = predict(session, word2idx, config, title, snippet, url, domain)
        is_correct = pred == 0
        label = "COMPANY" if pred == 1 else "JUNK"
        status = "✓" if is_correct else "✗"
        
        results["junk"]["total"] += 1
        if is_correct:
            results["junk"]["correct"] += 1
        
        results["junk"]["details"].append({
            "title": title[:50],
            "domain": domain,
            "pred": label,
            "conf": conf,
            "correct": is_correct,
        })
        
        if not is_correct:
            print(f"{status} {title[:55]:<55} | {domain:<30} → {label} ({conf*100:.1f}%)")
    
    junk_acc = results["junk"]["correct"] / results["junk"]["total"]
    junk_errors = results["junk"]["total"] - results["junk"]["correct"]
    print(f"\nJunk detection: {results['junk']['correct']}/{results['junk']['total']} ({junk_acc*100:.1f}%)")
    if junk_errors > 0:
        print(f"  Errors: {junk_errors} junk items misclassified as COMPANY")
    
    # Summary
    total_correct = results["companies"]["correct"] + results["junk"]["correct"]
    total_samples = results["companies"]["total"] + results["junk"]["total"]
    overall_acc = total_correct / total_samples
    
    print("\n" + "=" * 80)
    print("SUMMARY")
    print("=" * 80)
    print(f"Company Detection (Recall):    {results['companies']['correct']}/{results['companies']['total']} ({company_acc*100:.1f}%)")
    print(f"Junk Detection (Specificity):  {results['junk']['correct']}/{results['junk']['total']} ({junk_acc*100:.1f}%)")
    print(f"Overall Accuracy:              {total_correct}/{total_samples} ({overall_acc*100:.1f}%)")
    
    # Breakdown by domain type for junk
    print("\n" + "-" * 40)
    print("JUNK DETECTION BY CATEGORY:")
    
    domain_categories = {
        "Market Research": ["mordorintelligence", "grandviewresearch", "marketsandmarkets", "statista", 
                          "technavio", "kenresearch", "alliedmarketresearch", "fortunebusinessinsights",
                          "ibisworld", "researchandmarkets", "gminsights", "precedenceresearch", 
                          "factmr", "transparencymarketresearch", "coherentmarketinsights"],
        "News Sites": ["businesswire", "reuters", "bloomberg", "prnewswire", "cnbc", "marketwatch",
                      "wsj", "ft.", "electronicdesign", "electronicsweekly", "eenewseurope", "eetimes",
                      "techcrunch", "datacenterknowledge", "datacenterdynamics"],
        "Directories": ["thomasnet", "kompass", "manta", "yellowpages", "dnb.", "zoominfo",
                       "globalsources", "made-in-china", "europages", "industrynet", "hoovers", "supplychain247"],
        "Job Sites": ["indeed", "linkedin", "glassdoor", "ziprecruiter", "monster", "careerbuilder",
                     "wellfound", "simplyhired", "dice."],
        "Wikipedia/Educational": ["wikipedia", "investopedia", "britannica", "sciencedirect"],
        "Government": ["trade.gov", "export.gov", "sba.gov", "census.gov", "bls.gov"],
        "Social/Forums": ["reddit", "quora", "youtube", "twitter", "facebook"],
        "Documents": ["slideshare", "scribd", "docplayer", "issuu"],
        "Events": ["eventbrite", "cvent", "semiconwest", "electronica.de", "ces.tech"],
        "Other": ["g2.", "capterra", "gartner", "trustpilot", "ieee", "mit.edu", "stanford", "researchgate"],
    }
    
    category_results = defaultdict(lambda: {"correct": 0, "total": 0})
    
    for detail in results["junk"]["details"]:
        domain = detail["domain"].lower()
        found_category = "Other"
        for cat, patterns in domain_categories.items():
            if any(p in domain for p in patterns):
                found_category = cat
                break
        
        category_results[found_category]["total"] += 1
        if detail["correct"]:
            category_results[found_category]["correct"] += 1
    
    for cat in sorted(category_results.keys()):
        stats = category_results[cat]
        acc = stats["correct"] / stats["total"] * 100 if stats["total"] > 0 else 0
        print(f"  {cat:<20}: {stats['correct']}/{stats['total']} ({acc:.0f}%)")
    
    # Print errors summary
    if company_errors > 0 or junk_errors > 0:
        print("\n" + "=" * 80)
        print("MISCLASSIFIED ITEMS")
        print("=" * 80)
        
        if company_errors > 0:
            print("\nCompanies misclassified as JUNK:")
            for d in results["companies"]["details"]:
                if not d["correct"]:
                    print(f"  - {d['title']} ({d['domain']}) - {d['conf']*100:.1f}% confidence")
        
        if junk_errors > 0:
            print("\nJunk misclassified as COMPANY:")
            for d in results["junk"]["details"]:
                if not d["correct"]:
                    print(f"  - {d['title']} ({d['domain']}) - {d['conf']*100:.1f}% confidence")
    
    return results


if __name__ == "__main__":
    import os
    os.chdir(Path(__file__).parent)
    run_comprehensive_test()
