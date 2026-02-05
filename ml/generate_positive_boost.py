
import json
import random
import os
from pathlib import Path
from typing import List, Dict

def generate_positive_boost_data(num_samples: int = 20000, output_file: str = "external_data/positive_company_boost.json"):
    """
    Generates high-quality synthetic POSITIVE samples (legitimate companies)
    to balance out the aggression of the model against junk.
    """
    
    print(f"Generating {num_samples} positive boost samples...")
    
    # 1. Component lists for constructing plausible manufacturing/industrial text
    
    company_types = [
        "Manufacturing", "Industries", "Corporation", "Inc.", "Group", "Solutions", 
        "Systems", "Technologies", "Engineering", "Precision", "Fabrication", "Works",
        "Automation", "Controls", "Dynamics", "Components", "Materials"
    ]
    
    sectors = [
        "Aerospace", "Automotive", "Medical Device", "Semiconductor", "Industrial", 
        "Contract", "Precision", "Custom", "Advanced", "Global"
    ]
    
    capabilities = [
        "CNC Machining", "Injection Molding", "Sheet Metal Fabrication", "PCB Assembly",
        "Die Casting", "Extrusion", "Surface Finishing", "Heat Treating", "Rapid Prototyping",
        "Additive Manufacturing", "Turnkey Assembly", "Box Build", "Cable Harnessing"
    ]
    
    products = [
        "precision components", "custom machinery", "automated systems", "electronic assemblies",
        "engineered materials", "hydraulic units", "pneumatic controls", "sensor arrays",
        "networking hardware", "embedded chillers", "filtration systems", "power supplies"
    ]
    
    certifications = [
        "ISO 9001:2015", "AS9100D", "ISO 13485", "IATF 16949", "NADCAP", "ITAR Registered", "UL Certified"
    ]
    
    value_props = [
        "leading provider of", "global leader in", "your trusted partner for", "specializing in",
        "providing advanced solutions for", "innovating the future of", "delivering excellence in",
        "full-service manufacturer of"
    ]
    
    locations = [
        "North America", "Europe", "Asia", "Global", "USA", "Germany", "Japan", "Worldwide"
    ]

    samples = []
    
    for _ in range(num_samples):
        # Construct Title
        # e.g., "Apex Precision - Custom CNC Machining Services"
        company_name = f"Tx{random.randint(100,999)} {random.choice(sectors)} {random.choice(company_types)}"
        capability = random.choice(capabilities)
        title_formats = [
            f"{company_name} | {capability}",
            f"{company_name} - {random.choice(value_props)} {capability}",
            f"{company_name} | {random.choice(sectors)} {random.choice(products)}",
            f"Welcome to {company_name}",
        ]
        title = random.choice(title_formats)
        
        # Construct Snippet
        # Needs to sound legitimate and "boring" (not spammy)
        
        cert = random.choice(certifications) if random.random() > 0.5 else ""
        prod = random.choice(products)
        cap = random.choice(capabilities)
        sector = random.choice(sectors)
        
        snippet_templates = [
            f"{company_name} is a {random.choice(value_props)} {prod} for the {sector} industry. We offer {cap} and {random.choice(capabilities)} services. {cert}",
            f"Since 19{random.randint(50,99)}, {company_name} has provided high-quality {cap} for clients in {random.choice(locations)}. Contact us for a quote today.",
            f"Specializing in {cap}, {company_name} delivers reliable {prod}. Our facility is {cert} certified and ready to handle high-volume production runs.",
            f"{company_name}: Your partner for {sector} manufacturing solutions. From prototype to production, we handle {cap}, {random.choice(capabilities)}, and more.",
            f"We manufacturer {prod} using state-of-the-art {cap} technology. Serving {sector} markets worldwide. {cert}."
        ]
        
        snippet = random.choice(snippet_templates)
        
        # Add some domain realism if not auto-generated (the loader auto-generates if missing, but we can provide explicit ones too)
        # Using specific legitimate-sounding domains helps
        domain_base = company_name.lower().replace(" ", "").replace(".", "").replace(",", "")
        domain = f"{domain_base}.com"
        url = f"https://www.{domain}/"

        samples.append({
            "title": title,
            "snippet": snippet,
            "url": url,
            "domain": domain,
            "label": 1  # POSITIVE
        })
        
    # Ensure directory exists
    os.makedirs(os.path.dirname(output_file), exist_ok=True)
    
    with open(output_file, 'w', encoding='utf-8') as f:
        json.dump(samples, f, indent=2)
        
    print(f"Successfully saved {num_samples} samples to {output_file}")

if __name__ == "__main__":
    generate_positive_boost_data()
