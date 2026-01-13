#!/bin/bash
# Script para criar build completo do plugin WordPress Painel de Campanhas

set -e

echo "🔨 Iniciando build do plugin Painel de Campanhas..."

# Diretório base do script
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# 1. Build da aplicação React
echo ""
echo "📦 Step 1/3: Building React application..."
cd react

if [ ! -d "node_modules" ]; then
    echo "⚠️  node_modules não encontrado. Instalando dependências..."
    npm install --legacy-peer-deps
fi

echo "🔨 Executando build do React..."
npm run build

echo "✅ Build do React concluído!"

# 2. Voltar para diretório do plugin
cd ..

# 3. Criar arquivo ZIP do plugin
echo ""
echo "📦 Step 2/3: Criando arquivo ZIP do plugin..."

# Nome do arquivo zip
ZIP_NAME="painel-campanhas-install-2.zip"
PARENT_DIR="$(dirname "$SCRIPT_DIR")"

# Remove zip antigo se existir
if [ -f "$PARENT_DIR/$ZIP_NAME" ]; then
    rm "$PARENT_DIR/$ZIP_NAME"
    echo "🗑️  Removido ZIP antigo"
fi

# Cria o ZIP excluindo arquivos desnecessários
cd "$PARENT_DIR"
zip -r "$ZIP_NAME" painel-campanhas-install-2 \
    -x "*/node_modules/*" \
    -x "*/.git/*" \
    -x "*/.gitignore" \
    -x "*/react/src/*" \
    -x "*/react/.vite/*" \
    -x "*/react/public/*" \
    -x "*/react/tsconfig*.json" \
    -x "*/react/vite.config.ts" \
    -x "*/react/postcss.config.js" \
    -x "*/react/tailwind.config.ts" \
    -x "*/react/eslint.config.js" \
    -x "*/react/package*.json" \
    -x "*/react/README.md" \
    -x "*/react/components.json" \
    -x "*/build-plugin.sh" \
    -x "*/.cursor/*" \
    -x "*/VERIFICAR_CABECALHO.php" \
    -x "*/debug-routes.php" \
    -x "*/flush-routes.php" \
    -x "*/react-wrapper-debug.php"

echo "✅ Arquivo ZIP criado: $ZIP_NAME"

# 4. Mostrar informações
echo ""
echo "📦 Step 3/3: Informações do build"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📁 Arquivo: $PARENT_DIR/$ZIP_NAME"
echo "📊 Tamanho: $(du -h "$ZIP_NAME" | cut -f1)"
echo ""
echo "✅ Build completo!"
echo ""
echo "📋 Próximos passos:"
echo "   1. Faça upload do arquivo $ZIP_NAME no WordPress"
echo "   2. Vá em Plugins > Adicionar novo > Enviar plugin"
echo "   3. Selecione o arquivo e clique em 'Instalar agora'"
echo "   4. Ative o plugin"
echo ""
echo "🎉 Pronto! Seu plugin está pronto para instalação!"
