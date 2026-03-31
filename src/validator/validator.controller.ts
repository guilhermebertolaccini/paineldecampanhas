import {
  BadRequestException,
  Body,
  Controller,
  Get,
  Headers,
  Param,
  Post,
  Query,
  UploadedFile,
  UseGuards,
  UseInterceptors,
  UsePipes,
  ValidationPipe,
} from '@nestjs/common';
import { FileInterceptor } from '@nestjs/platform-express';
import { ApiKeyGuard } from '../common/guards/api-key.guard';
import { ValidatorService } from './validator.service';
import { StreamableFile } from '@nestjs/common';
import { createReadStream } from 'fs';
import { diskStorage } from 'multer';
import { randomUUID } from 'crypto';
import * as os from 'os';

function parseWpUserId(header: string | undefined, query: string | undefined): number {
  const raw = (header ?? query ?? '').trim();
  const n = parseInt(raw, 10);
  if (!Number.isFinite(n) || n <= 0) {
    throw new BadRequestException('Informe x-wp-user-id (header) ou wp_user_id (query) com ID numérico do usuário WordPress.');
  }
  return n;
}

@Controller('validator')
@UseGuards(ApiKeyGuard)
@UsePipes(
  new ValidationPipe({
    whitelist: true,
    transform: true,
    forbidNonWhitelisted: false,
  }),
)
export class ValidatorController {
  constructor(private readonly validator: ValidatorService) {}

  @Post('upload')
  @UseInterceptors(
    FileInterceptor('file', {
      limits: { fileSize: 80 * 1024 * 1024 },
      storage: diskStorage({
        destination: (_req, _file, cb) => cb(null, os.tmpdir()),
        filename: (_req, file, cb) => {
          const ext = file.originalname.toLowerCase().endsWith('.txt') ? '.txt' : '.csv';
          cb(null, `pc-val-${randomUUID()}${ext}`);
        },
      }),
    }),
  )
  async upload(
    @UploadedFile() file: Express.Multer.File,
    @Body('wp_user_id') wpUserIdBody?: string,
    @Headers('x-wp-user-id') wpUserIdHeader?: string,
  ) {
    if (!file?.path) {
      throw new BadRequestException('Nenhum arquivo enviado (campo file).');
    }
    const lower = file.originalname.toLowerCase();
    if (!lower.endsWith('.csv') && !lower.endsWith('.txt')) {
      throw new BadRequestException('Apenas arquivos .csv ou .txt são permitidos.');
    }
    const wpUserId = parseWpUserId(wpUserIdHeader, wpUserIdBody);

    return this.validator.saveAndProcessUpload(file.path, file.originalname || 'upload.csv', wpUserId);
  }

  @Get('history')
  async history(
    @Headers('x-wp-user-id') wpUserIdHeader?: string,
    @Query('wp_user_id') wpUserIdQuery?: string,
  ) {
    const wpUserId = parseWpUserId(wpUserIdHeader, wpUserIdQuery);
    const itens = await this.validator.listHistory(wpUserId);
    return {
      itens: itens.map((r) => ({
        id: r.id,
        nome_arquivo: r.nomeArquivo,
        total_linhas: r.totalLinhas,
        linhas_validas: r.linhasValidas,
        linhas_invalidas: r.linhasInvalidas,
        data_criacao: r.createdAt.toISOString(),
      })),
    };
  }

  @Get('download/:id/:type')
  async download(
    @Param('id') id: string,
    @Param('type') typeRaw: string,
    @Headers('x-wp-user-id') wpUserIdHeader?: string,
    @Query('wp_user_id') wpUserIdQuery?: string,
  ): Promise<StreamableFile> {
    const t = typeRaw.toLowerCase();
    if (t !== 'original' && t !== 'validated') {
      throw new BadRequestException('type deve ser original ou validated');
    }
    const type = t === 'original' ? 'original' : 'validated';
    const wpUserId = parseWpUserId(wpUserIdHeader, wpUserIdQuery);
    const { path, downloadName } = await this.validator.resolveDownloadPath(
      wpUserId,
      id,
      type === 'original' ? 'original' : 'validated',
    );
    const stream = createReadStream(path);
    const safeName = downloadName.replace(/[^\w.\-()+ ]/g, '_').slice(0, 200) || 'download.csv';
    return new StreamableFile(stream, {
      type: 'text/csv; charset=utf-8',
      disposition: `attachment; filename="${safeName}"`,
    });
  }
}
