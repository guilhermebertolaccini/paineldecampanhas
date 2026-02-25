import re

try:
    with open(r'd:\vivaldi\wp_digit_db1.sql', 'r', encoding='utf-8', errors='ignore') as f:
        text = f.read()

    # Find the block where wp_options is inserted
    options_blocks = re.findall(r"INSERT INTO `?wp_options`?.*?VALUES\s*(.*?;)", text, re.IGNORECASE | re.DOTALL)
    
    tuples = []
    
    for block in options_blocks:
        # Split block into tuples roughly. 
        # A tuple starts with ( and ends with ) followed by , or ;
        # This is hard because of strings containing commas.
        pass
        
    # Let's just find any match of ('something_with_fornecedor_or_credenciais', 'value', 'yes/no') in the whole text
    matches = re.finditer(r"\(\d+\s*,\s*'([^']*(?:fornecedor|credencia|token|api)[^']*)'\s*,\s*'(.*?)'\s*,\s*'((?:yes|no|on|off))'\)", text, re.IGNORECASE)
    
    for m in matches:
        print(f"Found option: {m.group(1)}")
        
except Exception as e:
    print("Error:", e)
