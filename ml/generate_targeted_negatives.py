#!/usr/bin/env python3
"""
Generate targeted negative samples for domains that are currently being misclassified.
This approach focuses on DOMAIN-based patterns rather than content patterns.
The key insight is that the model needs to learn that certain DOMAINS are never leads,
regardless of how business-like their content appears.
"""

import json
import random
from pathlib import Path


def generate_domain_based_negatives(seed: int = 42) -> list:
    """Generate samples that teach the model to recognize non-lead DOMAINS."""
    rng = random.Random(seed)
    samples = []
    
    # Business-related terms that appear in titles/snippets
    industry_terms = [
        "Electronics Manufacturing", "Semiconductor", "EMS Provider", "PCB Assembly",
        "Contract Manufacturing", "OEM Solutions", "Data Center", "Cloud Infrastructure",
        "Supply Chain", "Automotive Electronics", "Aerospace Components", "Medical Devices",
        "Industrial Automation", "IoT Solutions", "AI Hardware", "Power Electronics"
    ]
    
    company_like_terms = [
        "Leading Provider", "Global Solutions", "Industry Leader", "Manufacturing Excellence",
        "Premium Quality", "Custom Solutions", "End-to-End Services", "Full-Service",
        "Tier 1 Supplier", "Certified Partner", "ISO Certified", "World-Class"
    ]
    
    # =========================================================================
    # DOCUMENT SHARING SITES - 0% accuracy currently
    # =========================================================================
    document_sites = [
        "slideshare.net", "scribd.com", "issuu.com", "docplayer.net", "calameo.com",
        "yumpu.com", "edocr.com", "slideserve.com", "authorstream.com", "powershow.com",
        "speakerdeck.com", "prezi.com", "slideshare.com", "docdroid.net", "pdfhost.io"
    ]
    
    # Titles that look like business content but on document sites
    doc_title_patterns = [
        "{industry} Overview and Capabilities",
        "{company_term} - {industry}",
        "{industry} Services Presentation",
        "{industry} Company Profile",
        "{company_term} in {industry}",
        "{industry} Solutions Overview",
        "{industry} Product Catalog",
        "{industry} Capabilities Brochure",
        "{industry} Corporate Presentation",
        "{industry} Service Portfolio",
        "About Our {industry} Solutions",
        "{industry} Manufacturing Services",
        "{industry} Case Study",
        "{industry} Whitepaper",
        "{industry} Technical Brief"
    ]
    
    doc_snippets = [
        "Presentation about {industry} capabilities and services.",
        "Company overview presentation for {industry}.",
        "Slideshare presentation covering {industry} solutions.",
        "PDF document describing {industry} services.",
        "Corporate brochure for {industry} manufacturing.",
        "Read the full presentation on {industry}.",
        "Download this presentation about {industry}.",
        "View presentation: {industry} overview.",
        "Uploaded document covering {industry} capabilities."
    ]
    
    for domain in document_sites:
        for _ in range(350):  # 350 per site = 5,250 total
            industry = rng.choice(industry_terms)
            company_term = rng.choice(company_like_terms)
            
            title_pattern = rng.choice(doc_title_patterns)
            title = title_pattern.format(industry=industry, company_term=company_term)
            
            snippet_pattern = rng.choice(doc_snippets)
            snippet = snippet_pattern.format(industry=industry)
            
            # URL patterns for document sites
            url_parts = industry.lower().replace(" ", "-")
            url = f"https://www.{domain}/{url_parts}-presentation"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': url,
                'domain': domain,
                'label': 0  # NOT a lead - document sharing site
            })
    
    # =========================================================================
    # EVENT/CONFERENCE SITES - 20% accuracy currently
    # =========================================================================
    event_sites = [
        "eventbrite.com", "cvent.com", "meetup.com", "10times.com", "eventful.com",
        "eventzilla.net", "bizzabo.com", "whova.com", "hopin.com", "airmeet.com",
        "run.events", "splash.events", "swoogo.com", "electronica.de", "ces.tech",
        "semicon.org", "productronica.com", "ifa-berlin.com", "embedded-world.de",
        "semiconwest.org", "semiconeuropa.org", "semiconjapan.org"
    ]
    
    event_title_patterns = [
        "{industry} Summit 2025",
        "{industry} Conference & Exhibition",
        "{industry} World Expo",
        "{industry} Trade Show",
        "{industry} Forum 2025",
        "{industry} Innovation Summit",
        "{industry} Technology Conference",
        "{industry} Industry Event",
        "{company_term} {industry} Meetup",
        "Register: {industry} Conference",
        "{industry} Annual Summit",
        "{industry} Networking Event",
        "{industry} Symposium 2025",
        "{industry} Exhibition & Conference"
    ]
    
    event_snippets = [
        "Register for the {industry} conference and trade show.",
        "Join industry leaders at the {industry} summit.",
        "Book tickets for the {industry} exhibition.",
        "Networking event for {industry} professionals.",
        "Annual conference covering {industry} trends.",
        "Trade show featuring {industry} exhibitors.",
        "Event registration for {industry} summit.",
        "Connect with {industry} experts at this event."
    ]
    
    for domain in event_sites:
        for _ in range(300):  # 300 per site = 5,700 total
            industry = rng.choice(industry_terms)
            company_term = rng.choice(company_like_terms)
            
            title_pattern = rng.choice(event_title_patterns)
            title = title_pattern.format(industry=industry, company_term=company_term)
            
            snippet_pattern = rng.choice(event_snippets)
            snippet = snippet_pattern.format(industry=industry)
            
            url_parts = industry.lower().replace(" ", "-")
            url = f"https://www.{domain}/e/{url_parts}-summit"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': url,
                'domain': domain,
                'label': 0  # NOT a lead - event site
            })
    
    # =========================================================================
    # REVIEW/COMPARISON SITES - Currently misclassified
    # =========================================================================
    review_sites = [
        "g2.com", "capterra.com", "trustpilot.com", "gartner.com", "softwareadvice.com",
        "getapp.com", "trustradius.com", "peerspot.com", "selecthub.com", "reviewsdir.com",
        "comparecamp.com", "crozdesk.com", "goodfirms.co", "financesonline.com",
        "saasgenius.com", "softwaresuggest.com"
    ]
    
    review_title_patterns = [
        "{industry} Software Reviews",
        "Best {industry} Solutions Reviewed",
        "Top {industry} Providers Compared",
        "Compare {industry} Vendors",
        "{industry} Company Reviews",
        "{industry} Platform Ratings",
        "Rate {industry} Services",
        "{company_term} {industry} Reviews",
        "{industry} Vendor Comparison",
        "{industry} Solutions Analysis",
        "Find the Best {industry} Software",
        "{industry} Provider Rankings"
    ]
    
    review_snippets = [
        "Read reviews and compare {industry} solutions.",
        "User reviews for {industry} software and services.",
        "Compare top {industry} vendors and providers.",
        "Ratings and reviews for {industry} platforms.",
        "Find and compare {industry} solutions.",
        "Independent reviews of {industry} services.",
        "Customer reviews for {industry} providers."
    ]
    
    for domain in review_sites:
        for _ in range(350):  # 350 per site = 5,600 total
            industry = rng.choice(industry_terms)
            company_term = rng.choice(company_like_terms)
            
            title_pattern = rng.choice(review_title_patterns)
            title = title_pattern.format(industry=industry, company_term=company_term)
            
            snippet_pattern = rng.choice(review_snippets)
            snippet = snippet_pattern.format(industry=industry)
            
            url_parts = industry.lower().replace(" ", "-")
            url = f"https://www.{domain}/categories/{url_parts}"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': url,
                'domain': domain,
                'label': 0  # NOT a lead - review site
            })
    
    # =========================================================================
    # NEWS/FINANCIAL SITES - Not covered well by hard_cases
    # =========================================================================
    news_financial_sites = [
        "wsj.com", "ft.com", "cnbc.com", "marketwatch.com", "barrons.com",
        "fortune.com", "forbes.com", "inc.com", "fastcompany.com", "wired.com",
        "theverge.com", "arstechnica.com", "zdnet.com", "cio.com", "computerworld.com",
        "infoworld.com", "networkworld.com", "csoonline.com", "venturebeat.com",
        "cnn.com", "bbc.com", "nytimes.com", "washingtonpost.com", "thehill.com"
    ]
    
    news_title_patterns = [
        "{company_term} {industry} Company Announces",
        "{industry} Industry Growth Report",
        "{industry} Sector Analysis",
        "Breaking: {industry} News",
        "{industry} Market Update",
        "{company_term} {industry} Stock Rises",
        "CEO Interview: {industry}",
        "{industry} Earnings Report",
        "{industry} Industry Outlook"
    ]
    
    news_snippets = [
        "Financial news coverage of {industry} sector developments.",
        "Breaking news about {industry} industry trends.",
        "Market analysis of {industry} companies.",
        "Industry update on {industry} sector.",
        "News coverage of {industry} technology."
    ]
    
    for domain in news_financial_sites:
        for _ in range(300):
            industry = rng.choice(industry_terms)
            company_term = rng.choice(company_like_terms)
            
            title_pattern = rng.choice(news_title_patterns)
            title = title_pattern.format(industry=industry, company_term=company_term)
            
            snippet_pattern = rng.choice(news_snippets)
            snippet = snippet_pattern.format(industry=industry)
            
            url = f"https://www.{domain}/article/{industry.lower().replace(' ', '-')}"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': url,
                'domain': domain,
                'label': 0
            })
    
    # =========================================================================
    # BUSINESS DATA/DIRECTORY SITES - dnb.com, hoovers.com failing
    # =========================================================================
    business_data_sites = [
        "dnb.com", "hoovers.com", "crunchbase.com", "pitchbook.com", "owler.com",
        "craftsales.com", "leadiq.com", "apollo.io", "clearbit.com", "lusha.com",
        "hunter.io", "contactout.com", "rocketreach.co", "datanyze.com",
        "europages.com", "zoominfo.com", "manta.com"
    ]
    
    business_data_titles = [
        "{industry} Companies List",
        "Find {industry} Suppliers",
        "{industry} Company Database",
        "{industry} Business Profiles",
        "Search {industry} Companies",
        "{industry} Company Directory",
        "{industry} Vendor Information",
        "{industry} Contact Database"
    ]
    
    business_data_snippets = [
        "Database of {industry} companies and contacts.",
        "Find and connect with {industry} businesses.",
        "Company information for {industry} sector.",
        "Business data and profiles for {industry}.",
        "Search our {industry} company database."
    ]
    
    for domain in business_data_sites:
        for _ in range(300):
            industry = rng.choice(industry_terms)
            
            title_pattern = rng.choice(business_data_titles)
            title = title_pattern.format(industry=industry)
            
            snippet_pattern = rng.choice(business_data_snippets)
            snippet = snippet_pattern.format(industry=industry)
            
            url = f"https://www.{domain}/companies/{industry.lower().replace(' ', '-')}"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': url,
                'domain': domain,
                'label': 0
            })
    
    # =========================================================================
    # ACADEMIC/RESEARCH SITES - Currently at 80%
    # =========================================================================
    academic_sites = [
        "researchgate.net", "sciencedirect.com", "academia.edu", "scholar.google.com",
        "ieee.org", "springer.com", "nature.com", "arxiv.org", "mdpi.com",
        "frontiersin.org", "wiley.com", "tandfonline.com", "sage.com",
        "investopedia.com", "britannica.com", "wikipedia.org", "en.wikipedia.org",
        "howstuffworks.com", "thoughtco.com", "study.com", "coursera.org",
        "khanacademy.org", "edx.org", "udemy.com"
    ]
    
    academic_title_patterns = [
        "Research Paper: {industry} Analysis",
        "{industry} Study and Findings",
        "Academic Research on {industry}",
        "{industry} Technical Paper",
        "Journal Article: {industry}",
        "Scientific Study of {industry}",
        "{industry} Research Publication",
        "Thesis: {industry} Innovation",
        "{industry} Academic Review"
    ]
    
    academic_snippets = [
        "Research paper analyzing {industry} trends and technology.",
        "Academic study on {industry} methods and approaches.",
        "Published research about {industry} innovations.",
        "Scientific paper covering {industry} developments.",
        "Journal article examining {industry} practices."
    ]
    
    for domain in academic_sites:
        for _ in range(200):  # 200 per site = 2,600 total
            industry = rng.choice(industry_terms)
            
            title_pattern = rng.choice(academic_title_patterns)
            title = title_pattern.format(industry=industry)
            
            snippet_pattern = rng.choice(academic_snippets)
            snippet = snippet_pattern.format(industry=industry)
            
            url_parts = industry.lower().replace(" ", "-")
            url = f"https://www.{domain}/publication/{url_parts}"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': url,
                'domain': domain,
                'label': 0  # NOT a lead - academic site
            })
    
    # =========================================================================
    # SOCIAL MEDIA SITES - 20% accuracy currently
    # =========================================================================
    social_media_sites = [
        "reddit.com", "twitter.com", "facebook.com", "quora.com", "linkedin.com",
        "x.com", "threads.net", "mastodon.social", "instagram.com", "tiktok.com",
        "discord.com", "slack.com", "telegram.org", "whatsapp.com", "pinterest.com"
    ]
    
    social_title_patterns = [
        "r/{industry} Discussion",
        "{industry} Community Forum",
        "{industry} Twitter Feed",
        "Follow {industry} Updates",
        "{industry} Facebook Group",
        "Join the {industry} Community",
        "{industry} LinkedIn Group",
        "{industry} Discussion Thread",
        "{company_term} {industry} Posts"
    ]
    
    social_snippets = [
        "Join the discussion about {industry} on social media.",
        "Follow updates about {industry} industry.",
        "Community posts about {industry}.",
        "Social media discussion about {industry}.",
        "Connect with {industry} professionals."
    ]
    
    for domain in social_media_sites:
        for _ in range(350):
            industry = rng.choice(industry_terms)
            company_term = rng.choice(company_like_terms)
            
            title_pattern = rng.choice(social_title_patterns)
            title = title_pattern.format(industry=industry, company_term=company_term)
            
            snippet_pattern = rng.choice(social_snippets)
            snippet = snippet_pattern.format(industry=industry)
            
            url = f"https://www.{domain}/r/{industry.lower().replace(' ', '')}"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': url,
                'domain': domain,
                'label': 0
            })
    
    # =========================================================================
    # VIDEO PLATFORMS - May be misclassified
    # =========================================================================
    video_sites = [
        "youtube.com", "vimeo.com", "dailymotion.com", "wistia.com", "brightcove.com",
        "vidyard.com", "loom.com", "twitch.tv"
    ]
    
    video_title_patterns = [
        "{industry} Manufacturing Process Video",
        "Factory Tour: {industry}",
        "{industry} Solutions Demo",
        "{company_term} {industry} Overview",
        "How {industry} Works",
        "{industry} Production Video",
        "{industry} Company Introduction Video",
        "Inside {industry} Manufacturing"
    ]
    
    video_snippets = [
        "Watch this video about {industry} manufacturing.",
        "Video tour of {industry} facilities.",
        "Demo video showing {industry} capabilities.",
        "Educational video about {industry}.",
        "Factory tour video featuring {industry}."
    ]
    
    for domain in video_sites:
        for _ in range(250):  # 250 per site = 2,000 total
            industry = rng.choice(industry_terms)
            company_term = rng.choice(company_like_terms)
            
            title_pattern = rng.choice(video_title_patterns)
            title = title_pattern.format(industry=industry, company_term=company_term)
            
            snippet_pattern = rng.choice(video_snippets)
            snippet = snippet_pattern.format(industry=industry)
            
            url = f"https://www.{domain}/watch?v={industry.lower().replace(' ', '')}"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': url,
                'domain': domain,
                'label': 0  # NOT a lead - video platform
            })
    
    return samples


def main():
    print("Generating targeted domain-based negative samples...")
    
    samples = generate_domain_based_negatives()
    
    output_path = Path(__file__).parent / "external_data" / "targeted_domain_negatives.json"
    output_path.parent.mkdir(parents=True, exist_ok=True)
    
    with open(output_path, 'w') as f:
        json.dump(samples, f, indent=2)
    
    print(f"Generated {len(samples)} targeted negative samples")
    
    # Count by domain category
    domains = {}
    for s in samples:
        domain = s['domain']
        domains[domain] = domains.get(domain, 0) + 1
    
    print("\nSamples by domain:")
    for domain, count in sorted(domains.items(), key=lambda x: -x[1])[:20]:
        print(f"  {domain}: {count}")


if __name__ == "__main__":
    main()
