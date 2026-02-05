#!/usr/bin/env python3
"""
Generate hard negative samples - market research, news, directories
These are the samples that commonly fool the classifier because they have
business-like content but are NOT actual company leads.
"""

import json
import random
from pathlib import Path

def generate_hard_negatives(seed: int = 42) -> list:
    """Generate hard negative samples that look like business content but are NOT leads."""
    rng = random.Random(seed)
    samples = []
    
    # =========================================================================
    # MARKET RESEARCH FIRMS - These look very business-like but are NOT leads
    # =========================================================================
    market_research_firms = [
        ("kenresearch.com", "Ken Research"),
        ("grandviewresearch.com", "Grand View Research"),
        ("mordorintelligence.com", "Mordor Intelligence"),
        ("marketsandmarkets.com", "MarketsandMarkets"),
        ("statista.com", "Statista"),
        ("ibisworld.com", "IBISWorld"),
        ("technavio.com", "Technavio"),
        ("alliedmarketresearch.com", "Allied Market Research"),
        ("fortunebusinessinsights.com", "Fortune Business Insights"),
        ("researchandmarkets.com", "Research and Markets"),
        ("gminsights.com", "Global Market Insights"),
        ("precedenceresearch.com", "Precedence Research"),
        ("factmr.com", "Fact.MR"),
        ("insightaceanalytic.com", "InsightAce Analytic"),
        ("transparencymarketresearch.com", "Transparency Market Research"),
        ("coherentmarketinsights.com", "Coherent Market Insights"),
        ("databridgemarketresearch.com", "Data Bridge Market Research"),
        ("futuremarketinsights.com", "Future Market Insights"),
        ("reportlinker.com", "ReportLinker"),
        ("persistence.com", "Persistence Market Research"),
    ]
    
    industries = [
        "Electronics Manufacturing", "Semiconductor", "Automotive", "Aerospace",
        "Data Center", "EMS", "PCB Assembly", "Contract Manufacturing",
        "Industrial Automation", "Medical Devices", "Telecommunications",
        "Energy Storage", "Electric Vehicle", "5G Infrastructure",
        "Cloud Computing", "Cybersecurity", "IoT", "AI Chips"
    ]
    
    regions = ["Morocco", "US", "EU", "UK", "Asia Pacific", "Global", "North America", 
               "Europe", "Middle East", "Latin America", "India", "China", "Japan"]
    
    years = ["2024", "2025", "2026", "2030"]
    
    report_patterns = [
        "{industry} Market Report {year}",
        "{region} {industry} Market Size",
        "Global {industry} Market Forecast {year}",
        "{industry} Industry Analysis",
        "{region} {industry} Market Trends",
        "{industry} Market Growth Analysis",
        "{industry} Market Share Report",
        "{region} {industry} Industry Outlook {year}",
        "{industry} Market Research Report",
        "Global {industry} Market Overview",
        "{industry} Sector Analysis {year}",
        "{region} {industry} Market Insights",
    ]
    
    snippet_patterns = [
        "The global {industry} market is projected to reach $X billion by {year}.",
        "Market research report analyzing the {industry} sector in {region}.",
        "Download the comprehensive {industry} market analysis report.",
        "Industry analysis covering key players, trends, and forecasts.",
        "Market size, share, and growth forecast for the {industry} industry.",
        "Detailed market research on {industry} with competitive analysis.",
        "The {region} {industry} market is expected to grow at X% CAGR.",
    ]
    
    for domain, firm in market_research_firms:
        for _ in range(200):  # 200 samples per firm = 4000 total
            industry = rng.choice(industries)
            region = rng.choice(regions)
            year = rng.choice(years)
            
            title_template = rng.choice(report_patterns)
            title = title_template.format(industry=industry, region=region, year=year)
            title = f"{title} | {firm}"
            
            snippet_template = rng.choice(snippet_patterns)
            snippet = snippet_template.format(industry=industry, region=region, year=year)
            
            url = f"https://www.{domain}/report/{industry.lower().replace(' ', '-')}"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': url,
                'domain': domain,
                'label': 0  # NOT a lead - it's a research firm
            })
    
    # =========================================================================
    # NEWS AND PRESS RELEASE SITES
    # =========================================================================
    news_sites = [
        ("reuters.com", "Reuters"),
        ("bloomberg.com", "Bloomberg"),
        ("businesswire.com", "Business Wire"),
        ("prnewswire.com", "PR Newswire"),
        ("cnbc.com", "CNBC"),
        ("marketwatch.com", "MarketWatch"),
        ("wsj.com", "Wall Street Journal"),
        ("ft.com", "Financial Times"),
        ("techcrunch.com", "TechCrunch"),
        ("electronicdesign.com", "Electronic Design"),
        ("electronicsweekly.com", "Electronics Weekly"),
        ("eenewseurope.com", "EE News Europe"),
        ("eetimes.com", "EE Times"),
        ("semiengineering.com", "Semiconductor Engineering"),
        ("venturebeat.com", "VentureBeat"),
        ("datacenterknowledge.com", "Data Center Knowledge"),
        ("datacenterdynamics.com", "Data Center Dynamics"),
    ]
    
    companies = [
        "Jabil", "Flex", "Celestica", "Sanmina", "Arrow Electronics",
        "Intel", "AMD", "NVIDIA", "Qualcomm", "Texas Instruments",
        "Digital Realty", "Equinix", "CyrusOne", "Bosch", "Denso"
    ]
    
    news_patterns = [
        "{company} Announces Expansion",
        "{company} Reports Q3 Earnings",
        "{company} to Acquire {industry} Firm",
        "{company} CEO Interview",
        "{company} Opens New {region} Facility",
        "{company} Stock Rises After {industry} Deal",
        "{company} Invests in {industry}",
        "Breaking: {company} Partnership Announced",
        "{company} Launches New {industry} Product",
        "{company} Wins Major {industry} Contract",
    ]
    
    news_snippets = [
        "Press release from {company} regarding recent developments.",
        "News coverage of {company}'s latest announcement.",
        "Industry news: {company} makes strategic move in {industry}.",
        "Financial news about {company} and market implications.",
        "Read the latest updates about {company} and the {industry} sector.",
    ]
    
    for domain, site in news_sites:
        for _ in range(150):  # 150 per site = 2550 total
            company = rng.choice(companies)
            industry = rng.choice(industries)
            region = rng.choice(regions)
            
            title_template = rng.choice(news_patterns)
            title = title_template.format(company=company, industry=industry, region=region)
            title = f"{title} | {site}"
            
            snippet_template = rng.choice(news_snippets)
            snippet = snippet_template.format(company=company, industry=industry)
            
            url = f"https://www.{domain}/news/{company.lower().replace(' ', '-')}"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': url,
                'domain': domain,
                'label': 0  # NOT a lead - it's a news site
            })
    
    # =========================================================================
    # DIRECTORY AND LISTING SITES
    # =========================================================================
    directory_sites = [
        ("thomasnet.com", "ThomasNet"),
        ("yellowpages.com", "Yellow Pages"),
        ("manta.com", "Manta"),
        ("kompass.com", "Kompass"),
        ("dnb.com", "Dun & Bradstreet"),
        ("zoominfo.com", "ZoomInfo"),
        ("globalsources.com", "Global Sources"),
        ("made-in-china.com", "Made-in-China"),
        ("europages.com", "Europages"),
        ("industrynet.com", "IndustryNet"),
        ("hoovers.com", "Hoovers"),
        ("supplychain247.com", "Supply Chain 247"),
    ]
    
    directory_patterns = [
        "{industry} Suppliers Directory",
        "Find {industry} Companies",
        "Top {industry} Manufacturers",
        "{industry} Company Listings",
        "{region} {industry} Suppliers",
        "{industry} Vendor Directory",
        "List of {industry} Companies",
        "{industry} Service Providers Directory",
        "Search {industry} Businesses",
        "{industry} Company Database",
    ]
    
    directory_snippets = [
        "Browse our directory of {industry} suppliers and manufacturers.",
        "Find and compare {industry} companies in our database.",
        "Business directory with {industry} company profiles.",
        "Search {industry} suppliers and request quotes.",
        "Comprehensive listing of {industry} vendors and suppliers.",
    ]
    
    for domain, site in directory_sites:
        for _ in range(150):  # 150 per site = 1800 total
            industry = rng.choice(industries)
            region = rng.choice(regions)
            
            title_template = rng.choice(directory_patterns)
            title = title_template.format(industry=industry, region=region)
            title = f"{title} - {site}"
            
            snippet_template = rng.choice(directory_snippets)
            snippet = snippet_template.format(industry=industry)
            
            url = f"https://www.{domain}/search/{industry.lower().replace(' ', '-')}"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': url,
                'domain': domain,
                'label': 0  # NOT a lead - it's a directory
            })
    
    # =========================================================================
    # JOB POSTING SITES
    # =========================================================================
    job_sites = [
        ("indeed.com", "Indeed"),
        ("linkedin.com", "LinkedIn"),
        ("glassdoor.com", "Glassdoor"),
        ("ziprecruiter.com", "ZipRecruiter"),
        ("monster.com", "Monster"),
        ("careerbuilder.com", "CareerBuilder"),
        ("wellfound.com", "Wellfound"),
        ("simplyhired.com", "SimplyHired"),
        ("dice.com", "Dice"),
        ("hired.com", "Hired"),
    ]
    
    job_patterns = [
        "Jobs at {company}",
        "{industry} Engineer - {company}",
        "Careers in {industry}",
        "{company} is Hiring",
        "{industry} Jobs in {region}",
        "{company} {industry} Positions",
        "Apply to {company}",
        "{industry} Technician Jobs",
        "{company} Career Opportunities",
        "Open Positions at {company}",
    ]
    
    job_snippets = [
        "View open positions and apply online.",
        "Career opportunities at {company} in {industry}.",
        "Join our team - multiple {industry} openings available.",
        "Search {industry} jobs and find your next career.",
        "Apply to {company} today - {industry} roles available.",
    ]
    
    for domain, site in job_sites:
        for _ in range(120):  # 120 per site = 1200 total
            company = rng.choice(companies)
            industry = rng.choice(industries)
            region = rng.choice(regions)
            
            title_template = rng.choice(job_patterns)
            title = title_template.format(company=company, industry=industry, region=region)
            title = f"{title} | {site}"
            
            snippet_template = rng.choice(job_snippets)
            snippet = snippet_template.format(company=company, industry=industry)
            
            url = f"https://www.{domain}/jobs/{company.lower().replace(' ', '-')}"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': url,
                'domain': domain,
                'label': 0  # NOT a lead - it's a job site
            })
    
    # =========================================================================
    # WIKIPEDIA AND EDUCATIONAL CONTENT
    # =========================================================================
    wiki_sites = [
        ("wikipedia.org", "Wikipedia"),
        ("en.wikipedia.org", "Wikipedia"),
        ("britannica.com", "Britannica"),
        ("investopedia.com", "Investopedia"),
    ]
    
    wiki_patterns = [
        "{industry} - Wikipedia",
        "History of {industry}",
        "{industry} Industry Overview",
        "What is {industry}?",
        "{industry} Definition",
        "Guide to {industry}",
        "{industry} Explained",
    ]
    
    for domain, site in wiki_sites:
        for _ in range(100):  # 100 per site = 400 total
            industry = rng.choice(industries)
            
            title_template = rng.choice(wiki_patterns)
            title = title_template.format(industry=industry)
            
            snippet = f"From {site}, the free encyclopedia. Article about {industry.lower()}."
            
            url = f"https://{domain}/wiki/{industry.replace(' ', '_')}"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': url,
                'domain': domain,
                'label': 0  # NOT a lead - it's educational content
            })
    
    # =========================================================================
    # GOVERNMENT AND TRADE ORGANIZATION SITES
    # =========================================================================
    gov_sites = [
        ("trade.gov", "Trade.gov"),
        ("commerce.gov", "Commerce.gov"),
        ("export.gov", "Export.gov"),
        ("sba.gov", "SBA.gov"),
        ("census.gov", "Census.gov"),
        ("bls.gov", "Bureau of Labor Statistics"),
    ]
    
    gov_patterns = [
        "{region} {industry} Trade Data",
        "{industry} Export Statistics",
        "US {industry} Industry Report",
        "{industry} Trade Information",
        "{region} {industry} Economic Data",
    ]
    
    for domain, site in gov_sites:
        for _ in range(80):  # 80 per site = 480 total
            industry = rng.choice(industries)
            region = rng.choice(regions)
            
            title_template = rng.choice(gov_patterns)
            title = title_template.format(industry=industry, region=region)
            title = f"{title} | {site}"
            
            snippet = f"Government data and statistics about the {industry.lower()} industry."
            
            url = f"https://www.{domain}/data/{industry.lower().replace(' ', '-')}"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': url,
                'domain': domain,
                'label': 0  # NOT a lead - it's government data
            })
    
    print(f"Generated {len(samples)} hard negative samples")
    return samples


def generate_hard_positives(seed: int = 42) -> list:
    """Generate hard positive samples - companies with generic/minimal info."""
    rng = random.Random(seed)
    samples = []
    
    # Real company domains we want to ensure are recognized
    real_companies = [
        ("jabil.com", "Jabil"),
        ("flex.com", "Flex"),
        ("celestica.com", "Celestica"),
        ("sanmina.com", "Sanmina"),
        ("arrow.com", "Arrow Electronics"),
        ("avnet.com", "Avnet"),
        ("digikey.com", "Digi-Key"),
        ("mouser.com", "Mouser Electronics"),
        ("tti.com", "TTI"),
        ("amd.com", "AMD"),
        ("intel.com", "Intel"),
        ("nvidia.com", "NVIDIA"),
        ("microchip.com", "Microchip"),
        ("nxp.com", "NXP"),
        ("ti.com", "Texas Instruments"),
        ("digitalrealty.com", "Digital Realty"),
        ("equinix.com", "Equinix"),
        ("cyrusone.com", "CyrusOne"),
        ("databank.com", "DataBank"),
        ("ussignal.com", "US Signal"),
        ("bosch.com", "Bosch"),
        ("denso.com", "Denso"),
        ("continental.com", "Continental"),
        ("lear.com", "Lear Corporation"),
        ("yazaki.com", "Yazaki"),
        ("thalesgroup.com", "Thales"),
        ("safran.com", "Safran"),
        ("airbus.com", "Airbus"),
        ("ibm.com", "IBM"),
        ("shi.com", "SHI"),
        ("cdw.com", "CDW"),
    ]
    
    # Generic page titles that companies have
    generic_titles = [
        "Home",
        "About",
        "About Us",
        "Contact",
        "Contact Us",
        "Products",
        "Services",
        "Solutions",
        "Capabilities",
        "Industries",
        "Locations",
        "Careers",
        "News",
        "Resources",
        "Support",
    ]
    
    generic_snippets = [
        "Welcome to our website.",
        "Learn more about us.",
        "Contact our team.",
        "Explore our solutions.",
        "View our capabilities.",
        "Find a location near you.",
        "Join our team.",
        "Latest news and updates.",
        "Enterprise solutions.",
        "Global operations.",
    ]
    
    for domain, company in real_companies:
        for _ in range(100):  # 100 per company = 3100 total
            title = rng.choice(generic_titles)
            # Sometimes add company name, sometimes not
            if rng.random() > 0.3:
                title_formats = [
                    f"{title} | {company}",
                    f"{company} | {title}",
                    f"{title} - {company}",
                    f"{company} - {title}",
                    f"{company}: {title}",
                ]
                title = rng.choice(title_formats)
            
            snippet = rng.choice(generic_snippets)
            if rng.random() > 0.5:
                snippet = f"{company}: {snippet}"
            
            url = f"https://www.{domain}/{title.lower().replace(' ', '-')}"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': url,
                'domain': domain,
                'label': 1  # IS a lead - it's a real company
            })
    
    print(f"Generated {len(samples)} hard positive samples")
    return samples


def main():
    output_dir = Path("external_data")
    output_dir.mkdir(exist_ok=True)
    
    print("Generating hard negative samples (market research, news, directories)...")
    hard_negatives = generate_hard_negatives(seed=42)
    
    print("Generating hard positive samples (companies with generic pages)...")
    hard_positives = generate_hard_positives(seed=42)
    
    all_samples = hard_negatives + hard_positives
    random.shuffle(all_samples)
    
    # Summary
    leads = sum(1 for s in all_samples if s['label'] == 1)
    non_leads = len(all_samples) - leads
    print(f"\nTotal: {len(all_samples)} samples")
    print(f"  Leads (companies): {leads}")
    print(f"  Non-leads (junk): {non_leads}")
    
    # Save
    output_path = output_dir / "hard_cases_training_data.json"
    with open(output_path, 'w') as f:
        json.dump(all_samples, f, indent=2)
    
    print(f"\nSaved to {output_path}")


if __name__ == "__main__":
    main()
