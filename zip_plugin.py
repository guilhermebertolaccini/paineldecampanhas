import os
import zipfile
import shutil

source_dir = r"d:\paineldecampanhascerto\painel-campanhas-install-2"
output_filename = r"d:\paineldecampanhascerto\painel-campanhas-install-2.zip"
temp_dir = r"d:\paineldecampanhascerto\temp_zip_build"
plugin_folder_name = "painel-campanhas-install-2"

exclusions = [
    'node_modules', '.git', '.gitignore', 'react/src', 'react/.vite', 'react/public', 
    'react/tsconfig.json', 'react/tsconfig.node.json', 'react/vite.config.ts', 
    'react/postcss.config.js', 'react/tailwind.config.ts', 'react/eslint.config.js', 
    'react/package.json', 'react/package-lock.json', 'react/README.md', 
    'react/components.json', 'build-plugin.sh', '.cursor', 'VERIFICAR_CABECALHO.php', 
    'debug-routes.php', 'flush-routes.php', 'react-wrapper-debug.php'
]

def create_zip():
    print(f"🔨 Starting build for {output_filename}...")
    
    if os.path.exists(temp_dir):
        print(f"🗑️ Removing old temp dir {temp_dir}...")
        shutil.rmtree(temp_dir)
    
    if os.path.exists(output_filename):
        print(f"🗑️ Removing old zip {output_filename}...")
        os.remove(output_filename)
        
    os.makedirs(temp_dir)
    target_path = os.path.join(temp_dir, plugin_folder_name)
    
    print(f"📦 Copying files to {target_path}...")
    
    def ignore_files(dir, files):
        rel_path = os.path.relpath(dir, source_dir)
        ignored = []
        for f in files:
            path = os.path.join(rel_path, f).replace('\\', '/')
            if path in exclusions or f in exclusions:
                ignored.append(f)
        return ignored

    shutil.copytree(source_dir, target_path, ignore=ignore_files)
    
    print(f"🗜️ Creating ZIP file...")
    with zipfile.ZipFile(output_filename, 'w', zipfile.ZIP_DEFLATED) as zipf:
        for root, dirs, files in os.walk(target_path):
            for file in files:
                file_path = os.path.join(root, file)
                arcname = os.path.relpath(file_path, temp_dir)
                zipf.write(file_path, arcname)
                
    print(f"✨ Cleaning up...")
    shutil.rmtree(temp_dir)
    
    if os.path.exists(output_filename):
        size = os.path.getsize(output_filename) / (1024 * 1024)
        print(f"✅ Success! Created {output_filename} ({size:.2f} MB)")
    else:
        print(f"❌ Failed to create {output_filename}")

if __name__ == "__main__":
    create_zip()
