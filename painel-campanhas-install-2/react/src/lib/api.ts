/**
 * API Client para integração com WordPress AJAX
 */

// Configuração da URL do WordPress
const getAjaxUrl = () => {
  // Se o WordPress já forneceu a URL correta via window.pcAjax, usa ela diretamente
  if (typeof (window as any).pcAjax !== 'undefined' && (window as any).pcAjax?.ajaxurl) {
    const ajaxUrl = (window as any).pcAjax.ajaxurl;
    console.log('🔵 [API] Usando AJAX URL do WordPress:', ajaxUrl);
    return ajaxUrl;
  }

  // Fallback: constrói URL absoluta (site raiz + /wp-admin/admin-ajax.php)
  const fallbackUrl = `${window.location.origin}/wp-admin/admin-ajax.php`;
  console.warn('⚠️ [API] window.pcAjax não encontrado, usando fallback:', fallbackUrl);
  return fallbackUrl;
};

// Helper para fazer requisições AJAX do WordPress
export const wpAjax = async (action: string, data: Record<string, any> = {}, nonceType: 'nonce' | 'cmNonce' = 'nonce') => {
  const formData = new FormData();
  formData.append('action', action);

  // Adiciona nonce se disponível
  if (typeof (window as any).pcAjax !== 'undefined') {
    // Para ações cm_* usa cmNonce, para pc_* usa nonce
    const nonce = (window as any).pcAjax[nonceType] || (window as any).pcAjax.nonce;
    if (nonce) {
      formData.append('nonce', nonce);
      console.log(`🔵 [API] Usando nonce tipo: ${nonceType} para ação: ${action}`);
    } else {
      console.warn(`⚠️ [API] Nonce ${nonceType} não encontrado para ação: ${action}`);
    }
  }

  // Adiciona outros dados
  Object.keys(data).forEach(key => {
    if (data[key] !== null && data[key] !== undefined) {
      if (data[key] instanceof File) {
        formData.append(key, data[key]);
      } else if (Array.isArray(data[key])) {
        // Para arrays, envia cada item separadamente com [] no nome
        // Isso faz o PHP receber como array nativo
        if (key === 'bases' && action === 'pc_vincular_base_carteira') {
          // Envia como array PHP nativo
          data[key].forEach((item: any, index: number) => {
            formData.append(`${key}[${index}]`, item);
          });
          console.log('🔵 [API] Enviando bases como array PHP nativo:', data[key]);
        } else {
          // Para outros arrays, envia como JSON
          formData.append(key, JSON.stringify(data[key]));
        }
      } else if (typeof data[key] === 'object') {
        const jsonValue = JSON.stringify(data[key]);
        formData.append(key, jsonValue);
      } else {
        formData.append(key, data[key]);
      }
    }
  });

  // Log do FormData para debug (apenas para vincular bases)
  if (action === 'pc_vincular_base_carteira') {
    console.log('🔵 [API] FormData completo:');
    for (const [key, value] of formData.entries()) {
      console.log(`🔵 [API]   ${key}:`, value);
    }
  }

  try {
    const ajaxUrl = getAjaxUrl();
    const response = await fetch(ajaxUrl, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
    });

    // Verifica se a resposta é JSON válido
    const contentType = response.headers.get('content-type');
    if (!contentType || !contentType.includes('application/json')) {
      const text = await response.text();
      console.error('Resposta não é JSON:', text.substring(0, 500));
      throw new Error(`Erro na requisição: ${response.status} ${response.statusText}. URL: ${ajaxUrl}`);
    }

    const result = await response.json();

    // Log detalhado para debug
    if (action === 'pc_get_bases_carteira') {
      console.log('🔵 [API] Resposta completa do backend:', result);
      console.log('🔵 [API] result.success:', result.success);
      console.log('🔵 [API] result.data:', result.data);
      console.log('🔵 [API] result.data type:', typeof result.data);
      console.log('🔵 [API] result.data isArray:', Array.isArray(result.data));
    }

    if (!result.success) {
      throw new Error(result.data?.message || result.data || 'Erro na requisição');
    }

    // Garante que retorna array se for pc_get_bases_carteira
    if (action === 'pc_get_bases_carteira') {
      const data = result.data;
      if (Array.isArray(data)) {
        return data;
      } else if (data && typeof data === 'object') {
        // Se vier como objeto, tenta converter
        console.warn('⚠️ [API] Dados não são array, tentando converter:', data);
        return Object.values(data);
      }
      return [];
    }

    return result.data;
  } catch (error) {
    console.error('Erro na requisição AJAX:', error);
    throw error;
  }
};

// API específicas para o plugin

// Login
export const login = (email: string, password: string) => {
  return wpAjax('pc_login', { email, password });
};

// Logout
export const logout = () => {
  return wpAjax('pc_logout', {});
};

// Dashboard
export const getDashboardStats = () => {
  return wpAjax('pc_get_dashboard_stats', {});
};

// Bases disponíveis
export const getAvailableBases = () => {
  return wpAjax('pc_get_available_bases', {});
};

// Campanhas
export const getCampanhas = (params: Record<string, any> = {}) => {
  return wpAjax('pc_get_campanhas', params);
};

export const scheduleCampaign = (data: Record<string, any>) => {
  // Formata os dados conforme esperado pelo backend
  const payload: Record<string, any> = {
    table_name: data.base || data.table_name,
    filters: data.filters || [],
    providers_config: data.providers_config || {},
    template_id: data.template_id || data.template,
    record_limit: data.record_limit || 0,
    exclude_recent_phones: data.exclude_recent_phones !== undefined ? data.exclude_recent_phones : 1,
    exclude_recent_hours: data.exclude_recent_hours !== undefined ? data.exclude_recent_hours : 48,
    include_baits: data.include_baits !== undefined ? data.include_baits : 0,
    test_only: data.test_only !== undefined ? data.test_only : 0,
    throttling_type: data.throttling_type || 'none',
    throttling_config: JSON.stringify(data.throttling_config || {}),
  };

  // Adiciona campos para templates da Ótima
  if (data.template_code) {
    payload.template_code = data.template_code;
  }
  if (data.template_source) {
    payload.template_source = data.template_source;
  }
  if (data.broker_code) {
    payload.broker_code = data.broker_code;
  }
  if (data.customer_code) {
    payload.customer_code = data.customer_code;
  }
  if (data.midia_campanha) {
    payload.midia_campanha = data.midia_campanha;
  }

  return wpAjax('cm_schedule_campaign', payload, 'cmNonce');
};

export const getPendingCampaigns = () => {
  return wpAjax('pc_get_pending_campaigns', {});
};

export const approveCampaign = (agendamentoId: string, fornecedor: string) => {
  return wpAjax('pc_approve_campaign', { agendamento_id: agendamentoId, fornecedor });
};

export const denyCampaign = (agendamentoId: string, fornecedor: string, motivo?: string) => {
  return wpAjax('pc_deny_campaign', { agendamento_id: agendamentoId, fornecedor, motivo });
};

// Filtros e bases
export const getFilters = (base: string) => {
  // Usa cmNonce para handlers de campanha (cm_*)
  return wpAjax('cm_get_filters', { table_name: base }, 'cmNonce');
};

export const getCount = (data: Record<string, any>) => {
  // Usa cmNonce para handlers de campanha (cm_*)
  return wpAjax('cm_get_count', {
    table_name: data.table_name || data.base,
    filters: data.filters || [],
  }, 'cmNonce');
};

export const getCountDetailed = (data: Record<string, any>) => {
  return wpAjax('cm_get_count_detailed', {
    table_name: data.table_name || data.base,
    filters: data.filters || [],
    exclude_recent: data.exclude_recent,
    exclude_recent_hours: data.exclude_recent_hours || 48,
  }, 'cmNonce');
};

// Templates de mensagem
export const getMessages = () => {
  return wpAjax('pc_get_messages', {});
};

export const getMessage = (id: string) => {
  return wpAjax('pc_get_message', { message_id: id });
};

export const createMessage = (data: Record<string, any>) => {
  return wpAjax('pc_create_message', data);
};

export const updateMessage = (id: string, data: Record<string, any>) => {
  return wpAjax('pc_update_message', { message_id: id, ...data });
};

export const deleteMessage = (id: string) => {
  return wpAjax('pc_delete_message', { message_id: id });
};

export const getIscas = () => {
  return wpAjax('pc_get_iscas', {});
};

export const getOtimaTemplates = () => {
  return wpAjax('pc_get_otima_templates', {});
};

export const getOtimaBrokers = () => {
  return wpAjax('pc_get_otima_brokers', {});
};

export const getGosacOficialTemplates = () => {
  return wpAjax('pc_get_gosac_oficial_templates', {});
};

export const getGosacOficialConnections = () => {
  return wpAjax('pc_get_gosac_oficial_connections', {});
};


export const getTemplateContent = async (id: string) => {
  console.log('📄 [getTemplateContent] ID recebido:', id, 'Tipo:', typeof id);

  // Valida se ID é válido
  if (!id || id === '' || id === '0') {
    console.error('🔴 [getTemplateContent] ID inválido:', id);
    throw new Error('ID do template inválido');
  }

  const templateId = parseInt(id);
  if (isNaN(templateId) || templateId <= 0) {
    console.error('🔴 [getTemplateContent] ID não é um número válido:', id);
    throw new Error('ID do template inválido');
  }

  console.log('✅ [getTemplateContent] Buscando template ID:', templateId);

  // Usa cmNonce para handlers de campanha (cm_*)
  const content = await wpAjax('cm_get_template_content', { template_id: templateId }, 'cmNonce');

  console.log('📄 [getTemplateContent] Conteúdo recebido:', typeof content === 'string' ? content.substring(0, 50) + '...' : content);

  // O handler retorna apenas a string do conteúdo, normalizamos para objeto
  return typeof content === 'string' ? { content } : content;
};

// Relatórios
export const getReportData = (params: Record<string, any> = {}) => {
  // Normaliza os nomes dos parâmetros
  return wpAjax('pc_get_report_data', {
    filter_date_start: params.data_inicio || params.dateFrom || '',
    filter_date_end: params.data_fim || params.dateTo || '',
    filter_fornecedor: params.fornecedor || params.provider || '',
    filter_user: params.filter_user || '',
    filter_ambiente: params.filter_ambiente || '',
    filter_agendamento: params.filter_agendamento || '',
    filter_idgis: params.filter_idgis || 0,
    status: params.status || '',
  });
};

export const getReport1x1Stats = (params: Record<string, any> = {}) => {
  return wpAjax('pc_get_report_1x1_stats', params);
};

// Campanhas recorrentes
export const getRecurring = () => {
  return wpAjax('cm_get_recurring', {}, 'cmNonce');
};

export const getRecurringEstimates = (id: string | number) => {
  return wpAjax('cm_get_recurring_estimates', { id: parseInt(id.toString()) }, 'cmNonce');
};

export const saveRecurring = (data: Record<string, any>) => {
  // Formata os dados conforme esperado pelo backend
  const payload: Record<string, any> = {
    nome_campanha: data.nome_campanha,
    table_name: data.table_name,
    template_id: data.template_id,
    providers_config: typeof data.providers_config === 'string'
      ? data.providers_config
      : JSON.stringify(data.providers_config || {}),
    filters: typeof data.filters === 'string'
      ? data.filters
      : JSON.stringify(data.filters || []),
    record_limit: data.record_limit || 0,
    exclude_recent_phones: data.exclude_recent_phones !== undefined ? data.exclude_recent_phones : 1,
    exclude_recent_hours: data.exclude_recent_hours !== undefined ? data.exclude_recent_hours : 48,
    include_baits: data.include_baits !== undefined ? data.include_baits : 0,
    throttling_type: data.throttling_type || 'none',
    throttling_config: typeof data.throttling_config === 'string'
      ? data.throttling_config
      : JSON.stringify(data.throttling_config || {}),
    id: data.id, // Se tiver id, será update, senão será insert
  };

  if (data.template_code) {
    payload.template_code = data.template_code;
  }
  if (data.template_source) {
    payload.template_source = data.template_source;
  }
  if (data.broker_code) {
    payload.broker_code = data.broker_code;
  }
  if (data.customer_code) {
    payload.customer_code = data.customer_code;
  }

  return wpAjax('cm_save_recurring', payload, 'cmNonce');
};

export const deleteRecurring = (id: string) => {
  return wpAjax('cm_delete_recurring', { id: parseInt(id) }, 'cmNonce');
};

export const toggleRecurring = (id: string, active: boolean) => {
  return wpAjax('cm_toggle_recurring', { id: parseInt(id), ativo: active ? 1 : 0 }, 'cmNonce');
};

export const executeRecurringNow = (id: string) => {
  return wpAjax('cm_execute_recurring_now', { id: parseInt(id) }, 'cmNonce');
};

// Campanha por arquivo
export const uploadCampaignFile = async (file: File, matchField: string) => {
  const formData = new FormData();
  formData.append('csv_file', file);
  formData.append('match_field', matchField);

  const ajaxUrl = typeof (window as any).pcAjax !== 'undefined' && (window as any).pcAjax?.ajaxurl
    ? (window as any).pcAjax.ajaxurl
    : '/wp-admin/admin-ajax.php';

  const nonce = typeof (window as any).pcAjax !== 'undefined' && (window as any).pcAjax?.nonce
    ? (window as any).pcAjax.nonce
    : '';

  formData.append('action', 'cpf_cm_upload_csv');
  formData.append('nonce', nonce);

  // Removendo explicitamente headers para que o fetch/browser defina o boundary correto do form-data
  const response = await fetch(ajaxUrl, {
    method: 'POST',
    body: formData,
  });

  const result = await response.json();

  if (!result.success) {
    throw new Error(result.data?.message || result.data || 'Erro no upload');
  }

  return result.data;
};

export const getCustomFilters = () => {
  return wpAjax('cpf_cm_get_custom_filters', {});
};

export const previewCount = (data: Record<string, any>) => {
  return wpAjax('cpf_cm_preview_count', data);
};

export const createCpfCampaign = (data: Record<string, any>) => {
  const payload: Record<string, any> = {
    temp_id: data.temp_id,
    table_name: data.table_name,
    template_id: data.template_id,
    template_code: data.template_code,
    template_source: data.template_source,
    broker_code: data.broker_code,
    customer_code: data.customer_code,
    provider: data.provider,
    match_field: data.match_field || 'cpf',
    include_baits: data.include_baits || 0,
    show_already_sent: data.show_already_sent || 0,
  };

  if (data.variables_map) {
    payload.variables_map = JSON.stringify(data.variables_map);
  }

  // O handler PHP exige o 'providers_config'
  if (!data.providers_config) {
    payload.providers_config = JSON.stringify({
      mode: 'split',
      providers: [
        { id: data.provider, weight: 100 }
      ]
    });
  } else {
    payload.providers_config = JSON.stringify(data.providers_config);
  }

  return wpAjax('cpf_cm_create_campaign', payload);
};

// Controle de custos
export const saveCustoProvider = (data: Record<string, any>) => {
  return wpAjax('pc_save_custo_provider', data);
};

export const getCustosProviders = () => {
  return wpAjax('pc_get_custos_providers', {});
};

export const deleteCustoProvider = (id: string) => {
  return wpAjax('pc_delete_custo_provider', { id });
};

export const saveOrcamentoBase = (data: Record<string, any>) => {
  return wpAjax('pc_save_orcamento_base', data);
};

export const getOrcamentosBases = (params: Record<string, any> = {}) => {
  return wpAjax('pc_get_orcamentos_bases', params);
};

export const deleteOrcamentoBase = (id: string) => {
  return wpAjax('pc_delete_orcamento_base', { id });
};

export const getRelatorioCustos = (params: Record<string, any> = {}) => {
  return wpAjax('pc_get_relatorio_custos', params);
};

// Configurações (Carteiras)
export const getCarteiras = () => {
  return wpAjax('pc_get_carteiras', {});
};

export const getCarteira = (id: string) => {
  return wpAjax('pc_get_carteira', { id });
};

export const createCarteira = (data: Record<string, any>) => {
  return wpAjax('pc_create_carteira', data);
};

export const updateCarteira = (id: string, data: Record<string, any>) => {
  return wpAjax('pc_update_carteira', { id, ...data });
};

export const deleteCarteira = (id: string) => {
  return wpAjax('pc_delete_carteira', { id });
};

export const getBasesCarteira = async (carteiraId: string) => {
  const carteiraIdNum = parseInt(carteiraId, 10);
  console.log('🔵 [API] getBasesCarteira chamado:', { carteiraId, carteiraIdNum });
  const result = await wpAjax('pc_get_bases_carteira', { carteira_id: carteiraIdNum });
  console.log('🔵 [API] getBasesCarteira resultado:', { carteiraId, result, type: typeof result, isArray: Array.isArray(result) });
  return result;
};

export const vincularBaseCarteira = async (carteiraId: string, bases: string[]) => {
  console.log('🔵 [API] vincularBaseCarteira chamado:', { carteiraId, bases, basesType: typeof bases, isArray: Array.isArray(bases) });

  // Garante que bases é um array
  const basesArray = Array.isArray(bases) ? bases : [];

  if (basesArray.length === 0) {
    console.warn('⚠️ [API] Nenhuma base para vincular!');
    throw new Error('Nenhuma base selecionada para vincular');
  }

  // Garante que carteiraId é um número válido
  const carteiraIdNum = parseInt(carteiraId, 10);
  if (isNaN(carteiraIdNum) || carteiraIdNum <= 0) {
    throw new Error('ID da carteira inválido');
  }

  console.log('🔵 [API] Enviando para wpAjax:', { carteira_id: carteiraIdNum, bases: basesArray, basesCount: basesArray.length });

  const result = await wpAjax('pc_vincular_base_carteira', { carteira_id: carteiraIdNum, bases: basesArray });

  console.log('🔵 [API] Resposta do vincularBaseCarteira:', result);

  return result;
};

// Iscas

export const getIsca = (id: string) => {
  return wpAjax('pc_get_isca', { id });
};

export const createIsca = (data: Record<string, any>) => {
  return wpAjax('pc_create_isca', data);
};

export const updateIsca = (id: string, data: Record<string, any>) => {
  return wpAjax('pc_update_isca', { id, ...data });
};

export const deleteIsca = (id: string) => {
  return wpAjax('pc_delete_isca', { id });
};

// Validação de Base
export const checkBaseUpdate = (tableName: string) => {
  return wpAjax('cm_check_base_update', { table_name: tableName }, 'cmNonce');
};

// Ranking
export const getRanking = () => {
  return wpAjax('pc_get_ranking', {});
};

// API Manager
export const saveMasterApiKey = (key: string) => {
  return wpAjax('pc_save_master_api_key', { master_api_key: key });
};

export const getMasterApiKey = () => {
  return wpAjax('pc_get_master_api_key', {});
};

export const getMicroserviceConfig = () => {
  return wpAjax('pc_get_microservice_config', {});
};

export const saveMicroserviceConfig = (data: Record<string, any>) => {
  return wpAjax('pc_save_microservice_config', data);
};

export const getStaticCredentials = async () => {
  console.log('🔵 [API] Buscando credenciais estáticas...');
  const result = await wpAjax('pc_get_static_credentials', {});
  console.log('🔵 [API] Credenciais estáticas retornadas:', result);
  console.log('🔵 [API] Campos preenchidos:', Object.entries(result || {}).filter(([_, v]) => v && String(v).trim()).map(([k]) => k));
  return result;
};

export const saveStaticCredentials = (data: Record<string, any>) => {
  return wpAjax('pc_save_static_credentials', data);
};

export const getOtimaCustomers = (provider: 'rcs' | 'wpp' = 'rcs') => {
  return wpAjax('pc_get_otima_customers', { provider });
};

export const listCredentials = () => {
  return wpAjax('pc_list_credentials', {});
};

export const createCredential = (data: Record<string, any>) => {
  return wpAjax('pc_create_credential', data);
};

export const getCredential = (provider: string, envId: string) => {
  return wpAjax('pc_get_credential', { provider, env_id: envId });
};

export const updateCredential = (provider: string, envId: string, data: Record<string, any>) => {
  return wpAjax('pc_update_credential', { provider, env_id: envId, credential_data: data });
};

export const deleteCredential = (provider: string, envId: string) => {
  return wpAjax('pc_delete_credential', { provider, env_id: envId });
};

// Custom Providers APIs
export const createCustomProvider = (data: Record<string, any>) => {
  return wpAjax('pc_create_custom_provider', data);
};

export const listCustomProviders = () => {
  return wpAjax('pc_list_custom_providers', {});
};

export const getCustomProvider = (providerKey: string) => {
  return wpAjax('pc_get_custom_provider', { provider_key: providerKey });
};

export const updateCustomProvider = (providerKey: string, data: Record<string, any>) => {
  return wpAjax('pc_update_custom_provider', { provider_key: providerKey, ...data });
};

export const deleteCustomProvider = (providerKey: string) => {
  return wpAjax('pc_delete_custom_provider', { provider_key: providerKey });
};

// Salesforce Manual Import Trigger
export const runSalesforceImport = () => {
  return wpAjax('pc_run_salesforce_import', {});
};

// Tracking Salesforce
export interface SalesforceTrackingParams {
  page?: number;
  per_page?: number;
  search?: string;
  status_filter?: string;
  date_from?: string;
  date_to?: string;
}

export interface SalesforceTrackingResponse {
  records: SalesforceTrackingRecord[];
  total_count: number;
  page: number;
  per_page: number;
  total_pages: number;
  statuses: string[];
}

export interface SalesforceTrackingRecord {
  id: string;
  mobilenumber: string;
  name: string;
  cpf_cnpj__c: string;
  status: string;
  trackingtype: string;
  sendtype: string;
  channeltype: string;
  activityname: string;
  channelname: string;
  reason: string;
  eventdateutc: string;
  criado_em: string;
  contactkey: string;
  operacao__c: string;
}

export const getSalesforceTracking = (params: SalesforceTrackingParams = {}): Promise<SalesforceTrackingResponse> => {
  return wpAjax('pc_get_salesforce_tracking', params);
};

export const downloadSalesforceCsv = (params: SalesforceTrackingParams & { max_rows?: number } = {}) => {
  return wpAjax('pc_download_salesforce_csv', params);
};

// Upload de mídia de campanha (PNG/JPEG)
export const uploadCampaignMedia = (file: File): Promise<{ attachment_id: number; url: string; filename: string }> => {
  return wpAjax('pc_upload_campaign_media', { media_file: file });
};

// Relatórios Multi-Tabela
export const getEnviosPendentes = (params: Record<string, any> = {}) => {
  return wpAjax('pc_get_envios_pendentes', params);
};

export const getEventosEnvios = (params: Record<string, any> = {}) => {
  return wpAjax('pc_get_eventos_envios', params);
};

export const getEventosIndicadores = (params: Record<string, any> = {}) => {
  return wpAjax('pc_get_eventos_indicadores', params);
};

export const getEventosTempos = (params: Record<string, any> = {}) => {
  return wpAjax('pc_get_eventos_tempos', params);
};

export const getReportSummary = (params: Record<string, any> = {}) => {
  return wpAjax('pc_get_report_summary', params);
};


// ─── Direct External Provider API Calls ─────────────────────────────────────
// These functions bypass the WordPress PHP proxy and call provider APIs directly.

const ensureBearer = (token: string | undefined): string => {
  if (!token) return '';
  const t = String(token).trim();
  return t.toLowerCase().startsWith('bearer ') ? t : `Bearer ${t}`;
};

interface DebugEntry {
  external_url: string;
  method: string;
  headers_sent: Record<string, string>;
  http_status: number | string;
  raw_response: string;
  id_ambient: string;
  provider: string;
}

/** Fetch Line Health directly from GOSAC middleware - no PHP proxy */
export const getWalletsHealth = async (): Promise<{ connections: any[]; debug_info: DebugEntry[] }> => {
  // 1. Load credentials from WordPress (one lightweight AJAX call)
  const creds = await getStaticCredentials();
  const gosacUrl = String(creds?.gosac_oficial_url || '').trim().replace(/\/$/, '');
  const gosacToken = ensureBearer(creds?.gosac_oficial_token);

  if (!gosacUrl || !gosacToken) {
    console.warn('⚠️ [LineHealth] GOSAC credentials not configured');
    return { connections: [], debug_info: [] };
  }

  // 2. Load wallet list from local WP DB to get unique idAmbient values
  const carteiras: any[] = await wpAjax('pc_get_carteiras', {});
  const uniqueAmbients = [...new Set(
    (carteiras || []).map((w: any) => String(w.id_carteira || '').trim()).filter(Boolean)
  )];

  const allConnections: any[] = [];
  const debugInfo: DebugEntry[] = [];

  // 3. Call GOSAC directly for each unique ambient
  for (const idAmbient of uniqueAmbients) {
    const url = `${gosacUrl}/connections/official?idAmbient=${encodeURIComponent(idAmbient)}`;
    const headers: Record<string, string> = {
      Authorization: gosacToken,
      'Content-Type': 'application/json',
      Accept: 'application/json',
    };

    let httpStatus: number | string = 'NETWORK_ERROR';
    let rawBody = '';

    try {
      console.log(`🔵 [LineHealth] Calling GOSAC directly: GET ${url}`);
      const res = await fetch(url, { method: 'GET', headers });
      httpStatus = res.status;
      rawBody = await res.text();

      if (res.ok) {
        const json = JSON.parse(rawBody);
        const envItems: any[] = json.data ?? json;

        if (Array.isArray(envItems)) {
          for (const envItem of envItems) {
            if (!envItem?.connections?.length) continue;
            for (const conn of envItem.connections) {
              let restriction = conn.accountRestriction ?? null;
              if (typeof restriction === 'string' && restriction) {
                try { restriction = JSON.parse(restriction); } catch { /* keep as string */ }
              }
              // Map wallet name from local carteiras
              const wallet = (carteiras || []).find((w: any) => String(w.id_carteira) === idAmbient);
              allConnections.push({
                id: conn.id ?? '',
                name: conn.name ?? '',
                status: conn.status ?? '',
                type: conn.type ?? '',
                messagingLimit: conn.messagingLimit ?? '',
                accountRestriction: restriction,
                provider: 'Gosac Oficial',
                id_ambient: idAmbient,
                idRuler: envItem.idRuler ?? '',
                wallet_name: wallet?.nome ?? idAmbient,
              });
            }
          }
        }
      }
    } catch (err: any) {
      httpStatus = 'NETWORK_ERROR';
      rawBody = String(err?.message || err);
      console.error(`🔴 [LineHealth] GOSAC fetch error for idAmbient ${idAmbient}:`, err);
    }

    // Mask token for debug display
    const tokenMasked = gosacToken.substring(0, 14) + '...' + gosacToken.slice(-6);
    debugInfo.push({
      external_url: url,
      method: 'GET',
      headers_sent: { Authorization: tokenMasked, 'Content-Type': 'application/json', Accept: 'application/json' },
      http_status: httpStatus,
      raw_response: rawBody,
      id_ambient: idAmbient,
      provider: 'gosac_oficial',
    });
  }

  return { connections: allConnections, debug_info: debugInfo };
};

/** Fetch Templates directly from GOSAC middleware for a given wallet/ambient ID */
export const getTemplatesByWallet = async (walletId: number | string): Promise<any[]> => {
  const creds = await getStaticCredentials();
  const gosacUrl = String(creds?.gosac_oficial_url || '').trim().replace(/\/$/, '');
  const gosacToken = ensureBearer(creds?.gosac_oficial_token);

  if (!gosacUrl || !gosacToken || !walletId) return [];

  // Get the id_carteira for this wallet
  const carteiras: any[] = await wpAjax('pc_get_carteiras', {});
  const wallet = (carteiras || []).find((w: any) => String(w.id) === String(walletId));
  const idAmbient = wallet?.id_carteira ? String(wallet.id_carteira).trim() : String(walletId);

  if (!idAmbient) return [];

  const url = `${gosacUrl}/templates/waba?idAmbient=${encodeURIComponent(idAmbient)}`;
  console.log(`🔵 [Templates] Calling GOSAC directly: GET ${url}`);

  try {
    const res = await fetch(url, {
      method: 'GET',
      headers: { Authorization: gosacToken, 'Content-Type': 'application/json', Accept: 'application/json' },
    });
    if (!res.ok) {
      console.error(`🔴 [Templates] GOSAC responded ${res.status}`);
      return [];
    }
    const json = await res.json();
    const envItems: any[] = json.data ?? json;
    const allTemplates: any[] = [];

    if (Array.isArray(envItems)) {
      for (const envItem of envItems) {
        if (!envItem?.templates?.length) continue;
        for (const t of envItem.templates) {
          allTemplates.push({
            id: t.id ?? t.name ?? '',
            name: t.name ?? '',
            content: t.content ?? '',
            category: t.category ?? '',
            language: t.language ?? '',
            status: t.status ?? '',
            provider: 'Gosac Oficial',
            source: 'gosac_oficial',
            id_ambient: idAmbient,
            idRuler: envItem.idRuler ?? '',
          });
        }
      }
    }
    return allTemplates;
  } catch (err) {
    console.error('🔴 [Templates] GOSAC fetch error:', err);
    return [];
  }
};
