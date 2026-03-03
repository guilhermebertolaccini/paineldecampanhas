import os

file_path = r'd:\paineldecampanhascerto\painel-campanhas-install-2\painel-campanhas.php'

with open(file_path, 'r', encoding='utf-8') as f:
    lines = f.readlines()

# Find the closing brace of Painel_Campanhas before the internal classes
brace_idx = -1
for i, line in enumerate(lines):
    if line.strip() == '// ========== CLASSES INTERNAS - Funcionalidades do Campaign Manager ==========':
        brace_idx = i - 2  # The '}' is typically 2 lines above
        break

if brace_idx == -1 or lines[brace_idx].strip() != '}':
    print("Could not find the exact split point.")
    exit(1)

# Find where the AJAX method starts (which should be inside Painel_Campanhas)
ajax_start_idx = -1
for i in range(brace_idx, len(lines)):
    if 'public function handle_run_salesforce_import()' in lines[i]:
        # Backtrack to the comment block
        ajax_start_idx = i - 3
        break

if ajax_start_idx == -1:
    print("Could not find handle_run_salesforce_import.")
    exit(1)

# Find the final closing brace of the file before register_activation_hook
final_brace_idx = -1
for i in range(ajax_start_idx, len(lines)):
    if 'register_activation_hook(__FILE__' in lines[i]:
        final_brace_idx = i - 3
        break

if final_brace_idx == -1:
    print("Could not find register_activation_hook.")
    exit(1)

# So the structure currently is:
# [0 : brace_idx] = Painel_Campanhas part 1
# [brace_idx] = '}' (wrongly closing Painel_Campanhas)
# [brace_idx+1 : ajax_start_idx] = Internal classes (missing final '}')
# [ajax_start_idx : final_brace_idx] = Painel_Campanhas part 2 (methods)
# [final_brace_idx] = '}' (wrongly closing PC_IDGIS_Mapper)
# [final_brace_idx+1 :] = Footer

part1 = lines[:brace_idx]
internal_classes = lines[brace_idx+1:ajax_start_idx]
# Fix internal classes by adding a closing brace
internal_classes.append("}\n\n")

part2 = lines[ajax_start_idx:final_brace_idx]
# Part 2 should be closed by exactly one brace
part2.append("}\n\n")

footer = lines[final_brace_idx+1:]

# Reassemble: Part 1 + Part 2 + Internal Classes + Footer
new_content = part1 + part2 + internal_classes + footer

with open(file_path, 'w', encoding='utf-8') as f:
    f.writelines(new_content)

print("File structure fixed successfully!")
