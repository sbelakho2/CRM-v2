#!/usr/bin/env python3
"""
Generate content-focused hard negatives.
Instead of focusing on domains, this focuses on CONTENT patterns that indicate
non-company pages, regardless of domain.
"""

import json
import random
from pathlib import Path


def generate_content_focused_negatives(seed: int = 42) -> list:
    """Generate samples that teach the model to recognize non-lead CONTENT patterns."""
    rng = random.Random(seed)
    samples = []
    
    # The domains here should NOT overlap with the webcrawler test domains
    # to ensure generalization
    generic_domains = [
        f"example{i}.com" for i in range(1, 100)
    ] + [
        f"generic-site-{i}.net" for i in range(1, 100)
    ] + [
        f"info-portal-{i}.org" for i in range(1, 100)
    ]
    
    industries = [
        "Electronics Manufacturing", "Semiconductor", "Automotive", "Aerospace",
        "Data Center", "EMS", "PCB Assembly", "Contract Manufacturing",
        "Industrial Automation", "Medical Devices", "Telecommunications",
        "Energy Storage", "Electric Vehicle", "5G Infrastructure"
    ]
    
    regions = ["Morocco", "US", "EU", "UK", "Asia Pacific", "Global", "North America", 
               "Europe", "Middle East", "India", "China", "Japan", "Texas", "California"]
    
    years = ["2024", "2025", "2026", "2030"]
    
    # =========================================================================
    # MARKET RESEARCH CONTENT PATTERNS (not domain-specific)
    # =========================================================================
    market_research_titles = [
        "{region} {industry} Market Report {year}",
        "{industry} Market Size & Forecast",
        "Global {industry} Market Analysis",
        "{industry} Industry Outlook {year}",
        "{region} {industry} Market Trends",
        "{industry} Market Growth Analysis",
        "{industry} Market Share Report",
        "{industry} Industry Research Report",
        "{industry} Market Forecast {year}",
        "{industry} Market Overview",
        "{industry} Sector Analysis",
        "{industry} Market Insights {year}",
        "{industry} Industry Forecast to {year}",
        "{industry} Market Size by Region",
        "{industry} Market Competitive Analysis"
    ]
    
    market_research_snippets = [
        "Market research report analyzing {industry} sector trends and forecasts.",
        "Industry report covering {region} {industry} market size and growth.",
        "Market analysis of the global {industry} industry.",
        "Research report on {industry} market trends to {year}.",
        "Comprehensive market study of the {industry} sector.",
        "Industry analysis covering key players and market share.",
        "Market forecast for the {industry} industry in {region}.",
        "Download the {industry} market research report."
    ]
    
    for _ in range(8000):
        industry = rng.choice(industries)
        region = rng.choice(regions)
        year = rng.choice(years)
        domain = rng.choice(generic_domains)
        
        title = rng.choice(market_research_titles).format(
            industry=industry, region=region, year=year
        )
        snippet = rng.choice(market_research_snippets).format(
            industry=industry, region=region, year=year
        )
        url = f"https://www.{domain}/report/{industry.lower().replace(' ', '-')}"
        
        samples.append({
            'title': title,
            'snippet': snippet,
            'url': url,
            'domain': domain,
            'label': 0
        })
    
    # =========================================================================
    # NEWS/PRESS RELEASE CONTENT PATTERNS
    # =========================================================================
    news_titles = [
        "{company} Announces Expansion",
        "{company} Reports Q{quarter} Earnings",
        "{company} to Acquire Startup",
        "Breaking: {company} Partnership Announced",
        "{company} Stock Rises on {industry} Demand",
        "{company} Opens New Facility",
        "{company} CEO Interview",
        "{industry} Shortage Update",
        "{industry} Industry News Today",
        "{company} Wins Major Contract"
    ]
    
    companies = ["Jabil", "Intel", "AMD", "NVIDIA", "Flex", "Celestica", "Texas Instruments",
                 "Samsung", "Apple", "Google", "Microsoft", "Dell", "HP", "Cisco"]
    
    news_snippets = [
        "Press release about {company}'s recent announcement.",
        "News coverage of {company}'s latest developments.",
        "Financial news regarding {company} stock performance.",
        "Industry news about {company} and the {industry} sector.",
        "Breaking news: {company} makes strategic move."
    ]
    
    for _ in range(5000):
        company = rng.choice(companies)
        industry = rng.choice(industries)
        quarter = rng.randint(1, 4)
        domain = rng.choice(generic_domains)
        
        title = rng.choice(news_titles).format(
            company=company, industry=industry, quarter=quarter
        )
        snippet = rng.choice(news_snippets).format(
            company=company, industry=industry
        )
        url = f"https://www.{domain}/news/{company.lower()}"
        
        samples.append({
            'title': title,
            'snippet': snippet,
            'url': url,
            'domain': domain,
            'label': 0
        })
    
    # =========================================================================
    # DIRECTORY/LISTING CONTENT PATTERNS
    # =========================================================================
    directory_titles = [
        "Top {industry} Companies",
        "{industry} Suppliers Directory",
        "Find {industry} Manufacturers",
        "{industry} Company Listings",
        "Best {industry} Providers {year}",
        "{industry} Vendor Directory",
        "{region} {industry} Suppliers List",
        "{industry} Companies Database",
        "Search {industry} Manufacturers"
    ]
    
    directory_snippets = [
        "Directory of {industry} suppliers and manufacturers.",
        "Find and compare {industry} companies.",
        "List of {industry} service providers.",
        "Database of {industry} manufacturers in {region}.",
        "Browse {industry} company listings."
    ]
    
    for _ in range(4000):
        industry = rng.choice(industries)
        region = rng.choice(regions)
        year = rng.choice(years)
        domain = rng.choice(generic_domains)
        
        title = rng.choice(directory_titles).format(
            industry=industry, region=region, year=year
        )
        snippet = rng.choice(directory_snippets).format(
            industry=industry, region=region
        )
        url = f"https://www.{domain}/directory/{industry.lower().replace(' ', '-')}"
        
        samples.append({
            'title': title,
            'snippet': snippet,
            'url': url,
            'domain': domain,
            'label': 0
        })
    
    # =========================================================================
    # JOB LISTING CONTENT PATTERNS
    # =========================================================================
    job_titles = [
        "{industry} Jobs",
        "{industry} Career Opportunities",
        "Hiring: {industry} Engineer",
        "{industry} Technician Jobs",
        "Jobs at {company}",
        "{industry} Employment Opportunities",
        "{region} {industry} Careers"
    ]
    
    job_snippets = [
        "Job listings for {industry} positions.",
        "Career opportunities in the {industry} sector.",
        "Find {industry} jobs near you.",
        "Employment opportunities in {industry}.",
        "Browse {industry} career openings."
    ]
    
    for _ in range(3000):
        industry = rng.choice(industries)
        region = rng.choice(regions)
        company = rng.choice(companies)
        domain = rng.choice(generic_domains)
        
        title = rng.choice(job_titles).format(
            industry=industry, region=region, company=company
        )
        snippet = rng.choice(job_snippets).format(
            industry=industry
        )
        url = f"https://www.{domain}/jobs/{industry.lower().replace(' ', '-')}"
        
        samples.append({
            'title': title,
            'snippet': snippet,
            'url': url,
            'domain': domain,
            'label': 0
        })
    
    return samples


def main():
    print("Generating content-focused negative samples...")
    
    samples = generate_content_focused_negatives()
    
    output_path = Path(__file__).parent / "external_data" / "content_focused_negatives.json"
    output_path.parent.mkdir(parents=True, exist_ok=True)
    
    with open(output_path, 'w') as f:
        json.dump(samples, f, indent=2)
    
    print(f"Generated {len(samples)} content-focused negative samples")


if __name__ == "__main__":
    main()
