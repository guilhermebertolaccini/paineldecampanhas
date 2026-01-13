import 'dotenv/config';
import { defineConfig } from '@prisma/config';

// Constrói DATABASE_URL se não estiver definida
const getDatabaseUrl = () => {
  if (process.env.DATABASE_URL) {
    return process.env.DATABASE_URL;
  }
  
  const user = process.env.DATABASE_USER || 'midpainel';
  const password = process.env.DATABASE_PASSWORD || '';
  const host = process.env.DATABASE_HOST || 'postgres';
  const port = process.env.DATABASE_PORT || '5432';
  const database = process.env.DATABASE_NAME || 'midpainel';
  
  return `postgresql://${user}:${password}@${host}:${port}/${database}?schema=public`;
};

export default defineConfig({
  schema: 'prisma/schema.prisma',
  datasource: {
    url: getDatabaseUrl(),
  },
});
