#!/usr/bin/env python3
import os
import zipfile
from pathlib import Path


def should_exclude(path):
    """Check if a path should be excluded from the ZIP"""
    # Normalize path separators to forward slashes for matching
    path = path.replace('\\', '/')
    
    exclude_patterns = [
        'react/node_modules',
        'react/src',
        'react/package.json',
        'react/package-lock.json',
        'react/.gitignore',
        'react/tsconfig',
        'react/vite.config.ts',
        'react/postcss.config.js',
        'react/tailwind.config',
        'react/components.json',
        'react/eslint.config.js',
        'react/public',
        'react/.vite',
        '.cursor',
        '__pycache__',
        '.git/',
        '.DS_Store',
        'build-plugin.sh',
        'build.ps1',
        'VERIFICAR_CABECALHO.php',
        'debug-routes.php',
        'flush-routes.php',
        'react-wrapper-debug.php'
    ]

    for pattern in exclude_patterns:
        # Check if the pattern is in the path
        # Use simple string matching or better path matching
        if pattern in path:
            return True
            
    return False

def create_plugin_zip():
    # Use paths relative to the script location
    script_dir = Path(__file__).parent.resolve()
    source_dir = script_dir / 'painel-campanhas-install-2'
    output_file = script_dir / 'painel-campanhas-filtro-corrigido.zip'

    print(f"Source: {source_dir}")
    print(f"Output: {output_file}")

    # Remove old file if exists
    if output_file.exists():
        output_file.unlink()

    with zipfile.ZipFile(output_file, 'w', zipfile.ZIP_DEFLATED) as zipf:
        for root, dirs, files in os.walk(source_dir):
            rel_path_from_source = os.path.relpath(root, source_dir)
            arcname_root = os.path.join('painel-campanhas', rel_path_from_source)

            # Check exclusions on the directory itself (optimization)
            # We check rel_path_from_source because our exclusion patterns are relative to the plugin root (e.g. react/node_modules)
            # But the 'exclude_patterns' in should_exclude seem to expect paths like 'painel-campanhas-install-2/...' or just be flexible?
            # Let's look at should_exclude. It checks "if pattern in path".
            # The previous code passed "painel-campanhas-install-2/..."
            # Let's pass the same structure to be safe, OR fix should_exclude. 
            # Actually, standardizing on the new arcname is better.
            
            if should_exclude(rel_path_from_source):
                # Modify dirs in-place to skip traversing excluded directories
                dirs[:] = []
                continue

            for file in files:
                file_path = os.path.join(root, file)
                # arcname should be painel-campanhas/path/to/file
                arcname = os.path.join(arcname_root, file)

                # Skip excluded files
                if should_exclude(arcname):
                    continue

                # print(f"Adding: {arcname}")
                zipf.write(file_path, arcname)

    # Get file size
    if output_file.exists():
        size_mb = output_file.stat().st_size / (1024 * 1024)
        print(f"Created: {output_file.name}")
        print(f"Size: {size_mb:.2f} MB")
    else:
        print("Error: Zip file was not created.")

if __name__ == '__main__':
    create_plugin_zip()
