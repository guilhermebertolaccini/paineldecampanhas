import re

with open(r'd:\vivaldi\wp_digit_db1.sql', 'r', encoding='utf-8', errors='ignore') as f:
    text = f.read()

# Find all option rows inside the wp_options table
# Values usually look like: (id, 'name', 'value', 'autoload')
# We'll regex search for anything with fornecedor or cred
pattern = re.compile(r"\(\d+,\s*'([^']*(?:forneced|credenc|api_key|token|integracao)[^']*)',\s*'(.*?)',\s*'(?:yes|no)'\)", re.IGNORECASE | re.DOTALL)

matches = pattern.findall(text)
for name, value in matches:
    if 'transient' not in name:
        print("Option Found:", name)
