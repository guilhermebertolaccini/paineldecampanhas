import { Injectable } from '@nestjs/common';
import { HttpService } from '@nestjs/axios';
import { firstValueFrom } from 'rxjs';
import { BaseProvider } from '../base/base.provider';
import {
    CampaignData,
    ProviderResponse,
    ProviderCredentials,
    RetryStrategy,
} from '../base/provider.interface';

/**
 * Variável HSM por contato — contrato Swagger GOSAC Oficial (`contacts[].variables[]`).
 */
export type GosacOfficialContactVariable = {
    componentId: number;
    variable: string;
    value: string;
};

/**
 * Contato no POST `/campaigns/official` (Swagger).
 */
export type GosacOfficialContactPayload = {
    number: string;
    name: string;
    cpf: string;
    variables: GosacOfficialContactVariable[];
};

/**
 * Payload raiz POST `/campaigns/official` (Swagger GOSAC Oficial).
 */
export type GosacOfficialCampaignPayload = {
    idAmbient: string;
    idRuler: string;
    name: string;
    connectionId: number;
    templateId: number;
    contacts: GosacOfficialContactPayload[];
};

/** Entrada em `variables_map` vinda do React: string (campo) ou `{ type, value }`. */
type GosacVariablesMapEntry = string | { type?: string; value?: string };

@Injectable()
export class GosacOficialProvider extends BaseProvider {
    constructor(httpService: HttpService) {
        super(httpService, 'GosacOficialProvider');
    }

    getRetryStrategy(): RetryStrategy {
        return {
            maxRetries: 3,
            delays: [1000, 2000, 5000],
        };
    }

    validateCredentials(credentials: ProviderCredentials): boolean {
        return !!(
            credentials.url &&
            credentials.token &&
            typeof credentials.url === 'string' &&
            typeof credentials.token === 'string'
        );
    }

    private parseGosacMensagemJson(mensagem: string): Record<string, unknown> | null {
        if (!mensagem || typeof mensagem !== 'string' || !mensagem.trim().startsWith('{')) {
            return null;
        }
        try {
            const parsed = JSON.parse(mensagem) as unknown;
            return parsed && typeof parsed === 'object' ? (parsed as Record<string, unknown>) : null;
        } catch {
            return null;
        }
    }

    /**
     * `templateId` / `connectionId` a partir do JSON da mensagem (WordPress grava `id` + `connectionId`).
     */
    private extractGosacIdsFromBatch(data: CampaignData[]): {
        templateId: number | null;
        connectionId: number | null;
        firstParsed: Record<string, unknown> | null;
    } {
        let templateId: number | null = null;
        let connectionId: number | null = null;
        let firstParsed: Record<string, unknown> | null = null;

        for (const item of data) {
            const parsed = this.parseGosacMensagemJson(item.mensagem);
            if (!parsed) continue;
            if (!firstParsed) firstParsed = parsed;

            const tidRaw = parsed.templateId ?? parsed.template_id ?? parsed.id;
            const cidRaw = parsed.connectionId ?? parsed.connection_id;

            if (tidRaw != null && templateId === null) {
                const n = parseInt(String(tidRaw), 10);
                if (!Number.isNaN(n) && n > 0) templateId = n;
            }
            if (cidRaw != null && connectionId === null) {
                const n = parseInt(String(cidRaw), 10);
                if (!Number.isNaN(n) && n > 0) connectionId = n;
            }
            if (templateId && templateId > 0 && connectionId && connectionId > 0) break;
        }

        return { templateId, connectionId, firstParsed };
    }

    /**
     * Campo da linha: 1º `dado.variables` (case-insensitive), 2º propriedades escalares em `dado` (case-insensitive).
     */
    private pickRowFieldValue(dado: CampaignData, fieldName: string): string {
        const f = (fieldName || '').trim();
        if (!f) return '';
        const fl = f.toLowerCase();

        const vars = dado.variables;
        if (vars && typeof vars === 'object' && !Array.isArray(vars)) {
            const mk = Object.keys(vars).find((k) => k.toLowerCase() === fl);
            if (mk != null) {
                const v = vars[mk];
                if (v != null && String(v).trim() !== '') {
                    return String(v).trim();
                }
            }
        }

        const rec = dado as unknown as Record<string, unknown>;
        const mk2 = Object.keys(rec).find(
            (k) =>
                k.toLowerCase() === fl &&
                k !== 'variables' &&
                k !== 'mensagem',
        );
        if (mk2 != null) {
            const v = rec[mk2];
            if (v != null && typeof v !== 'object' && String(v).trim() !== '') {
                return String(v).trim();
            }
        }
        return '';
    }

    /**
     * Nome da campanha na raiz (`name`) — Swagger. Sem nome de template/carteira.
     * Ordem: `nome_campanha` REST → `variables.nome_campanha` (CI) → `Campanha {timestamp}`.
     */
    private resolveGosacRootCampaignName(first: CampaignData | undefined): string {
        if (!first) {
            return `Campanha ${Date.now()}`;
        }
        const fromCol = (first.nome_campanha ?? '').trim();
        if (fromCol) {
            return fromCol.slice(0, 255);
        }
        const fromVars = this.pickRowFieldValue(first, 'nome_campanha');
        if (fromVars) {
            return fromVars.slice(0, 255);
        }
        return `Campanha ${Date.now()}`;
    }

    /** Exibeção do contato: prioriza `variables.nome`, senão `dado.nome`, senão `-` (Swagger exige string). */
    private gosacContactName(dado: CampaignData): string {
        const fromVars = this.pickRowFieldValue(dado, 'nome');
        if (fromVars) return fromVars.slice(0, 255);
        const root = (dado.nome ?? '').trim();
        if (root) return root.slice(0, 255);
        return '-';
    }

    /** CPF 11 dígitos; sem dado válido usa `-` para não enviar string vazia. */
    private gosacContactCpf(dado: CampaignData): string {
        const fromVars =
            this.pickRowFieldValue(dado, 'cpf_cnpj') || this.pickRowFieldValue(dado, 'cpf');
        let digits = fromVars ? fromVars.replace(/\D/g, '').slice(0, 11) : '';
        if (digits.length < 11 && dado.cpf_cnpj) {
            digits = String(dado.cpf_cnpj).replace(/\D/g, '').slice(0, 11);
        }
        if (digits.length === 11) return digits;
        return '-';
    }

    /**
     * Regra anti-422: `variables[].value` nunca pode ser `""`.
     */
    private gosacApiVariableValue(raw: string): string {
        const t = (raw ?? '').trim();
        return t === '' ? ' ' : t;
    }

    private sanitizeGosacContactVariables(vars: GosacOfficialContactVariable[]): GosacOfficialContactVariable[] {
        return vars.map((x) => ({
            ...x,
            value: this.gosacApiVariableValue(x.value),
        }));
    }

    private resolveVariablesMapEntryToValue(
        dado: CampaignData,
        entry: GosacVariablesMapEntry | undefined,
    ): string {
        if (entry == null) return '';
        if (typeof entry === 'string') {
            return this.pickRowFieldValue(dado, entry);
        }
        if (typeof entry === 'object') {
            const t = String(entry.type ?? '');
            const v = entry.value != null ? String(entry.value) : '';
            if (t === 'field' && v !== '') {
                return this.pickRowFieldValue(dado, v);
            }
            return v;
        }
        return '';
    }

    private normalizeContactVariablesFromPhp(raw: unknown): GosacOfficialContactVariable[] {
        if (!Array.isArray(raw)) return [];
        const out: GosacOfficialContactVariable[] = [];
        for (const row of raw) {
            if (!row || typeof row !== 'object') continue;
            const r = row as Record<string, unknown>;
            const componentId = Number(r.componentId ?? r.component_id ?? 0) || 0;
            const variable = r.variable != null ? String(r.variable) : '';
            const value = this.gosacApiVariableValue(r.value != null ? String(r.value) : '');
            if (variable === '' && componentId === 0) continue;
            out.push({ componentId, variable, value });
        }
        return out;
    }

    private variablesFromBodyComponentsAndVc(
        components: unknown,
        variableComponents: { componentId: number; variable: string }[],
    ): GosacOfficialContactVariable[] {
        if (!Array.isArray(components) || variableComponents.length === 0) return [];
        for (const c of components) {
            if (!c || typeof c !== 'object') continue;
            const comp = c as Record<string, unknown>;
            const typ = String(comp.type ?? '').toLowerCase();
            if (typ !== 'body') continue;
            const rawParams = comp.parameters;
            if (!Array.isArray(rawParams)) continue;
            const texts: string[] = [];
            for (const p of rawParams) {
                if (!p || typeof p !== 'object') continue;
                const pr = p as Record<string, unknown>;
                const pTyp = String(pr.type ?? 'text').toLowerCase();
                if (pTyp === 'text') {
                    texts.push(pr.text != null ? String(pr.text) : '');
                }
            }
            if (texts.length === 0) continue;
            const out: GosacOfficialContactVariable[] = [];
            for (let i = 0; i < variableComponents.length; i++) {
                const vc = variableComponents[i];
                out.push({
                    componentId: vc.componentId,
                    variable: vc.variable,
                    value: this.gosacApiVariableValue(texts[i] ?? ''),
                });
            }
            return out;
        }
        return [];
    }

    private buildVariablesForContact(
        dado: CampaignData,
        parsed: Record<string, unknown> | null,
        variableComponents: { componentId: number; variable: string }[],
        variablesMap: Record<string, GosacVariablesMapEntry>,
    ): GosacOfficialContactVariable[] {
        if (parsed) {
            const fromPhp = this.normalizeContactVariablesFromPhp(parsed.contact_variables);
            if (fromPhp.length > 0) {
                return fromPhp;
            }
            const fromComponents = this.variablesFromBodyComponentsAndVc(
                parsed.components,
                variableComponents,
            );
            if (fromComponents.length > 0) {
                return fromComponents;
            }
        }

        if (Object.keys(variablesMap).length === 0) {
            return [];
        }

        const out: GosacOfficialContactVariable[] = [];
        for (const vc of variableComponents) {
            const rawVar = (vc.variable || '').trim();
            let mapKey = rawVar;
            const m = /^\{\{(.+)\}\}$/u.exec(rawVar);
            if (m) {
                mapKey = m[1].trim();
            }
            let entry: GosacVariablesMapEntry | undefined = variablesMap[rawVar];
            if (entry === undefined) entry = variablesMap[mapKey];
            if (entry === undefined) {
                const keys = Object.keys(variablesMap);
                const found = keys.find(
                    (k) =>
                        k.toLowerCase() === rawVar.toLowerCase() ||
                        k.toLowerCase() === mapKey.toLowerCase(),
                );
                if (found) entry = variablesMap[found];
            }
            const value = this.gosacApiVariableValue(
                this.resolveVariablesMapEntryToValue(dado, entry),
            );
            out.push({
                componentId: vc.componentId,
                variable: rawVar || mapKey,
                value,
            });
        }

        if (out.length === 0 && Object.keys(variablesMap).length > 0) {
            for (const [varName, entry] of Object.entries(variablesMap)) {
                const value = this.gosacApiVariableValue(
                    this.resolveVariablesMapEntryToValue(dado, entry),
                );
                out.push({ componentId: 0, variable: varName, value });
            }
        }

        return out;
    }

    async send(
        data: CampaignData[],
        credentials: ProviderCredentials,
    ): Promise<ProviderResponse> {
        if (!this.validateCredentials(credentials)) {
            return {
                success: false,
                error: 'Credenciais inválidas: URL e Token são obrigatórias',
            };
        }

        if (!data || data.length === 0) {
            return {
                success: false,
                error: 'Nenhum dado para enviar',
            };
        }

        const { templateId, connectionId, firstParsed } = this.extractGosacIdsFromBatch(data);

        if (!templateId || templateId <= 0 || !connectionId || connectionId <= 0) {
            const sample = data[0]
                ? JSON.stringify(data[0].mensagem || '').slice(0, 200)
                : 'vazio';
            this.logger.warn(
                `⚠️ [Gosac Oficial] Template/connectionId ausentes. Amostra mensagem: ${sample}`,
            );
            return {
                success: false,
                error:
                    'connectionId e templateId são obrigatórios. Verifique o template GOSAC e a ilha (connectionId) na mensagem agendada.',
            };
        }

        const idAmbient = String(
            (credentials as Record<string, unknown>).id_carteira ?? data[0].id_carteira ?? '',
        ).trim();
        const idRuler = String((credentials as Record<string, unknown>).idRuler ?? '').trim();

        if (!idAmbient || !idRuler) {
            return {
                success: false,
                error:
                    'idAmbient e idRuler são obrigatórios. Configure id_carteira e id_ruler na carteira em Configurações.',
            };
        }

        let variablesMap: Record<string, GosacVariablesMapEntry> = {};
        let variableComponents: { componentId: number; variable: string }[] = [];
        if (firstParsed) {
            const vm = firstParsed.variables_map;
            if (vm && typeof vm === 'object' && !Array.isArray(vm)) {
                variablesMap = vm as Record<string, GosacVariablesMapEntry>;
            }
            const vcomp = firstParsed.variableComponents;
            if (Array.isArray(vcomp)) {
                variableComponents = vcomp
                    .filter((x) => x && typeof x === 'object')
                    .map((x) => {
                        const o = x as Record<string, unknown>;
                        return {
                            componentId: Number(o.componentId ?? o.component_id ?? 0) || 0,
                            variable: o.variable != null ? String(o.variable) : '',
                        };
                    });
            }
        }

        const campaignName = this.resolveGosacRootCampaignName(data[0]);
        this.logger.log(`[GOSAC] Nome da campanha resolvido para o payload: ${campaignName}`);

        const contacts: GosacOfficialContactPayload[] = data
            .filter((dado) => dado.telefone && String(dado.telefone).trim() !== '')
            .map((dado) => {
                const rowParsed = this.parseGosacMensagemJson(dado.mensagem) ?? firstParsed;
                let variables = this.buildVariablesForContact(
                    dado,
                    rowParsed,
                    variableComponents,
                    variablesMap,
                );
                variables = this.sanitizeGosacContactVariables(variables);

                return {
                    number: this.normalizePhoneNumber(dado.telefone),
                    name: this.gosacContactName(dado),
                    cpf: this.gosacContactCpf(dado),
                    variables,
                };
            });

        if (contacts.length === 0) {
            return {
                success: false,
                error: 'Nenhum contato válido para enviar (telefone obrigatório)',
            };
        }

        const payload: GosacOfficialCampaignPayload = {
            idAmbient,
            idRuler,
            name: campaignName.slice(0, 500),
            connectionId,
            templateId,
            contacts,
        };

        const baseUrl = (credentials.url as string).replace(/\/$/, '');
        const postUrl = `${baseUrl}/campaigns/official`;
        const authToken = (credentials.token as string)?.trim();
        const authHeader = authToken?.toLowerCase().startsWith('bearer ')
            ? authToken
            : `Bearer ${authToken}`;

        try {
            const createResponse = await this.executeWithRetry(
                async () => {
                    const result = await firstValueFrom(
                        this.httpService.post(postUrl, payload, {
                            headers: {
                                'Content-Type': 'application/json',
                                Accept: 'application/json',
                                Authorization: authHeader,
                            },
                            timeout: 30000,
                        }),
                    );
                    return result;
                },
                this.getRetryStrategy(),
                { provider: 'GOSAC_OFICIAL' },
            );

            const campaignId =
                createResponse.data?.campaignId ||
                createResponse.data?.id ||
                createResponse.data?.data?.id;

            if (!campaignId) {
                return {
                    success: false,
                    error: 'ID da campanha não encontrado na resposta',
                    data: createResponse.data,
                };
            }

            return {
                success: true,
                message: 'Campanha oficial criada na GoSAC com sucesso',
                campaignId: campaignId.toString(),
                data: {
                    campaignId,
                    body: createResponse.data,
                },
            };
        } catch (error: any) {
            return this.handleError(error, { provider: 'GOSAC_OFICIAL' });
        }
    }

    async startCampaign(
        campaignId: string,
        credentials: ProviderCredentials,
    ): Promise<ProviderResponse> {
        const baseUrl = (credentials.url as string).replace(/\/$/, '');
        const url = `${baseUrl}/${campaignId}/status/started`;
        const authToken = (credentials.token as string)?.trim();
        const authHeader = authToken?.toLowerCase().startsWith('bearer ')
            ? authToken
            : `Bearer ${authToken}`;

        try {
            const response = await this.executeWithRetry(
                async () => {
                    const result = await firstValueFrom(
                        this.httpService.put(
                            url,
                            {},
                            {
                                headers: {
                                    'Content-Type': 'application/json',
                                    Authorization: authHeader,
                                },
                                timeout: 60000,
                            },
                        ),
                    );
                    return result;
                },
                this.getRetryStrategy(),
                { provider: 'GOSAC_OFICIAL' },
            );

            return {
                success: true,
                message: 'Campanha oficial iniciada com sucesso',
                data: response.data,
            };
        } catch (error: any) {
            return this.handleError(error, { provider: 'GOSAC_OFICIAL' });
        }
    }
}
