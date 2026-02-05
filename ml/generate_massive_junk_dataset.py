#!/usr/bin/env python3
"""
Generate MASSIVE junk dataset with thousands of realistic examples.
Focus on the hardest cases: market research, news, directories, jobs, etc.
"""

import json
import random
from pathlib import Path
from itertools import product

def generate_massive_junk_dataset(seed: int = 42) -> list:
    rng = random.Random(seed)
    samples = []
    
    # ==========================================================================
    # COMPREHENSIVE INDUSTRY TERMS
    # ==========================================================================
    industries = [
        "Electronics Manufacturing", "EMS", "PCB Assembly", "Semiconductor",
        "Automotive", "Aerospace", "Defense", "Data Center", "Cloud Computing",
        "Industrial Automation", "Medical Devices", "Telecommunications",
        "Energy Storage", "Electric Vehicle", "5G Infrastructure", "IoT",
        "Contract Manufacturing", "Surface Mount Technology", "Wire Harness",
        "Printed Circuit Board", "PCBA", "Box Build", "System Integration",
        "Supply Chain", "Logistics", "Distribution", "Component Distribution",
        "IT Services", "Managed Services", "Cybersecurity", "Network Infrastructure",
        "Artificial Intelligence", "Machine Learning", "Robotics", "Automation",
        "Renewable Energy", "Solar", "Wind Energy", "Battery Technology",
        "Pharmaceutical", "Biotechnology", "Healthcare IT", "Life Sciences",
        "Food Processing", "Packaging", "Plastics", "Chemicals", "Textiles",
        "Heavy Machinery", "Construction Equipment", "Mining Equipment",
        "Oil and Gas", "Petrochemical", "Refining", "Pipeline",
    ]
    
    regions = [
        "Morocco", "US", "USA", "United States", "Europe", "EU", "UK",
        "Germany", "France", "Spain", "Italy", "Poland", "Czech Republic",
        "Romania", "Hungary", "Slovakia", "Mexico", "Canada", "Brazil",
        "China", "Japan", "Korea", "Taiwan", "India", "Vietnam", "Thailand",
        "Malaysia", "Singapore", "Philippines", "Indonesia", "Australia",
        "Global", "Worldwide", "International", "North America", "EMEA",
        "Asia Pacific", "APAC", "Latin America", "LATAM", "Middle East",
        "Africa", "Eastern Europe", "Western Europe", "Nordic", "Scandinavia",
    ]
    
    years = ["2024", "2025", "2026", "2027", "2028", "2029", "2030", "2031", "2032"]
    
    # Real company names that appear in news/reports (NOT the company sites themselves)
    company_names = [
        "Jabil", "Flex", "Foxconn", "Pegatron", "Wistron", "Quanta", "Compal",
        "Celestica", "Sanmina", "Benchmark", "Plexus", "Fabrinet", "Venture",
        "Arrow Electronics", "Avnet", "Digi-Key", "Mouser", "TTI", "Future Electronics",
        "Intel", "AMD", "NVIDIA", "Qualcomm", "Broadcom", "Texas Instruments",
        "NXP", "Infineon", "STMicroelectronics", "Microchip", "ON Semiconductor",
        "Micron", "Samsung", "SK Hynix", "Western Digital", "Seagate",
        "Apple", "Microsoft", "Google", "Amazon", "Meta", "Tesla", "SpaceX",
        "Bosch", "Continental", "Denso", "Aptiv", "Magna", "Lear", "Yazaki",
        "Thales", "Safran", "Airbus", "Boeing", "Lockheed Martin", "Raytheon",
        "IBM", "HPE", "Dell", "Cisco", "Oracle", "SAP", "Salesforce",
        "Digital Realty", "Equinix", "CyrusOne", "DataBank", "QTS", "CoreSite",
    ]
    
    # ==========================================================================
    # MARKET RESEARCH SITES (2000+ samples each)
    # ==========================================================================
    market_research_sites = [
        ("mordorintelligence.com", "Mordor Intelligence"),
        ("grandviewresearch.com", "Grand View Research"),
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
        ("transparencymarketresearch.com", "Transparency Market Research"),
        ("coherentmarketinsights.com", "Coherent Market Insights"),
        ("databridgemarketresearch.com", "Data Bridge Market Research"),
        ("futuremarketinsights.com", "Future Market Insights"),
        ("reportlinker.com", "ReportLinker"),
        ("kenresearch.com", "Ken Research"),
        ("strategyanalytics.com", "Strategy Analytics"),
        ("idc.com", "IDC"),
        ("gartner.com", "Gartner"),
        ("forrester.com", "Forrester"),
        ("frost.com", "Frost & Sullivan"),
        ("kbvresearch.com", "KBV Research"),
        ("psmarketresearch.com", "P&S Intelligence"),
        ("expertmarketresearch.com", "Expert Market Research"),
        ("adroitmarketresearch.com", "Adroit Market Research"),
        ("marketresearchfuture.com", "Market Research Future"),
        ("reportsanddata.com", "Reports and Data"),
        ("verifiedmarketresearch.com", "Verified Market Research"),
    ]
    
    report_title_templates = [
        "{industry} Market Report {year}",
        "{region} {industry} Market Size {year}",
        "Global {industry} Market Forecast to {year}",
        "{industry} Industry Analysis {year}",
        "{region} {industry} Market Trends and Forecast",
        "{industry} Market Growth Analysis {year}",
        "{industry} Market Share Report {year}",
        "{region} {industry} Industry Outlook to {year}",
        "{industry} Market Research Report {year}",
        "Global {industry} Market Overview {year}",
        "{industry} Sector Analysis and Forecast {year}",
        "{region} {industry} Market Insights {year}",
        "{industry} Market Size, Share & Trends {year}",
        "{industry} Market Dynamics and Opportunities",
        "Comprehensive {industry} Market Study {year}",
        "{industry} Market Competitive Landscape {year}",
        "{region} {industry} Market Revenue Forecast",
        "{industry} Market Segmentation Analysis",
        "Strategic {industry} Market Report {year}",
        "{industry} Market Investment Analysis {year}",
        "{industry} Market: Key Players and Strategies",
        "{region} {industry} Market Demand Analysis",
        "{industry} Market Value Chain Analysis",
        "{industry} Market SWOT Analysis {year}",
        "{industry} Market Porter's Five Forces Analysis",
    ]
    
    report_snippet_templates = [
        "The global {industry} market is projected to reach $X billion by {year}, growing at X% CAGR.",
        "Market research report analyzing the {industry} sector in {region}. Download sample report.",
        "Comprehensive {industry} market analysis with industry trends, forecasts, and competitive landscape.",
        "The {region} {industry} market size was valued at $X billion in 2024 and is expected to grow.",
        "Industry analysis covering key players, market segmentation, and growth opportunities in {industry}.",
        "Download the full {industry} market report for detailed analysis and forecasts to {year}.",
        "This report provides insights into the {industry} market including market size, share, and trends.",
        "The {industry} market report covers comprehensive analysis of market dynamics and opportunities.",
        "Get detailed analysis of the {region} {industry} market with company profiles and forecasts.",
        "Market study on {industry} sector covering drivers, restraints, and competitive analysis.",
        "The report analyzes {industry} market by type, application, and geography through {year}.",
        "Request free sample of our {industry} market research report with detailed analysis.",
        "This {industry} market report includes market size estimates, forecasts, and trend analysis.",
        "{industry} market analysis report with insights on key market players and strategies.",
        "Purchase this comprehensive {industry} market report for strategic business planning.",
    ]
    
    print("Generating market research samples...")
    for domain, site_name in market_research_sites:
        for _ in range(300):  # 300 per site = 9000 total
            industry = rng.choice(industries)
            region = rng.choice(regions)
            year = rng.choice(years)
            
            title_template = rng.choice(report_title_templates)
            title = title_template.format(industry=industry, region=region, year=year)
            
            # Vary the title format
            title_formats = [
                f"{title} | {site_name}",
                f"{title} - {site_name}",
                f"{site_name}: {title}",
                f"{title}",
                f"[PDF] {title}",
                f"[Report] {title} | {site_name}",
            ]
            title = rng.choice(title_formats)
            
            snippet_template = rng.choice(report_snippet_templates)
            snippet = snippet_template.format(industry=industry, region=region, year=year)
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': f"https://www.{domain}/report/{industry.lower().replace(' ', '-')}",
                'domain': domain,
                'label': 0
            })
    
    # ==========================================================================
    # NEWS AND PRESS RELEASE SITES (1500+ samples each)
    # ==========================================================================
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
        ("venturebeat.com", "VentureBeat"),
        ("zdnet.com", "ZDNet"),
        ("cnet.com", "CNET"),
        ("theverge.com", "The Verge"),
        ("wired.com", "Wired"),
        ("arstechnica.com", "Ars Technica"),
        ("electronicdesign.com", "Electronic Design"),
        ("electronicsweekly.com", "Electronics Weekly"),
        ("eenewseurope.com", "EE News Europe"),
        ("eetimes.com", "EE Times"),
        ("semiengineering.com", "Semiconductor Engineering"),
        ("datacenterknowledge.com", "Data Center Knowledge"),
        ("datacenterdynamics.com", "Data Center Dynamics"),
        ("supplychaindive.com", "Supply Chain Dive"),
        ("manufacturingdive.com", "Manufacturing Dive"),
        ("industryweek.com", "Industry Week"),
        ("automotivenews.com", "Automotive News"),
        ("autonews.com", "Automotive News"),
        ("electrek.co", "Electrek"),
        ("therobotreport.com", "The Robot Report"),
        ("automationworld.com", "Automation World"),
    ]
    
    news_title_templates = [
        "{company} Announces {event}",
        "{company} Reports Q{q} {year} Earnings",
        "{company} to Acquire {target} in ${amount}B Deal",
        "{company} CEO {name} Speaks on {topic}",
        "{company} Opens New {type} Facility in {region}",
        "{company} Stock {direction} After {event}",
        "{company} Invests ${amount}B in {industry}",
        "Breaking: {company} {event}",
        "{company} Launches New {product} for {industry}",
        "{company} Wins ${amount}M {industry} Contract",
        "{company} and {company2} Announce Partnership",
        "{company} Expands {industry} Operations in {region}",
        "{company} Posts Record {metric} in Q{q}",
        "{company} Faces {challenge} Amid {situation}",
        "{company} Names New {role} Executive",
        "Analysis: {company}'s {strategy} Strategy",
        "{company} Cuts {number} Jobs in Restructuring",
        "{company} Raises ${amount}B in Funding Round",
        "{company} Enters {industry} Market with New {product}",
        "{company} Plans ${amount}B {region} Expansion",
        "Exclusive: Inside {company}'s {initiative}",
        "{company} Shares Surge on {news}",
        "{company} Warns of {issue} Impact",
        "{company} Beats Analyst Expectations",
        "{company} Misses Revenue Targets",
    ]
    
    news_snippet_templates = [
        "Press release from {company} regarding {topic}. Read the full announcement.",
        "News coverage of {company}'s latest {event}. Industry implications discussed.",
        "{company} announced today that it will {action}. Shares responded {reaction}.",
        "In a statement, {company} said the {decision} reflects its commitment to {goal}.",
        "Industry analysts react to {company}'s {announcement}. Market outlook revised.",
        "The {event} marks a significant milestone for {company} in the {industry} sector.",
        "{company}'s {leader} told reporters that the company is focused on {priority}.",
        "Sources familiar with the matter say {company} is considering {option}.",
        "The deal is expected to close in Q{q} {year}, pending regulatory approval.",
        "This represents {company}'s largest {type} investment to date.",
        "{company} stock traded {direction} following the announcement.",
        "Read the full press release for details on {company}'s {topic}.",
        "Breaking news: {company} confirms {development}. More details emerging.",
        "Financial analysts have {reaction} outlook for {company} following {event}.",
        "The announcement comes as {company} seeks to expand its {industry} presence.",
    ]
    
    events = ["Expansion", "Restructuring", "New Product Launch", "Partnership", "Acquisition", "Investment"]
    targets = ["smaller competitor", "technology startup", "manufacturing unit", "services division"]
    directions = ["rises", "falls", "surges", "drops", "jumps", "slides"]
    roles = ["CEO", "CFO", "CTO", "COO", "VP of Operations", "Chief Strategy Officer"]
    
    print("Generating news samples...")
    for domain, site_name in news_sites:
        for _ in range(200):  # 200 per site = 6000 total
            company = rng.choice(company_names)
            company2 = rng.choice([c for c in company_names if c != company])
            industry = rng.choice(industries)
            region = rng.choice(regions)
            year = rng.choice(years[:3])  # Recent years for news
            
            title_template = rng.choice(news_title_templates)
            title = title_template.format(
                company=company, company2=company2, industry=industry, region=region,
                year=year, event=rng.choice(events), target=rng.choice(targets),
                amount=rng.randint(1, 50), q=rng.randint(1, 4),
                direction=rng.choice(directions), name="John Smith",
                topic=industry, type="Manufacturing", product="Solution",
                role=rng.choice(roles), strategy="Growth", metric="Revenue",
                challenge="Supply Chain Issues", situation="Market Uncertainty",
                number=rng.randint(100, 5000), initiative="Transformation",
                news="Strong Earnings", issue="Component Shortage"
            )
            
            title_formats = [
                f"{title} | {site_name}",
                f"{title} - {site_name}",
                f"{title}",
                f"[Breaking] {title}",
                f"[Exclusive] {title}",
            ]
            title = rng.choice(title_formats)
            
            snippet_template = rng.choice(news_snippet_templates)
            snippet = snippet_template.format(
                company=company, topic=industry, event=rng.choice(events),
                action="expand operations", reaction="positively", decision="move",
                goal="growth", announcement="news", leader="CEO", priority="innovation",
                option="strategic alternatives", q=rng.randint(1, 4), year=year,
                type="capital", direction=rng.choice(["higher", "lower"]),
                development="strategic initiative", industry=industry
            )
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': f"https://www.{domain}/news/{company.lower().replace(' ', '-')}",
                'domain': domain,
                'label': 0
            })
    
    # ==========================================================================
    # DIRECTORY AND LISTING SITES (1000+ samples each)
    # ==========================================================================
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
        ("owler.com", "Owler"),
        ("crunchbase.com", "Crunchbase"),
        ("apollo.io", "Apollo"),
        ("leadiq.com", "LeadIQ"),
        ("lusha.com", "Lusha"),
        ("clearbit.com", "Clearbit"),
        ("builtwith.com", "BuiltWith"),
        ("similarweb.com", "SimilarWeb"),
        ("spyfu.com", "SpyFu"),
    ]
    
    directory_title_templates = [
        "{industry} Suppliers - Find Manufacturers",
        "Top {industry} Companies Directory",
        "{industry} Manufacturers List {year}",
        "Find {industry} Suppliers in {region}",
        "{industry} Company Database",
        "Search {industry} Vendors",
        "Best {industry} Service Providers",
        "{region} {industry} Business Directory",
        "{industry} Supplier Listings",
        "Compare {industry} Companies",
        "{industry} Manufacturer Search",
        "Verified {industry} Suppliers",
        "{industry} Vendor Directory {year}",
        "Top Rated {industry} Companies",
        "{industry} Business Listings",
        "Find Local {industry} Suppliers",
        "{industry} Company Profiles",
        "{industry} Supplier Network",
        "B2B {industry} Marketplace",
        "{industry} Trade Directory",
    ]
    
    directory_snippet_templates = [
        "Browse our directory of {industry} suppliers and manufacturers. Compare quotes.",
        "Find and compare {industry} companies. Read reviews and request quotes.",
        "Search {num}+ verified {industry} suppliers. Free quotes from top manufacturers.",
        "Comprehensive directory of {industry} companies in {region}. Contact suppliers.",
        "Connect with {industry} manufacturers and suppliers. Request free quotes today.",
        "Our database contains {num}+ {industry} companies. Find the right supplier.",
        "Compare {industry} vendors by price, quality, and reviews. Get matched today.",
        "Looking for {industry} suppliers? Search our verified business directory.",
        "Find top {industry} manufacturers in {region}. Request quotes from suppliers.",
        "Business directory featuring {industry} companies. Company profiles and reviews.",
    ]
    
    print("Generating directory samples...")
    for domain, site_name in directory_sites:
        for _ in range(150):  # 150 per site = 3000 total
            industry = rng.choice(industries)
            region = rng.choice(regions)
            year = rng.choice(years[:3])
            
            title_template = rng.choice(directory_title_templates)
            title = title_template.format(industry=industry, region=region, year=year)
            title = f"{title} | {site_name}" if rng.random() > 0.3 else title
            
            snippet_template = rng.choice(directory_snippet_templates)
            snippet = snippet_template.format(
                industry=industry, region=region, num=rng.randint(100, 10000)
            )
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': f"https://www.{domain}/search/{industry.lower().replace(' ', '-')}",
                'domain': domain,
                'label': 0
            })
    
    # ==========================================================================
    # JOB SITES (1000+ samples each)
    # ==========================================================================
    job_sites = [
        ("indeed.com", "Indeed"),
        ("linkedin.com", "LinkedIn"),
        ("glassdoor.com", "Glassdoor"),
        ("ziprecruiter.com", "ZipRecruiter"),
        ("monster.com", "Monster"),
        ("careerbuilder.com", "CareerBuilder"),
        ("dice.com", "Dice"),
        ("hired.com", "Hired"),
        ("simplyhired.com", "SimplyHired"),
        ("snagajob.com", "Snagajob"),
        ("flexjobs.com", "FlexJobs"),
        ("wellfound.com", "Wellfound"),
        ("builtin.com", "Built In"),
        ("levels.fyi", "Levels.fyi"),
        ("teamblind.com", "Blind"),
    ]
    
    job_titles = [
        "Engineer", "Manager", "Director", "Technician", "Specialist",
        "Analyst", "Developer", "Designer", "Coordinator", "Supervisor",
        "Lead", "Senior", "Principal", "Staff", "Associate"
    ]
    
    job_title_templates = [
        "{level} {industry} {role} Jobs at {company}",
        "{industry} {role} - {company} Careers",
        "Hiring: {industry} {role} in {region}",
        "{company} is Hiring {industry} {role}s",
        "{number}+ {industry} Jobs in {region}",
        "Apply: {level} {role} at {company}",
        "{industry} Career Opportunities - {region}",
        "Join {company} - {industry} {role} Needed",
        "{company} {industry} Team - Open Positions",
        "Remote {industry} {role} Jobs",
        "{level} {industry} {role} - ${salary}k",
        "{industry} Jobs Near {region}",
        "Urgent: {industry} {role} Wanted",
        "{company} {industry} Internship",
        "Entry Level {industry} {role}",
    ]
    
    job_snippet_templates = [
        "Apply to {industry} {role} jobs at {company}. {number}+ open positions.",
        "Find your next {industry} career. Browse {number}+ jobs in {region}.",
        "{company} is hiring {industry} professionals. Competitive salary and benefits.",
        "Join the {company} team as a {industry} {role}. Apply now.",
        "Search {industry} jobs by salary, location, and company. Apply today.",
        "Get hired as a {industry} {role}. {number}+ companies are hiring now.",
        "View all {industry} job openings at {company}. Easy apply with resume.",
        "{company} {industry} jobs: {number}+ positions available in {region}.",
        "Looking for {industry} jobs? We have {number}+ listings updated daily.",
        "Start your {industry} career at {company}. Submit your application today.",
    ]
    
    print("Generating job site samples...")
    for domain, site_name in job_sites:
        for _ in range(150):  # 150 per site = 2250 total
            industry = rng.choice(industries)
            company = rng.choice(company_names)
            region = rng.choice(regions)
            role = rng.choice(job_titles)
            level = rng.choice(["Senior", "Junior", "Lead", "Staff", "Principal", ""])
            
            title_template = rng.choice(job_title_templates)
            title = title_template.format(
                industry=industry, company=company, region=region,
                role=role, level=level, number=rng.randint(10, 500),
                salary=rng.randint(50, 200)
            )
            title = f"{title} | {site_name}" if rng.random() > 0.3 else title
            
            snippet_template = rng.choice(job_snippet_templates)
            snippet = snippet_template.format(
                industry=industry, company=company, region=region,
                role=role, number=rng.randint(10, 500)
            )
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': f"https://www.{domain}/jobs/{company.lower().replace(' ', '-')}",
                'domain': domain,
                'label': 0
            })
    
    # ==========================================================================
    # WIKIPEDIA AND EDUCATIONAL (500+ samples)
    # ==========================================================================
    wiki_sites = [
        ("wikipedia.org", "Wikipedia"),
        ("en.wikipedia.org", "Wikipedia"),
        ("britannica.com", "Britannica"),
        ("investopedia.com", "Investopedia"),
        ("sciencedirect.com", "ScienceDirect"),
        ("researchgate.net", "ResearchGate"),
        ("academia.edu", "Academia.edu"),
        ("scholar.google.com", "Google Scholar"),
        ("coursera.org", "Coursera"),
        ("edx.org", "edX"),
        ("udemy.com", "Udemy"),
        ("khanacademy.org", "Khan Academy"),
    ]
    
    wiki_templates = [
        "{industry} - Wikipedia",
        "History of {industry}",
        "{industry} Industry Overview",
        "What is {industry}?",
        "{industry} Definition and Examples",
        "Introduction to {industry}",
        "{industry} Technology Explained",
        "Guide to {industry}",
        "{industry}: A Comprehensive Overview",
        "Understanding {industry}",
        "{industry} Course - Learn {industry}",
        "Free {industry} Tutorial",
        "{industry} Fundamentals",
        "The Complete Guide to {industry}",
        "{industry} for Beginners",
    ]
    
    print("Generating educational samples...")
    for domain, site_name in wiki_sites:
        for _ in range(100):  # 100 per site = 1200 total
            industry = rng.choice(industries)
            
            title_template = rng.choice(wiki_templates)
            title = title_template.format(industry=industry)
            title = f"{title} | {site_name}" if rng.random() > 0.5 else title
            
            snippet = f"Learn about {industry}. Educational content covering fundamentals, history, and applications."
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': f"https://{domain}/wiki/{industry.replace(' ', '_')}",
                'domain': domain,
                'label': 0
            })
    
    # ==========================================================================
    # SOCIAL MEDIA AND FORUMS (500+ samples)
    # ==========================================================================
    social_sites = [
        ("reddit.com", "Reddit"),
        ("twitter.com", "Twitter"),
        ("x.com", "X"),
        ("facebook.com", "Facebook"),
        ("quora.com", "Quora"),
        ("stackoverflow.com", "Stack Overflow"),
        ("medium.com", "Medium"),
        ("substack.com", "Substack"),
        ("discord.com", "Discord"),
        ("slack.com", "Slack"),
    ]
    
    social_templates = [
        "r/{industry} - {topic} Discussion",
        "What do you think about {industry}? : r/business",
        "{industry} trends - Thread",
        "Question about {industry} careers",
        "{company} employees, how is it working there?",
        "Best {industry} companies to work for?",
        "{industry} industry news and discussion",
        "ELI5: How does {industry} work?",
        "AMA: I work in {industry}",
        "{company} just announced {event}. Thoughts?",
    ]
    
    print("Generating social media samples...")
    for domain, site_name in social_sites:
        for _ in range(100):  # 100 per site = 1000 total
            industry = rng.choice(industries)
            company = rng.choice(company_names)
            
            title_template = rng.choice(social_templates)
            title = title_template.format(
                industry=industry, company=company,
                topic="Discussion", event="expansion"
            )
            
            snippet = f"Community discussion about {industry}. Join the conversation."
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': f"https://www.{domain}/r/{industry.lower().replace(' ', '')}",
                'domain': domain,
                'label': 0
            })
    
    # ==========================================================================
    # DOCUMENT SHARING SITES (500+ samples)
    # ==========================================================================
    doc_sites = [
        ("slideshare.net", "SlideShare"),
        ("scribd.com", "Scribd"),
        ("issuu.com", "Issuu"),
        ("docplayer.net", "DocPlayer"),
        ("calameo.com", "Calaméo"),
        ("yumpu.com", "Yumpu"),
        ("fliphtml5.com", "FlipHTML5"),
    ]
    
    doc_templates = [
        "{industry} Market Overview Presentation",
        "{company} Company Profile [PDF]",
        "{industry} Industry Report Slides",
        "{industry} Best Practices Guide",
        "Introduction to {industry} [Presentation]",
        "{year} {industry} Trends Whitepaper",
        "{company} Investor Presentation",
        "{industry} Case Study: {company}",
        "{industry} Technical Guide",
        "Understanding {industry} [eBook]",
    ]
    
    print("Generating document sharing samples...")
    for domain, site_name in doc_sites:
        for _ in range(100):  # 100 per site = 700 total
            industry = rng.choice(industries)
            company = rng.choice(company_names)
            year = rng.choice(years[:3])
            
            title_template = rng.choice(doc_templates)
            title = title_template.format(industry=industry, company=company, year=year)
            title = f"{title} | {site_name}"
            
            snippet = f"View and download {industry} documents. Presentation slides and PDF reports."
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': f"https://www.{domain}/doc/{industry.lower().replace(' ', '-')}",
                'domain': domain,
                'label': 0
            })
    
    # ==========================================================================
    # EVENTS AND CONFERENCES (500+ samples)
    # ==========================================================================
    event_sites = [
        ("eventbrite.com", "Eventbrite"),
        ("cvent.com", "Cvent"),
        ("meetup.com", "Meetup"),
        ("10times.com", "10Times"),
        ("tradeshowplus.com", "Trade Show Plus"),
        ("expodatabase.com", "Expo Database"),
        ("electronica.de", "electronica"),
        ("productronica.com", "productronica"),
        ("semicon.org", "SEMICON"),
        ("ces.tech", "CES"),
    ]
    
    event_templates = [
        "{industry} Conference {year}",
        "{region} {industry} Expo {year}",
        "{industry} Trade Show - {region}",
        "{industry} Summit {year}",
        "Annual {industry} Convention",
        "{industry} World {year}",
        "International {industry} Forum",
        "{industry} Week {region} {year}",
        "Global {industry} Congress",
        "{industry} Innovation Summit",
    ]
    
    print("Generating event samples...")
    for domain, site_name in event_sites:
        for _ in range(100):  # 100 per site = 1000 total
            industry = rng.choice(industries)
            region = rng.choice(regions[:20])  # Use cities/countries
            year = rng.choice(years[:3])
            
            title_template = rng.choice(event_templates)
            title = title_template.format(industry=industry, region=region, year=year)
            title = f"{title} | {site_name}"
            
            snippet = f"Join the {industry} event in {region}. Networking, exhibitions, and conferences."
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': f"https://www.{domain}/events/{industry.lower().replace(' ', '-')}",
                'domain': domain,
                'label': 0
            })
    
    # ==========================================================================
    # REVIEW AND COMPARISON SITES (500+ samples)
    # ==========================================================================
    review_sites = [
        ("g2.com", "G2"),
        ("capterra.com", "Capterra"),
        ("trustpilot.com", "Trustpilot"),
        ("trustradius.com", "TrustRadius"),
        ("softwareadvice.com", "Software Advice"),
        ("getapp.com", "GetApp"),
        ("comparably.com", "Comparably"),
        ("ambitionbox.com", "AmbitionBox"),
    ]
    
    review_templates = [
        "{company} Reviews - {rating}/5",
        "Best {industry} Software {year}",
        "{company} vs {company2} Comparison",
        "Top {industry} Solutions Ranked",
        "{company} Employee Reviews",
        "{industry} Tool Comparisons",
        "Is {company} worth it? Reviews",
        "{company} Pricing and Features",
        "{number}+ {industry} Reviews",
        "Compare {industry} Vendors",
    ]
    
    print("Generating review site samples...")
    for domain, site_name in review_sites:
        for _ in range(100):  # 100 per site = 800 total
            industry = rng.choice(industries)
            company = rng.choice(company_names)
            company2 = rng.choice([c for c in company_names if c != company])
            year = rng.choice(years[:3])
            
            title_template = rng.choice(review_templates)
            title = title_template.format(
                industry=industry, company=company, company2=company2,
                year=year, rating=round(rng.uniform(3.5, 4.9), 1),
                number=rng.randint(50, 500)
            )
            title = f"{title} | {site_name}"
            
            snippet = f"Read verified reviews of {company}. Compare with alternatives."
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': f"https://www.{domain}/products/{company.lower().replace(' ', '-')}",
                'domain': domain,
                'label': 0
            })
    
    # ==========================================================================
    # GOVERNMENT AND TRADE SITES (500+ samples)
    # ==========================================================================
    gov_sites = [
        ("trade.gov", "Trade.gov"),
        ("commerce.gov", "Commerce.gov"),
        ("export.gov", "Export.gov"),
        ("sba.gov", "SBA"),
        ("bls.gov", "Bureau of Labor Statistics"),
        ("census.gov", "Census Bureau"),
        ("usitc.gov", "US International Trade Commission"),
        ("ec.europa.eu", "European Commission"),
        ("gov.uk", "GOV.UK"),
    ]
    
    gov_templates = [
        "{region} {industry} Trade Data",
        "{industry} Export Statistics {year}",
        "{industry} Industry Report - Official",
        "{region} {industry} Economic Data",
        "Trade Information: {industry}",
        "{industry} Market Access Guide",
        "{industry} Import/Export Data",
        "{region} {industry} Employment Statistics",
        "Official {industry} Industry Overview",
        "{industry} Trade Regulations",
    ]
    
    print("Generating government samples...")
    for domain, site_name in gov_sites:
        for _ in range(100):  # 100 per site = 900 total
            industry = rng.choice(industries)
            region = rng.choice(regions)
            year = rng.choice(years[:3])
            
            title_template = rng.choice(gov_templates)
            title = title_template.format(industry=industry, region=region, year=year)
            title = f"{title} | {site_name}"
            
            snippet = f"Official government data on {industry}. Statistics and trade information."
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'url': f"https://www.{domain}/data/{industry.lower().replace(' ', '-')}",
                'domain': domain,
                'label': 0
            })
    
    print(f"\nTotal junk samples generated: {len(samples)}")
    return samples


def main():
    output_dir = Path("external_data")
    output_dir.mkdir(exist_ok=True)
    
    samples = generate_massive_junk_dataset(seed=42)
    
    # Shuffle
    random.shuffle(samples)
    
    # Summary by domain type
    from collections import Counter
    domain_counts = Counter(s['domain'].split('.')[-2] if '.' in s['domain'] else s['domain'] for s in samples)
    print("\nSamples by domain category (top 20):")
    for domain, count in domain_counts.most_common(20):
        print(f"  {domain}: {count}")
    
    # Save
    output_path = output_dir / "massive_junk_dataset.json"
    with open(output_path, 'w') as f:
        json.dump(samples, f)
    
    print(f"\nSaved {len(samples)} samples to {output_path}")
    print(f"File size: {output_path.stat().st_size / 1024 / 1024:.1f} MB")


if __name__ == "__main__":
    main()
