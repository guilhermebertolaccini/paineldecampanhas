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
  };

  // Adiciona campos para templates da Ótima
  if (data.template_code) {
    payload.template_code = data.template_code;
  }
  if (data.template_source) {
    payload.template_source = data.template_source;
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

export const getOtimaTemplates = () => {
  return wpAjax('pc_get_otima_templates', {});
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

export const saveRecurring = (data: Record<string, any>) => {
  // Formata os dados conforme esperado pelo backend
  return wpAjax('cm_save_recurring', {
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
    id: data.id, // Se tiver id, será update, senão será insert
  }, 'cmNonce');
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

  const response = await fetch(ajaxUrl, {
    method: 'POST',
    body: formData,
    credentials: 'same-origin',
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
  // O handler espera: temp_id, table_name, template_id, provider, match_field
  return wpAjax('cpf_cm_create_campaign', {
    temp_id: data.temp_id,
    table_name: data.table_name,
    template_id: data.template_id,
    provider: data.provider,
    match_field: data.match_field || 'cpf',
  });
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

export const getOrcamentosBases = () => {
  return wpAjax('pc_get_orcamentos_bases', {});
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
export const getIscas = () => {
  return wpAjax('pc_get_iscas', {});
};

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

