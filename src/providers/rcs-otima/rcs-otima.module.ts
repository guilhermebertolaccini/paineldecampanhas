import { Module } from '@nestjs/common';
import { HttpModule } from '@nestjs/axios';
import { RcsOtimaProvider } from './rcs-otima.provider';

@Module({
  imports: [HttpModule],
  providers: [RcsOtimaProvider],
  exports: [RcsOtimaProvider],
})
export class RcsOtimaModule {}
