"""Quick test of the PyTorch model on webcrawler data."""
import torch
import json
import sys
sys.path.insert(0, '/home/aaron/IdeaProjects/CRM-v2/ml')

from train_lead_classifier_v4 import LeadClassifierAttention, Config

# Load vocabulary
with open("models/vocab.json", "r") as f:
    vocab = json.load(f)

# Create model
config = Config()
model = LeadClassifierAttention(config)
model.load_state_dict(torch.load("models/best_model_v4.pt", map_location="cpu"))
model.eval()

# Simple tokenizer function
def tokenize(text, max_len):
    tokens = [vocab.get(c.lower(), vocab.get('[UNK]', 1)) for c in str(text)[:max_len]]
    tokens = tokens + [0] * (max_len - len(tokens))  # Pad
    return torch.tensor([tokens])

# Test cases - companies that should be detected as leads
companies = [
    ("Kimball Electronics | Manufacturing", "kimballelectronics.com"),
    ("NVIDIA | AI Computing", "nvidia.com"),
    ("Qualcomm | Mobile Technology", "qualcomm.com"),
    ("Dell Technologies | Enterprise", "dell.com"),
    ("Cisco Systems | Networking", "cisco.com"),
    ("Honeywell | Industrial", "honeywell.com"),
    ("Continental AG | Automotive", "continental.com"),
    ("Denso Corporation | Automotive", "denso.com"),
    ("3M Company | Industrial", "3m.com"),
    ("Oracle | Enterprise Software", "oracle.com"),
]

# Test cases - junk that should be detected as non-leads
junk = [
    ("Wikipedia: Electronics Industry", "wikipedia.org"),
    ("Monster.com - Electronics Jobs", "monster.com"),
    ("CNET News - Tech Reviews", "cnet.com"),
    ("TechCrunch Article", "techcrunch.com"),
    ("LinkedIn Profile - Engineer", "linkedin.com"),
]

print("Testing Companies (should be COMPANY/LEAD):")
company_correct = 0
for title, domain in companies:
    t = tokenize(title, 64)
    s = tokenize(title, 128)  # Use title as snippet too
    u = tokenize(f"https://{domain}/", 64)
    d = tokenize(domain, 32)
    
    with torch.no_grad():
        logits = model(t, s, u, d)
        prob = torch.softmax(logits, dim=-1)[0]
        pred = logits.argmax(dim=-1).item()
    
    result = "COMPANY" if pred == 1 else "JUNK"
    conf = prob[pred].item() * 100
    status = "✓" if pred == 1 else "✗"
    if pred == 1:
        company_correct += 1
    print(f"  {status} {title[:40]:40s} → {result} ({conf:.1f}%)")

print(f"\nCompany Detection: {company_correct}/{len(companies)} ({100*company_correct/len(companies):.1f}%)")

print("\nTesting Junk (should be JUNK):")
junk_correct = 0
for title, domain in junk:
    t = tokenize(title, 64)
    s = tokenize(title, 128)
    u = tokenize(f"https://{domain}/", 64)
    d = tokenize(domain, 32)
    
    with torch.no_grad():
        logits = model(t, s, u, d)
        prob = torch.softmax(logits, dim=-1)[0]
        pred = logits.argmax(dim=-1).item()
    
    result = "COMPANY" if pred == 1 else "JUNK"
    conf = prob[pred].item() * 100
    status = "✓" if pred == 0 else "✗"
    if pred == 0:
        junk_correct += 1
    print(f"  {status} {title[:40]:40s} → {result} ({conf:.1f}%)")

print(f"\nJunk Detection: {junk_correct}/{len(junk)} ({100*junk_correct/len(junk):.1f}%)")
