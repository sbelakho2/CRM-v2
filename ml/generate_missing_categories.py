#!/usr/bin/env python3
"""
Generate training data for categories that the model is missing:
1. Document sharing sites (slideshare, scribd, docplayer, issuu)
2. Event/conference sites (eventbrite, cvent, meetup)
3. Review/comparison sites (g2, capterra, trustpilot, gartner)
4. Academic/PDF sites
5. Video platforms (youtube, vimeo)
"""

import json
import random
from pathlib import Path

def generate_missing_categories(seed: int = 42) -> list:
    """Generate training data for missing junk categories."""
    rng = random.Random(seed)
    samples = []
    
    industries = [
        "Electronics Manufacturing", "Semiconductor", "Automotive", "Aerospace",
        "Data Center", "EMS", "PCB Assembly", "Contract Manufacturing",
        "Industrial Automation", "Medical Devices", "Telecommunications",
        "Supply Chain", "Logistics", "Cloud Computing", "Cybersecurity"
    ]
    
    companies = [
        "Jabil", "Flex", "Celestica", "Sanmina", "Arrow Electronics",
        "Intel", "AMD", "NVIDIA", "Qualcomm", "Texas Instruments",
        "Digital Realty", "Equinix", "Bosch", "Denso", "Continental"
    ]
    
    # =========================================================================
    # DOCUMENT SHARING SITES
    # =========================================================================
    doc_sites = [
        ("slideshare.net", "SlideShare"),
        ("scribd.com", "Scribd"),
        ("docplayer.net", "DocPlayer"),
        ("issuu.com", "Issuu"),
        ("calameo.com", "Calaméo"),
        ("slideshare.com", "SlideShare"),
        ("prezi.com", "Prezi"),
        ("speakerdeck.com", "Speaker Deck"),
        ("docdroid.net", "DocDroid"),
        ("yumpu.com", "Yumpu"),
    ]
    
    doc_patterns = [
        "{industry} Overview Presentation",
        "{company} Company Profile",
        "{industry} Market Analysis [PDF]",
        "{company} Annual Report",
        "{industry} Industry Trends",
        "Introduction to {industry}",
        "{company} Corporate Presentation",
        "{industry} Best Practices Guide",
        "{company} Investor Presentation",
        "{industry} Whitepaper",
        "{company} Case Study",
        "{industry} Technical Guide",
    ]
    
    doc_snippets = [
        "View and download this presentation on {site}.",
        "Read the full document on {site}. Free to view and share.",
        "Uploaded presentation about {industry}. View online or download PDF.",
        "Document shared on {site}. Embedded viewer available.",
        "Presentation slides covering {industry} topics.",
    ]
    
    for domain, site in doc_sites:
        for _ in range(200):
            industry = rng.choice(industries)
            company = rng.choice(companies)
            
            title_template = rng.choice(doc_patterns)
            title = title_template.format(industry=industry, company=company)
            title = f"{title} | {site}"
            
            snippet_template = rng.choice(doc_snippets)
            snippet = snippet_template.format(site=site, industry=industry)
            
            url = f"https://www.{domain}/doc/{industry.lower().replace(' ', '-')}"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': url,
                'domain': domain,
                'label': 0  # NOT a lead
            })
    
    # =========================================================================
    # EVENT AND CONFERENCE SITES
    # =========================================================================
    event_sites = [
        ("eventbrite.com", "Eventbrite"),
        ("eventbrite.co.uk", "Eventbrite UK"),
        ("cvent.com", "Cvent"),
        ("meetup.com", "Meetup"),
        ("10times.com", "10times"),
        ("bizzabo.com", "Bizzabo"),
        ("whova.com", "Whova"),
        ("hopin.com", "Hopin"),
        ("airmeet.com", "Airmeet"),
        ("eventmobi.com", "EventMobi"),
    ]
    
    event_types = ["Conference", "Summit", "Expo", "Trade Show", "Symposium",
                   "Workshop", "Webinar", "Seminar", "Forum", "Convention",
                   "Meetup", "Networking Event", "Exhibition", "Congress"]
    
    locations = ["Las Vegas", "Chicago", "New York", "San Francisco", "Orlando",
                 "London", "Frankfurt", "Munich", "Shanghai", "Tokyo", "Singapore",
                 "Los Angeles", "Dallas", "Boston", "Seattle", "Denver"]
    
    event_patterns = [
        "{industry} {event_type} 2026",
        "{event_type}: {industry} Innovation",
        "Annual {industry} {event_type}",
        "{industry} World {event_type}",
        "Global {industry} {event_type} {location}",
        "{event_type} - Future of {industry}",
        "{industry} Leaders {event_type}",
        "International {industry} {event_type}",
    ]
    
    event_snippets = [
        "Register for this {event_type} in {location}. Book your tickets now!",
        "Join industry leaders at the {industry} {event_type}. Early bird pricing available.",
        "Don't miss the premier {industry} event of the year. Register today!",
        "Connect with professionals at this {industry} networking event.",
        "Tickets available for the {event_type}. Virtual and in-person options.",
    ]
    
    for domain, site in event_sites:
        for _ in range(180):
            industry = rng.choice(industries)
            event_type = rng.choice(event_types)
            location = rng.choice(locations)
            
            title_template = rng.choice(event_patterns)
            title = title_template.format(industry=industry, event_type=event_type, location=location)
            title = f"{title} | {site}"
            
            snippet_template = rng.choice(event_snippets)
            snippet = snippet_template.format(industry=industry, event_type=event_type, location=location)
            
            url = f"https://www.{domain}/e/{industry.lower().replace(' ', '-')}-{event_type.lower()}"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': url,
                'domain': domain,
                'label': 0  # NOT a lead
            })
    
    # =========================================================================
    # REVIEW AND COMPARISON SITES
    # =========================================================================
    review_sites = [
        ("g2.com", "G2"),
        ("capterra.com", "Capterra"),
        ("trustpilot.com", "Trustpilot"),
        ("gartner.com", "Gartner"),
        ("trustradius.com", "TrustRadius"),
        ("getapp.com", "GetApp"),
        ("softwareadvice.com", "Software Advice"),
        ("sourceforge.net", "SourceForge"),
        ("crozdesk.com", "Crozdesk"),
        ("comparably.com", "Comparably"),
    ]
    
    review_patterns = [
        "{company} Reviews",
        "{company} vs Competitors",
        "Best {industry} Software",
        "{company} Pricing & Reviews",
        "Top {industry} Solutions Compared",
        "{company} Customer Reviews",
        "{industry} Software Comparison",
        "{company} Alternatives",
        "Compare {industry} Vendors",
        "{company} Product Reviews",
    ]
    
    review_snippets = [
        "Read verified reviews from real {company} customers.",
        "Compare {industry} software solutions. See pricing and features.",
        "See what users are saying about {company}. Ratings and reviews.",
        "Find the best {industry} tools. Compare features and pricing.",
        "User reviews and ratings for {company}. Make an informed decision.",
    ]
    
    for domain, site in review_sites:
        for _ in range(180):
            industry = rng.choice(industries)
            company = rng.choice(companies)
            
            title_template = rng.choice(review_patterns)
            title = title_template.format(industry=industry, company=company)
            title = f"{title} | {site}"
            
            snippet_template = rng.choice(review_snippets)
            snippet = snippet_template.format(industry=industry, company=company)
            
            url = f"https://www.{domain}/products/{company.lower().replace(' ', '-')}"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': url,
                'domain': domain,
                'label': 0  # NOT a lead
            })
    
    # =========================================================================
    # VIDEO PLATFORMS
    # =========================================================================
    video_sites = [
        ("youtube.com", "YouTube"),
        ("vimeo.com", "Vimeo"),
        ("dailymotion.com", "Dailymotion"),
        ("wistia.com", "Wistia"),
        ("brightcove.com", "Brightcove"),
    ]
    
    video_patterns = [
        "{company} Company Overview",
        "{industry} Explained",
        "How {industry} Works",
        "{company} Factory Tour",
        "{industry} Manufacturing Process",
        "{company} Corporate Video",
        "Inside {company}",
        "{industry} Technology Demo",
        "{company} Product Demo",
        "What is {industry}?",
    ]
    
    video_snippets = [
        "Watch this video about {company} on {site}.",
        "Learn about {industry} in this educational video.",
        "Corporate video showcasing {company} capabilities.",
        "Video tour of {industry} manufacturing facility.",
        "Educational content about {industry}. Watch now.",
    ]
    
    for domain, site in video_sites:
        for _ in range(150):
            industry = rng.choice(industries)
            company = rng.choice(companies)
            
            title_template = rng.choice(video_patterns)
            title = title_template.format(industry=industry, company=company)
            title = f"{title} - {site}"
            
            snippet_template = rng.choice(video_snippets)
            snippet = snippet_template.format(industry=industry, company=company, site=site)
            
            url = f"https://www.{domain}/watch/{company.lower().replace(' ', '-')}"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': url,
                'domain': domain,
                'label': 0  # NOT a lead
            })
    
    # =========================================================================
    # ACADEMIC AND RESEARCH SITES
    # =========================================================================
    academic_sites = [
        ("researchgate.net", "ResearchGate"),
        ("academia.edu", "Academia.edu"),
        ("arxiv.org", "arXiv"),
        ("ieee.org", "IEEE"),
        ("sciencedirect.com", "ScienceDirect"),
        ("springer.com", "Springer"),
        ("ncbi.nlm.nih.gov", "PubMed"),
    ]
    
    academic_patterns = [
        "{industry} Research Paper",
        "Analysis of {industry} Technologies",
        "{industry}: A Comprehensive Review",
        "Advances in {industry}",
        "{industry} Case Study Analysis",
        "The Future of {industry}",
        "{industry} Technical Standards",
    ]
    
    for domain, site in academic_sites:
        for _ in range(100):
            industry = rng.choice(industries)
            
            title_template = rng.choice(academic_patterns)
            title = title_template.format(industry=industry)
            title = f"{title} | {site}"
            
            snippet = f"Academic paper about {industry.lower()}. Read on {site}."
            url = f"https://www.{domain}/publication/{industry.lower().replace(' ', '-')}"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': url,
                'domain': domain,
                'label': 0  # NOT a lead
            })
    
    # =========================================================================
    # ECOMMERCE / PRODUCT LISTING SITES
    # =========================================================================
    ecommerce_sites = [
        ("amazon.com", "Amazon"),
        ("alibaba.com", "Alibaba"),
        ("ebay.com", "eBay"),
        ("aliexpress.com", "AliExpress"),
        ("indiamart.com", "IndiaMART"),
    ]
    
    ecommerce_patterns = [
        "{industry} Equipment for Sale",
        "Buy {industry} Products",
        "{industry} Supplies",
        "{industry} Tools and Equipment",
        "Shop {industry} Components",
    ]
    
    for domain, site in ecommerce_sites:
        for _ in range(100):
            industry = rng.choice(industries)
            
            title_template = rng.choice(ecommerce_patterns)
            title = title_template.format(industry=industry)
            title = f"{title} | {site}"
            
            snippet = f"Shop for {industry.lower()} products on {site}. Free shipping available."
            url = f"https://www.{domain}/s/{industry.lower().replace(' ', '-')}"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': url,
                'domain': domain,
                'label': 0  # NOT a lead
            })
    
    # =========================================================================
    # CONSULTING AND ANALYST FIRMS (similar to market research but different)
    # =========================================================================
    consulting_sites = [
        ("mckinsey.com", "McKinsey"),
        ("bcg.com", "BCG"),
        ("bain.com", "Bain"),
        ("deloitte.com", "Deloitte"),
        ("accenture.com", "Accenture"),
        ("pwc.com", "PwC"),
        ("kpmg.com", "KPMG"),
        ("ey.com", "EY"),
    ]
    
    consulting_patterns = [
        "{industry} Industry Insights",
        "The Future of {industry}",
        "{industry} Trends Report",
        "{industry} Strategy Guide",
        "Digital Transformation in {industry}",
        "{industry} Outlook 2026",
    ]
    
    for domain, site in consulting_sites:
        for _ in range(80):
            industry = rng.choice(industries)
            
            title_template = rng.choice(consulting_patterns)
            title = title_template.format(industry=industry)
            title = f"{title} | {site}"
            
            snippet = f"Insights and analysis from {site} about the {industry.lower()} industry."
            url = f"https://www.{domain}/insights/{industry.lower().replace(' ', '-')}"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': url,
                'domain': domain,
                'label': 0  # NOT a lead - consulting firm content, not the company itself
            })
    
    print(f"Generated {len(samples)} samples for missing categories")
    return samples


def main():
    output_dir = Path("external_data")
    output_dir.mkdir(exist_ok=True)
    
    samples = generate_missing_categories(seed=42)
    
    # Summary by domain
    from collections import Counter
    domain_counts = Counter(s['domain'] for s in samples)
    print("\nSamples by domain:")
    for domain, count in sorted(domain_counts.items(), key=lambda x: -x[1])[:15]:
        print(f"  {domain}: {count}")
    
    # Save
    output_path = output_dir / "missing_categories_data.json"
    with open(output_path, 'w') as f:
        json.dump(samples, f, indent=2)
    
    print(f"\nSaved to {output_path}")


if __name__ == "__main__":
    main()
