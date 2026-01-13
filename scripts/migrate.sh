#!/bin/sh
set -e

echo "🔄 Executando migrações do Prisma..."

# Aguarda o PostgreSQL estar pronto
until pg_isready -h "${DATABASE_HOST:-postgres}" -p "${DATABASE_PORT:-5432}" -U "${DATABASE_USER:-midpainel}"; do
  echo "⏳ Aguardando PostgreSQL..."
  sleep 2
done

echo "✅ PostgreSQL está pronto!"

# Constrói a DATABASE_URL se não estiver definida
if [ -z "$DATABASE_URL" ]; then
  export DATABASE_URL="postgresql://${DATABASE_USER:-midpainel}:${DATABASE_PASSWORD}@${DATABASE_HOST:-postgres}:${DATABASE_PORT:-5432}/${DATABASE_NAME:-midpainel}?schema=public"
fi

echo "📊 Conectando ao banco: ${DATABASE_HOST:-postgres}:${DATABASE_PORT:-5432}/${DATABASE_NAME:-midpainel}"
echo "🔐 Usuário: ${DATABASE_USER:-midpainel}"

# Verifica se há migrações na pasta migrations
if [ -d "prisma/migrations" ] && [ "$(ls -A prisma/migrations 2>/dev/null)" ]; then
  echo "📦 Migrações encontradas, aplicando..."
  npx prisma migrate deploy || {
    echo "❌ Erro ao aplicar migrações. Tentando db push..."
    npx prisma db push --accept-data-loss || {
      echo "❌ Erro ao criar schema. Verifique as credenciais do banco de dados."
      echo "💡 DATABASE_URL: ${DATABASE_URL}"
      exit 1
    }
  }
  echo "✅ Migrações aplicadas com sucesso!"
else
  echo "⚠️  Nenhuma migração encontrada, criando schema inicial..."
  # Se não houver migrações, usa db push para criar o schema
  npx prisma db push --accept-data-loss || {
    echo "❌ Erro ao criar schema. Verifique as credenciais do banco de dados."
    echo "💡 DATABASE_URL: ${DATABASE_URL}"
    exit 1
  }
  echo "✅ Schema criado com sucesso!"
fi

echo "✅ Migrações concluídas!"

