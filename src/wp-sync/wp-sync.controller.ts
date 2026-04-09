import { Controller, Get, UseGuards } from '@nestjs/common';
import { ApiKeyGuard } from '../common/guards/api-key.guard';
import { WpSyncService } from './wp-sync.service';

@Controller('api/v1/sync')
@UseGuards(ApiKeyGuard)
export class WpSyncController {
  constructor(private readonly wpSyncService: WpSyncService) {}

  /**
   * Dispara ingestão paginada: WP `/wp-json/pc/v1/relatorios/envios_pendentes` → MSSQL `PC_WP_ENVIOS_PENDENTES_RAW`.
   */
  @Get('wp-pending-sends')
  async syncWpPendingSends() {
    return this.wpSyncService.runFullPendingSendsSync();
  }
}
