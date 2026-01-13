import { Module } from '@nestjs/common';
import { HttpModule } from '@nestjs/axios';
import { RcsProvider } from './rcs.provider';

@Module({
  imports: [HttpModule],
  providers: [RcsProvider],
  exports: [RcsProvider],
})
export class RcsModule {}

