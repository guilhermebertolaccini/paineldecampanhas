import { Controller, Get, Logger, UseGuards } from '@nestjs/common';
import { ApiKeyGuard } from '../../common/guards/api-key.guard';
import { OtimaSftpSyncService } from './otima-sftp-sync.service';

/**
 * Gatilho manual do cronjob Ótima SFTP.
 * Proteção: `X-API-KEY` (mesma WORDPRESS_API_KEY dos outros health endpoints).
 */
@Controller('api/v1/health')
@UseGuards(ApiKeyGuard)
export class OtimaSftpSyncController {
  private readonly logger = new Logger(OtimaSftpSyncController.name);

  constructor(private readonly otima: OtimaSftpSyncService) {}

  /**
   * GET /api/v1/health/sync-otima
   * Executa o pipeline SFTP → XLSX → De-Para → Prisma imediatamente, ignorando o cron.
   * Retorna o sumário detalhado (arquivos lidos, linhas processadas, erros, dry-run do delete).
   */
  @Get('sync-otima')
  async syncOtima() {
    this.logger.log('[TRIGGER] GET /api/v1/health/sync-otima chamado — executando runOnce().');
    try {
      const result = await this.otima.runOnce();
      return {
        ok: true,
        message: 'Sync Ótima SFTP executado.',
        ...result,
      };
    } catch (e) {
      const err = e instanceof Error ? e : new Error(String(e));
      this.logger.error(
        `[TRIGGER] Falha ao executar runOnce(): ${err.message}`,
        err.stack,
      );
      return {
        ok: false,
        message: `Sync Ótima SFTP FALHOU: ${err.message}`,
        stack: err.stack,
      };
    }
  }

  /**
   * GET /api/v1/health/otima/lines
   * API de consumo interno — serve como "fonte da verdade" sobre as linhas da Ótima
   * para o WordPress (equivalente a chamar a API oficial de um provedor).
   */
  @Get('otima/lines')
  async listOtimaLines() {
    const items = await this.otima.listLines();
    return {
      ok: true,
      count: items.length,
      items,
    };
  }
}
