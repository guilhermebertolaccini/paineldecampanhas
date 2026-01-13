import { Module } from '@nestjs/common';
import { HttpModule } from '@nestjs/axios';
import { CdaProvider } from './cda.provider';

@Module({
  imports: [HttpModule],
  providers: [CdaProvider],
  exports: [CdaProvider],
})
export class CdaModule {}

