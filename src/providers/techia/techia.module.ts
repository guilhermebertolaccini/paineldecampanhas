import { Module } from '@nestjs/common';
import { HttpModule } from '@nestjs/axios';
import { TechiaProvider } from './techia.provider';

@Module({
  imports: [HttpModule],
  providers: [TechiaProvider],
  exports: [TechiaProvider],
})
export class TechiaModule {}
