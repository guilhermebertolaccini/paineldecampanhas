import {
  BadRequestException,
  Controller,
  Get,
  UseGuards,
} from '@nestjs/common';
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
}
