#!/usr/bin/env python3
"""
Generate additional hard negatives for categories the model struggles with:
- Document sharing sites (slideshare, scribd, docplayer, issuu)
- Event/conference sites (eventbrite, cvent, trade shows)
- Review/comparison sites (g2, capterra, trustpilot, gartner)
- Academic/research sites (researchgate, ieee, sciencedirect)
"""

import json
import random
from pathlib import Path


def generate_additional_hard_negatives(seed: int = 42) -> list:
    """Generate hard negatives for underrepresented categories."""
    rng = random.Random(seed)
    samples = []
    
    industries = [
        "Electronics Manufacturing", "Semiconductor", "Automotive", "Aerospace",
        "Data Center", "EMS", "PCB Assembly", "Contract Manufacturing",
        "Industrial Automation", "Medical Devices", "Telecommunications",
        "Cloud Computing", "Cybersecurity", "IoT", "AI", "5G"
    ]
    
    # =========================================================================
    # DOCUMENT SHARING SITES
    # =========================================================================
    doc_sites = [
        ("slideshare.net", "SlideShare"),
        ("scribd.com", "Scribd"),
        ("docplayer.net", "DocPlayer"),
        ("issuu.com", "Issuu"),
        ("calameo.com", "Calameo"),
        ("academia.edu", "Academia.edu"),
        ("speakerdeck.com", "Speaker Deck"),
        ("prezi.com", "Prezi"),
    ]
    
    doc_patterns = [
        "{industry} Industry Presentation",
        "{industry} Market Overview Slides",
        "{industry} Whitepaper PDF",
        "{industry} Analysis Report PDF",
        "{industry} Trends Presentation",
        "{industry} Industry Report Download",
        "{industry} Best Practices Guide",
        "{industry} Strategy Presentation",
        "{industry} Technical Overview",
        "{industry} Executive Summary PDF",
        "Download: {industry} Report",
        "{industry} Infographic",
        "{industry} Case Study PDF",
        "{industry} Research Findings",
    ]
    
    doc_snippets = [
        "Download this presentation about {industry}.",
        "View slides on {industry} trends and analysis.",
        "PDF document covering {industry} industry.",
        "Presentation slides from {industry} conference.",
        "Whitepaper: {industry} best practices.",
        "Free download: {industry} report.",
    ]
    
    for domain, site in doc_sites:
        for _ in range(150):
            industry = rng.choice(industries)
            title = rng.choice(doc_patterns).format(industry=industry)
            title = f"{title} | {site}"
            snippet = rng.choice(doc_snippets).format(industry=industry)
            url = f"https://www.{domain}/document/{industry.lower().replace(' ', '-')}"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': url,
                'domain': domain,
                'label': 0
            })
    
    # =========================================================================
    # EVENT AND CONFERENCE SITES
    # =========================================================================
    event_sites = [
        ("eventbrite.com", "Eventbrite"),
        ("cvent.com", "Cvent"),
        ("meetup.com", "Meetup"),
        ("hopin.com", "Hopin"),
        ("whova.com", "Whova"),
        ("bizzabo.com", "Bizzabo"),
        ("10times.com", "10Times"),
        ("eventful.com", "Eventful"),
    ]
    
    event_types = ["Trade Show", "Conference", "Summit", "Expo", "Forum", 
                   "Workshop", "Webinar", "Symposium", "Convention", "Fair"]
    
    locations = ["Las Vegas", "Chicago", "New York", "San Francisco", "Orlando",
                 "London", "Munich", "Shanghai", "Tokyo", "Singapore", "Dallas", "Boston"]
    
    years = ["2025", "2026"]
    
    event_patterns = [
        "{industry} {event_type} {year}",
        "{location} {industry} {event_type}",
        "{industry} {event_type} - {location}",
        "Register: {industry} {event_type} {year}",
        "{event_type}: {industry} Innovation",
        "Annual {industry} {event_type}",
        "Global {industry} {event_type} {year}",
        "{industry} World {event_type}",
        "International {industry} {event_type}",
    ]
    
    event_snippets = [
        "Register now for the leading {industry} {event_type}.",
        "Join industry professionals at the {industry} {event_type}.",
        "Don't miss the biggest {industry} event of the year.",
        "Networking and exhibitions at {industry} {event_type}.",
        "Early bird tickets available for {industry} {event_type}.",
        "Book your spot at the premier {industry} gathering.",
    ]
    
    # Specific trade shows
    trade_shows = [
        ("semiconwest.org", "SEMICON West"),
        ("electronica.de", "Electronica"),
        ("ces.tech", "CES"),
        ("productronica.com", "Productronica"),
        ("embedded-world.de", "Embedded World"),
        ("ila-berlin.de", "ILA Berlin"),
        ("automechanika.messefrankfurt.com", "Automechanika"),
        ("hannovermesse.de", "Hannover Messe"),
        ("sps-exhibition.com", "SPS Smart Production"),
        ("smtconnect.mesago.com", "SMT Connect"),
    ]
    
    for domain, site in event_sites + trade_shows:
        for _ in range(100):
            industry = rng.choice(industries)
            event_type = rng.choice(event_types)
            location = rng.choice(locations)
            year = rng.choice(years)
            
            title = rng.choice(event_patterns).format(
                industry=industry, event_type=event_type, 
                location=location, year=year
            )
            if site not in title:
                title = f"{title} | {site}"
            
            snippet = rng.choice(event_snippets).format(
                industry=industry, event_type=event_type
            )
            url = f"https://www.{domain}/events/{industry.lower().replace(' ', '-')}"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': url,
                'domain': domain,
                'label': 0
            })
    
    # =========================================================================
    # REVIEW AND COMPARISON SITES
    # =========================================================================
    review_sites = [
        ("g2.com", "G2"),
        ("capterra.com", "Capterra"),
        ("trustpilot.com", "Trustpilot"),
        ("gartner.com", "Gartner"),
        ("forrester.com", "Forrester"),
        ("softwareadvice.com", "Software Advice"),
        ("getapp.com", "GetApp"),
        ("trustradius.com", "TrustRadius"),
        ("peerspot.com", "PeerSpot"),
        ("sourceforge.net", "SourceForge"),
    ]
    
    review_patterns = [
        "Best {industry} Software Reviews",
        "Compare {industry} Solutions",
        "Top {industry} Providers Reviewed",
        "{industry} Vendor Comparison",
        "{industry} Tools Ratings",
        "User Reviews: {industry} Platforms",
        "{industry} Software Comparison",
        "Best {industry} Companies 2025",
        "{industry} Provider Rankings",
        "Top Rated {industry} Solutions",
        "{industry} Buyer's Guide",
        "Compare Top {industry} Vendors",
    ]
    
    review_snippets = [
        "Read verified user reviews of {industry} solutions.",
        "Compare features and pricing of {industry} providers.",
        "See ratings and reviews from real {industry} users.",
        "Find the best {industry} software for your business.",
        "Browse {industry} vendor comparisons and reviews.",
        "User ratings help you choose {industry} solutions.",
    ]
    
    for domain, site in review_sites:
        for _ in range(120):
            industry = rng.choice(industries)
            title = rng.choice(review_patterns).format(industry=industry)
            title = f"{title} | {site}"
            snippet = rng.choice(review_snippets).format(industry=industry)
            url = f"https://www.{domain}/categories/{industry.lower().replace(' ', '-')}"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': url,
                'domain': domain,
                'label': 0
            })
    
    # =========================================================================
    # ACADEMIC AND RESEARCH SITES
    # =========================================================================
    academic_sites = [
        ("researchgate.net", "ResearchGate"),
        ("sciencedirect.com", "ScienceDirect"),
        ("ieee.org", "IEEE"),
        ("springer.com", "Springer"),
        ("elsevier.com", "Elsevier"),
        ("acm.org", "ACM"),
        ("nature.com", "Nature"),
        ("wiley.com", "Wiley"),
        ("tandfonline.com", "Taylor & Francis"),
        ("mdpi.com", "MDPI"),
        ("arxiv.org", "arXiv"),
    ]
    
    academic_patterns = [
        "{industry}: A Comprehensive Review",
        "Research Paper: {industry} Technology",
        "Study on {industry} Processes",
        "IEEE: {industry} Systems",
        "Analysis of {industry} Methods",
        "Academic Review: {industry}",
        "{industry} Research Publication",
        "Journal Article: {industry}",
        "Technical Paper: {industry}",
        "Proceedings: {industry} Conference",
        "Thesis: {industry} Innovation",
        "Survey of {industry} Techniques",
    ]
    
    academic_snippets = [
        "Academic research paper on {industry}.",
        "Peer-reviewed study covering {industry}.",
        "Scientific publication about {industry} technology.",
        "Research findings in {industry} field.",
        "Conference proceedings on {industry}.",
        "Journal article analyzing {industry} trends.",
    ]
    
    for domain, site in academic_sites:
        for _ in range(100):
            industry = rng.choice(industries)
            title = rng.choice(academic_patterns).format(industry=industry)
            title = f"{title} | {site}"
            snippet = rng.choice(academic_snippets).format(industry=industry)
            url = f"https://www.{domain}/article/{industry.lower().replace(' ', '-')}"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': url,
                'domain': domain,
                'label': 0
            })
    
    # =========================================================================
    # GENERIC REGIONAL DOMAINS (.ma, .de, etc.) AS NON-LEADS
    # =========================================================================
    # The model incorrectly classified sumitomo-electric.ma as junk, likely due to
    # the unusual domain. Let's make sure we have non-lead examples from various TLDs
    # so the model doesn't just learn to flag unusual domains as junk.
    
    generic_tlds = [".ma", ".de", ".fr", ".uk", ".nl", ".pl", ".cz", ".mx", ".br", ".in", ".cn", ".jp"]
    
    non_company_regional = [
        ("{industry} News {region}", "{industry} industry news from {region}.", "news-site"),
        ("{industry} Forum {region}", "Discussion forum for {industry} professionals.", "forum-site"),
        ("{industry} Magazine {region}", "Trade publication covering {industry}.", "magazine-site"),
        ("{industry} Association {region}", "Industry association for {industry}.", "association-site"),
    ]
    
    regions = ["Morocco", "Germany", "France", "UK", "Netherlands", "Poland", "Czechia", "Mexico", "Brazil", "India"]
    
    for tld in generic_tlds:
        for _ in range(30):
            industry = rng.choice(industries)
            region = rng.choice(regions)
            pattern = rng.choice(non_company_regional)
            
            title = pattern[0].format(industry=industry, region=region)
            snippet = pattern[1].format(industry=industry, region=region)
            domain = f"{pattern[2]}{tld}"
            url = f"https://www.{domain}/"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': url,
                'domain': domain,
                'label': 0
            })
    
    print(f"Generated {len(samples)} additional hard negative samples")
    return samples


def main():
    output_dir = Path("external_data")
    output_dir.mkdir(exist_ok=True)
    
    samples = generate_additional_hard_negatives(seed=42)
    
    # Summary by domain
    domain_counts = {}
    for s in samples:
        d = s['domain']
        domain_counts[d] = domain_counts.get(d, 0) + 1
    
    print(f"\nTotal samples: {len(samples)}")
    print(f"Unique domains: {len(domain_counts)}")
    
    # Save
    output_path = output_dir / "additional_hard_negatives.json"
    with open(output_path, 'w') as f:
        json.dump(samples, f, indent=2)
    
    print(f"\nSaved to {output_path}")


if __name__ == "__main__":
    main()
