import os
import re

def process_file(file_path):
    with open(file_path, 'r') as f:
        content = f.read()
    
    original_content = content
    
    # --- 1. Container Standardization ---
    content = content.replace('rams-container', 'rams-page')
    
    # --- 2. Button Legacy Fixes ---
    # Convert 'rams-btn-primary' (single dash) to 'rams-btn rams-btn--primary'
    content = re.sub(r'rams-btn-primary\b', 'rams-btn rams-btn--primary', content)
    # Convert 'rams-btn-secondary' to just 'rams-btn' (default is usually secondary/neutral)
    content = re.sub(r'rams-btn-secondary\b', 'rams-btn', content)
    # Remove double 'rams-btn rams-btn'
    content = content.replace('rams-btn rams-btn', 'rams-btn')
    
    # --- 3. Text Utilities ---
    # Map legacy text-X to rams-text--X (double dash for modifiers)
    # text-muted, text-center, text-left, text-right, text-success, text-danger, text-warning, text-info
    # Also handle single dash rams-text-muted -> rams-text--muted
    
    replacements = {
        r'\btext-muted\b': 'rams-text--muted',
        r'\btext-center\b': 'rams-text--center',
        r'\btext-left\b': 'rams-text--left',
        r'\btext-right\b': 'rams-text--right',
        r'\btext-success\b': 'rams-text--success',
        r'\btext-danger\b': 'rams-text--danger',
        r'\btext-warning\b': 'rams-text--warning',
        r'\btext-info\b': 'rams-text--info',
        r'\btext-xs\b': 'rams-text--xs',
        r'\btext-sm\b': 'rams-text--sm',
        r'\btext-lg\b': 'rams-text--lg',
        r'\btext-xl\b': 'rams-text--xl',
        
        # Consistent double dash
        r'\brams-text-muted\b': 'rams-text--muted',
        r'\brams-text-center\b': 'rams-text--center',
        r'\brams-text-left\b': 'rams-text--left',
        r'\brams-text-right\b': 'rams-text--right',
        r'\brams-text-success\b': 'rams-text--success',
        r'\brams-text-danger\b': 'rams-text--danger',
        r'\brams-text-warning\b': 'rams-text--warning',
        r'\brams-text-info\b': 'rams-text--info',
        
        # Legacy bold
        r'\bfont-bold\b': 'rams-text--bold',
        r'\brams-font-bold\b': 'rams-text--bold', # Assuming rams-font-bold isn't valid, checking implied
    }
    
    for pattern, replacement in replacements.items():
        content = re.sub(pattern, replacement, content)

    # --- 4. Spacing Utilities ---
    # Map m-X, p-X to rams-m-X, rams-p-X
    # Regex for m, p, mt, mb, ml, mr, mx, my, pt, pb, pl, pr, px, py followed by -number
    # Be careful not to replace existing rams- classes. 
    # Look for NOT preceded by "rams-"
    
    # Margin
    content = re.sub(r'(?<!rams-)\b(m|mt|mb|ml|mr|mx|my)-(\d+)\b', r'rams-\1-\2', content)
    # Padding
    content = re.sub(r'(?<!rams-)\b(p|pt|pb|pl|pr|px|py)-(\d+)\b', r'rams-\1-\2', content)
    # Gap
    content = re.sub(r'(?<!rams-)\bgap-(\d+)\b', r'rams-gap-\1', content)
    
    # --- 5. Flex Utilities ---
    content = re.sub(r'(?<!rams-)\bd-flex\b', 'rams-flex', content)
    content = re.sub(r'(?<!rams-)\bflex-col\b', 'rams-flex-col', content) # Tailwind
    content = re.sub(r'(?<!rams-)\bflex-column\b', 'rams-flex-col', content) # Bootstrap
    content = re.sub(r'(?<!rams-)\bflex-row\b', 'rams-flex-row', content)
    content = re.sub(r'(?<!rams-)\bflex-wrap\b', 'rams-flex-wrap', content)
    content = re.sub(r'(?<!rams-)\bitems-center\b', 'rams-items-center', content)
    content = re.sub(r'(?<!rams-)\bitems-start\b', 'rams-items-start', content)
    content = re.sub(r'(?<!rams-)\bitems-end\b', 'rams-items-end', content)
    content = re.sub(r'(?<!rams-)\bjustify-center\b', 'rams-justify-center', content)
    content = re.sub(r'(?<!rams-)\bjustify-between\b', 'rams-justify-between', content)
    content = re.sub(r'(?<!rams-)\bjustify-start\b', 'rams-justify-start', content)
    content = re.sub(r'(?<!rams-)\bjustify-end\b', 'rams-justify-end', content)
    
    # --- 6. Sizing/Border ---
    content = re.sub(r'(?<!rams-)\bw-full\b', 'rams-w-full', content)
    content = re.sub(r'(?<!rams-)\bw-auto\b', 'rams-w-auto', content)
    content = re.sub(r'(?<!rams-)\brounded\b', 'rams-rounded', content)
    content = re.sub(r'(?<!rams-)\brounded-lg\b', 'rams-rounded-lg', content)
    content = re.sub(r'(?<!rams-)\bborder\b', 'rams-border', content)
    content = re.sub(r'(?<!rams-)\bborder-b\b', 'rams-border-b', content)
    content = re.sub(r'(?<!rams-)\bborder-t\b', 'rams-border-t', content)

    # --- 7. Specific Fixes ---
    # rams-list-item -> rams-list__item (Assuming usage in context of list structure, but avoiding if standalone component exists. grep showed both. safer to keep existing if unsure, but typically BEM prefers underscores for elements)
    # Actually, previous grep showed rams-list-item definition in CSS. So it IS valid. Skipping.
    
    # rams-stat-card -> rams-metric (if stat card is visually just a metric) 
    # CSS showed rams-stat-card exists. Skipping.
    
    if content != original_content:
        with open(file_path, 'w') as f:
            f.write(content)
        return True
    return False

root_dir = 'templates'
count = 0
for root, dirs, files in os.walk(root_dir):
    for file in files:
        if file.endswith('.html.twig'):
            if process_file(os.path.join(root, file)):
                count += 1
                
print(f"Refactored {count} files.")
