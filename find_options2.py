import re

try:
    with open(r'd:\vivaldi\wp_digit_db1.sql', 'r', encoding='utf-8', errors='ignore') as f:
        text = f.read()

    # Find INSERT INTO `wp_options` or wp_options
    options_inserts = re.findall(r"INSERT INTO `?wp_options`?\s*\([^)]+\)\s*VALUES\s*(.*?;)", text, re.IGNORECASE | re.DOTALL)
    
    option_names = []
    
    for insert in options_inserts:
        # Match each tuple (...)
        # The tuple is usually (id, 'name', 'value', 'autoload')
        # We'll just extract the second element which is the name
        matches = re.finditer(r"\(\d+\s*,\s*'([^']+)'", insert)
        for m in matches:
            name = m.group(1)
            if 'transient' not in name and 'wc_session' not in name:
                option_names.append(name)
                
    # write all option names to a file so I can grep them
    with open(r'd:\paineldecampanhascerto\all_options.txt', 'w', encoding='utf-8') as f:
        for name in option_names:
            f.write(name + '\n')
            
    print(f"Found {len(option_names)} non-transient options. Wrote to all_options.txt")
except Exception as e:
    print("Error:", e)
