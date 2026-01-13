/**
 * JavaScript para página Nova Campanha
 * Integra Campaign Manager e CPF Campaign Manager
 */

(function($) {
    'use strict';

    const NovaCampanha = {
        filters: [],
        cpfTempId: null,
        cpfMatchField: null,

        init: function() {
            this.initCampaignType();
            this.initNormalCampaign();
            this.initCpfCampaign();
            this.initProviderPercentages();
        },

        initCampaignType: function() {
            $('.campaign-type-radio').on('change', function() {
                const type = $(this).val();
                $('.campaign-form').hide();
                if (type === 'normal') {
                    $('#normal-campaign-form').show();
                } else {
                    $('#cpf-campaign-form').show();
                }
            });
        },

        initNormalCampaign: function() {
            // Step 1: Selecionar base
            $('#data-source-select').on('change', function() {
                const tableName = $(this).val();
                if (tableName) {
                    NovaCampanha.loadFilters(tableName);
                    $('#filters-step').show();
                } else {
                    $('#filters-step').hide();
                    $('#details-step').hide();
                }
            });

            // Step 3: Selecionar template
            $('#template-select').on('change', function() {
                const templateId = $(this).val();
                if (templateId) {
                    NovaCampanha.loadTemplatePreview(templateId);
                } else {
                    $('#message-preview-container').hide();
                }
            });

            // Criar campanha
            $('#create-campaign-btn').on('click', function(e) {
                e.preventDefault();
                NovaCampanha.createNormalCampaign();
            });
        },

        initCpfCampaign: function() {
            // Step 1: Selecionar base
            $('#cpf-table-select').on('change', function() {
                const tableName = $(this).val();
                if (tableName) {
                    $('#cpf-upload-step').show();
                } else {
                    $('#cpf-upload-step').hide();
                    $('#cpf-details-step').hide();
                }
            });

            // Step 2: Upload CSV
            $('#cpf-upload-area').on('click', function() {
                $('#cpf-csv-file-input').click();
            });

            $('#cpf-csv-file-input').on('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    NovaCampanha.uploadCsvFile(file);
                }
            });

            $('#clear-cpf-upload').on('click', function() {
                $('#cpf-csv-file-input').val('');
                $('#cpf-upload-preview').hide();
                $('#cpf-details-step').hide();
                NovaCampanha.cpfTempId = null;
            });

            // Carregar filtros após upload
            $('#cpf-table-select, #matching-field').on('change', function() {
                if (NovaCampanha.cpfTempId && $('#cpf-table-select').val()) {
                    NovaCampanha.loadCpfFilters();
                }
            });

            // Download CSV
            $('#download-cpf-csv-btn').on('click', function() {
                NovaCampanha.downloadCpfCsv();
            });

            // Criar campanha CPF
            $('#create-cpf-campaign-btn').on('click', function(e) {
                e.preventDefault();
                NovaCampanha.createCpfCampaign();
            });
        },

        initProviderPercentages: function() {
            // Normal campaign providers
            $('.provider-checkbox').on('change', function() {
                NovaCampanha.updateProviderPercentages('normal');
            });

            $('.distribution-mode').on('change', function() {
                NovaCampanha.updateProviderPercentages('normal');
            });

            $('.provider-percent').on('input', function() {
                NovaCampanha.updateProviderPercentages('normal');
            });

            // CPF campaign providers
            $('.cpf-provider-checkbox').on('change', function() {
                NovaCampanha.updateProviderPercentages('cpf');
            });

            $('.cpf-distribution-mode').on('change', function() {
                NovaCampanha.updateProviderPercentages('cpf');
            });

            $('.cpf-provider-percent').on('input', function() {
                NovaCampanha.updateProviderPercentages('cpf');
            });
        },

        updateProviderPercentages: function(type) {
            const prefix = type === 'cpf' ? 'cpf-' : '';
            const mode = $(`input[name="${prefix}distribution_mode"]:checked`).val();
            const checkboxes = $(`.${prefix}provider-checkbox:checked`);
            
            // Mostra/esconde campos de porcentagem
            if (mode === 'split' && checkboxes.length > 0) {
                checkboxes.each(function() {
                    const provider = $(this).val();
                    $(`.${prefix}provider-percent[data-provider="${provider}"]`).closest(`.${prefix}provider-percent-container`).show();
                });
                $(`#${prefix}percent-total`).show();
            } else {
                $(`.${prefix}provider-percent-container`).hide();
                $(`#${prefix}percent-total`).hide();
            }

            // Calcula total
            if (mode === 'split') {
                let total = 0;
                checkboxes.each(function() {
                    const provider = $(this).val();
                    const percent = parseFloat($(`.${prefix}provider-percent[data-provider="${provider}"]`).val()) || 0;
                    total += percent;
                });
                $(`#${prefix}percent-sum`).text(total.toFixed(0));
                
                // Validação visual
                if (total !== 100 && checkboxes.length > 0) {
                    $(`#${prefix}percent-total`).addClass('text-red-600 dark:text-red-400').removeClass('text-gray-600 dark:text-gray-400');
                } else {
                    $(`#${prefix}percent-total`).addClass('text-gray-600 dark:text-gray-400').removeClass('text-red-600 dark:text-red-400');
                }
            }
        },

        loadFilters: function(tableName) {
            $('#filters-container').html('<p class="text-gray-600 dark:text-gray-400">⏳ Carregando filtros...</p>');
            
            $.ajax({
                url: pcAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cm_get_filters',
                    nonce: pcAjax.nonce,
                    table_name: tableName
                },
                success: (response) => {
                    if (response.success) {
                        this.filters = [];
                        this.renderFilters(response.data);
                        this.updateAudienceCount();
                    } else {
                        $('#filters-container').html('<p class="text-red-600">Erro ao carregar filtros</p>');
                    }
                },
                error: () => {
                    $('#filters-container').html('<p class="text-red-600">Erro de conexão</p>');
                }
            });
        },

        renderFilters: function(filtersData) {
            let html = '<div class="space-y-4">';
            
            if (!filtersData || Object.keys(filtersData).length === 0) {
                html += '<p class="text-gray-600 dark:text-gray-400">Nenhum filtro disponível para esta tabela</p>';
                html += '</div>';
                $('#filters-container').html(html);
                return;
            }

            for (const [column, filterInfo] of Object.entries(filtersData)) {
                html += `<div class="filter-item p-4 border border-gray-200 dark:border-gray-700 rounded-lg">`;
                html += `<label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">${column}</label>`;
                
                if (filterInfo.type === 'numeric') {
                    html += `<div class="grid grid-cols-2 gap-2">`;
                    html += `<input type="number" class="filter-min w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white" data-column="${column}" placeholder="Mínimo">`;
                    html += `<input type="number" class="filter-max w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white" data-column="${column}" placeholder="Máximo">`;
                    html += `</div>`;
                } else if (filterInfo.type === 'categorical') {
                    html += `<select class="filter-select w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white" data-column="${column}" multiple size="5">`;
                    filterInfo.values.forEach(value => {
                        html += `<option value="${value}">${value}</option>`;
                    });
                    html += `</select>`;
                    html += `<p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Mantenha Ctrl pressionado para selecionar múltiplos</p>`;
                }
                
                html += `</div>`;
            }

            html += '</div>';
            html += '<button type="button" id="apply-filters-btn" class="mt-4 px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">Aplicar Filtros</button>';
            
            $('#filters-container').html(html);
            
            // Event listener para aplicar filtros
            $('#apply-filters-btn').on('click', () => {
                this.collectFilters();
                this.updateAudienceCount();
            });
        },

        collectFilters: function() {
            this.filters = [];
            const filtersMap = {};
            
            // Filtros numéricos
            $('.filter-min, .filter-max').each(function() {
                const column = $(this).data('column');
                const isMin = $(this).hasClass('filter-min');
                const value = $(this).val();
                
                if (value) {
                    if (!filtersMap[column]) {
                        filtersMap[column] = {};
                    }
                    if (isMin) {
                        filtersMap[column].min = value;
                    } else {
                        filtersMap[column].max = value;
                    }
                }
            });
            
            // Converte map para array de filtros
            Object.keys(filtersMap).forEach(column => {
                const filter = filtersMap[column];
                if (filter.min !== undefined && filter.max !== undefined) {
                    // Range: cria dois filtros
                    this.filters.push({
                        column: column,
                        operator: '>=',
                        value: filter.min
                    });
                    this.filters.push({
                        column: column,
                        operator: '<=',
                        value: filter.max
                    });
                } else if (filter.min !== undefined) {
                    this.filters.push({
                        column: column,
                        operator: '>=',
                        value: filter.min
                    });
                } else if (filter.max !== undefined) {
                    this.filters.push({
                        column: column,
                        operator: '<=',
                        value: filter.max
                    });
                }
            });
            
            // Filtros categóricos
            $('.filter-select').each(function() {
                const column = $(this).data('column');
                const values = $(this).val();
                
                if (values && values.length > 0) {
                    this.filters.push({
                        column: column,
                        operator: 'IN',
                        value: values
                    });
                }
            }.bind(this));
        },

        updateAudienceCount: function() {
            const tableName = $('#data-source-select').val();
            if (!tableName) return;

            const filters = this.filters.length > 0 ? JSON.stringify(this.filters) : JSON.stringify([]);

            $.ajax({
                url: pcAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cm_get_count',
                    nonce: pcAjax.nonce,
                    table_name: tableName,
                    filters: filters
                },
                success: (response) => {
                    if (response.success) {
                        $('#audience-count').text(response.data);
                        $('#details-step').show();
                    }
                }
            });
        },

        loadTemplatePreview: function(templateId) {
            $.ajax({
                url: pcAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cm_get_template_content',
                    nonce: pcAjax.nonce,
                    template_id: templateId
                },
                success: (response) => {
                    if (response.success) {
                        $('#message-preview').text(response.data);
                        const charCount = response.data.length;
                        $('#char-count').text(charCount);
                        $('#message-preview-container').show();
                    }
                }
            });
        },

        createNormalCampaign: function() {
            const tableName = $('#data-source-select').val();
            const templateId = $('#template-select').val();
            const recordLimit = $('#record-limit').val() || 0;
            const distributionMode = $('input[name="distribution_mode"]:checked').val();
            const selectedProviders = $('.provider-checkbox:checked').map(function() {
                return $(this).val();
            }).get();

            if (!tableName || !templateId || selectedProviders.length === 0) {
                alert('Preencha todos os campos obrigatórios');
                return;
            }

            // Coleta porcentagens
            const percentages = {};
            if (distributionMode === 'split') {
                selectedProviders.forEach(provider => {
                    const percent = parseFloat($(`.provider-percent[data-provider="${provider}"]`).val()) || 0;
                    percentages[provider] = percent;
                });
                
                const total = Object.values(percentages).reduce((a, b) => a + b, 0);
                if (total !== 100) {
                    alert('A soma das porcentagens deve ser 100%');
                    return;
                }
            }

            const providersConfig = {
                mode: distributionMode,
                providers: selectedProviders,
                percentages: percentages
            };

            const btn = $('#create-campaign-btn');
            const originalText = btn.html();
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Criando...');

            $.ajax({
                url: pcAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cm_schedule_campaign',
                    nonce: pcAjax.nonce,
                    table_name: tableName,
                    filters: JSON.stringify(this.filters),
                    template_id: templateId,
                    providers_config: JSON.stringify(providersConfig),
                    record_limit: recordLimit,
                    exclude_recent_phones: false
                },
                success: (response) => {
                    if (response.success) {
                        alert(response.data.message);
                        window.location.href = pcAjax.homeUrl + '/painel/campanhas';
                    } else {
                        alert('Erro: ' + (response.data || 'Erro desconhecido'));
                        btn.prop('disabled', false).html(originalText);
                    }
                },
                error: () => {
                    alert('Erro de conexão');
                    btn.prop('disabled', false).html(originalText);
                }
            });
        },

        uploadCsvFile: function(file) {
            if (!file) return;

            const matchField = $('#matching-field').val();
            if (!matchField) {
                alert('Selecione o tipo de cruzamento (CPF ou Telefone)');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'cpf_cm_upload_csv');
            formData.append('nonce', pcAjax.cpfNonce);
            formData.append('csv_file', file);
            formData.append('match_field', matchField);

            $.ajax({
                url: pcAjax.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: (response) => {
                    if (response.success) {
                        this.cpfTempId = response.data.temp_id;
                        this.cpfMatchField = response.data.match_field;
                        $('#cpf-count').text(response.data.count);
                        $('#cpf-upload-preview').show();
                        $('#cpf-details-step').show();
                        this.loadCpfFilters();
                    } else {
                        alert('Erro: ' + (response.data || 'Erro ao processar arquivo'));
                    }
                },
                error: () => {
                    alert('Erro de conexão');
                }
            });
        },

        loadCpfFilters: function() {
            const tableName = $('#cpf-table-select').val();
            if (!tableName || !this.cpfTempId) return;

            $('#cpf-filters-container').html('<p class="text-gray-600 dark:text-gray-400">⏳ Carregando filtros...</p>');

            $.ajax({
                url: pcAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cpf_cm_get_custom_filters',
                    nonce: pcAjax.cpfNonce,
                    table_name: tableName
                },
                success: (response) => {
                    if (response.success) {
                        this.renderCpfFilters(response.data);
                        this.updateCpfCount();
                    }
                }
            });
        },

        renderCpfFilters: function(filtersData) {
            let html = '<div class="space-y-4">';
            
            if (!filtersData || Object.keys(filtersData).length === 0) {
                html += '<p class="text-gray-600 dark:text-gray-400">Nenhum filtro disponível</p>';
            } else {
                for (const [column, filterInfo] of Object.entries(filtersData)) {
                    html += `<div class="cpf-filter-item p-4 border border-gray-200 dark:border-gray-700 rounded-lg">`;
                    html += `<label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">${column}</label>`;
                    
                    if (filterInfo.type === 'categorical' && filterInfo.values) {
                        html += `<select class="cpf-filter-select w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white" data-column="${column}" multiple size="5">`;
                        filterInfo.values.forEach(value => {
                            html += `<option value="${value}">${value}</option>`;
                        });
                        html += `</select>`;
                    }
                    
                    html += `</div>`;
                }
            }

            html += '</div>';
            html += '<button type="button" id="apply-cpf-filters-btn" class="mt-4 px-4 py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600 transition-colors">Aplicar Filtros</button>';
            
            $('#cpf-filters-container').html(html);
            
            $('#apply-cpf-filters-btn').on('click', () => {
                this.updateCpfCount();
            });
        },

        updateCpfCount: function() {
            const tableName = $('#cpf-table-select').val();
            if (!tableName || !this.cpfTempId) return;

            const filters = this.collectCpfFilters();

            $.ajax({
                url: pcAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cpf_cm_preview_count',
                    nonce: pcAjax.cpfNonce,
                    table_name: tableName,
                    temp_id: this.cpfTempId,
                    match_field: this.cpfMatchField,
                    filters: JSON.stringify(filters)
                },
                success: (response) => {
                    if (response.success) {
                        $('#cpf-records-count').text(response.data.count);
                        $('#cpf-download-section').show();
                        $('#download-cpf-csv-btn').prop('disabled', false);
                    }
                }
            });
        },

        collectCpfFilters: function() {
            const filters = {};
            $('.cpf-filter-select').each(function() {
                const column = $(this).data('column');
                const values = $(this).val();
                if (values && values.length > 0) {
                    filters[column] = values;
                }
            });
            return filters;
        },

        downloadCpfCsv: function() {
            const tableName = $('#cpf-table-select').val();
            if (!tableName || !this.cpfTempId) return;

            const filters = this.collectCpfFilters();
            const btn = $('#download-cpf-csv-btn');
            const originalText = btn.html();
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Gerando...');

            $.ajax({
                url: pcAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cpf_cm_generate_clean_file',
                    nonce: pcAjax.cpfNonce,
                    table_name: tableName,
                    temp_id: this.cpfTempId,
                    filters: JSON.stringify(filters)
                },
                success: (response) => {
                    if (response.success) {
                        // Decodifica base64 e faz download
                        const csvContent = atob(response.data.file);
                        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                        const link = document.createElement('a');
                        const url = URL.createObjectURL(blob);
                        link.setAttribute('href', url);
                        link.setAttribute('download', response.data.filename);
                        link.style.visibility = 'hidden';
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                        btn.prop('disabled', false).html(originalText);
                    } else {
                        alert('Erro: ' + (response.data || 'Erro ao gerar arquivo'));
                        btn.prop('disabled', false).html(originalText);
                    }
                },
                error: () => {
                    alert('Erro de conexão');
                    btn.prop('disabled', false).html(originalText);
                }
            });
        },

        createCpfCampaign: function() {
            const tableName = $('#cpf-table-select').val();
            const templateId = $('#cpf-template-select').val();
            const distributionMode = $('input[name="cpf-distribution_mode"]:checked').val();
            const selectedProviders = $('.cpf-provider-checkbox:checked').map(function() {
                return $(this).val();
            }).get();

            if (!tableName || !templateId || !this.cpfTempId || selectedProviders.length === 0) {
                alert('Preencha todos os campos obrigatórios');
                return;
            }

            // Coleta porcentagens
            const percentages = {};
            if (distributionMode === 'split') {
                selectedProviders.forEach(provider => {
                    const percent = parseFloat($(`.cpf-provider-percent[data-provider="${provider}"]`).val()) || 0;
                    percentages[provider] = percent;
                });
                
                const total = Object.values(percentages).reduce((a, b) => a + b, 0);
                if (total !== 100) {
                    alert('A soma das porcentagens deve ser 100%');
                    return;
                }
            }

            const filters = this.collectCpfFilters();

            const btn = $('#create-cpf-campaign-btn');
            const originalText = btn.html();
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Criando...');

            // Primeiro, gera o arquivo limpo e depois cria a campanha
            $.ajax({
                url: pcAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cpf_cm_create_campaign',
                    nonce: pcAjax.nonce,
                    table_name: tableName,
                    temp_id: this.cpfTempId,
                    match_field: this.cpfMatchField,
                    filters: JSON.stringify(filters),
                    template_id: templateId,
                    providers_config: JSON.stringify({
                        mode: distributionMode,
                        providers: selectedProviders,
                        percentages: percentages
                    })
                },
                success: (response) => {
                    if (response.success) {
                        alert(response.data.message || 'Campanha criada com sucesso!');
                        window.location.href = pcAjax.homeUrl + '/painel/campanhas';
                    } else {
                        alert('Erro: ' + (response.data || 'Erro desconhecido'));
                        btn.prop('disabled', false).html(originalText);
                    }
                },
                error: () => {
                    alert('Erro de conexão');
                    btn.prop('disabled', false).html(originalText);
                }
            });
        }
    };

    $(document).ready(function() {
        NovaCampanha.init();
    });

})(jQuery);
