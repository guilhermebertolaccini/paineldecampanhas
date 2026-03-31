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

/** Uma variável HSM por contato (contrato atual do provider). */
type GosacContactVariable = {
    componentId: number;
    variable: string;
    value: string;
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
     * Campo da base em `CampaignData` (o WP não envia colunas arbitrárias; só chaves conhecidas + index signature implícita em runtime).
     */
    private pickRowFieldValue(dado: CampaignData, fieldName: string): string {
        const f = (fieldName || '').trim();
        if (!f) return '';
        const rec = dado as unknown as Record<string, unknown>;
        const direct = rec[f];
        if (direct != null && direct !== '') return String(direct);
        const upper = rec[f.toUpperCase()];
        if (upper != null && upper !== '') return String(upper);
        return '';
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

    private normalizeContactVariablesFromPhp(raw: unknown): GosacContactVariable[] {
        if (!Array.isArray(raw)) return [];
        const out: GosacContactVariable[] = [];
        for (const row of raw) {
            if (!row || typeof row !== 'object') continue;
            const r = row as Record<string, unknown>;
            const componentId = Number(r.componentId ?? r.component_id ?? 0) || 0;
            const variable = r.variable != null ? String(r.variable) : '';
            const value = r.value != null ? String(r.value) : '';
            if (variable === '' && componentId === 0) continue;
            out.push({ componentId, variable, value });
        }
        return out;
    }

    /**
     * Alinha `components` estilo NOAH (body + parameters text) com `variableComponents` da API GOSAC.
     */
    private variablesFromBodyComponentsAndVc(
        components: unknown,
        variableComponents: { componentId: number; variable: string }[],
    ): GosacContactVariable[] {
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
            const out: GosacContactVariable[] = [];
            for (let i = 0; i < variableComponents.length; i++) {
                const vc = variableComponents[i];
                out.push({
                    componentId: vc.componentId,
                    variable: vc.variable,
                    value: texts[i] ?? '',
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
    ): GosacContactVariable[] {
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

        const out: GosacContactVariable[] = [];
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
                    (k) => k.toLowerCase() === rawVar.toLowerCase() || k.toLowerCase() === mapKey.toLowerCase(),
                );
                if (found) entry = variablesMap[found];
            }
            const value = this.resolveVariablesMapEntryToValue(dado, entry);
            out.push({
                componentId: vc.componentId,
                variable: rawVar || mapKey,
                value,
            });
        }

        if (out.length === 0 && Object.keys(variablesMap).length > 0) {
            for (const [varName, entry] of Object.entries(variablesMap)) {
                const value = this.resolveVariablesMapEntryToValue(dado, entry);
                out.push({ componentId: 0, variable: varName, value });
            }
        }

        return out;
    }

    private resolveCampaignDisplayName(data: CampaignData[], firstParsed: Record<string, unknown> | null): string {
        const fromRow = (data[0]?.nome_campanha ?? '').trim();
        if (fromRow) return fromRow.slice(0, 255);
        const fromJson =
            firstParsed && firstParsed.nome_campanha != null ? String(firstParsed.nome_campanha).trim() : '';
        if (fromJson) return fromJson.slice(0, 255);
        const now = new Date();
        return `campanha_oficial_${now.getTime()}`;
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

        // Extrai template e connectionId do JSON da mensagem (API exige ambos como números)
        let templateId: number | null = null;
        let connectionId: number | null = null;

        // Tenta extrair de qualquer mensagem do batch (todas devem ter o mesmo template)
        for (const item of data) {
            const msg = (item as any).mensagem;
            if (msg && typeof msg === 'string' && msg.trim().startsWith('{')) {
                try {
                    const parsed = JSON.parse(msg);
                    if (parsed.id != null) templateId = parseInt(String(parsed.id), 10);
                    if (parsed.connectionId != null) connectionId = parseInt(String(parsed.connectionId), 10);
                    if (templateId && templateId > 0 && connectionId && connectionId > 0) break;
                } catch (e) {
                    this.logger.warn(`⚠️ [Gosac Oficial] Falha ao parsear JSON da mensagem: ${e.message}`);
                }
            }
        }

        if (!templateId || templateId <= 0 || !connectionId || connectionId <= 0) {
            const sample = data[0] ? JSON.stringify((data[0] as any).mensagem || '').slice(0, 200) : 'vazio';
            this.logger.warn(`⚠️ [Gosac Oficial] Template/connectionId ausentes. Amostra mensagem: ${sample}`);
            return {
                success: false,
                error: 'connectionId e templateId são obrigatórios. Verifique se o template GOSAC foi selecionado corretamente na Nova Campanha e se a carteira tem id_ruler configurado.',
            };
        }

        // idAmbient e idRuler vêm da carteira (PHP busca por id_carteira e retorna nas credenciais)
        const idAmbient = (credentials as any).id_carteira || (data[0] as any)?.id_carteira;
        const idRuler = (credentials as any).idRuler;

        if (!idAmbient || !idRuler) {
            return {
                success: false,
                error: 'idAmbient e idRuler são obrigatórios. Configure id_carteira e id_ruler na carteira em Configurações.',
            };
        }

        const firstParsed = this.parseGosacMensagemJson(data[0].mensagem);
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

        const campaignDisplayName = this.resolveCampaignDisplayName(data, firstParsed);

        // Formata contatos conforme doc: number, name, cpf, variables (HSM)
        const contacts = data
            .filter((dado) => dado.nome && dado.telefone)
            .map((dado) => {
                const rowParsed = this.parseGosacMensagemJson(dado.mensagem) ?? firstParsed;
                const variables = this.buildVariablesForContact(
                    dado,
                    rowParsed,
                    variableComponents,
                    variablesMap,
                );

                const base: {
                    number: string;
                    name: string;
                    cpf?: string;
                    variables?: GosacContactVariable[];
                } = {
                    number: this.normalizePhoneNumber(dado.telefone),
                    name: dado.nome || '',
                };
                if (dado.cpf_cnpj) {
                    base.cpf = String(dado.cpf_cnpj).replace(/\D/g, '').slice(0, 11);
                }
                if (variables.length > 0) {
                    base.variables = variables;
                }
                return base;
            });

        if (contacts.length === 0) {
            return {
                success: false,
                error: 'Nenhum contato válido para enviar',
            };
        }

        const now = new Date();
        const nameSuffix = now.toISOString().replace(/[:.]/g, '-');
        const payloadName =
            campaignDisplayName && !campaignDisplayName.startsWith('campanha_oficial_')
                ? `${campaignDisplayName} — ${nameSuffix}`
                : `${campaignDisplayName}_${nameSuffix}`;

        // Payload conforme doc POST /campaigns/official - connectionId e templateId obrigatórios
        const payload: Record<string, unknown> = {
            idAmbient: String(idAmbient),
            idRuler: String(idRuler),
            name: payloadName.slice(0, 500),
            connectionId,
            templateId,
            contacts,
        };

        const baseUrl = (credentials.url as string).replace(/\/$/, '');
        const postUrl = `${baseUrl}/campaigns/official`;
        const authToken = (credentials.token as string)?.trim();
        const authHeader = authToken?.toLowerCase().startsWith('bearer ') ? authToken : `Bearer ${authToken}`;

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

            const campaignId = createResponse.data?.campaignId || createResponse.data?.id || createResponse.data?.data?.id;

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
        const authHeader = authToken?.toLowerCase().startsWith('bearer ') ? authToken : `Bearer ${authToken}`;

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
