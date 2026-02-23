import { Injectable, CanActivate, ExecutionContext, UnauthorizedException, Logger } from '@nestjs/common';
import { Observable } from 'rxjs';
import { wordpressConfig } from '../../config/wordpress.config';

@Injectable()
export class ApiKeyGuard implements CanActivate {
  private readonly logger = new Logger(ApiKeyGuard.name);

  canActivate(context: ExecutionContext): boolean | Promise<boolean> | Observable<boolean> {
    const request = context.switchToHttp().getRequest();
    const apiKey = request.headers['x-api-key'];

    if (!apiKey) {
      this.logger.warn('❌ API Key não fornecida no header x-api-key');
      this.logger.warn(`Headers recebidos: ${JSON.stringify(Object.keys(request.headers))}`);
      throw new UnauthorizedException('API Key não fornecida');
    }

    const receivedKey = String(apiKey).trim();
    const expectedKey = String(wordpressConfig.apiKey || '').trim();

    if (receivedKey !== expectedKey) {
      const mask = (k: string) => k.length > 8 ? `${k.slice(0, 4)}...${k.slice(-4)}` : `[${k.length} chars]`;
      this.logger.warn(`❌ API Key inválida!`);
      this.logger.warn(`   Recebida: "${mask(receivedKey)}" (len=${receivedKey.length})`);
      this.logger.warn(`   Esperada: "${mask(expectedKey)}" (len=${expectedKey.length})`);
      this.logger.warn(`   WORDPRESS_API_KEY env: ${process.env.WORDPRESS_API_KEY ? 'SET' : 'NOT SET'}`);
      this.logger.warn(`   ACM_MASTER_API_KEY env: ${process.env.ACM_MASTER_API_KEY ? 'SET' : 'NOT SET'}`);
      throw new UnauthorizedException('API Key inválida');
    }

    this.logger.log('✅ API Key válida');
    return true;
  }
}

