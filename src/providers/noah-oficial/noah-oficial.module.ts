import { Module } from '@nestjs/common';
import { HttpModule } from '@nestjs/axios';
import { NoahOficialProvider } from './noah-oficial.provider';

@Module({
  imports: [HttpModule],
  providers: [NoahOficialProvider],
  exports: [NoahOficialProvider],
})
export class NoahOficialModule {}
