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

        // Tenta extrair template do JSON da mensagem
        let templateId = null;

        if (data[0].mensagem && typeof data[0].mensagem === 'string' && data[0].mensagem.trim().startsWith('{')) {
            try {
                const parsed = JSON.parse(data[0].mensagem);
                if (parsed.id) {
                    templateId = parsed.id;
                }
            } catch (e) {
                this.logger.warn(`⚠️ [Gosac Oficial] Falha ao parsear JSON da mensagem: ${e.message}`);
            }
        }

        // Formata contatos
        const contacts = data
            .filter((dado) => dado.nome && dado.telefone)
            .map((dado) => ({
                name: dado.nome,
                number: this.normalizePhoneNumber(dado.telefone),
                hasWhatsapp: true,
            }));

        if (contacts.length === 0) {
            return {
                success: false,
                error: 'Nenhum contato válido para enviar',
            };
        }

        const now = new Date();
        const campanha = `campanha_oficial_${now.getTime()}`;

        const payload = {
            name: `${campanha}_${now.toISOString().replace(/[:.]/g, '-')}`,
            kind: 'whats',
            connectionId: null, // Pode ser expandido se necessário
            contacts: contacts,
            defaultQueueId: 1,
            scheduled: false,
            scheduledAt: now.toISOString(),
            speed: 'low',
            templateId: templateId,
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
