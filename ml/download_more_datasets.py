#!/usr/bin/env python3
"""
Extended Dataset Downloader - Download MANY more external datasets
for comprehensive lead classifier training.

Additional sources:
1. AG News Dataset (120K news articles)
2. TREC Spam Corpus patterns
3. Enron Email patterns  
4. Website classification patterns
5. Industry-specific company databases
6. B2B vs B2C classification patterns
"""

import os
import json
import csv
import random
import urllib.request
import gzip
import io
import re
from pathlib import Path
from typing import List, Dict, Tuple
from collections import Counter

try:
    from datasets import load_dataset
    HAS_HF_DATASETS = True
except ImportError:
    HAS_HF_DATASETS = False
    print("Warning: huggingface datasets not available")


class ExtendedDatasetDownloader:
    """Downloads many more external datasets for lead classifier training."""
    
    def __init__(self, output_dir: str = "external_data", seed: int = 42):
        self.output_dir = Path(output_dir)
        self.output_dir.mkdir(exist_ok=True)
        self.rng = random.Random(seed)
        self.all_samples: List[Dict] = []
        
    def download_ag_news(self) -> List[Dict]:
        """
        Download AG News dataset (120K news articles, 4 categories).
        News articles are NON-LEADS.
        """
        if not HAS_HF_DATASETS:
            print("  Skipping AG News (datasets library not available)")
            return []
            
        print("Downloading AG News dataset from HuggingFace...")
        try:
            dataset = load_dataset("fancyzhx/ag_news", split="train")
            samples = []
            
            # Take a sample to avoid overwhelming the dataset
            indices = list(range(len(dataset)))
            self.rng.shuffle(indices)
            
            for idx in indices[:15000]:  # Take 15K samples
                item = dataset[idx]
                text = item['text']
                label = item['label']  # 0=World, 1=Sports, 2=Business, 3=Sci/Tech
                
                # Split into title and snippet
                sentences = text.split('. ')
                title = sentences[0][:100] if sentences else text[:100]
                snippet = '. '.join(sentences[:3])[:300] if len(sentences) > 1 else text[:300]
                
                # Business news about specific companies could be leads
                is_lead = 0
                if label == 2:  # Business
                    company_indicators = ['Inc', 'Corp', 'Ltd', 'LLC', 'Company', 'announces', 'reports']
                    if any(ind in text for ind in company_indicators) and self.rng.random() > 0.8:
                        is_lead = 1
                
                samples.append({
                    'title': title,
                    'snippet': snippet,
                    'label': is_lead,
                    'source': 'ag_news'
                })
            
            print(f"  Downloaded {len(samples)} AG News samples")
            lead_count = sum(1 for s in samples if s['label'] == 1)
            print(f"  Leads: {lead_count}, Non-leads: {len(samples) - lead_count}")
            return samples
            
        except Exception as e:
            print(f"  Error downloading AG News: {e}")
            return []
    
    def download_yelp_reviews(self) -> List[Dict]:
        """
        Download Yelp reviews (business reviews - mostly NON-LEADS).
        """
        if not HAS_HF_DATASETS:
            return []
            
        print("Downloading Yelp Polarity dataset...")
        try:
            dataset = load_dataset("fancyzhx/yelp_polarity", split="train")
            samples = []
            
            indices = list(range(len(dataset)))
            self.rng.shuffle(indices)
            
            for idx in indices[:8000]:
                item = dataset[idx]
                text = item['text']
                
                # Yelp reviews are consumer content, not B2B leads
                title = text[:80] + '...' if len(text) > 80 else text
                snippet = text[:300]
                
                samples.append({
                    'title': f"Review: {title}",
                    'snippet': snippet,
                    'label': 0,  # Reviews are non-leads
                    'source': 'yelp_reviews'
                })
            
            print(f"  Downloaded {len(samples)} Yelp samples")
            return samples
            
        except Exception as e:
            print(f"  Error downloading Yelp: {e}")
            return []
    
    def download_tweet_samples(self) -> List[Dict]:
        """
        Download tweet-like patterns (social media = NON-LEADS).
        """
        if not HAS_HF_DATASETS:
            return []
            
        print("Downloading Tweet samples...")
        try:
            # Use sentiment140 or similar
            dataset = load_dataset("stanfordnlp/sentiment140", split="train", trust_remote_code=True)
            samples = []
            
            indices = list(range(len(dataset)))
            self.rng.shuffle(indices)
            
            for idx in indices[:10000]:
                item = dataset[idx]
                text = item.get('text', '')
                
                if len(text) < 20:
                    continue
                
                samples.append({
                    'title': text[:80],
                    'snippet': text,
                    'label': 0,  # Social media = non-lead
                    'source': 'tweets'
                })
            
            print(f"  Downloaded {len(samples)} tweet samples")
            return samples
            
        except Exception as e:
            print(f"  Error downloading tweets: {e}")
            return self._generate_social_media_extended()
    
    def _generate_social_media_extended(self) -> List[Dict]:
        """Generate extended social media patterns."""
        print("  Generating extended social media patterns...")
        samples = []
        
        templates = [
            "Just {action} at {place}! #amazing",
            "Can't believe {event}... thoughts?",
            "Who else loves {thing}? 🔥",
            "Happy {occasion} everyone! 🎉",
            "Check out my new {item}! Link in bio",
            "RT if you agree: {opinion}",
            "Throwback to {memory} 📸",
            "Feeling {emotion} about {topic}",
            "Anyone else dealing with {problem}?",
            "Best {category} ever! Here's why...",
        ]
        
        actions = ['eating', 'working out', 'relaxing', 'traveling', 'shopping']
        places = ['the beach', 'downtown', 'home', 'the gym', 'a cafe']
        events = ['this happened', 'the news today', 'what I just saw']
        things = ['coffee', 'sunsets', 'weekends', 'pizza', 'dogs']
        occasions = ['Friday', 'Monday', 'Birthday', 'Holiday', 'New Year']
        items = ['phone', 'outfit', 'car', 'apartment', 'project']
        opinions = ['life is short', 'kindness matters', 'music heals']
        memories = ['last summer', 'college days', 'that trip']
        emotions = ['excited', 'grateful', 'nervous', 'happy', 'tired']
        topics = ['tomorrow', 'this week', 'the future', 'my plans']
        problems = ['traffic', 'deadlines', 'insomnia', 'stress']
        categories = ['movie', 'book', 'song', 'meal', 'day']
        
        for _ in range(5000):
            template = self.rng.choice(templates)
            text = template.format(
                action=self.rng.choice(actions),
                place=self.rng.choice(places),
                event=self.rng.choice(events),
                thing=self.rng.choice(things),
                occasion=self.rng.choice(occasions),
                item=self.rng.choice(items),
                opinion=self.rng.choice(opinions),
                memory=self.rng.choice(memories),
                emotion=self.rng.choice(emotions),
                topic=self.rng.choice(topics),
                problem=self.rng.choice(problems),
                category=self.rng.choice(categories)
            )
            
            samples.append({
                'title': text,
                'snippet': text + " #lifestyle #daily",
                'label': 0,
                'source': 'generated_social_extended'
            })
        
        print(f"  Generated {len(samples)} extended social samples")
        return samples
    
    def generate_b2b_company_patterns(self) -> List[Dict]:
        """Generate realistic B2B company website patterns (LEADS)."""
        print("Generating B2B company patterns...")
        samples = []
        
        # Industry verticals
        industries = {
            'Manufacturing': [
                'CNC machining', 'injection molding', 'sheet metal fabrication',
                'precision engineering', 'assembly services', 'prototype development',
                'quality inspection', 'industrial automation', 'tool and die',
                'powder coating', 'electroplating', 'heat treatment'
            ],
            'Electronics': [
                'PCB assembly', 'SMT services', 'cable harness', 'box build',
                'electronic testing', 'firmware development', 'RF engineering',
                'power supply design', 'embedded systems', 'IoT solutions'
            ],
            'IT Services': [
                'managed IT', 'cloud migration', 'cybersecurity', 'network infrastructure',
                'data center services', 'disaster recovery', 'IT consulting',
                'software development', 'system integration', 'helpdesk support'
            ],
            'Industrial': [
                'industrial equipment', 'process automation', 'instrumentation',
                'control systems', 'HVAC solutions', 'compressed air systems',
                'material handling', 'conveyor systems', 'robotics integration'
            ],
            'Logistics': [
                'freight forwarding', 'warehousing', '3PL services', 'supply chain',
                'last mile delivery', 'customs brokerage', 'inventory management',
                'cross-docking', 'cold chain logistics', 'reverse logistics'
            ]
        }
        
        company_templates = [
            "{prefix} {industry} {suffix}",
            "{prefix}{word} {suffix}",
            "{word} {industry} {suffix}",
            "{prefix} {word} Solutions",
            "{word}Tech {suffix}",
        ]
        
        prefixes = ['Advanced', 'Precision', 'Global', 'Premier', 'Elite', 'Pro', 
                    'First', 'United', 'National', 'American', 'Pacific', 'Atlantic']
        suffixes = ['Inc', 'LLC', 'Corp', 'Ltd', 'Group', 'Co', 'Industries', 'Systems']
        words = ['Apex', 'Summit', 'Vertex', 'Core', 'Prime', 'Nova', 'Atlas', 'Titan']
        
        page_types = [
            ('About Us', 'Learn about {company} and our mission to deliver {service}.'),
            ('Services', '{company} offers comprehensive {service} solutions for your business.'),
            ('Capabilities', 'Our {service} capabilities include state-of-the-art facilities.'),
            ('Contact', 'Get in touch with {company} for your {service} needs.'),
            ('Industries', '{company} serves clients across {industry} and more.'),
            ('Solutions', 'Custom {service} solutions tailored to your requirements.'),
            ('Products', 'Explore {company}\'s range of {service} products and equipment.'),
            ('Case Studies', 'See how {company} helped clients with {service} challenges.'),
        ]
        
        for industry, services in industries.items():
            for _ in range(800):  # 800 per industry = 4000 total
                template = self.rng.choice(company_templates)
                company = template.format(
                    prefix=self.rng.choice(prefixes),
                    suffix=self.rng.choice(suffixes),
                    word=self.rng.choice(words),
                    industry=industry
                )
                
                service = self.rng.choice(services)
                page_title, page_snippet = self.rng.choice(page_types)
                
                title = f"{page_title} | {company}"
                snippet = page_snippet.format(
                    company=company,
                    service=service,
                    industry=industry
                )
                
                samples.append({
                    'title': title,
                    'snippet': snippet,
                    'label': 1,  # B2B companies are leads
                    'source': 'generated_b2b'
                })
        
        print(f"  Generated {len(samples)} B2B company patterns")
        return samples
    
    def generate_ecommerce_extended(self) -> List[Dict]:
        """Generate extended e-commerce patterns (NON-LEADS for B2B context)."""
        print("Generating extended e-commerce patterns...")
        samples = []
        
        marketplaces = [
            'Amazon', 'eBay', 'Walmart', 'Target', 'Best Buy', 'Etsy',
            'AliExpress', 'Wish', 'Wayfair', 'Overstock', 'Newegg', 'B&H Photo'
        ]
        
        categories = [
            'Electronics', 'Home & Garden', 'Clothing', 'Toys & Games',
            'Sports & Outdoors', 'Beauty', 'Automotive', 'Pet Supplies',
            'Office Products', 'Health & Household', 'Baby', 'Grocery'
        ]
        
        patterns = [
            "{product} - {price} - {marketplace}",
            "Buy {product} Online | {marketplace}",
            "{product} for Sale | Best Deals on {marketplace}",
            "Shop {category} at {marketplace} | Free Shipping",
            "{marketplace}: {product} - Customer Reviews",
            "Compare {product} Prices | {marketplace}",
        ]
        
        products = [
            'Wireless Headphones', 'Smart Watch', 'Laptop Stand', 'USB Hub',
            'Phone Case', 'Bluetooth Speaker', 'Gaming Mouse', 'LED Lights',
            'Power Bank', 'Webcam', 'Microphone', 'Keyboard', 'Monitor'
        ]
        
        prices = ['$19.99', '$29.99', '$49.99', '$99.99', '$149.99', '$199.99']
        
        for _ in range(4000):
            pattern = self.rng.choice(patterns)
            title = pattern.format(
                product=self.rng.choice(products),
                price=self.rng.choice(prices),
                marketplace=self.rng.choice(marketplaces),
                category=self.rng.choice(categories)
            )
            
            samples.append({
                'title': title,
                'snippet': f"{title}. Shop now for best deals and fast delivery.",
                'label': 0,  # E-commerce is non-lead for B2B
                'source': 'generated_ecommerce_extended'
            })
        
        print(f"  Generated {len(samples)} extended e-commerce patterns")
        return samples
    
    def generate_forum_patterns(self) -> List[Dict]:
        """Generate forum/Q&A site patterns (NON-LEADS)."""
        print("Generating forum/Q&A patterns...")
        samples = []
        
        sites = ['Stack Overflow', 'Reddit', 'Quora', 'Stack Exchange', 
                 'Yahoo Answers', 'Forums', 'Community']
        
        question_starters = [
            'How to', 'Why does', 'What is', 'Can someone explain',
            'Need help with', 'Best way to', 'Is it possible to',
            'Should I', 'Has anyone tried', 'Looking for advice on'
        ]
        
        topics = [
            'fix this error', 'configure settings', 'install software',
            'solve this problem', 'understand this concept', 'choose between options',
            'improve performance', 'debug code', 'set up environment',
            'migrate data', 'optimize query', 'deploy application'
        ]
        
        for _ in range(3000):
            site = self.rng.choice(sites)
            starter = self.rng.choice(question_starters)
            topic = self.rng.choice(topics)
            
            title = f"{starter} {topic}? - {site}"
            snippet = f"{title}. Discussion thread with community answers and solutions."
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'label': 0,  # Forums are non-leads
                'source': 'generated_forums'
            })
        
        print(f"  Generated {len(samples)} forum patterns")
        return samples
    
    def generate_wiki_patterns(self) -> List[Dict]:
        """Generate Wikipedia/encyclopedia patterns (NON-LEADS)."""
        print("Generating Wikipedia patterns...")
        samples = []
        
        topics = [
            'History of', 'Overview of', 'Introduction to', 'Guide to',
            'Analysis of', 'Comparison of', 'Evolution of', 'Impact of',
            'Development of', 'Theory of', 'Principles of', 'Applications of'
        ]
        
        subjects = [
            'manufacturing', 'electronics', 'software engineering', 'business',
            'economics', 'technology', 'science', 'industry', 'automation',
            'robotics', 'artificial intelligence', 'data science', 'networking'
        ]
        
        for _ in range(2000):
            topic = self.rng.choice(topics)
            subject = self.rng.choice(subjects)
            
            title = f"{topic} {subject} - Wikipedia"
            snippet = f"This article covers the {topic.lower()} {subject}. From Wikipedia, the free encyclopedia."
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'label': 0,  # Wikipedia is non-lead
                'source': 'generated_wiki'
            })
        
        print(f"  Generated {len(samples)} Wikipedia patterns")
        return samples
    
    def generate_pdf_document_patterns(self) -> List[Dict]:
        """Generate PDF/document patterns (mostly NON-LEADS)."""
        print("Generating PDF/document patterns...")
        samples = []
        
        doc_types = [
            'Whitepaper', 'Case Study', 'Technical Report', 'Research Paper',
            'Annual Report', 'Industry Analysis', 'Market Research', 'Guide',
            'Brochure', 'Datasheet', 'Specification', 'Manual'
        ]
        
        topics = [
            'Digital Transformation', 'Cloud Computing', 'IoT Implementation',
            'Supply Chain Optimization', 'Manufacturing Trends', 'Industry 4.0',
            'Cybersecurity Best Practices', 'Data Analytics', 'AI Applications',
            'Sustainability', 'Cost Reduction', 'Quality Management'
        ]
        
        sources = [
            'Gartner', 'Forrester', 'McKinsey', 'Deloitte', 'PwC', 'KPMG',
            'IDC', 'Accenture', 'BCG', 'Bain', 'EY', 'IBM Research'
        ]
        
        for _ in range(2500):
            doc_type = self.rng.choice(doc_types)
            topic = self.rng.choice(topics)
            source = self.rng.choice(sources)
            
            title = f"{topic} {doc_type} | {source} [PDF]"
            snippet = f"Download the {doc_type.lower()} on {topic.lower()} from {source}."
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'label': 0,  # Research documents are non-leads
                'source': 'generated_pdfs'
            })
        
        print(f"  Generated {len(samples)} PDF/document patterns")
        return samples
    
    def generate_event_patterns(self) -> List[Dict]:
        """Generate event/conference patterns (NON-LEADS)."""
        print("Generating event/conference patterns...")
        samples = []
        
        event_types = ['Conference', 'Summit', 'Expo', 'Trade Show', 'Symposium',
                       'Workshop', 'Webinar', 'Seminar', 'Forum', 'Congress']
        
        industries = ['Manufacturing', 'Electronics', 'Technology', 'Healthcare',
                      'Automotive', 'Aerospace', 'Energy', 'Logistics']
        
        locations = ['Las Vegas', 'Chicago', 'New York', 'San Francisco', 'Orlando',
                     'London', 'Frankfurt', 'Shanghai', 'Tokyo', 'Singapore']
        
        years = ['2024', '2025', '2026']
        
        for _ in range(1500):
            event = self.rng.choice(event_types)
            industry = self.rng.choice(industries)
            location = self.rng.choice(locations)
            year = self.rng.choice(years)
            
            title = f"{industry} {event} {year} | {location}"
            snippet = f"Join the leading {industry.lower()} {event.lower()} in {location}. Register now!"
            
            samples.append({
                'title': title,
                'snippet': snippet,
                'label': 0,  # Events are non-leads
                'source': 'generated_events'
            })
        
        print(f"  Generated {len(samples)} event patterns")
        return samples
    
    def generate_real_company_enhanced(self) -> List[Dict]:
        """Generate patterns for real well-known companies (LEADS)."""
        print("Generating real company patterns...")
        samples = []
        
        # Real companies with their typical page patterns
        companies = {
            'Jabil': ['manufacturing', 'EMS', 'supply chain'],
            'Flex': ['manufacturing', 'design', 'engineering'],
            'Celestica': ['electronics manufacturing', 'aerospace', 'defense'],
            'Sanmina': ['PCB', 'optical', 'medical devices'],
            'Benchmark Electronics': ['engineering', 'manufacturing', 'test'],
            'Plexus': ['product development', 'manufacturing', 'services'],
            'Arrow Electronics': ['components', 'distribution', 'solutions'],
            'Avnet': ['electronic components', 'supply chain', 'IoT'],
            'Digi-Key': ['electronic components', 'distribution', 'engineering'],
            'Mouser': ['semiconductors', 'electronic components', 'prototyping'],
            'TTI': ['passive components', 'connectors', 'electromechanical'],
            'Future Electronics': ['semiconductors', 'components', 'design'],
            'Digital Realty': ['data centers', 'colocation', 'interconnection'],
            'Equinix': ['data centers', 'interconnection', 'cloud'],
            'CyrusOne': ['data centers', 'enterprise', 'cloud'],
            'AMD': ['processors', 'graphics', 'data center'],
            'Intel': ['processors', 'memory', 'FPGA'],
            'NVIDIA': ['GPUs', 'AI', 'data center'],
            'Texas Instruments': ['semiconductors', 'analog', 'embedded'],
            'Microchip': ['microcontrollers', 'analog', 'memory'],
        }
        
        page_types = [
            ('About', 'Learn about {company} and our commitment to {service}.'),
            ('Solutions', '{company} provides {service} solutions for enterprises.'),
            ('Products', 'Explore {company}\'s {service} product portfolio.'),
            ('Services', '{company} offers comprehensive {service} services.'),
            ('Contact', 'Contact {company} for your {service} needs.'),
            ('Locations', '{company} has facilities worldwide for {service}.'),
            ('Careers', 'Join {company} and work on cutting-edge {service}.'),
            ('News', 'Latest announcements from {company} about {service}.'),
        ]
        
        for company, services in companies.items():
            for _ in range(150):  # 150 patterns per company
                service = self.rng.choice(services)
                page_title, page_snippet = self.rng.choice(page_types)
                
                # Vary the title format
                title_formats = [
                    f"{page_title} | {company}",
                    f"{company} | {page_title}",
                    f"{company} - {page_title}",
                    f"{page_title} - {company} Official Site",
                ]
                
                title = self.rng.choice(title_formats)
                snippet = page_snippet.format(company=company, service=service)
                
                samples.append({
                    'title': title,
                    'snippet': snippet,
                    'label': 1,  # Real companies are leads
                    'source': 'real_companies_enhanced'
                })
        
        print(f"  Generated {len(samples)} real company patterns")
        return samples
    
    def download_all(self) -> List[Dict]:
        """Download and combine all datasets."""
        print("=" * 70)
        print("DOWNLOADING EXTENDED DATASETS FOR LEAD CLASSIFIER")
        print("=" * 70)
        
        all_samples = []
        
        # Real datasets from HuggingFace
        all_samples.extend(self.download_ag_news())
        all_samples.extend(self.download_yelp_reviews())
        all_samples.extend(self.download_tweet_samples())
        
        # Generated patterns
        all_samples.extend(self.generate_b2b_company_patterns())
        all_samples.extend(self.generate_ecommerce_extended())
        all_samples.extend(self.generate_forum_patterns())
        all_samples.extend(self.generate_wiki_patterns())
        all_samples.extend(self.generate_pdf_document_patterns())
        all_samples.extend(self.generate_event_patterns())
        all_samples.extend(self.generate_real_company_enhanced())
        
        # Shuffle
        self.rng.shuffle(all_samples)
        
        print("\n" + "=" * 70)
        print("EXTENDED DATASET SUMMARY")
        print("=" * 70)
        
        source_counts = Counter(s['source'] for s in all_samples)
        for source, count in sorted(source_counts.items()):
            leads = sum(1 for s in all_samples if s['source'] == source and s['label'] == 1)
            print(f"  {source}: {count} samples ({leads} leads)")
        
        total_leads = sum(1 for s in all_samples if s['label'] == 1)
        print(f"\nEXTENDED TOTAL: {len(all_samples)} samples")
        print(f"  Leads: {total_leads} ({100 * total_leads / len(all_samples):.1f}%)")
        print(f"  Non-leads: {len(all_samples) - total_leads}")
        
        self.all_samples = all_samples
        return all_samples
    
    def save_dataset(self, filename: str = "extended_training_data.json"):
        """Save the combined dataset to JSON."""
        output_path = self.output_dir / filename
        
        training_data = [
            {'title': s['title'], 'snippet': s['snippet'], 'label': s['label']}
            for s in self.all_samples
        ]
        
        with open(output_path, 'w', encoding='utf-8') as f:
            json.dump(training_data, f, indent=2, ensure_ascii=False)
        
        print(f"\nSaved {len(training_data)} samples to {output_path}")
        return output_path


def main():
    import argparse
    parser = argparse.ArgumentParser(description="Download extended datasets")
    parser.add_argument('--output-dir', default='external_data', help='Output directory')
    parser.add_argument('--seed', type=int, default=42, help='Random seed')
    args = parser.parse_args()
    
    downloader = ExtendedDatasetDownloader(output_dir=args.output_dir, seed=args.seed)
    downloader.download_all()
    downloader.save_dataset()
    
    print("\n" + "=" * 70)
    print("EXTENDED DATASET DOWNLOAD COMPLETE")
    print("=" * 70)


if __name__ == "__main__":
    main()
