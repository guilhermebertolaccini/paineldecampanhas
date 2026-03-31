import {
  BadRequestException,
  Injectable,
  Logger,
  NotFoundException,
  ServiceUnavailableException,
} from '@nestjs/common';
import { createReadStream, createWriteStream, promises as fsPromises } from 'fs';
import { join } from 'path';
import { randomUUID } from 'crypto';
import { parse } from 'csv-parse';
import { PrismaService } from '../prisma/prisma.service';
import { EvolutionApiService } from './evolution-api.service';
import { ConfigService } from '@nestjs/config';

const BATCH_SIZE = 18;
const THROTTLE_MS = 180;
const MAX_FAIL_STREAK = 12;
const RETENTION_DAYS = 15;

function delay(ms: number): Promise<void> {
  return new Promise((r) => setTimeout(r, ms));
}

function csvEscapeCell(v: string): string {
  if (/[",\n\r]/.test(v)) {
    return `"${v.replace(/"/g, '""')}"`;
  }
  return v;
}

function telefoneColumnKey(row: Record<string, unknown>): string | null {
  for (const k of Object.keys(row)) {
    if (k.trim().toUpperCase() === 'TELEFONE') {
      return k;
    }
  }
  return null;
}

@Injectable()
export class ValidatorService {
  private readonly logger = new Logger(ValidatorService.name);

  constructor(
    private readonly prisma: PrismaService,
    private readonly evolution: EvolutionApiService,
    private readonly config: ConfigService,
  ) {}

  storageRoot(): string {
    const env = this.config.get<string>('VALIDATOR_STORAGE_ROOT');
    if (env && env.trim() !== '') {
      return env.trim();
    }
    return join(process.cwd(), 'storage', 'validators');
  }

  async ensureStorageDir(): Promise<void> {
    await fsPromises.mkdir(this.storageRoot(), { recursive: true });
  }

  async saveAndProcessUpload(
    tempPath: string,
    originalName: string,
    wpUserId: number,
  ): Promise<{
    id: string;
    nomeArquivo: string;
    totalLinhas: number;
    linhasValidas: number;
    linhasInvalidas: number;
  }> {
    if (!Number.isFinite(wpUserId) || wpUserId <= 0) {
      throw new BadRequestException('wp_user_id inválido');
    }
    if (!this.evolution.isConfigured()) {
      throw new ServiceUnavailableException(
        'Evolution API não configurada no servidor (EVOLUTION_API_URL / EVOLUTION_API_TOKEN).',
      );
    }

    await this.ensureStorageDir();
    const jobId = randomUUID();
    const dir = join(this.storageRoot(), jobId);
    await fsPromises.mkdir(dir, { recursive: true });

    const pathOriginal = join(dir, 'original.csv');
    const pathValidado = join(dir, 'validated.csv');

    await fsPromises.copyFile(tempPath, pathOriginal);
    await fsPromises.unlink(tempPath).catch(() => undefined);

    const stats = await this.processCsvToValidated(pathOriginal, pathValidado);

    const row = await this.prisma.validatorHistory.create({
      data: {
        wpUserId,
        nomeArquivo: originalName.slice(0, 512),
        pathOriginal,
        pathValidado,
        totalLinhas: stats.totalLinhas,
        linhasValidas: stats.linhasValidas,
        linhasInvalidas: stats.linhasInvalidas,
      },
    });

    return {
      id: row.id,
      nomeArquivo: row.nomeArquivo,
      totalLinhas: row.totalLinhas,
      linhasValidas: row.linhasValidas,
      linhasInvalidas: row.linhasInvalidas,
    };
  }

  private async processCsvToValidated(
    inputPath: string,
    outputPath: string,
  ): Promise<{ totalLinhas: number; linhasValidas: number; linhasInvalidas: number }> {
    const instances = await this.evolution.fetchConnectedInstances();
    if (instances.length === 0) {
      throw new ServiceUnavailableException(
        'Nenhuma instância Evolution com status conectado (open). Verifique as sessões.',
      );
    }
    const instanceNames = instances.map((i) => i.name);

    const readStream = createReadStream(inputPath);
    const parser = readStream.pipe(
      parse({
        columns: true,
        skip_empty_lines: true,
        trim: true,
        bom: true,
        relax_column_count: true,
      }),
    );

    let telefoneKey: string | null = null;
    const out = createWriteStream(outputPath, { encoding: 'utf8' });
    out.write('\ufeff');
    out.write('TELEFONE,WPP\n');

    let totalLinhas = 0;
    let linhasValidas = 0;
    let linhasInvalidas = 0;

    const writeResult = (original: string, wpp: 'verdadeiro' | 'falso') => {
      out.write(`${csvEscapeCell(original)},${wpp}\n`);
      totalLinhas++;
      if (wpp === 'verdadeiro') linhasValidas++;
      else linhasInvalidas++;
    };

    const processBatch = async (originals: string[], norms: string[]) => {
      if (originals.length === 0) return;
      let failStreak = 0;
      let attempt = 0;
      while (true) {
        const inst = instanceNames[attempt % instanceNames.length];
        try {
          const map = await this.evolution.postWhatsappNumbers(inst, norms);
          for (let i = 0; i < originals.length; i++) {
            const norm = norms[i] ?? '';
            const exists = norm ? (map.get(norm) ?? false) : false;
            writeResult(originals[i], exists ? 'verdadeiro' : 'falso');
          }
          await delay(THROTTLE_MS);
          return;
        } catch {
          failStreak++;
          this.logger.warn(`Batch falhou na instância ${inst} (tentativa ${failStreak})`);
          if (failStreak >= MAX_FAIL_STREAK) {
            for (const o of originals) {
              writeResult(o, 'falso');
            }
            await delay(THROTTLE_MS);
            return;
          }
          attempt++;
          await delay(THROTTLE_MS);
        }
      }
    };

    let batchOrig: string[] = [];
    let batchNorm: string[] = [];

    for await (const record of parser) {
      if (!record || typeof record !== 'object') continue;
      const row = record as Record<string, unknown>;
      if (telefoneKey === null) {
        telefoneKey = telefoneColumnKey(row);
        if (telefoneKey === null) {
          throw new BadRequestException('Cabeçalho obrigatório: coluna TELEFONE.');
        }
      }
      const raw = String(row[telefoneKey] ?? '').trim();
      if (!raw) continue;
      const norm = this.evolution.normalizePhoneBr(raw);
      if (!norm) continue;
      batchOrig.push(raw);
      batchNorm.push(norm);
      if (batchNorm.length >= BATCH_SIZE) {
        await processBatch(batchOrig, batchNorm);
        batchOrig = [];
        batchNorm = [];
      }
    }

    if (batchNorm.length > 0) {
      await processBatch(batchOrig, batchNorm);
    }

    await new Promise<void>((resolve, reject) => {
      out.end(() => resolve());
      out.on('error', reject);
    });

    if (totalLinhas === 0) {
      throw new BadRequestException('Nenhum telefone válido encontrado no CSV.');
    }

    return { totalLinhas, linhasValidas, linhasInvalidas };
  }

  async listHistory(wpUserId: number) {
    if (!Number.isFinite(wpUserId) || wpUserId <= 0) {
      throw new BadRequestException('wp_user_id inválido');
    }
    const since = new Date();
    since.setUTCDate(since.getUTCDate() - RETENTION_DAYS);

    return this.prisma.validatorHistory.findMany({
      where: {
        wpUserId,
        createdAt: { gte: since },
      },
      orderBy: { createdAt: 'desc' },
      take: 100,
      select: {
        id: true,
        nomeArquivo: true,
        totalLinhas: true,
        linhasValidas: true,
        linhasInvalidas: true,
        createdAt: true,
      },
    });
  }

  async resolveDownloadPath(
    wpUserId: number,
    id: string,
    type: 'original' | 'validated',
  ): Promise<{ path: string; downloadName: string }> {
    const row = await this.prisma.validatorHistory.findUnique({ where: { id } });
    if (!row || row.wpUserId !== wpUserId) {
      throw new NotFoundException('Registro não encontrado');
    }
    const path = type === 'original' ? row.pathOriginal : row.pathValidado;
    try {
      await fsPromises.access(path);
    } catch {
      throw new NotFoundException('Arquivo não encontrado no disco');
    }
    const base = row.nomeArquivo.replace(/[/\\]/g, '_') || 'arquivo.csv';
    const downloadName =
      type === 'validated'
        ? base.replace(/\.csv$/i, '') + '-validado.csv'
        : base.endsWith('.csv')
          ? base
          : `${base}.csv`;
    return { path, downloadName };
  }

  pathWithinStorage(absPath: string): boolean {
    const root = this.storageRoot();
    const norm = (p: string) => p.replace(/\\/g, '/').toLowerCase();
    return norm(absPath).startsWith(norm(root));
  }
}
