import { Module } from '@nestjs/common';
import { HttpModule } from '@nestjs/axios';
import { RobbOficialProvider } from './robbu-oficial.provider';

@Module({
  imports: [HttpModule],
  providers: [RobbOficialProvider],
  exports: [RobbOficialProvider],
})
export class RobbOficialModule {}
