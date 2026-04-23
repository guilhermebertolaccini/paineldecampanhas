import { Injectable, Logger } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { Cron, CronExpression } from '@nestjs/schedule';
import SftpClient from 'ssh2-sftp-client';
import * as XLSX from 'xlsx';

import {
  MssqlService,
  type LineHealthSyncRow,
} from '../../sql-server/mssql.service';
import type { StandardLineHealth } from '../../line-health/adapters/standard-line-health.interface';

/** Linha bruta lida da planilha Ótima (após `sheet_to_json`). */
type OtimaRawRow = {
  carteira?: unknown;
  numero?: unknown;
  plataforma?: unknown;
  status_numero?: unknown;
  capacidade_disparo?: unknown;
  // quaisquer outras colunas são preservadas no dados_brutos
  [key: string]: unknown;
};

/** Linha já aplicada ao De-Para (contrato interno do módulo). */
type OtimaMappedLine = {
  name: string;
  number: string;
  provider: string;
  status: 'CONNECTED' | 'BANNED' | 'UNKNOWN';
  messagingLimit: string;
  quality: 'GREEN';
  raw: OtimaRawRow;
};

/** Origem do provedor — rótulo canônico nos snapshots/histórico. */
const PROVIDER_CANONICAL = 'OTIMA';

@Injectable()
export class OtimaSftpSyncService {
  private readonly logger = new Logger(OtimaSftpSyncService.name);
  /** Logs com prefixo dedicado para facilitar grep em produção. */
  private readonly syncLog = new Logger('OtimaSftpSync');

  constructor(
    private readonly config: ConfigService,
    private readonly mssql: MssqlService,
  ) {}

  /** A cada 30 minutos. */
  @Cron(CronExpression.EVERY_30_MINUTES, { name: 'otima-sftp-sync' })
  async runScheduled(): Promise<void> {
    // Kill-switch: OTIMA_SFTP_CRON_ENABLED=false desliga o agendamento sem remover código.
    const enabled =
      this.config.get<string>('OTIMA_SFTP_CRON_ENABLED', 'true') !== 'false';
    this.syncLog.log(
      `[CRON TICK] @Cron EVERY_30_MINUTES disparou (enabled=${enabled}) às ${new Date().toISOString()}.`,
    );
    if (!enabled) {
      this.logger.debug('Cron Ótima SFTP desabilitado (OTIMA_SFTP_CRON_ENABLED=false).');
      return;
    }
    try {
      await this.runOnce();
    } catch (e) {
      this.logger.error(
        `Cron Ótima SFTP falhou: ${this.toMsg(e)}`,
        e instanceof Error ? e.stack : undefined,
      );
    }
  }

  /**
   * Executa uma varredura completa:
   * 1) conecta SFTP
   * 2) lista .xlsx do diretório configurado
   * 3) para cada arquivo: download → parse → De-Para → UPSERT → delete remoto
   *
   * Retorna um sumário para telemetria / chamadas manuais (ex.: endpoint admin).
   */
  async runOnce(): Promise<{
    files_scanned: number;
    files_processed: number;
    files_failed: number;
    rows_upserted: number;
    files_list: string[];
    errors: Array<{ file: string; message: string }>;
    dry_run_delete: boolean;
    started_at: string;
    finished_at: string;
  }> {
    const startedAt = new Date().toISOString();
    this.syncLog.log(`========== OTIMA SFTP SYNC :: START ${startedAt} ==========`);

    const cfg = this.readSftpConfig();
    if (!cfg) {
      // Log super-explícito de quais vars estão faltando — zero adivinhação em produção.
      const envSnapshot = {
        OTIMA_SFTP_HOST: this.presenceTag(this.config.get<string>('OTIMA_SFTP_HOST')),
        OTIMA_SFTP_PORT: this.config.get<string>('OTIMA_SFTP_PORT', '2222'),
        OTIMA_SFTP_USER: this.presenceTag(this.config.get<string>('OTIMA_SFTP_USER')),
        OTIMA_SFTP_PASS: this.presenceTag(this.config.get<string>('OTIMA_SFTP_PASS')),
        OTIMA_SFTP_DIR: this.presenceTag(this.config.get<string>('OTIMA_SFTP_DIR')),
      };
      this.syncLog.error(
        `Credenciais SFTP Ótima incompletas. Env atual: ${JSON.stringify(envSnapshot)}`,
      );
      return {
        files_scanned: 0,
        files_processed: 0,
        files_failed: 0,
        rows_upserted: 0,
        files_list: [],
        errors: [
          {
            file: '<config>',
            message: `Credenciais SFTP incompletas: ${JSON.stringify(envSnapshot)}`,
          },
        ],
        dry_run_delete: this.isDryRunDelete(),
        started_at: startedAt,
        finished_at: new Date().toISOString(),
      };
    }

    // [DEBUG] Imprime configuração efetiva (sem expor a senha).
    this.syncLog.log(
      `[DEBUG-CFG] host=${cfg.host} port=${cfg.port} user=${cfg.user} dir=${cfg.dir} passLen=${cfg.pass.length}`,
    );

    const sftp = new SftpClient();
    const filesList: string[] = [];
    const errors: Array<{ file: string; message: string }> = [];
    let filesScanned = 0;
    let filesProcessed = 0;
    let filesFailed = 0;
    let rowsUpserted = 0;
    const dryRun = this.isDryRunDelete();

    try {
      this.syncLog.log(
        `[STEP 1/4] Conectando no SFTP Ótima ${cfg.user}@${cfg.host}:${cfg.port} → ${cfg.dir}`,
      );
      await sftp.connect({
        host: cfg.host,
        port: cfg.port,
        username: cfg.user,
        password: cfg.pass,
        readyTimeout: 20_000,
      });
      this.syncLog.log('[STEP 1/4] ✓ Conexão SFTP estabelecida.');

      this.syncLog.log(`[STEP 2/4] Listando arquivos em ${cfg.dir}...`);
      const list = await sftp.list(cfg.dir);
      const xlsxFiles = list
        .filter((f) => f.type === '-' && /\.xlsx$/i.test(f.name))
        .sort((a, b) => a.name.localeCompare(b.name));

      filesScanned = xlsxFiles.length;
      this.syncLog.log(
        `[STEP 2/4] ✓ Listagem: ${list.length} entrada(s) no dir, ${xlsxFiles.length} .xlsx candidatos.`,
      );

      if (xlsxFiles.length === 0) {
        // [DEBUG] Se não achou nenhum .xlsx, dumpa as 20 primeiras entradas (tipo+nome) pra ver o que tem.
        const preview = list.slice(0, 20).map((e) => ({ type: e.type, name: e.name }));
        this.syncLog.warn(
          `[DEBUG-LIST] Nenhum .xlsx encontrado. Primeiras ${preview.length} entradas do diretório: ${JSON.stringify(preview)}`,
        );
      } else {
        this.syncLog.log(
          `[DEBUG-LIST] Arquivos .xlsx a processar: ${JSON.stringify(xlsxFiles.map((f) => f.name))}`,
        );
      }

      this.syncLog.log(
        `[STEP 3/4] Processando ${xlsxFiles.length} arquivo(s). dry_run_delete=${dryRun}`,
      );
      for (const entry of xlsxFiles) {
        const remotePath = this.joinRemote(cfg.dir, entry.name);
        filesList.push(entry.name);
        try {
          const upserted = await this.processOneFile(sftp, remotePath, entry.name);
          rowsUpserted += upserted;
          filesProcessed += 1;
        } catch (e) {
          filesFailed += 1;
          const msg = this.toMsg(e);
          errors.push({ file: entry.name, message: msg });
          this.syncLog.error(
            `[FILE-FAIL] Arquivo Ótima falhou (${entry.name}): ${msg}. Pulando para o próximo.`,
            e instanceof Error ? e.stack : String(e),
          );
          // não dar "throw": erro de UM arquivo não impede o resto da fila
        }
      }

      this.syncLog.log(
        `[STEP 4/4] ✓ Concluído: ${filesProcessed}/${xlsxFiles.length} arquivo(s) processado(s); ` +
          `${rowsUpserted} linha(s) UPSERT no MSSQL; ${filesFailed} falha(s).`,
      );
    } catch (e) {
      // Erro "alto nível" (ex.: SFTP connect/list). Registra e propaga no shape de retorno.
      const msg = this.toMsg(e);
      errors.push({ file: '<pipeline>', message: msg });
      this.syncLog.error(
        `[FATAL] Falha no pipeline SFTP Ótima: ${msg}`,
        e instanceof Error ? e.stack : String(e),
      );
    } finally {
      try {
        await sftp.end();
        this.syncLog.debug('[CLEANUP] Conexão SFTP encerrada.');
      } catch (endErr) {
        this.syncLog.warn(
          `[CLEANUP] sftp.end() falhou (ignorado): ${this.toMsg(endErr)}`,
        );
      }
    }

    const finishedAt = new Date().toISOString();
    this.syncLog.log(`========== OTIMA SFTP SYNC :: END ${finishedAt} ==========`);

    return {
      files_scanned: filesScanned,
      files_processed: filesProcessed,
      files_failed: filesFailed,
      rows_upserted: rowsUpserted,
      files_list: filesList,
      errors,
      dry_run_delete: dryRun,
      started_at: startedAt,
      finished_at: finishedAt,
    };
  }

  /**
   * Baixa UM arquivo, parseia, faz UPSERT no MSSQL e, só então, apaga do servidor remoto.
   * Throws quando o arquivo é inválido OU o UPSERT falha — impedindo o delete.
   *
   * @returns quantidade de linhas enviadas para UPSERT
   */
  private async processOneFile(
    sftp: SftpClient,
    remotePath: string,
    fileName: string,
  ): Promise<number> {
    this.syncLog.log(`[FILE] ↓ Baixando ${remotePath}`);
    const buffer = (await sftp.get(remotePath)) as Buffer;
    if (!Buffer.isBuffer(buffer) || buffer.length === 0) {
      throw new Error(`Download retornou buffer vazio para ${remotePath}`);
    }
    this.syncLog.log(
      `[FILE] ↓ Download OK (${fileName}): ${buffer.length} bytes (${(buffer.length / 1024).toFixed(1)} KB).`,
    );

    this.syncLog.debug(`[PARSE] ${fileName}: chamando XLSX.read...`);
    const workbook = XLSX.read(buffer, { type: 'buffer' });
    const sheetNames = workbook.SheetNames || [];
    this.syncLog.log(
      `[PARSE] ${fileName}: ${sheetNames.length} aba(s) [${sheetNames.join(', ')}].`,
    );
    const firstSheetName = sheetNames[0];
    if (!firstSheetName) {
      throw new Error('Planilha sem abas (workbook.SheetNames vazio).');
    }
    const sheet = workbook.Sheets[firstSheetName];
    const rawRows = XLSX.utils.sheet_to_json<OtimaRawRow>(sheet, {
      defval: '',
      raw: false, // deixa o SheetJS converter tudo em string — simplifica o parse do status
    });

    this.syncLog.log(
      `[PARSE] ↪ ${fileName}: aba "${firstSheetName}" produziu ${rawRows.length} linha(s).`,
    );

    // [DEBUG-SCHEMA] Dumpa as colunas + primeira linha crua. Vital pra descobrir
    // se o cabeçalho está com nomes estranhos (acentos, BOM, espaços, camelCase, etc).
    if (rawRows.length > 0) {
      const first = rawRows[0];
      const columns = Object.keys(first);
      this.syncLog.log(
        `[DEBUG-SCHEMA] ${fileName}: colunas detectadas (${columns.length}) = ${JSON.stringify(columns)}`,
      );
      this.syncLog.log(
        `[DEBUG-SCHEMA] ${fileName}: PRIMEIRA LINHA CRUA = ${JSON.stringify(first)}`,
      );
      if (rawRows.length > 1) {
        this.syncLog.debug(
          `[DEBUG-SCHEMA] ${fileName}: SEGUNDA LINHA CRUA = ${JSON.stringify(rawRows[1])}`,
        );
      }
    } else {
      this.syncLog.warn(
        `[DEBUG-SCHEMA] ${fileName}: sheet_to_json retornou 0 linhas. Verifique se a planilha tem cabeçalho na primeira linha.`,
      );
    }

    const mapped: OtimaMappedLine[] = [];
    let discarded = 0;
    for (const raw of rawRows) {
      const line = this.applyDePara(raw);
      if (!line) {
        discarded += 1;
        continue;
      }
      mapped.push(line);
    }
    this.syncLog.log(
      `[DE-PARA] ${fileName}: ${mapped.length} válida(s) + ${discarded} descartada(s) (de ${rawRows.length} total).`,
    );
    if (mapped.length > 0) {
      this.syncLog.debug(
        `[DE-PARA] ${fileName}: PRIMEIRA LINHA MAPEADA = ${JSON.stringify(this.sampleMapped(mapped[0]))}`,
      );
    }

    if (mapped.length === 0) {
      this.syncLog.warn(
        `[DE-PARA] ${fileName}: nenhuma linha válida após De-Para; arquivo NÃO será deletado para investigação.`,
      );
      return 0;
    }

    const syncRows = mapped.map((l) => this.toLineHealthSyncRow(l, fileName));
    this.syncLog.log(
      `[DB] ${fileName}: enviando ${syncRows.length} linha(s) para MssqlService.syncLineHealth...`,
    );
    this.syncLog.debug(
      `[DB] ${fileName}: PRIMEIRA LINHA SYNC = ${JSON.stringify(syncRows[0])}`,
    );

    // UPSERT — mesma função usada para Gosac/Noah (MERGE por line_key no MSSQL).
    const merged = await this.mssql.syncLineHealth(syncRows, {
      strict: true, // se falhar, NÃO deletamos o arquivo
      metricsSource: 'nest_otima_sftp_sync',
      verbose: true,
    });

    this.syncLog.log(
      `[DB] ✓ ${fileName}: UPSERT concluído. MERGE=${merged} (enviadas=${syncRows.length}).`,
    );

    // ------------------------------------------------------------------
    // [DRY-RUN] Delete remoto desligado temporariamente para debug.
    // Para reativar: OTIMA_SFTP_DELETE_AFTER_SYNC=true (ou remover este gate).
    // ------------------------------------------------------------------
    if (!this.isDryRunDelete()) {
      this.syncLog.log(`[FILE] Deletando arquivo remoto ${remotePath}...`);
      await sftp.delete(remotePath);
      this.syncLog.log(`[FILE] ✓ ${fileName}: apagado em ${remotePath}.`);
    } else {
      this.syncLog.warn(
        `[DRY-RUN] ${fileName}: sftp.delete PULADO. Arquivo preservado em ${remotePath}. ` +
          `Ative com OTIMA_SFTP_DELETE_AFTER_SYNC=true quando o debug terminar.`,
      );
    }
    return syncRows.length;
  }

  /** Resumo "bonito" de uma linha mapeada para logs (evita dump gigante do raw). */
  private sampleMapped(l: OtimaMappedLine): Record<string, unknown> {
    return {
      name: l.name,
      number: l.number,
      provider: l.provider,
      status: l.status,
      messagingLimit: l.messagingLimit,
      quality: l.quality,
    };
  }

  /**
   * Dry-run do delete: quando `OTIMA_SFTP_DELETE_AFTER_SYNC` não é `'true'`,
   * os arquivos permanecem no SFTP após o UPSERT (modo debug padrão).
   */
  private isDryRunDelete(): boolean {
    const raw = String(
      this.config.get<string>('OTIMA_SFTP_DELETE_AFTER_SYNC', 'false'),
    )
      .trim()
      .toLowerCase();
    return raw !== 'true' && raw !== '1' && raw !== 'yes';
  }

  /** Marcador (SET/EMPTY) + comprimento para inspecionar env vars sem vazar valor. */
  private presenceTag(v: string | undefined | null): string {
    if (v === undefined || v === null) return 'UNSET';
    const s = String(v);
    if (s.trim() === '') return 'EMPTY';
    return `SET(len=${s.length})`;
  }

  // ---------------------------------------------------------------------------
  // De-Para (planilha → contrato interno) + mapeamento para LineHealthSyncRow
  // ---------------------------------------------------------------------------

  /** Aplica o De-Para solicitado na especificação. */
  private applyDePara(raw: OtimaRawRow): OtimaMappedLine | null {
    const name = this.readString(raw.carteira);
    const provider = this.readString(raw.plataforma);
    const number = this.onlyDigits(this.readString(raw.numero));

    // Sem número E sem nome → descarta (não conseguimos chavear).
    if (name === '' && number === '') {
      return null;
    }

    const status = this.parseStatus(this.readString(raw.status_numero));
    const messagingLimit = this.toTierLabel(raw.capacidade_disparo);

    return {
      name,
      number,
      provider: provider || PROVIDER_CANONICAL,
      status,
      messagingLimit,
      quality: 'GREEN',
      raw,
    };
  }

  /** "verde" → CONNECTED; "vermelho" → BANNED; resto → UNKNOWN. */
  private parseStatus(input: string): 'CONNECTED' | 'BANNED' | 'UNKNOWN' {
    const lower = input.toLowerCase();
    if (lower.includes('verde')) return 'CONNECTED';
    if (lower.includes('vermelho')) return 'BANNED';
    return 'UNKNOWN';
  }

  /**
   * Transforma `capacidade_disparo` em rótulo TIER_*.
   * 10000 → TIER_10K; 1000 → TIER_1K; 250 → TIER_250; 100000 → TIER_100K.
   * Quando o valor não é numérico (ex.: "Ilimitado"), devolve a string original em upper/trim.
   */
  private toTierLabel(value: unknown): string {
    if (value === null || value === undefined || value === '') {
      return 'TIER_UNKNOWN';
    }
    const str = String(value).trim();
    const cleaned = str.replace(/[^\d]/g, '');
    if (!cleaned) {
      return `TIER_${str.replace(/\s+/g, '_').toUpperCase()}`;
    }
    const n = Number(cleaned);
    if (!Number.isFinite(n) || n <= 0) {
      return 'TIER_UNKNOWN';
    }
    if (n >= 1_000_000 && n % 1_000_000 === 0) {
      return `TIER_${n / 1_000_000}M`;
    }
    if (n >= 1_000 && n % 1_000 === 0) {
      return `TIER_${n / 1_000}K`;
    }
    return `TIER_${n}`;
  }

  /**
   * Converte a linha mapeada para o formato que o `MssqlService.syncLineHealth` consome.
   * `id_linha` = chave natural composta (prefere número; cai para nome). Evita colisão
   * com outros provedores usando o prefixo PROVIDER_CANONICAL.
   */
  private toLineHealthSyncRow(
    line: OtimaMappedLine,
    sourceFile: string,
  ): LineHealthSyncRow {
    const naturalKey =
      line.number !== ''
        ? `num:${line.number}`
        : `name:${line.name.toLowerCase().replace(/\s+/g, '_')}`;

    const idLinha = `${PROVIDER_CANONICAL}:${naturalKey}`.slice(0, 200);

    const standard: StandardLineHealth = {
      provedor: PROVIDER_CANONICAL,
      id_externo: naturalKey,
      nome_linha: line.name || line.number || naturalKey,
      numero_telefone: line.number || null,
      status_conexao: line.status,
      limite_mensagens: line.messagingLimit,
      restricao_conta: null,
      waba_id: null,
      waba_phone_id: null,
      dados_brutos: {
        fonte: 'otima_sftp_xlsx',
        arquivo: sourceFile,
        quality: line.quality,
        provider_planilha: line.provider,
        linha_bruta: line.raw,
      },
    };

    return {
      id_linha: idLinha,
      nome_linha: standard.nome_linha.slice(0, 512),
      provedor: PROVIDER_CANONICAL,
      status_qualidade: line.status,
      detalhes_retorno: null,
      standard_line_health: standard,
    };
  }

  // ---------------------------------------------------------------------------
  // utils
  // ---------------------------------------------------------------------------

  private readSftpConfig():
    | {
        host: string;
        port: number;
        user: string;
        pass: string;
        dir: string;
      }
    | null {
    const host = (this.config.get<string>('OTIMA_SFTP_HOST') || '').trim();
    const user = (this.config.get<string>('OTIMA_SFTP_USER') || '').trim();
    const pass = this.config.get<string>('OTIMA_SFTP_PASS') || '';
    const dir = (this.config.get<string>('OTIMA_SFTP_DIR') || '').trim();
    const portRaw = this.config.get<string>('OTIMA_SFTP_PORT', '2222');
    const port = Number.parseInt(String(portRaw), 10) || 2222;

    if (!host || !user || !pass || !dir) {
      return null;
    }
    return { host, port, user, pass, dir };
  }

  private readString(v: unknown): string {
    if (v === null || v === undefined) return '';
    return String(v).trim();
  }

  private onlyDigits(v: string): string {
    return v.replace(/\D+/g, '');
  }

  private joinRemote(dir: string, name: string): string {
    const clean = dir.endsWith('/') ? dir.slice(0, -1) : dir;
    return `${clean}/${name}`;
  }

  private toMsg(e: unknown): string {
    if (e instanceof Error) return e.message;
    return String(e);
  }
}
