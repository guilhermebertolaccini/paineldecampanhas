import { Injectable, CanActivate, ExecutionContext, UnauthorizedException } from '@nestjs/common';
import { Observable } from 'rxjs';
import { wordpressConfig } from '../../config/wordpress.config';

@Injectable()
export class ApiKeyGuard implements CanActivate {
  canActivate(context: ExecutionContext): boolean | Promise<boolean> | Observable<boolean> {
    const request = context.switchToHttp().getRequest();
    const apiKey = request.headers['x-api-key'];

    if (!apiKey) {
      throw new UnauthorizedException('API Key não fornecida');
    }

    if (apiKey !== wordpressConfig.apiKey) {
      throw new UnauthorizedException('API Key inválida');
    }

    return true;
  }
}

