import { Module } from '@nestjs/common';
import { HttpModule } from '@nestjs/axios';
import { WhatsappOtimaProvider } from './whatsapp-otima.provider';

@Module({
  imports: [HttpModule],
  providers: [WhatsappOtimaProvider],
  exports: [WhatsappOtimaProvider],
})
export class WhatsappOtimaModule {}
