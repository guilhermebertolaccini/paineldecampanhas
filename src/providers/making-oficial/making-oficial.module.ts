import { Module } from '@nestjs/common';
import { HttpModule } from '@nestjs/axios';
import { MakingOficialProvider } from './making-oficial.provider';

@Module({
  imports: [HttpModule],
  providers: [MakingOficialProvider],
  exports: [MakingOficialProvider],
})
export class MakingOficialModule {}
