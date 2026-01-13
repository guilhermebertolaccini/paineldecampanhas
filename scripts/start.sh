#!/bin/sh
set -e

echo "🚀 Iniciando aplicação..."

# Gera Prisma Client (necessário pois não foi gerado no build)
echo "📦 Gerando Prisma Client..."
npx prisma generate

# Executa migrações
./scripts/migrate.sh

# Inicia a aplicação
exec node dist/src/main

