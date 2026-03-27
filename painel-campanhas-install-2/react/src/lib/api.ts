/**
 * API Client para integração com WordPress AJAX
 */

// Configuração da URL do WordPress
export const getAjaxUrl = () => {
  const pc = (window as any).pcAjax;
  const url = pc?.ajaxUrl || pc?.ajaxurl;
  if (typeof pc !== 'undefined' && url) {
    return url;
  }
  return `${window.location.origin}/wp-admin/admin-ajax.php`;
};

// Helper para fazer requisições AJAX do WordPress
export const wpAjax = async (
  action: string,
  data: Record<string, any> = {},
  nonceType: 'nonce' | 'cmNonce' | 'validatorNonce' = 'nonce'
) => {
  const formData = new FormData();
  formData.append('action', action);

  // Adiciona nonce se disponível
  if (typeof (window as any).pcAjax !== 'undefined') {
    // Para ações cm_* usa cmNonce, para pc_* usa nonce
    const pc = (window as any).pcAjax;
    const nonce =
      nonceType === 'validatorNonce'
        ? (pc?.validatorNonce || pc?.nonce)
        : (pc?.[nonceType] || pc?.nonce);
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

    if (action === 'pc_get_otima_templates') {
      console.log('[DEBUG ÓTIMA] URL interna (WP AJAX) chamada:', ajaxUrl);
      console.log(
        '[DEBUG ÓTIMA] Resposta bruta (Raw Payload) recebida do backend:',
        typeof result === 'object' ? JSON.stringify(result, null, 2).slice(0, 80000) : String(result)
      );
    }

    // Log detalhado para debug
    if (action === 'pc_get_bases_carteira') {
      console.log('🔵 [API] Resposta completa do backend:', result);
      console.log('🔵 [API] result.success:', result.success);
      console.log('🔵 [API] result.data:', result.data);
      console.log('🔵 [API] result.data type:', typeof result.data);
      console.log('🔵 [API] result.data isArray:', Array.isArray(result.data));
    }

    if (!result.success) {
      const err = result.data;
      const msg = typeof err === 'string'
        ? err
        : (err?.message ?? err?.error ?? (typeof err === 'object' ? JSON.stringify(err) : String(err)));
      throw new Error(msg || 'Erro na requisição');
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

export const getSalesforceSyncStatus = () => {
  return wpAjax('pc_get_salesforce_sync_status', {});
};

export const cancelCampanha = (params: {
  agendamento_id: string;
  fornecedor: string;
  motivo: string;
}) => {
  return wpAjax('pc_cancel_campanha', {
    agendamento_id: params.agendamento_id,
    fornecedor: params.fornecedor,
    motivo: params.motivo,
  });
};

export const scheduleCampaign = (data: Record<string, any>) => {
  // Formata os dados conforme esperado pelo backend
  const payload: Record<string, any> = {
    table_name: data.base || data.table_name,
    carteira: data.carteira || '',
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

  if (data.include_baits) {
    payload.bait_ids = JSON.stringify(Array.isArray(data.bait_ids) ? data.bait_ids : []);
  }

  // Adiciona campos para templates da Ótima
  if (data.template_code) {
    payload.template_code = data.template_code;
  }
  if (data.template_source) {
    payload.template_source = data.template_source;
  }
  // broker_code: WPP = telefone remetente (campo `code` de GET /whatsapp/credential); RCS = code RCS — nunca o nome da credencial
  if (data.template_source === 'otima_rcs' || data.template_source === 'otima_wpp') {
    payload.broker_code = data.broker_code ?? '';
  } else if (data.broker_code) {
    payload.broker_code = data.broker_code;
  }
  if (data.customer_code) {
    payload.customer_code = data.customer_code;
  }
  if (data.midia_campanha) {
    payload.midia_campanha = data.midia_campanha;
  }
  if (data.variables_map && Object.keys(data.variables_map).length > 0) {
    payload.variables_map = JSON.stringify(data.variables_map);
  }
  if (data.template_source === 'noah_oficial') {
    payload.noah_channel_id = data.noah_channel_id ?? '';
    payload.noah_template_id = data.noah_template_id ?? '';
    payload.noah_language = data.noah_language ?? 'pt_BR';
  }
  if (data.template_source === 'gosac_oficial') {
    const tid = data.gosac_template_id;
    const cid = data.gosac_connection_id;
    payload.gosac_template_id = (typeof tid === 'number' && tid > 0) ? tid : (typeof tid === 'string' && /^\d+$/.test(tid) ? parseInt(tid, 10) : '');
    payload.gosac_connection_id = (typeof cid === 'number' && cid > 0) ? cid : (typeof cid === 'string' && /^\d+$/.test(cid) ? parseInt(cid, 10) : '');
    payload.gosac_variable_components = typeof data.gosac_variable_components === 'string'
      ? data.gosac_variable_components
      : JSON.stringify(data.gosac_variable_components || []);
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

/**
 * Templates Ótima (HSM/RCS). Regra: o ID na URL da Ótima é sempre `wallet_id` (coluna id_carteira no cadastro).
 * Quando `walletId` está definido, só ele é enviado — a PK local não acompanha o request (evita confusão no proxy).
 * Sem wallet: envia `carteira_id` (PK) só para o PHP resolver `id_carteira` no banco; a API externa nunca recebe a PK.
 */
export const getOtimaTemplates = (walletId?: string, carteiraDbId?: string) => {
  const payload: Record<string, string> = {};
  if (walletId) {
    payload.wallet_id = String(walletId);
  } else if (carteiraDbId) {
    payload.carteira_id = String(carteiraDbId);
  }

  if (typeof window !== 'undefined') {
    console.log('[DEBUG ÓTIMA] wallet_id (provedor Ótima) enviado:', walletId ?? '(omitido)');
    console.log('[DEBUG ÓTIMA] carteira_id PK local (só se sem wallet):', walletId ? '(omitido — não enviado ao PHP)' : carteiraDbId ?? '(vazio)');
    console.log('[DEBUG ÓTIMA] Action AJAX:', 'pc_get_otima_templates', '(não é pc_get_messages / templates locais)');
    console.log('[DEBUG ÓTIMA] URL interna (WP AJAX) será usada no próximo log (wpAjax):', getAjaxUrl());
    console.log('[DEBUG ÓTIMA] Payload FormData (chaves):', Object.keys(payload), payload);
  }

  return wpAjax('pc_get_otima_templates', payload).then((data) => {
    if (typeof window !== 'undefined') {
      const arr = Array.isArray(data) ? data : [];
      const wppCodes = arr
        .filter((x: any) => x?.source === 'otima_wpp')
        .map((x: any) => x?.template_code)
        .filter(Boolean);
      console.log('[DEBUG ÓTIMA] Após unwrap result.data — itens totais:', arr.length, '| WPP (source=otima_wpp):', wppCodes.length);
      console.log('[DEBUG ÓTIMA] template_code(s) WPP (amostra):', wppCodes.slice(0, 25));
      console.log('[DEBUG ÓTIMA] Primeiro item (qualquer):', arr[0] ?? null);
    }
    return data;
  });
};

export const getOtimaBrokers = () => {
  return wpAjax('pc_get_otima_brokers', {});
};

/** Templates GOSAC Oficial (estáticos) */
export const getGosacOficialTemplates = async (): Promise<any[]> => {
  try {
    const data = await wpAjax('pc_get_gosac_oficial_templates', {});
    return Array.isArray(data) ? data : [];
  } catch (err) {
    console.error('🔴 [Templates] getGosacOficialTemplates error:', err);
    return [];
  }
};

export const getGosacOficialConnections = (params?: { carteira?: string; id_ambient?: string; id_ruler?: string }) => {
  return wpAjax('pc_get_gosac_oficial_connections', params || {});
};

export const getRobbuWebhookStats = () => {
  return wpAjax('pc_get_robbu_webhook_stats', {});
};

/** Templates Robbu Oficial (estáticos, não dependem da carteira) */
export const getRobbuOficialTemplates = async (): Promise<any[]> => {
  try {
    const data = await wpAjax('pc_get_robbu_oficial_templates', {});
    return Array.isArray(data) ? data : [];
  } catch (err) {
    console.error('🔴 [Templates] getRobbuOficialTemplates error:', err);
    return [];
  }
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
    carteira: data.carteira || '',
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

  if (data.template_code !== undefined && data.template_code !== null) {
    payload.template_code = data.template_code;
  }
  if (data.template_source !== undefined && data.template_source !== null && data.template_source !== '') {
    payload.template_source = data.template_source;
  }
  if (data.broker_code) {
    payload.broker_code = data.broker_code;
  }
  if (data.customer_code) {
    payload.customer_code = data.customer_code;
  }
  if (data.template_source === 'noah_oficial') {
    payload.noah_channel_id = data.noah_channel_id ?? '';
    payload.noah_template_id = data.noah_template_id ?? '';
    payload.noah_language = data.noah_language ?? 'pt_BR';
  }
  if (data.template_source === 'robbu_oficial') {
    payload.robbu_channel = data.robbu_channel ?? 3;
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
  const templateSource = data.template_source || 'local';
  const payload: Record<string, any> = {
    temp_id: data.temp_id,
    table_name: data.table_name,
    carteira: data.carteira || '',
    template_id: data.template_id,
    template_code: data.template_code,
    template_source: templateSource,
    provider: data.provider,
    match_field: data.match_field || 'cpf',
    include_baits: data.include_baits || 0,
    show_already_sent: data.show_already_sent || 0,
  };

  if (data.include_baits) {
    payload.bait_ids = JSON.stringify(Array.isArray(data.bait_ids) ? data.bait_ids : []);
  }

  // broker_code e customer_code: igual Nova Campanha - broker do select, customer = id_carteira por registro (PHP)
  if (templateSource === 'otima_rcs' || templateSource === 'otima_wpp') {
    payload.broker_code = data.broker_code ?? '';
  }
  if (data.customer_code) {
    payload.customer_code = data.customer_code;
  }
  if (templateSource === 'noah_oficial') {
    payload.noah_channel_id = data.noah_channel_id ?? '';
    payload.noah_template_id = data.noah_template_id ?? '';
    payload.noah_language = data.noah_language ?? 'pt_BR';
  }
  if (templateSource === 'robbu_oficial') {
    payload.robbu_channel = data.robbu_channel ?? 3;
  }
  if (templateSource === 'gosac_oficial') {
    payload.gosac_template_id = data.gosac_template_id ?? '';
    payload.gosac_connection_id = data.gosac_connection_id ?? '';
    payload.gosac_variable_components = data.gosac_variable_components ?? '[]';
  }

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

/** Evolution API — credenciais só no servidor; token nunca retornado completo */
export const getEvolutionConfig = async () => {
  const { z } = await import('zod');
  const Schema = z.object({
    evolution_api_url: z.string(),
    evolution_token_configured: z.boolean(),
  });
  const raw = await wpAjax('pc_evolution_get_config', {});
  return Schema.parse(raw);
};

export const saveEvolutionConfig = (data: { evolution_api_url: string; evolution_api_token?: string }) =>
  wpAjax('pc_evolution_save_config', {
    evolution_api_url: data.evolution_api_url,
    evolution_api_token: data.evolution_api_token ?? '',
  });

/** Validador WhatsApp (CSV) — processamento em etapas */
export const waValidatorUpload = async (file: File) => {
  const { z } = await import('zod');
  const Schema = z.object({
    job_id: z.string(),
    download_nonce: z.string().optional(),
  });
  const raw = await wpAjax('pc_wa_validator_upload', { file }, 'validatorNonce');
  return Schema.parse(raw);
};

export const waValidatorStep = async (jobId: string) => {
  const { z } = await import('zod');
  const Schema = z.object({
    done: z.boolean(),
    progress: z.number(),
    processed: z.number(),
    total: z.number(),
    download_nonce: z.string().optional(),
  });
  const raw = await wpAjax('pc_wa_validator_step', { job_id: jobId }, 'validatorNonce');
  return Schema.parse(raw);
};

/** Métricas agregadas do Validador (REST, só administradores) */
export async function fetchValidadorMetricas(dataInicio: string, dataFim: string) {
  const { z } = await import('zod');
  const pc = (window as any).pcAjax;
  const base = pc?.validadorMetricasRest as string | undefined;
  if (!base) {
    throw new Error('validadorMetricasRest não disponível (recarregue a página).');
  }
  const url = new URL(base, window.location.origin);
  url.searchParams.set('data_inicio', dataInicio);
  url.searchParams.set('data_fim', dataFim);

  const res = await fetch(url.toString(), {
    method: 'GET',
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'X-WP-Nonce': (pc?.restNonce as string) || '',
    },
  });

  const body = await res.json().catch(() => ({}));
  if (!res.ok) {
    const msg =
      typeof body?.message === 'string'
        ? body.message
        : typeof body?.code === 'string'
          ? body.code
          : `HTTP ${res.status}`;
    throw new Error(msg);
  }

  const Schema = z.object({
    periodo: z.object({
      data_inicio: z.string(),
      data_fim: z.string(),
      timezone: z.string().optional(),
    }),
    linhas: z.array(
      z.object({
        usuario_id: z.coerce.number(),
        usuario_nome: z.string(),
        total_enviado: z.coerce.number(),
        total_validos: z.coerce.number(),
        taxa_qualidade_pct: z.coerce.number(),
      })
    ),
  });

  return Schema.parse(body);
}

export const getOtimaCustomers = (provider: 'rcs' | 'wpp' = 'rcs') => {
  return wpAjax('pc_get_otima_customers', { provider });
};

export const listCredentials = () => {
  return wpAjax('pc_list_credentials', {});
};

export const createCredential = (data: Record<string, any>) => {
  const { credential_data, ...rest } = data;
  const payload: Record<string, any> = { ...rest };
  if (credential_data && typeof credential_data === 'object') {
    Object.entries(credential_data).forEach(([k, v]) => {
      payload[`credential_data[${k}]`] = Array.isArray(v) ? JSON.stringify(v) : v;
    });
  }
  return wpAjax('pc_create_credential', payload);
};

export const getCredential = (provider: string, envId: string) => {
  return wpAjax('pc_get_credential', { provider, env_id: envId });
};

export const updateCredential = (provider: string, envId: string, data: Record<string, any>) => {
  const payload: Record<string, any> = { provider, env_id: envId };
  if (data && typeof data === 'object') {
    Object.entries(data).forEach(([k, v]) => {
      payload[`credential_data[${k}]`] = Array.isArray(v) ? JSON.stringify(v) : v;
    });
  }
  return wpAjax('pc_update_credential', payload);
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
  TemplateName?: string;
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

/** Fetch Line Health via PHP (GOSAC Oficial + NOAH Oficial). Sempre via servidor para evitar ERR_CERT_DATE_INVALID no browser. */
export const getWalletsHealth = async (): Promise<{ connections: any[]; debug_info: DebugEntry[] }> => {
  const ajaxUrl = getAjaxUrl();
  const nonce = (window as any).pcAjax?.nonce || '(não disponível)';
  console.log('🔍 [LineHealth] Chamada PHP:', {
    url: ajaxUrl,
    action: 'pc_get_all_connections_health',
    nonce: nonce !== '(não disponível)' ? `${String(nonce).substring(0, 12)}...${String(nonce).slice(-6)}` : nonce,
  });

  const result = await wpAjax('pc_get_all_connections_health', {});
  const connections = result?.connections ?? [];
  const debug_info = result?.debug_info ?? [];
  return {
    connections: Array.isArray(connections) ? connections : [],
    debug_info: Array.isArray(debug_info) ? debug_info : [],
  };
};

/** Fetch Templates from backend (GOSAC Oficial + NOAH Oficial) for a given wallet */
export const getTemplatesByWallet = async (walletId: number | string): Promise<any[]> => {
  if (!walletId) return [];
  try {
    const data = await wpAjax('pc_get_templates_by_wallet', { wallet_id: String(walletId) });
    return Array.isArray(data) ? data : [];
  } catch (err) {
    console.error('🔴 [Templates] getTemplatesByWallet error:', err);
    return [];
  }
};
