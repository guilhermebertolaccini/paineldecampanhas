import { Injectable } from '@nestjs/common';
import { HttpService } from '@nestjs/axios';
import { firstValueFrom } from 'rxjs';
import { AxiosResponse } from 'axios';
import { BaseProvider } from '../base/base.provider';
import {
  CampaignData,
  ProviderResponse,
  ProviderCredentials,
  RetryStrategy,
} from '../base/provider.interface';

/** Limite da Composite API (Salesforce Core). */
const COMPOSITE_SOBJECTS_MAX_RECORDS = 200;

const DEFAULT_SF_API_VERSION = 'v59.0';

export type SalesforceCoreAuthResult = {
  accessToken: string;
  instanceUrl: string;
};

@Injectable()
export class SalesforceProvider extends BaseProvider {
  constructor(httpService: HttpService) {
    super(httpService, 'SalesforceProvider');
  }

  getRetryStrategy(): RetryStrategy {
    return {
      maxRetries: 3,
      delays: [2000, 5000, 10000],
    };
  }

  /** MC: aceita mc_* (Postman) ou mkc_* (legado UI). */
  private resolveMcClientId(c: ProviderCredentials): string | undefined {
    const v = c.mc_client_id ?? c.mkc_client_id;
    return v != null ? String(v).trim() || undefined : undefined;
  }

  private resolveMcClientSecret(c: ProviderCredentials): string | undefined {
    const v = c.mc_client_secret ?? c.mkc_client_secret;
    return v != null ? String(v).trim() || undefined : undefined;
  }

  private resolveMcTokenUrl(c: ProviderCredentials): string | undefined {
    const v = c.mc_token_url ?? c.mkc_token_url;
    return v != null ? String(v).trim() || undefined : undefined;
  }

  /**
   * Base REST do Marketing Cloud para disparar automação (host + path até antes do automation id).
   * Não usar `api_url` do WordPress quando for a URL do Composite do Core (.../composite/sobjects).
   */
  private resolveMcRestBaseUrl(c: ProviderCredentials): string | undefined {
    const explicit = c.mkc_api_url ?? c.mc_api_url;
    if (explicit != null && String(explicit).trim()) {
      return String(explicit).trim().replace(/\/+$/, '');
    }
    const apiUrl = c.api_url != null ? String(c.api_url).trim() : '';
    if (!apiUrl) {
      return undefined;
    }
    if (/\/composite\/sobjects/i.test(apiUrl)) {
      return undefined;
    }
    return apiUrl.replace(/\/+$/, '');
  }

  /**
   * URL exata do POST composite/sobjects: usa `api_url` se o WP já enviar o path completo; senão monta com instance_url.
   */
  private resolveCompositePostUrl(
    instanceUrl: string,
    credentials: ProviderCredentials,
  ): string {
    const apiUrl = credentials.api_url != null ? String(credentials.api_url).trim() : '';
    if (apiUrl && /\/composite\/sobjects/i.test(apiUrl)) {
      return apiUrl.replace(/\/+$/, '');
    }
    return this.buildCompositeSobjectsUrl(instanceUrl, credentials);
  }

  private resolveSfApiVersion(c: ProviderCredentials): string {
    const v = c.sf_api_version;
    if (v != null && String(v).trim()) {
      const s = String(v).trim();
      return s.startsWith('v') ? s : `v${s}`;
    }
    return DEFAULT_SF_API_VERSION;
  }

  private joinUrlPath(base: string, path: string): string {
    const b = base.replace(/\/+$/, '');
    const p = path.replace(/^\/+/, '');
    return `${b}/${p}`;
  }

  validateCredentials(credentials: ProviderCredentials): boolean {
    // MC é validado ao rodar o job atrasado (triggerMarketingCloud); o WP pode não enviar mkc_* no mesmo objeto.
    return !!(
      credentials.client_id &&
      credentials.client_secret &&
      credentials.username &&
      credentials.password &&
      credentials.token_url &&
      credentials.operacao &&
      credentials.automation_id
    );
  }

  /**
   * 1) Auth Salesforce Core — OAuth2 password (token_url).
   */
  async authenticateSalesforceCore(
    credentials: ProviderCredentials,
  ): Promise<SalesforceCoreAuthResult> {
    const params = new URLSearchParams();
    params.append('grant_type', 'password');
    params.append('client_id', String(credentials.client_id));
    params.append('client_secret', String(credentials.client_secret));
    params.append('username', String(credentials.username));
    params.append('password', String(credentials.password));

    const tokenResponse = await this.executeWithRetry(
      async () =>
        firstValueFrom(
          this.httpService.post(
            String(credentials.token_url),
            params.toString(),
            {
              headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                Accept: 'application/json',
              },
              timeout: 30000,
            },
          ),
        ),
      this.getRetryStrategy(),
      { provider: 'SALESFORCE' },
    );

    const accessToken = tokenResponse.data?.access_token as string | undefined;
    let instanceUrl = tokenResponse.data?.instance_url as string | undefined;

    if (!accessToken) {
      throw new Error('Resposta OAuth Core sem access_token');
    }

    if (!instanceUrl?.trim()) {
      instanceUrl =
        (credentials.instance_url as string) ||
        (credentials.sf_instance_url as string) ||
        (credentials.core_instance_url as string);
    }

    if (!instanceUrl?.trim()) {
      throw new Error(
        'OAuth Core não retornou instance_url; defina instance_url / sf_instance_url nas credenciais',
      );
    }

    return {
      accessToken,
      instanceUrl: instanceUrl.trim().replace(/\/+$/, ''),
    };
  }

  /**
   * URL da Composite API — sempre no host do Salesforce Core, nunca na raiz do MC.
   */
  buildCompositeSobjectsUrl(
    instanceUrl: string,
    credentials: ProviderCredentials,
  ): string {
    const version = this.resolveSfApiVersion(credentials);
    return this.joinUrlPath(
      instanceUrl,
      `services/data/${version}/composite/sobjects`,
    );
  }

  private mapRowToContactRecord(
    dado: CampaignData,
    operacao: string,
  ): Record<string, unknown> {
    const doc = (dado.cpf_cnpj ?? '').toString().trim();
    const lastName = (dado.nome ?? '').toString().trim() || 'Contato';
    return {
      attributes: { type: 'Contact' },
      MobilePhone: this.normalizePhoneNumber(dado.telefone),
      LastName: lastName,
      CPF_CNPJ__c: doc,
      Operacao__c: operacao,
      disparo__c: true,
    };
  }

  /**
   * 2) Importação no Core — lotes de até 200 em POST .../composite/sobjects.
   */
  async importContactsCompositeInChunks(
    accessToken: string,
    compositeUrl: string,
    rows: CampaignData[],
    operacao: string,
  ): Promise<{ chunksOk: number; totalRecords: number; lastResponse?: unknown }> {
    const records = rows.map((d) => this.mapRowToContactRecord(d, operacao));
    const chunks: Record<string, unknown>[][] = [];
    for (let i = 0; i < records.length; i += COMPOSITE_SOBJECTS_MAX_RECORDS) {
      chunks.push(records.slice(i, i + COMPOSITE_SOBJECTS_MAX_RECORDS));
    }

    let chunksOk = 0;
    let lastResponse: unknown;

    for (let idx = 0; idx < chunks.length; idx++) {
      const chunk = chunks[idx];
      const payload = { allOrNone: false, records: chunk };

      this.logger.log(
        `Composite sobjects: chunk ${idx + 1}/${chunks.length} (${chunk.length} registros) → ${compositeUrl}`,
      );

      const response: AxiosResponse = await this.executeWithRetry(
        async () =>
          firstValueFrom(
            this.httpService.post(compositeUrl, payload, {
              headers: {
                'Content-Type': 'application/json',
                Authorization: `Bearer ${accessToken}`,
              },
              timeout: 120000,
            }),
          ),
        this.getRetryStrategy(),
        { provider: 'SALESFORCE' },
      );

      lastResponse = response.data;
      const data = response.data as {
        hasErrors?: boolean;
        results?: Array<{ statusCode?: number; errors?: unknown }>;
      };

      if (data?.hasErrors && Array.isArray(data.results)) {
        const failed = data.results.filter(
          (r) => r.statusCode != null && r.statusCode >= 400,
        );
        this.logger.warn(
          `Composite chunk ${idx + 1}: hasErrors=true, ${failed.length} linha(s) com status >= 400`,
        );
      }

      chunksOk += 1;
    }

    return {
      chunksOk,
      totalRecords: records.length,
      lastResponse,
    };
  }

  /**
   * 3) Auth Marketing Cloud — client_credentials em .../v2/token.
   */
  async authenticateMarketingCloud(
    credentials: ProviderCredentials,
  ): Promise<string> {
    const clientId = this.resolveMcClientId(credentials);
    const clientSecret = this.resolveMcClientSecret(credentials);
    const tokenUrl = this.resolveMcTokenUrl(credentials);

    if (!clientId || !clientSecret || !tokenUrl) {
      throw new Error(
        'Credenciais MC incompletas (mc_client_id/mkc_client_id, secret, mc_token_url/mkc_token_url)',
      );
    }

    const tokenResponse = await this.executeWithRetry(
      async () =>
        firstValueFrom(
          this.httpService.post(
            tokenUrl,
            {
              grant_type: 'client_credentials',
              client_id: clientId,
              client_secret: clientSecret,
            },
            {
              headers: { 'Content-Type': 'application/json' },
              timeout: 30000,
            },
          ),
        ),
      this.getRetryStrategy(),
      { provider: 'SALESFORCE_MKC' },
    );

    const accessToken = tokenResponse.data?.access_token as string | undefined;
    if (!accessToken) {
      throw new Error('Resposta MC token sem access_token');
    }
    return accessToken;
  }

  async send(
    data: CampaignData[],
    credentials: ProviderCredentials,
  ): Promise<ProviderResponse> {
    if (!this.validateCredentials(credentials)) {
      return {
        success: false,
        error:
          'Credenciais inválidas: token_url, client_id, client_secret, username, password, operacao e automation_id são obrigatórios',
      };
    }

    if (!data || data.length === 0) {
      return {
        success: false,
        error: 'Nenhum dado para enviar',
      };
    }

    const rows: CampaignData[] = data.map((d) => ({
      ...d,
      mensagem:
        typeof (d as { mensagem?: unknown }).mensagem === 'string'
          ? ((d as { mensagem: string }).mensagem as string)
          : '',
    }));

    const operacao = String(credentials.operacao);
    const automationId = String(credentials.automation_id);

    try {
      const { accessToken, instanceUrl } =
        await this.authenticateSalesforceCore(credentials);

      const compositeUrl = this.resolveCompositePostUrl(instanceUrl, credentials);

      const importResult = await this.importContactsCompositeInChunks(
        accessToken,
        compositeUrl,
        rows,
        operacao,
      );

      return {
        success: true,
        message:
          'Contatos importados no Core (composite em lotes de 200); automação MC agendada em 15 minutos',
        data: {
          automationId,
          scheduledAt: new Date(Date.now() + 15 * 60 * 1000).toISOString(),
          contactsSent: importResult.totalRecords,
          compositeChunks: importResult.chunksOk,
          instanceUrl,
        },
      };
    } catch (error: unknown) {
      return this.handleError(error, { provider: 'SALESFORCE' });
    }
  }

  /**
   * 4) Disparo da automação no MC (job atrasado ~15 min) — POST .../{automationId}/actions/runallonce
   */
  async triggerMarketingCloud(
    automationId: string,
    credentials: ProviderCredentials,
  ): Promise<ProviderResponse> {
    const mcBase = this.resolveMcRestBaseUrl(credentials);
    if (!mcBase) {
      return {
        success: false,
        error:
          'Base REST do Marketing Cloud não configurada (mkc_api_url, mc_api_url ou api_url)',
      };
    }

    let accessToken: string;
    try {
      accessToken = await this.authenticateMarketingCloud(credentials);
    } catch (error: unknown) {
      return this.handleError(error, { provider: 'SALESFORCE_MKC' });
    }

    const url = this.joinUrlPath(
      mcBase,
      `${encodeURIComponent(automationId)}/actions/runallonce`,
    );

    try {
      const response = await this.executeWithRetry(
        async () =>
          firstValueFrom(
            this.httpService.post(
              url,
              {},
              {
                headers: {
                  'Content-Type': 'application/json',
                  Authorization: `Bearer ${accessToken}`,
                },
                timeout: 30000,
              },
            ),
          ),
        this.getRetryStrategy(),
        { provider: 'SALESFORCE_MKC' },
      );

      return {
        success: true,
        message: 'Automação do Marketing Cloud executada com sucesso',
        data: {
          status: response.status,
          body: response.data,
        },
      };
    } catch (error: unknown) {
      return this.handleError(error, { provider: 'SALESFORCE_MKC' });
    }
  }
}
