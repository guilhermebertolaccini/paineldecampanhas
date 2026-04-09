import { Module } from '@nestjs/common';
import { HttpModule } from '@nestjs/axios';
import { WpSyncService } from './wp-sync.service';
import { WpSyncController } from './wp-sync.controller';

@Module({
  imports: [HttpModule],
  controllers: [WpSyncController],
  providers: [WpSyncService],
})
export class WpSyncModule {}
