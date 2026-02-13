import json

def merge_dicts(d1, d2):
    for k, v in d2.items():
        if k in d1 and isinstance(d1[k], dict) and isinstance(v, dict):
            merge_dicts(d1[k], v)
        else:
            d1[k] = v

def handle_duplicates(pairs):
    d = {}
    for k, v in pairs:
        if k in d and isinstance(d[k], dict) and isinstance(v, dict):
            merge_dicts(d[k], v)
        else:
            d[k] = v
    return d

try:
    with open("translations/messages.en.json", "r") as f:
        content = f.read()
    
    data = json.loads(content, object_pairs_hook=handle_duplicates)
    
    with open("translations/messages.en.json", "w") as f:
        json.dump(data, f, indent=4, sort_keys=True, ensure_ascii=False)
    print("Successfully consolidated translations/messages.en.json")
except Exception as e:
    print(f"Error: {e}")
