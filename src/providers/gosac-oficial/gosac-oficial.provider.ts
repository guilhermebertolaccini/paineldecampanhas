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

        // Extrai template e connectionId do JSON da mensagem (formato da API GOSAC)
        let templateId: number | null = null;
        let connectionId: number | null = null;

        if (data[0].mensagem && typeof data[0].mensagem === 'string' && data[0].mensagem.trim().startsWith('{')) {
            try {
                const parsed = JSON.parse(data[0].mensagem);
                if (parsed.id) templateId = parsed.id;
                if (parsed.connectionId) connectionId = parsed.connectionId;
            } catch (e) {
                this.logger.warn(`⚠️ [Gosac Oficial] Falha ao parsear JSON da mensagem: ${e.message}`);
            }
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

        // Formata contatos conforme doc: number, name, cpf, variables
        const contacts = data
            .filter((dado) => dado.nome && dado.telefone)
            .map((dado) => {
                const base: { number: string; name: string; cpf?: string; variables?: any[] } = {
                    number: this.normalizePhoneNumber(dado.telefone),
                    name: dado.nome || '',
                };
                if ((dado as any).cpf_cnpj) base.cpf = String((dado as any).cpf_cnpj).replace(/\D/g, '').slice(0, 11);
                return base;
            });

        if (contacts.length === 0) {
            return {
                success: false,
                error: 'Nenhum contato válido para enviar',
            };
        }

        const now = new Date();
        const campanha = `campanha_oficial_${now.getTime()}`;

        // Payload conforme doc POST /campaigns/official: idAmbient, idRuler, name, connectionId, templateId, contacts
        const payload: any = {
            idAmbient: String(idAmbient),
            idRuler: String(idRuler),
            name: `${campanha}_${now.toISOString().replace(/[:.]/g, '-')}`,
            connectionId: connectionId ?? undefined,
            templateId: templateId ?? undefined,
            contacts,
        };

        try {
            const createResponse = await this.executeWithRetry(
                async () => {
                    const result = await firstValueFrom(
                        this.httpService.post(credentials.url as string, payload, {
                            headers: {
                                'Content-Type': 'application/json',
                                Accept: 'application/json',
                                Authorization: credentials.token as string,
                            },
                            timeout: 30000,
                        }),
                    );
                    return result;
                },
                this.getRetryStrategy(),
                { provider: 'GOSAC_OFICIAL' },
            );

            const campaignId = createResponse.data?.id || createResponse.data?.data?.id;

            if (!campaignId) {
                return {
                    success: false,
                    error: 'ID da campanha não encontrado na resposta',
                    data: createResponse.data,
                };
            }

            return {
                success: true,
                message: 'Campanha oficial criada e agendada',
                campaignId: campaignId.toString(),
                data: {
                    campaignId,
                    url: `${credentials.url}/${campaignId}/status/started`,
                    token: credentials.token,
                    scheduledAt: new Date(Date.now() + 60000).toISOString(),
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
        const url = `${credentials.url}/${campaignId}/status/started`;
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
                                    Authorization: credentials.token as string,
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
