import {
  Body,
  Controller,
  HttpCode,
  HttpStatus,
  Logger,
  Post,
  Req,
  UnauthorizedException,
  UsePipes,
  ValidationPipe,
} from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import type { Request } from 'express';
import { DigitalFunnelMssqlService } from '../sql-server/digital-funnel-mssql.service';

/**
 * POST /webhooks/salesforce/tracking
 * Body: objeto plano com chaves do tracking (case-insensitive).
 * Opcional: Authorization: Bearer <SALESFORCE_TRACKING_WEBHOOK_SECRET>
 */
@Controller('webhooks/salesforce')
@UsePipes(
  new ValidationPipe({
    whitelist: false,
    forbidNonWhitelisted: false,
    transform: false,
  }),
)
export class SalesforceTrackingWebhookController {
  private readonly logger = new Logger(SalesforceTrackingWebhookController.name);

  constructor(
    private readonly digitalFunnel: DigitalFunnelMssqlService,
    private readonly config: ConfigService,
  ) {}

  @Post('tracking')
  @HttpCode(HttpStatus.ACCEPTED)
  async receive(
    @Req() req: Request,
    @Body() body: unknown,
  ): Promise<{ accepted: true; persisted: boolean }> {
    if (!this.assertWebhookAuth(req)) {
      throw new UnauthorizedException('Webhook não autorizado');
    }

    const raw =
      body && typeof body === 'object' && !Array.isArray(body)
        ? (body as Record<string, unknown>)
        : {};

    try {
      const persisted =
        await this.digitalFunnel.insertSalesforceTrackingFromPayload(raw);
      return { accepted: true, persisted };
    } catch (e) {
      this.logger.error(
        `Salesforce tracking: erro inesperado antes da resposta: ${e}`,
      );
      return { accepted: true, persisted: false };
    }
  }

  private assertWebhookAuth(req: Request): boolean {
    const secret = this.config
      .get<string>('SALESFORCE_TRACKING_WEBHOOK_SECRET')
      ?.trim();
    if (!secret) {
      return true;
    }
    const auth = req.headers.authorization;
    return auth === `Bearer ${secret}`;
  }
}
