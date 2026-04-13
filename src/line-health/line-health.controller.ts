import { Controller, Get, Res, UseGuards } from '@nestjs/common';
import type { Response } from 'express';
import { ApiKeyGuard } from '../common/guards/api-key.guard';
import { LineHealthService } from './line-health.service';

@Controller('api/v1/health')
@UseGuards(ApiKeyGuard)
export class LineHealthController {
  constructor(private readonly lineHealthService: LineHealthService) {}

  /**
   * Mesma rotina do cron 06:00 (probes + TB_SAUDE_LINHAS + POST opcional + PC_LINE_HEALTH_SNAPSHOT).
   * Não exige LINE_HEALTH_CRON_ENABLED=true (uso operacional / teste).
   */
  @Get('force-sync')
  async forceSync() {
    return this.lineHealthService.forceSyncLineHealth();
  }

  /**
   * Download CSV do snapshot de saúde das linhas (MSSQL `PC_LINE_HEALTH_SNAPSHOT` + `metricas_json`).
   * Cabeçalho `X-API-KEY` obrigatório (mesmo guard das demais rotas).
   */
  @Get('export/csv')
  async exportCsv(@Res() res: Response): Promise<void> {
    const csvData = await this.lineHealthService.buildLineHealthCsvExport();
    res.setHeader('Content-Type', 'text/csv; charset=utf-8');
    res.setHeader(
      'Content-Disposition',
      `attachment; filename="saude_das_linhas_${Date.now()}.csv"`,
    );
    res.send(csvData);
  }
}
