import { Module } from '@nestjs/common';
import { HttpModule } from '@nestjs/axios';
import { GosacOficialProvider } from './gosac-oficial.provider';

@Module({
    imports: [HttpModule],
    providers: [GosacOficialProvider],
    exports: [GosacOficialProvider],
})
export class GosacOficialModule { }
