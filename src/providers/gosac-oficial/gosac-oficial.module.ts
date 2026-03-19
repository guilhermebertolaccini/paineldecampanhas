import { Module } from '@nestjs/common';
import { HttpModule } from '@nestjs/axios';
import * as https from 'https';
import { GosacOficialProvider } from './gosac-oficial.provider';

const gosacHttpsAgent = new https.Agent({ rejectUnauthorized: false });

@Module({
    imports: [
        HttpModule.register({
            httpsAgent: gosacHttpsAgent,
            timeout: 60000,
        }),
    ],
    providers: [GosacOficialProvider],
    exports: [GosacOficialProvider],
})
export class GosacOficialModule { }
