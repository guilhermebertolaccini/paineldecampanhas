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
        '.cursor',
        '__pycache__',
        '.git/',
        '.DS_Store'
    ]

    for pattern in exclude_patterns:
        if pattern in path:
            return True
    return False

def create_plugin_zip():
    # Use paths relative to the script location
    script_dir = Path(__file__).parent.resolve()
    source_dir = script_dir / 'painel-campanhas-install-2'
    output_file = script_dir / 'painel-campanhas-CORRIGIDO-FINAL.zip'

    print(f"Source: {source_dir}")
    print(f"Output: {output_file}")

    # Remove old file if exists
    if output_file.exists():
        output_file.unlink()

    with zipfile.ZipFile(output_file, 'w', zipfile.ZIP_DEFLATED) as zipf:
        for root, dirs, files in os.walk(source_dir):
            # Calculate relative path from the parent of source_dir so the zip root isn't empty
            # We want the zip to contain a folder "painel-campanhas-install-2" (or rename it to proper slug)
            # Typically WP plugins zip contains "slug-name/files".
            
            # rel_path_from_source = os.path.relpath(root, source_dir)
            # arcname_root = os.path.join(source_dir.name, rel_path_from_source)
            
            # Actually easier: relative to source_dir's parent
            rel_path = os.path.relpath(root, script_dir)
            
            # Check exclusions on the directory itself (optimization)
            if should_exclude(rel_path):
                # Modify dirs in-place to skip traversing excluded directories
                dirs[:] = []
                continue

            for file in files:
                file_path = os.path.join(root, file)
                arcname = os.path.join(rel_path, file)

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
