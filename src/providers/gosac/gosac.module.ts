import { Module } from '@nestjs/common';
import { HttpModule } from '@nestjs/axios';
import { GosacProvider } from './gosac.provider';

@Module({
  imports: [HttpModule],
  providers: [GosacProvider],
  exports: [GosacProvider],
})
export class GosacModule {}

