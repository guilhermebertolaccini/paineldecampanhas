import re

try:
    with open(r'd:\vivaldi\wp_digit_db1.sql', 'r', encoding='utf-8', errors='ignore') as f:
        text = f.read()

    # Find the acm_provider_credentials tuple
    # It starts with (something, 'acm_provider_credentials', 
    # and ends with 'yes'), or 'no'), or 'auto'),
    # Because serialized data might have commas and quotes, we'll try to find the full tuple
    match = re.search(r"(\(\d+,\s*'acm_provider_credentials',\s*'.*?',\s*'(?:yes|no|auto)'\))", text, re.DOTALL)
    
    if match:
        tuple_text = match.group(1)
        insert_stmt = f"INSERT INTO `wp_options` (`option_id`, `option_name`, `option_value`, `autoload`) VALUES\n{tuple_text};\n"
        with open(r'd:\paineldecampanhascerto\insert_fornecedores.sql', 'w', encoding='utf-8') as out:
            out.write(insert_stmt)
        print("Successfully created d:\\paineldecampanhascerto\\insert_fornecedores.sql")
    else:
        print("Could not find the exact tuple via regex.")
        
    match2 = re.search(r"(\(\d+,\s*'acm_static_credentials',\s*'.*?',\s*'(?:yes|no|auto)'\))", text, re.DOTALL)
    if match2:
        tuple_text2 = match2.group(1)
        insert_stmt2 = f"INSERT INTO `wp_options` (`option_id`, `option_name`, `option_value`, `autoload`) VALUES\n{tuple_text2};\n"
        with open(r'd:\paineldecampanhascerto\insert_fornecedores.sql', 'a', encoding='utf-8') as out:
            out.write(insert_stmt2)
        print("Successfully appended acm_static_credentials")

    match3 = re.search(r"(\(\d+,\s*'acm_custom_providers',\s*'.*?',\s*'(?:yes|no|auto)'\))", text, re.DOTALL)
    if match3:
        tuple_text3 = match3.group(1)
        insert_stmt3 = f"INSERT INTO `wp_options` (`option_id`, `option_name`, `option_value`, `autoload`) VALUES\n{tuple_text3};\n"
        with open(r'd:\paineldecampanhascerto\insert_fornecedores.sql', 'a', encoding='utf-8') as out:
            out.write(insert_stmt3)
        print("Successfully appended acm_custom_providers")

except Exception as e:
    print("Error:", e)
