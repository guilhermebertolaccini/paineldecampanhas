import { Module } from '@nestjs/common';
import { HttpModule } from '@nestjs/axios';
import { SalesforceProvider } from './salesforce.provider';

@Module({
  imports: [HttpModule],
  providers: [SalesforceProvider],
  exports: [SalesforceProvider],
})
export class SalesforceModule {}

