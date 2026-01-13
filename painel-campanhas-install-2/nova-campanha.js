/**
 * JavaScript para página Nova Campanha - Adaptado do Campaign Manager
 * Sistema de filtros dinâmicos e campanhas recorrentes
 */

/**
 * Campaign Manager - JavaScript com Exibição Detalhada de Iscas
 */

jQuery(document).ready(function($) {
    let selectedTable = '';
    let availableFilters = {};
    let currentFilters = {};
    let audienceCount = 0;
    let isBaseUpdated = true; // Flag para controlar se a base está atualizada

    console.log('✅ Campaign Manager JS carregado');

    // ===== STEP 1: SELECIONAR TABELA =====
    $('#data-source-select').on('change', function() {
        selectedTable = $(this).val();
        console.log('📊 Tabela selecionada:', selectedTable);
        
        if (selectedTable) {
            checkBaseUpdate(selectedTable);
            loadFilters(selectedTable);
        } else {
            isBaseUpdated = true;
            hideBaseUpdateWarning();
        }
    });
    
    // ===== VERIFICAR ATUALIZAÇÃO DA BASE =====
    function checkBaseUpdate(tableName) {
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cm_check_base_update',
                nonce: pcAjax.cmNonce || pcAjax.nonce,
                table_name: tableName
            },
            success: function(response) {
                if (response.success) {
                    isBaseUpdated = response.data.is_updated;
                    
                    if (!isBaseUpdated) {
                        showBaseUpdateWarning(response.data.message, response.data.ult_atualizacao);
                    } else {
                        hideBaseUpdateWarning();
                    }
                } else {
                    console.error('Erro ao verificar atualização da base:', response.data);
                    // Em caso de erro, permite continuar (não bloqueia)
                    isBaseUpdated = true;
                    hideBaseUpdateWarning();
                }
            },
            error: function() {
                console.error('Erro de conexão ao verificar atualização da base');
                // Em caso de erro, permite continuar (não bloqueia)
                isBaseUpdated = true;
                hideBaseUpdateWarning();
            }
        });
    }
    
    // ===== MOSTRAR AVISO DE BASE DESATUALIZADA =====
    function showBaseUpdateWarning(message, ultAtualizacao) {
        // Remove aviso anterior se existir
        $('#base-update-warning').remove();
        
        const warningHtml = `
            <div id="base-update-warning" class="bg-red-50 dark:bg-red-900/20 border-l-4 border-red-500 p-4 mb-4 rounded-lg">
                <div class="flex items-start">
                    <i class="fas fa-exclamation-triangle text-red-500 text-xl mr-3 mt-1"></i>
                    <div class="flex-1">
                        <h4 class="font-semibold text-red-800 dark:text-red-300 mb-1">⚠️ Base Desatualizada</h4>
                        <p class="text-red-700 dark:text-red-400 text-sm">
                            ${message || 'A base de dados selecionada não foi atualizada hoje.'}
                            ${ultAtualizacao ? `<br><strong>Última atualização:</strong> ${ultAtualizacao}` : ''}
                        </p>
                        <p class="text-red-600 dark:text-red-400 text-xs mt-2 font-medium">
                            ⛔ Não é possível agendar campanhas com bases desatualizadas. Por favor, selecione uma base atualizada.
                        </p>
                    </div>
                </div>
            </div>
        `;
        
        // Insere o aviso após o select da base
        $('#data-source-select').closest('.bg-white, .bg-surface-dark').after(warningHtml);
    }
    
    // ===== OCULTAR AVISO =====
    function hideBaseUpdateWarning() {
        $('#base-update-warning').remove();
    }

    // ===== CARREGAR FILTROS =====
    function loadFilters(tableName) {
        console.log('⏳ Carregando filtros para:', tableName);
        $('#filters-container').html('<p>⏳ Carregando filtros...</p>');
        
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cm_get_filters',
                nonce: pcAjax.cmNonce || pcAjax.nonce, // Usa cmNonce para handlers de campanha normal
                table_name: tableName
            },
            success: function(response) {
                console.log('✅ Filtros recebidos:', response);
                if (response.success) {
                    availableFilters = response.data;
                    renderFilters(availableFilters);
                    $('#filters-step').show();
                    updateAudienceCount();
                    // Mostra o passo 3 após carregar filtros
                    $('#details-step').show();
                } else {
                    alert('Erro ao carregar filtros: ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ Erro AJAX:', error);
                alert('Erro de conexão ao carregar filtros');
            }
        });
    }

    // ===== RENDERIZAR FILTROS (NOVA VERSÃO DINÂMICA) =====
    function renderFilters(filters) {
        const container = $('#filters-container');
        container.empty();

        if (Object.keys(filters).length === 0) {
            container.html('<p>✅ Nenhum filtro disponível. Todos os registros serão incluídos.</p>');
            return;
        }

        // Guarda os filtros disponíveis globalmente
        window.availableFiltersData = filters;

        // Cria interface de filtros dinâmicos
        const filtersUI = $(`
            <div class="cm-dynamic-filters">
                <!-- Barra de Filtros Ativos -->
                <div class="cm-active-filters-bar">
                    <div class="cm-active-filters-list" id="active-filters-list">
                        <span class="cm-no-filters-text">Nenhum filtro aplicado</span>
                    </div>
                    <button type="button" class="cm-btn-add-filter" id="btn-add-filter">
                        <span>➕</span> Adicionar Filtro
                    </button>
                </div>

                <!-- Dropdown de Seleção de Filtro -->
                <div class="cm-filter-dropdown" id="filter-dropdown" style="display:none;">
                    <div class="cm-filter-search">
                        <input type="text" id="filter-search-input" 
                               placeholder="🔍 Buscar coluna..." 
                               class="cm-input">
                    </div>
                    <div class="cm-filter-options" id="filter-options">
                        <!-- Opções serão preenchidas dinamicamente -->
                    </div>
                </div>

                <!-- Modal de Configuração de Filtro -->
                <div class="cm-filter-modal-overlay" id="filter-modal-overlay" style="display:none;">
                    <div class="cm-filter-modal">
                        <div class="cm-filter-modal-header">
                            <h3 id="filter-modal-title">Configurar Filtro</h3>
                            <button type="button" class="cm-filter-modal-close" id="filter-modal-close">×</button>
                        </div>
                        <div class="cm-filter-modal-body" id="filter-modal-body">
                            <!-- Conteúdo será preenchido dinamicamente -->
                        </div>
                    </div>
                </div>
            </div>
        `);

        container.append(filtersUI);
        renderFilterOptions();
        renderActiveFilters();
    }

    // ===== RENDERIZAR OPÇÕES DE FILTRO NO DROPDOWN =====
    function renderFilterOptions(searchTerm = '') {
        const optionsContainer = $('#filter-options');
        optionsContainer.empty();

        if (!window.availableFiltersData) return;

        const searchLower = searchTerm.toLowerCase();
        let hasResults = false;

        Object.keys(window.availableFiltersData).forEach(function(columnName) {
            // Verifica se já está ativo
            if (currentFilters[columnName]) {
                return; // Pula se já está sendo usado
            }

            // Filtra por busca
            if (searchTerm && !columnName.toLowerCase().includes(searchLower)) {
                return;
            }

            hasResults = true;
            const filterData = window.availableFiltersData[columnName];
            const typeLabel = filterData.type === 'numeric' ? '🔢 Numérico' : '📋 Categórico';
            
            const option = $(`
                <div class="cm-filter-option" data-column="${columnName}">
                    <div class="cm-filter-option-info">
                        <strong>${columnName}</strong>
                        <span class="cm-filter-option-type">${typeLabel}</span>
                    </div>
                    <span class="cm-filter-option-arrow">→</span>
                </div>
            `);

            option.on('click', function() {
                openFilterModal(columnName, window.availableFiltersData[columnName]);
                $('#filter-dropdown').hide();
                $('#filter-search-input').val('');
            });

            optionsContainer.append(option);
        });

        if (!hasResults) {
            optionsContainer.html('<div class="cm-filter-no-results">Nenhuma coluna disponível</div>');
        }
    }

    // ===== ABRIR MODAL DE CONFIGURAÇÃO DE FILTRO =====
    function openFilterModal(columnName, filterData) {
        const modal = $('#filter-modal-overlay');
        const modalBody = $('#filter-modal-body');
        const modalTitle = $('#filter-modal-title');

        modalTitle.text(`Filtro: ${columnName}`);
        modalBody.empty();

        let filterHTML = '';

        // FILTRO NUMÉRICO
        if (filterData.type === 'numeric') {
            const currentFilter = currentFilters[columnName] || {};
            
            filterHTML = $(`
                <div class="cm-filter-config">
                    <label>Operador:</label>
                    <select class="cm-select" id="modal-operator" data-column="${columnName}">
                        <option value="">-- Selecione --</option>
                        <option value="=" ${currentFilter.operator === '=' ? 'selected' : ''}>= (Igual)</option>
                        <option value="!=" ${currentFilter.operator === '!=' ? 'selected' : ''}>≠ (Diferente)</option>
                        <option value=">" ${currentFilter.operator === '>' ? 'selected' : ''}>> (Maior que)</option>
                        <option value="<" ${currentFilter.operator === '<' ? 'selected' : ''}>< (Menor que)</option>
                        <option value=">=" ${currentFilter.operator === '>=' ? 'selected' : ''}>≥ (Maior ou igual)</option>
                        <option value="<=" ${currentFilter.operator === '<=' ? 'selected' : ''}>≤ (Menor ou igual)</option>
                    </select>
                    <label style="margin-top:15px;">Valor:</label>
                    <input type="number" class="cm-input" id="modal-value" 
                           data-column="${columnName}" 
                           value="${currentFilter.value || ''}" 
                           placeholder="Digite o valor">
                    <div class="cm-filter-modal-actions">
                        <button type="button" class="cm-btn cm-btn-primary" id="btn-save-filter">
                            Salvar Filtro
                        </button>
                        <button type="button" class="cm-btn" id="btn-cancel-filter" style="background:#e5e7eb;">
                            Cancelar
                        </button>
                    </div>
                </div>
            `);
        } 
        // FILTRO CATEGÓRICO
        else if (filterData.type === 'categorical') {
            const currentFilter = currentFilters[columnName] || {};
            const selectedValues = currentFilter.value || [];

            filterHTML = $(`
                <div class="cm-filter-config">
                    <label>Selecione os valores:</label>
                    <div class="cm-checkbox-grid" style="max-height:400px;">
                        ${filterData.values.map(value => {
                            const isChecked = selectedValues.includes(value);
                            return `
                                <label class="cm-checkbox-item">
                                    <input type="checkbox" class="modal-checkbox" 
                                           data-column="${columnName}" 
                                           value="${escapeHtml(value)}" 
                                           ${isChecked ? 'checked' : ''}>
                                    <span>${escapeHtml(value)}</span>
                                </label>
                            `;
                        }).join('')}
                    </div>
                    <div class="cm-filter-modal-actions">
                        <button type="button" class="cm-btn cm-btn-primary" id="btn-save-filter">
                            Salvar Filtro
                        </button>
                        <button type="button" class="cm-btn" id="btn-cancel-filter" style="background:#e5e7eb;">
                            Cancelar
                        </button>
                    </div>
                </div>
            `);
        }

        modalBody.append(filterHTML);
        modal.fadeIn(200);

        // Event handlers
        $('#btn-save-filter').on('click', function() {
            saveFilter(columnName, filterData);
        });

        $('#btn-cancel-filter, #filter-modal-close').on('click', function() {
            modal.fadeOut(200);
        });

        // Fecha ao clicar fora
        modal.on('click', function(e) {
            if ($(e.target).is(modal)) {
                modal.fadeOut(200);
            }
        });
    }

    // ===== SALVAR FILTRO =====
    function saveFilter(columnName, filterData) {
        if (filterData.type === 'numeric') {
            const operator = $('#modal-operator').val();
            const value = $('#modal-value').val();

            if (!operator || value === '') {
                alert('Por favor, preencha operador e valor');
                return;
            }

            currentFilters[columnName] = {
                operator: operator,
                value: value
            };
        } else if (filterData.type === 'categorical') {
            const checkedValues = [];
            $(`.modal-checkbox[data-column="${columnName}"]:checked`).each(function() {
                checkedValues.push($(this).val());
            });

            if (checkedValues.length === 0) {
                alert('Selecione pelo menos um valor');
                return;
            }

            currentFilters[columnName] = {
                operator: 'IN',
                value: checkedValues
            };
        }

        $('#filter-modal-overlay').fadeOut(200);
        renderActiveFilters();
        updateAudienceCount();
    }

    // ===== RENDERIZAR FILTROS ATIVOS (CHIPS) =====
    function renderActiveFilters() {
        const container = $('#active-filters-list');
        container.empty();

        const filtersCount = Object.keys(currentFilters).length;

        if (filtersCount === 0) {
            container.html('<span class="cm-no-filters-text">Nenhum filtro aplicado</span>');
            return;
        }

        Object.keys(currentFilters).forEach(function(columnName) {
            const filter = currentFilters[columnName];
            const filterData = window.availableFiltersData[columnName];
            
            let filterText = columnName;
            
            if (filter.operator === 'IN') {
                const valuesCount = filter.value.length;
                filterText += ` IN (${valuesCount} ${valuesCount === 1 ? 'valor' : 'valores'})`;
            } else {
                const operatorSymbols = {
                    '=': '=',
                    '!=': '≠',
                    '>': '>',
                    '<': '<',
                    '>=': '≥',
                    '<=': '≤'
                };
                filterText += ` ${operatorSymbols[filter.operator] || filter.operator} ${filter.value}`;
            }

            const chip = $(`
                <div class="cm-filter-chip" data-column="${columnName}">
                    <span class="cm-filter-chip-text">${filterText}</span>
                    <button type="button" class="cm-filter-chip-remove" data-column="${columnName}">×</button>
                </div>
            `);

            chip.find('.cm-filter-chip-remove').on('click', function() {
                removeFilter(columnName);
            });

            // Permite editar ao clicar no chip
            chip.on('click', function(e) {
                if (!$(e.target).is('.cm-filter-chip-remove')) {
                    openFilterModal(columnName, filterData);
                }
            });

            container.append(chip);
        });
    }

    // ===== REMOVER FILTRO =====
    function removeFilter(columnName) {
        delete currentFilters[columnName];
        renderActiveFilters();
        updateAudienceCount();
    }

    // ===== ESCAPE HTML =====
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ===== EVENT HANDLERS PARA FILTROS DINÂMICOS =====
    $(document).on('click', '#btn-add-filter', function() {
        const dropdown = $('#filter-dropdown');
        if (dropdown.is(':visible')) {
            dropdown.slideUp(200);
        } else {
            dropdown.slideDown(200);
            $('#filter-search-input').focus();
            renderFilterOptions();
        }
    });

    $(document).on('input', '#filter-search-input', function() {
        const searchTerm = $(this).val();
        renderFilterOptions(searchTerm);
    });

    // Fecha dropdown ao clicar fora
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.cm-dynamic-filters').length) {
            $('#filter-dropdown').slideUp(200);
        }
    });

    // ===== ATUALIZAR CONTAGEM =====
    function updateAudienceCount() {
        if (!selectedTable) return;
        
        $('#audience-count').text('...');
        updateBaitsInfo(); // ← Atualiza info das iscas
        
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cm_get_count',
                nonce: pcAjax.cmNonce || pcAjax.nonce, // Usa cmNonce para handlers de campanha normal
                table_name: selectedTable,
                filters: JSON.stringify(currentFilters)
            },
            success: function(response) {
                if (response.success) {
                    audienceCount = parseInt(response.data) || 0;
                    $('#audience-count').text(audienceCount.toLocaleString('pt-BR'));
                    console.log('👥 Audiência:', audienceCount);
                    // Mostra o passo 3 (detalhes) quando a contagem é atualizada
                    $('#details-step').show();
                }
            }
        });
    }
    
    // ===== 🎯 ATUALIZAR ISCAS COMPATÍVEIS - VERSÃO MELHORADA =====
    function updateBaitsInfo() {
        console.log('🎣 Verificando iscas compatíveis...');
        
        if (!selectedTable) {
            $('#cm-baits-info-container').empty();
            return;
        }
        
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cm_get_compatible_baits',
                nonce: pcAjax.cmNonce || pcAjax.nonce, // Usa cmNonce para handlers de campanha normal
                table_name: selectedTable,
                filters: JSON.stringify(currentFilters)
            },
            success: function(response) {
                console.log('🎣 Resposta das iscas:', response);
                
                if (response.success && response.data.count > 0) {
                    const count = response.data.count;
                    const details = response.data.details || [];
                    const plural = count > 1;
                    
                    // Monta lista de iscas
                    let baitsListHTML = '';
                    if (details.length > 0) {
                        baitsListHTML = '<ul style="margin:10px 0 0 0;padding-left:20px;color:#78350f;">';
                        details.forEach(function(bait) {
                            baitsListHTML += `<li><strong>${bait.nome}</strong> (IDGIS: ${bait.idgis}) - ${bait.telefone}</li>`;
                        });
                        baitsListHTML += '</ul>';
                    }
                    
                    $('#cm-baits-info-container').html(`
                        <div class="cm-baits-info">
                            <span class="cm-baits-icon">🎣</span>
                            <div style="flex:1;">
                                <strong>${count} isca${plural ? 's' : ''} ativa${plural ? 's' : ''} compatível${plural ? 'is' : ''}</strong>
                                <p style="margin:5px 0 0 0;">Será${plural ? 'ão' : ''} adicionada${plural ? 's' : ''} automaticamente à campanha</p>
                                ${baitsListHTML}
                            </div>
                        </div>
                    `);
                    
                    console.log('✅ Iscas exibidas:', count);
                } else {
                    $('#cm-baits-info-container').html(`
                        <div class="cm-baits-warning">
                            <span style="font-size:24px;">⚠️</span>
                            <div>
                                <strong>Nenhuma isca compatível</strong>
                                <p>Não há iscas ativas com IDGIS compatível com esta tabela</p>
                            </div>
                        </div>
                    `);
                    console.log('⚠️ Nenhuma isca compatível encontrada');
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ Erro ao buscar iscas:', error);
            }
        });
    }

    // ===== CONTINUAR PARA STEP 3 =====
    // Remove referência a continue-to-step-3 que não existe na nossa estrutura
    // O passo 3 aparece automaticamente quando a contagem é atualizada

    // ===== VALIDAÇÃO STEP 3 =====
    function validateStep3() {
        // Só valida se os elementos existirem
        if (!$('#template-select').length || !$('#create-campaign-btn').length) {
            return;
        }

        const templateSelected = $('#template-select').val() !== '';
        const providersSelected = $('.provider-checkbox:checked').length > 0;
        const saveAsTemplate = $('#save-as-template').is(':checked');
        const campaignNameEl = $('#template-name');
        const campaignName = campaignNameEl.length ? campaignNameEl.val() : null;
        const campaignNameValid = campaignName ? campaignName.trim() !== '' : true;

        let isValid = false;

        if (saveAsTemplate) {
            isValid = campaignNameValid && templateSelected && providersSelected;
        } else {
            isValid = templateSelected && providersSelected;
        }

        $('#create-campaign-btn').prop('disabled', !isValid);
    }

    // Event listeners para validação
    $(document).on('change', '#template-select, #template-name, #save-as-template, .provider-checkbox', validateStep3);
    
    $('input[name="scheduling_mode"]').on('change', function() {
        const mode = $(this).val();
        
        if (mode === 'recurring') {
            $('#recurring-options').slideDown();
            $('#exclusion-option-immediate').slideUp(); // Esconde checkbox de envio imediato
            $('#create-campaign-btn').html('💾 Salvar Template');
        } else {
            $('#recurring-options').slideUp();
            $('#exclusion-option-immediate').slideDown(); // Mostra checkbox de envio imediato
            $('#create-campaign-btn').html('🚀 Agendar Campanha');
        }
        
        validateStep3();
    });
    
    // Inicializa visibilidade correta no carregamento
    const initialMode = $('input[name="scheduling_mode"]:checked').val();
    if (initialMode === 'recurring') {
        $('#exclusion-option-immediate').hide();
    } else {
        $('#exclusion-option-immediate').show();
    }

    $('input[name="distribution_mode"]').on('change', function() {
        const mode = $(this).val();
        $('.provider-percent').prop('disabled', mode === 'all');
    });

    // Mostra/esconde campo de nome do template
    $('#save-as-template').on('change', function() {
        if ($(this).is(':checked')) {
            $('#template-name-container').slideDown();
        } else {
            $('#template-name-container').slideUp();
        }
    });

    // ===== AGENDAR CAMPANHA =====
    $('#create-campaign-btn').on('click', function() {
        const btn = $(this);
        
        // Verifica se a base está atualizada antes de permitir agendamento
        if (!isBaseUpdated) {
            alert('⚠️ Não é possível agendar campanhas com bases desatualizadas.\n\nPor favor, selecione uma base que foi atualizada hoje.');
            return;
        }
        
        const templateId = $('#template-select').val();
        const selectedProviders = [];
        const percentages = {};
        
        $('.provider-checkbox:checked').each(function() {
            const provider = $(this).val();
            selectedProviders.push(provider);
            const percent = parseInt($(`.provider-percent[data-provider="${provider}"]`).val()) || 0;
            percentages[provider] = percent;
        });

        const distributionMode = $('input[name="distribution_mode"]:checked').val();
        const providersConfig = {
            mode: distributionMode,
            providers: selectedProviders,
            percentages: percentages
        };

        const recordLimit = parseInt($('#record-limit').val()) || 0;
        const excludeRecentPhones = $('#exclude-recent-phones').is(':checked') ? 1 : 0;
        const saveAsTemplate = $('#save-as-template').is(':checked');

        btn.prop('disabled', true).html('<span class="cm-loading-spinner"></span> Processando...');

        // MODO TEMPLATE RECORRENTE
        if (saveAsTemplate) {
            const campaignName = $('#template-name').val().trim();
            if (!campaignName) {
                alert('Por favor, informe o nome do template');
                btn.prop('disabled', false).html('<i class="fas fa-paper-plane mr-2"></i>Criar Campanha');
                return;
            }
            
            $.ajax({
                url: pcAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cm_save_recurring',
                    nonce: pcAjax.cmNonce || pcAjax.nonce, // Usa cmNonce para handlers de campanha normal
                    nome_campanha: campaignName,
                    table_name: selectedTable,
                    filters: JSON.stringify(currentFilters),
                    providers_config: JSON.stringify(providersConfig),
                    template_id: templateId,
                    record_limit: recordLimit,
                    exclude_recent_phones: excludeRecentPhones
                },
                success: function(response) {
                    if (response.success) {
                        alert('✅ Template salvo com sucesso!');
                        window.location.href = pcAjax.homeUrl + '/painel/campanhas';
                    } else {
                        alert('❌ Erro: ' + (response.data || 'Erro desconhecido'));
                        btn.prop('disabled', false).html('<i class="fas fa-paper-plane mr-2"></i>Criar Campanha');
                    }
                },
                error: function() {
                    alert('❌ Erro de conexão');
                    btn.prop('disabled', false).html('<i class="fas fa-paper-plane mr-2"></i>Criar Campanha');
                }
            });
        } 
        // MODO CAMPANHA NORMAL
        else {
            console.log('📤 Enviando campanha:', {
                table_name: selectedTable,
                template_id: templateId,
                providers: selectedProviders,
                filters: currentFilters,
                exclude_recent_phones: excludeRecentPhones
            });
            
            $.ajax({
                url: pcAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cm_schedule_campaign',
                    nonce: pcAjax.cmNonce || pcAjax.nonce, // Usa cmNonce para handlers de campanha normal
                    table_name: selectedTable,
                    filters: JSON.stringify(currentFilters),
                    providers_config: JSON.stringify(providersConfig),
                    template_id: templateId,
                    record_limit: recordLimit,
                    exclude_recent_phones: excludeRecentPhones
                },
                success: function(response) {
                    console.log('✅ Resposta recebida:', response);
                    if (response.success) {
                        let message = response.data.message || response.data;
                        if (response.data && response.data.records_skipped > 0 && response.data.exclusion_enabled) {
                            message += ` | ⚠️ ${response.data.records_skipped} telefones excluídos`;
                        }
                        alert('✅ ' + message);
                        window.location.href = pcAjax.homeUrl + '/painel/campanhas';
                    } else {
                        console.error('❌ Erro na resposta:', response);
                        alert('❌ Erro: ' + (response.data || 'Erro desconhecido'));
                        btn.prop('disabled', false).html('<i class="fas fa-paper-plane mr-2"></i>Criar Campanha');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('❌ Erro AJAX:', {xhr, status, error, responseText: xhr.responseText});
                    alert('❌ Erro de conexão: ' + error + '\n\nDetalhes: ' + (xhr.responseText || 'Sem resposta do servidor'));
                    btn.prop('disabled', false).html('<i class="fas fa-paper-plane mr-2"></i>Criar Campanha');
                }
            });
        }
    });

    // ===== TOAST NOTIFICATIONS =====
    function createToastContainer() {
        if ($('#cm-toast-container').length === 0) {
            $('body').append('<div id="cm-toast-container" class="cm-toast-container"></div>');
        }
    }

    function showToast(title, message, type = 'info', duration = 5000) {
        createToastContainer();
        
        const icons = {
            success: '✅',
            error: '❌',
            warning: '⚠️',
            info: 'ℹ️'
        };
        
        const toast = $(`
            <div class="cm-toast ${type}">
                <span class="cm-toast-icon">${icons[type] || icons.info}</span>
                <div class="cm-toast-content">
                    <div class="cm-toast-title">${title}</div>
                    <div class="cm-toast-message">${message}</div>
                </div>
                <button class="cm-toast-close" onclick="$(this).closest('.cm-toast').fadeOut(300, function() { $(this).remove(); });">×</button>
            </div>
        `);
        
        $('#cm-toast-container').append(toast);
        
        // Auto remove após duration
        if (duration > 0) {
            setTimeout(() => {
                toast.fadeOut(300, function() {
                    $(this).remove();
                });
            }, duration);
        }
        
        return toast;
    }

    // ===== PREVIEW DE MENSAGEM =====
    $('#template-select').on('change', function() {
        const templateId = $(this).val();
        
        if (templateId) {
            // Busca conteúdo completo do template via AJAX
            $.ajax({
                url: pcAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cm_get_template_content',
                    nonce: pcAjax.cmNonce || pcAjax.nonce, // Usa cmNonce para handlers de campanha normal
                    template_id: templateId
                },
                success: function(response) {
                    if (response.success) {
                        updateMessagePreview(response.data.content);
                        $('#message-preview-container').slideDown(300);
                    }
                },
                error: function() {
                    console.error('Erro ao carregar template');
                }
            });
        } else {
            $('#message-preview-container').slideUp(300);
        }
    });

    function updateMessagePreview(content) {
        // Simula preview com dados de exemplo
        let preview = content;
        
        // Substitui placeholders por exemplos
        const placeholders = {
            '{nome}': 'João Silva',
            '{cpf}': '123.456.789-00',
            '{cnpj}': '12.345.678/0001-90',
            '{telefone}': '(11) 98765-4321',
            '{idgis}': '123',
            '{contrato}': '12345',
            '{data}': new Date().toLocaleDateString('pt-BR')
        };
        
        Object.keys(placeholders).forEach(placeholder => {
            preview = preview.replace(new RegExp(placeholder.replace(/[{}]/g, '\\$&'), 'g'), placeholders[placeholder]);
        });
        
        $('#message-preview').html(preview || '<em style="color:#9ca3af;">Nenhuma mensagem</em>');
        
        // Atualiza contador de caracteres
        const charCount = preview.length;
        $('#char-count').text(charCount);
        
        // Alerta se passar do limite SMS (160 caracteres)
        if (charCount > 160) {
            $('#character-count').css('color', '#ef4444');
            $('#character-count').html(`<span id="char-count" style="font-weight:bold;">${charCount}</span> / 160 caracteres <span style="color:#f59e0b;">⚠️ Mensagem longa (pode ser cobrado como múltiplas SMS)</span>`);
        } else {
            $('#character-count').css('color', '#6b7280');
            $('#character-count').html(`<span id="char-count">${charCount}</span> / 160 caracteres`);
        }
    }

    // ===== VALIDAÇÃO DE TELEFONES =====
    function validatePhone(phone) {
        if (!phone) return { valid: false, message: 'Telefone vazio' };
        
        // Remove caracteres não numéricos
        const cleaned = phone.replace(/\D/g, '');
        
        // Valida comprimento (10 ou 11 dígitos para Brasil)
        if (cleaned.length < 10 || cleaned.length > 11) {
            return { valid: false, message: 'Telefone deve ter 10 ou 11 dígitos' };
        }
        
        // Remove código do país se presente
        let phoneNumber = cleaned;
        if (phoneNumber.length > 11 && phoneNumber.startsWith('55')) {
            phoneNumber = phoneNumber.substring(2);
        }
        
        // Valida DDD (deve começar com 0 e ter 2 dígitos após)
        if (phoneNumber.length === 11 && phoneNumber[2] !== '9') {
            return { valid: false, message: 'Celular deve começar com 9 após o DDD' };
        }
        
        return { valid: true, phone: phoneNumber };
    }

    // ===== UTILS =====
    function showMessage(text, type) {
        $('#schedule-message')
            .removeClass('success error')
            .addClass(type)
            .html(text);
    }

    function debounce(func, wait) {
        let timeout;
        return function(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    }

    // Inicialização
    // Só valida se os elementos existirem
    if ($('#template-select').length && $('#create-campaign-btn').length) {
        validateStep3();
    }
    createToastContainer();
    console.log('✅ Campaign Manager JS inicializado');
    
    // ===== CAMPANHA POR CPF =====
    let cpfTempId = '';
    let cpfTableName = '';
    let cpfMatchField = '';
    let cpfFilters = {};
    let cpfRecordsCount = 0;
    
    // Alternar entre tipos de campanha
    $('input[name="campaign_type"]').on('change', function() {
        const type = $(this).val();
        if (type === 'normal') {
            $('#normal-campaign-form').show();
            $('#cpf-campaign-form').hide();
        } else {
            $('#normal-campaign-form').hide();
            $('#cpf-campaign-form').show();
        }
    });
    
    // CPF Step 2: Selecionar tabela (após upload do CSV)
    $('#cpf-table-select').on('change', function() {
        cpfTableName = $(this).val();
        if (cpfTableName && cpfTempId) {
            // Mostra filtros e permite fazer o cruzamento
            $('#cpf-filters-step').slideDown();
            loadCpfFilters();
            // Faz preview count imediatamente (sem filtros)
            updateCpfPreviewCount();
        } else {
            $('#cpf-filters-step').slideUp();
            $('#cpf-download-step').slideUp();
            $('#cpf-campaign-step').slideUp();
        }
    });
    
    // CPF Step 1: Upload CSV
    // Processa arquivo selecionado ou arrastado
    function processCpfFile(file) {
        if (!file) return;
        
        // Valida tipo de arquivo
        if (!file.name.toLowerCase().endsWith('.csv')) {
            alert('Por favor, selecione apenas arquivos CSV');
            return;
        }
        
        // Valida tamanho (10MB)
        if (file.size > 10 * 1024 * 1024) {
            alert('Arquivo muito grande. Tamanho máximo: 10MB');
            return;
        }
        
        cpfMatchField = $('#matching-field').val();
        if (!cpfMatchField) {
            alert('Por favor, selecione o tipo de cruzamento primeiro');
            return;
        }
        
        // Mostra loading
        const originalHtml = $('#cpf-upload-area').html();
        $('#cpf-upload-area').html('<i class="fas fa-spinner fa-spin text-4xl text-blue-500 mb-3"></i><p class="text-gray-700 dark:text-gray-300">Processando arquivo...</p>');
        
        const formData = new FormData();
        formData.append('action', 'cpf_cm_upload_csv');
        formData.append('nonce', pcAjax.nonce); // Usa pc_nonce para handlers CPF
        formData.append('match_field', cpfMatchField);
        formData.append('csv_file', file);
        
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    cpfTempId = response.data.temp_id;
                    $('#cpf-count').text(response.data.count);
                    $('#cpf-preview-list').html(response.data.preview.join('<br>'));
                    $('#cpf-upload-preview').slideDown();
                    // Após upload, mostra step 2 (seleção de base)
                    $('#cpf-table-step').slideDown();
                } else {
                    alert('Erro: ' + response.data);
                    $('#cpf-upload-area').html(originalHtml);
                    initCpfUploadListeners();
                }
            },
            error: function() {
                alert('Erro ao fazer upload do arquivo');
                $('#cpf-upload-area').html(originalHtml);
                initCpfUploadListeners();
            }
        });
    }
    
    // Inicializa listeners de upload
    function initCpfUploadListeners() {
        // Remove listeners antigos
        $('#cpf-upload-area').off('click');
        $('#cpf-csv-file-input').off('change');
        $('#cpf-upload-step').off('dragover drop dragleave');
        
        // Click na área de upload - abre o seletor de arquivo
        $(document).on('click', '#cpf-upload-area', function(e) {
            // Evita loop infinito: não dispara se o click foi no input file
            if ($(e.target).is('input[type="file"]') || $(e.target).closest('input[type="file"]').length > 0) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            // Remove pointer-events: none temporariamente para permitir o click
            $('#cpf-csv-file-input').css('pointer-events', 'auto');
            $('#cpf-csv-file-input')[0].click();
            // Restaura após um pequeno delay
            setTimeout(() => {
                $('#cpf-csv-file-input').css('pointer-events', 'none');
            }, 100);
        });
        
        // Change no input file - quando arquivo é selecionado
        $(document).on('change', '#cpf-csv-file-input', function(e) {
            e.stopPropagation();
            const file = this.files[0];
            if (file) {
                processCpfFile(file);
            }
        });
        
        // Drag and drop
        $(document).on('dragover', '#cpf-upload-step', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $('#cpf-upload-area').addClass('border-blue-500 bg-blue-50 dark:bg-blue-900/20');
        });
        
        $(document).on('dragleave', '#cpf-upload-step', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $('#cpf-upload-area').removeClass('border-blue-500 bg-blue-50 dark:bg-blue-900/20');
        });
        
        $(document).on('drop', '#cpf-upload-step', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $('#cpf-upload-area').removeClass('border-blue-500 bg-blue-50 dark:bg-blue-900/20');
            
            const files = e.originalEvent.dataTransfer.files;
            if (files.length > 0) {
                processCpfFile(files[0]);
            }
        });
    }
    
    // Inicializa listeners quando a página carrega
    initCpfUploadListeners();
    
    $('#clear-cpf-upload').on('click', function() {
        cpfTempId = '';
        $('#cpf-csv-file-input').val('');
        $('#cpf-upload-preview').slideUp();
        $('#cpf-filters-step').slideUp();
        $('#cpf-download-step').slideUp();
        $('#cpf-campaign-step').slideUp();
    });
    
    // CPF Step 3: Filtros
    function loadCpfFilters() {
        if (!cpfTableName) return;
        
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cpf_cm_get_custom_filters',
                nonce: pcAjax.nonce,
                table_name: cpfTableName
            },
            success: function(response) {
                if (response.success) {
                    renderCpfFilters(response.data);
                } else {
                    $('#cpf-filters-container').html('<p class="text-gray-600 dark:text-gray-400">Nenhum filtro disponível</p>');
                }
            }
        });
    }
    
    function renderCpfFilters(filters) {
        const container = $('#cpf-filters-container');
        container.empty();
        
        if (Object.keys(filters).length === 0) {
            container.html('<p class="text-gray-600 dark:text-gray-400">Nenhum filtro disponível</p>');
            updateCpfPreviewCount();
            return;
        }
        
        let html = '';
        for (const [column, data] of Object.entries(filters)) {
            html += `<div class="mb-4 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">`;
            html += `<strong class="block mb-2 text-gray-900 dark:text-white">${column}</strong>`;
            html += `<div class="grid grid-cols-2 md:grid-cols-4 gap-2">`;
            data.values.forEach(value => {
                html += `<label class="flex items-center space-x-2 cursor-pointer">`;
                html += `<input type="checkbox" class="cpf-filter-checkbox" data-column="${column}" value="${value}">`;
                html += `<span class="text-sm text-gray-700 dark:text-gray-300">${value}</span>`;
                html += `</label>`;
            });
            html += `</div></div>`;
        }
        container.html(html);
        
        // Event listeners para checkboxes de filtros
        $(document).off('change', '.cpf-filter-checkbox').on('change', '.cpf-filter-checkbox', function() {
            updateCpfFilters();
            updateCpfPreviewCount();
        });
        
        // Atualiza contagem inicial (sem filtros)
        updateCpfPreviewCount();
    }
    
    function updateCpfFilters() {
        cpfFilters = {};
        $('.cpf-filter-checkbox:checked').each(function() {
            const column = $(this).data('column');
            const value = $(this).val();
            if (!cpfFilters[column]) {
                cpfFilters[column] = [];
            }
            cpfFilters[column].push(value);
        });
    }
    
    function updateCpfPreviewCount() {
        if (!cpfTempId || !cpfTableName) return;
        
        updateCpfFilters();
        
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cpf_cm_preview_count',
                nonce: pcAjax.nonce,
                table_name: cpfTableName,
                temp_id: cpfTempId,
                filters: JSON.stringify(cpfFilters)
            },
            success: function(response) {
                if (response.success) {
                    cpfRecordsCount = response.data.count;
                    $('#cpf-records-count').text(cpfRecordsCount.toLocaleString('pt-BR'));
                    // Mostra o step 4 (download/campanha) quando tiver contagem
                    if (cpfRecordsCount > 0) {
                        $('#cpf-download-step').slideDown();
                        $('#download-cpf-csv-btn').prop('disabled', false);
                        $('#create-cpf-campaign-btn').prop('disabled', false);
                    } else {
                        $('#cpf-download-step').slideDown();
                        $('#download-cpf-csv-btn').prop('disabled', true);
                        $('#create-cpf-campaign-btn').prop('disabled', true);
                        $('#cpf-records-count').parent().append('<p class="text-red-500 text-sm mt-2">⚠️ Nenhum registro encontrado com os critérios selecionados</p>');
                    }
                } else {
                    alert('Erro ao contar registros: ' + (response.data || 'Erro desconhecido'));
                }
            },
            error: function(xhr, status, error) {
                console.error('Erro ao contar registros:', error);
                alert('Erro ao contar registros. Verifique o console para mais detalhes.');
            }
        });
    }
    
    // CPF Step 4: Download CSV
    $('#download-cpf-csv-btn').on('click', function() {
        const btn = $(this);
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Gerando CSV...');
        
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cpf_cm_generate_clean_file',
                nonce: pcAjax.nonce,
                table_name: cpfTableName,
                temp_id: cpfTempId,
                filters: JSON.stringify(cpfFilters)
            },
            success: function(response) {
                if (response.success) {
                    // Download do arquivo
                    const link = document.createElement('a');
                    link.href = 'data:text/csv;base64,' + response.data.file;
                    link.download = response.data.filename;
                    link.click();
                    
                    // Após download, mostra opção de criar campanha
                    $('#cpf-campaign-step').slideDown();
                    $('#create-cpf-campaign-btn').prop('disabled', false);
                    btn.prop('disabled', false).html('<i class="fas fa-download mr-2"></i>Baixar arquivo limpo');
                } else {
                    alert('Erro: ' + response.data);
                    btn.prop('disabled', false).html('<i class="fas fa-download mr-2"></i>Baixar arquivo limpo');
                }
            },
            error: function() {
                alert('Erro ao gerar arquivo CSV');
                btn.prop('disabled', false).html('<i class="fas fa-download mr-2"></i>Baixar arquivo limpo');
            }
        });
    });
    
    // CPF Step 5: Preview mensagem
    $('#cpf-template-select').on('change', function() {
        const templateId = $(this).val();
        if (templateId) {
            const content = $(this).find('option:selected').data('content') || '';
            $('#cpf-message-preview').text(content);
            $('#cpf-char-count').text(content.length);
            $('#cpf-message-preview-container').slideDown();
            validateCpfStep5();
        } else {
            $('#cpf-message-preview-container').slideUp();
            validateCpfStep5();
        }
    });
    
    // CPF Step 5: Provedores
    $('input[name="cpf-distribution_mode"]').on('change', function() {
        const mode = $(this).val();
        $('.cpf-provider-percent-container').toggle(mode === 'split');
        $('.cpf-provider-percent').prop('disabled', mode === 'all');
        $('#cpf-percent-total').toggle(mode === 'split');
        validateCpfStep5();
    });
    
    $('.cpf-provider-checkbox').on('change', function() {
        validateCpfStep5();
        updateCpfPercentTotal();
    });
    
    $('.cpf-provider-percent').on('input', function() {
        updateCpfPercentTotal();
        validateCpfStep5();
    });
    
    function updateCpfPercentTotal() {
        let total = 0;
        $('.cpf-provider-checkbox:checked').each(function() {
            const provider = $(this).val();
            const percent = parseInt($(`.cpf-provider-percent[data-provider="${provider}"]`).val()) || 0;
            total += percent;
        });
        $('#cpf-percent-sum').text(total);
    }
    
    function validateCpfStep5() {
        const templateSelected = $('#cpf-template-select').val() !== '';
        const providersSelected = $('.cpf-provider-checkbox:checked').length > 0;
        const distributionMode = $('input[name="cpf-distribution_mode"]:checked').val();
        let isValid = templateSelected && providersSelected;
        
        if (distributionMode === 'split' && providersSelected) {
            const total = parseInt($('#cpf-percent-sum').text()) || 0;
            isValid = isValid && total === 100;
        }
        
        $('#create-cpf-campaign-btn').prop('disabled', !isValid);
    }
    
    // CPF Step 5: Criar campanha
    // Botão para criar campanha diretamente (sem baixar CSV)
    $('#create-cpf-campaign-btn').on('click', function() {
        // Mostra o step 5 (configuração de campanha) diretamente
        $('#cpf-campaign-step').slideDown();
        // Scroll para o step 5
        $('html, body').animate({
            scrollTop: $('#cpf-campaign-step').offset().top - 100
        }, 500);
    });
    
    // Handler original do botão criar campanha (após validar)
    $('#create-cpf-campaign-btn-final').on('click', function() {
        const btn = $(this);
        const templateId = $('#cpf-template-select').val();
        const selectedProviders = [];
        const percentages = {};
        
        $('.cpf-provider-checkbox:checked').each(function() {
            const provider = $(this).val();
            selectedProviders.push(provider);
            const percent = parseInt($(`.cpf-provider-percent[data-provider="${provider}"]`).val()) || 0;
            percentages[provider] = percent;
        });

        const distributionMode = $('input[name="cpf-distribution_mode"]:checked').val();
        const providersConfig = {
            mode: distributionMode,
            providers: selectedProviders,
            percentages: percentages
        };

        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Criando campanha...');

        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cpf_cm_create_campaign',
                nonce: pcAjax.nonce,
                table_name: cpfTableName,
                temp_id: cpfTempId,
                match_field: cpfMatchField,
                template_id: templateId,
                filters: JSON.stringify(cpfFilters),
                providers_config: JSON.stringify(providersConfig)
            },
            success: function(response) {
                if (response.success) {
                    showToast('Campanha criada com sucesso!', 'success');
                    setTimeout(() => {
                        window.location.href = pcData.homeUrl + '/painel/campanhas';
                    }, 1500);
                } else {
                    alert('Erro: ' + response.data);
                    btn.prop('disabled', false).html('<i class="fas fa-paper-plane mr-2"></i>Criar Campanha');
                }
            },
            error: function() {
                alert('Erro ao criar campanha');
                btn.prop('disabled', false).html('<i class="fas fa-paper-plane mr-2"></i>Criar Campanha');
            }
        });
    });
});

// ===== ESTILOS ADICIONAIS PARA ISCAS =====
if (!document.getElementById('cm-baits-custom-styles')) {
    const style = document.createElement('style');
    style.id = 'cm-baits-custom-styles';
    style.textContent = `
        .cm-baits-warning {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #f59e0b;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .cm-baits-warning strong {
            color: #92400e;
            font-size: 16px;
            display: block;
        }
        
        .cm-baits-warning p {
            margin: 5px 0 0 0;
            color: #78350f;
            font-size: 13px;
        }
        
        .cm-baits-info ul {
            list-style: none;
            padding-left: 0;
            margin: 10px 0 0 0;
        }
        
        .cm-baits-info ul li {
            padding: 5px 0;
            border-bottom: 1px solid rgba(120, 53, 15, 0.1);
        }
        
        .cm-baits-info ul li:last-child {
            border-bottom: none;
        }
    `;
    document.head.appendChild(style);
}