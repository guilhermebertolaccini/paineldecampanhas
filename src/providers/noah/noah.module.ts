import { Module } from '@nestjs/common';
import { HttpModule } from '@nestjs/axios';
import { NoahProvider } from './noah.provider';

@Module({
  imports: [HttpModule],
  providers: [NoahProvider],
  exports: [NoahProvider],
})
export class NoahModule {}

