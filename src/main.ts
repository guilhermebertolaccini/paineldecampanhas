import { NestFactory } from '@nestjs/core';
import { ValidationPipe } from '@nestjs/common';
import { AppModule } from './app.module';

/** Origem padrão do SPA em produção (use CORS_ORIGIN no .env para múltiplas origens ou dev local). */
const DEFAULT_CORS_ORIGIN =
  'https://paneldecampanhas.taticamarketing.com.br';

function resolveCorsOrigins(): string | string[] {
  const raw = process.env.CORS_ORIGIN?.trim();
  if (raw) {
    const list = raw.split(',').map((o) => o.trim()).filter(Boolean);
    return list.length === 1 ? list[0] : list;
  }
  return DEFAULT_CORS_ORIGIN;
}

async function bootstrap() {
  const app = await NestFactory.create(AppModule);
  
  // Global validation pipe
  app.useGlobalPipes(
    new ValidationPipe({
      whitelist: true,
      forbidNonWhitelisted: true,
      transform: true,
    }),
  );

  // CORS: nunca usar '*' com credentials: true; exige CORS_ORIGIN ou fallback explícito
  app.enableCors({
    origin: resolveCorsOrigins(),
    credentials: true,
  });

  const port = process.env.PORT || 3000;
  await app.listen(port);
  
  console.log(`🚀 Application is running on: http://localhost:${port}`);
}
bootstrap();
