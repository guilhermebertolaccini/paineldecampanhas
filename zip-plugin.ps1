$source = 'd:\paineldecampanhascerto\painel-campanhas-install-2'
$dest = 'd:\paineldecampanhascerto\painel-campanhas-install-2.zip'
$temp = 'd:\paineldecampanhascerto\temp_zip_build'
$buildPath = Join-Path $temp 'painel-campanhas-install-2'

Write-Host "Iniciando empacotamento do plugin..."

if (Test-Path $temp) { 
    Write-Host "Limpando pasta temporaria..."
    Remove-Item -Recurse -Force $temp 
}
if (Test-Path $dest) { 
    Write-Host "Removendo ZIP antigo..."
    Remove-Item $dest 
}

New-Item -ItemType Directory -Path $buildPath -Force | Out-Null

Write-Host "Copiando arquivos (com exclusoes)..."
# Robocopy para copiar excluindo pastas e arquivos
$robocopyArgs = @(
    $source,
    $buildPath,
    "/E", "/COPY:DAT", "/R:2", "/W:5", "/MT:16", "/NFL", "/NDL",
    "/XD", "node_modules", ".git", "src", ".vite", "public", ".cursor",
    "/XF", ".gitignore", "tsconfig*.json", "vite.config.ts", "postcss.config.js", "tailwind.config.ts", "eslint.config.js", "package*.json", "README.md", "components.json", "build-plugin.sh", "VERIFICAR_CABECALHO.php", "debug-routes.php", "flush-routes.php", "react-wrapper-debug.php"
)

# Nota: src, public etc estao dentro de react. Robocopy /XD busca nomes de pastas relativos.
# Mas para garantir, podemos ser mais especificos ou rodar em etapas.

& robocopy @robocopyArgs

Write-Host "Criando arquivo ZIP..."
$currentDir = Get-Location
Set-Location $temp
Compress-Archive -Path "painel-campanhas-install-2" -DestinationPath $dest
Set-Location $currentDir

Write-Host "Limpando..."
Remove-Item -Recurse -Force $temp

Write-Host "Pronto! Arquivo criado em: $dest"
