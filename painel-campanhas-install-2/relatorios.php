<?php
/**
 * Página de Relatórios
 */

if (!defined('ABSPATH')) exit;

$current_page = 'relatorios';
$page_title = 'Relatórios';

ob_start();
?>
<div class="max-w-7xl mx-auto">
    <!-- Header -->
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Relatório de Envios</h2>
        <p class="text-gray-600 dark:text-gray-400 mt-2">Visualize estatísticas e dados detalhados dos envios</p>
    </div>

    <!-- Cards de Estatísticas -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4 mb-6">
        <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border-l-4 border-green-500 p-4">
            <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">✅ Enviados</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white" id="stat-enviado">-</div>
        </div>
        <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border-l-4 border-blue-500 p-4">
            <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">⏳ Pend. Aprovação</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white" id="stat-pendente-aprovacao">-</div>
        </div>
        <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border-l-4 border-yellow-500 p-4">
            <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">📅 Agendado MKC</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white" id="stat-agendado-mkc">-</div>
        </div>
        <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border-l-4 border-indigo-500 p-4">
            <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">⏸️ Pendente</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white" id="stat-pendente">-</div>
        </div>
        <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border-l-4 border-red-500 p-4">
            <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">❌ Recusados</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white" id="stat-negado">-</div>
        </div>
        <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border-l-4 border-purple-500 p-4 cursor-pointer hover:shadow-md transition-shadow" id="card-1x1">
            <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">📞 Envios 1X1</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white" id="stat-1x1">-</div>
            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1" id="stat-1x1-detail">Clique para detalhes</div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
            <input type="text" id="filterUser" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white dark:bg-gray-800 text-gray-900 dark:text-white" placeholder="🔍 Filtrar usuário">
            <input type="text" id="filterFornecedor" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white dark:bg-gray-800 text-gray-900 dark:text-white" placeholder="🔍 Filtrar fornecedor">
            <input type="text" id="filterAmbiente" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white dark:bg-gray-800 text-gray-900 dark:text-white" placeholder="🔍 Filtrar ambiente">
            <input type="text" id="filterAgendamento" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white dark:bg-gray-800 text-gray-900 dark:text-white" placeholder="🔍 Filtrar agendamento ID">
            <input type="number" id="filterIdgis" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white dark:bg-gray-800 text-gray-900 dark:text-white" placeholder="🔍 Filtrar IDGIS">
            <input type="date" id="filterDateStart" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
            <input type="date" id="filterDateEnd" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
        </div>
        
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div class="flex gap-3">
                <button id="btnApplyFilters" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors flex items-center gap-2">
                    <i class="fas fa-search"></i>
                    <span>Aplicar Filtros</span>
                </button>
                <button id="btnClearFilters" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors flex items-center gap-2">
                    <i class="fas fa-redo"></i>
                    <span>Limpar</span>
                </button>
            </div>
            
            <div class="flex items-center gap-4">
                <div class="flex items-center gap-2">
                    <label for="perPageSelect" class="text-sm text-gray-600 dark:text-gray-400">Por página:</label>
                    <select id="perPageSelect" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
                        <option value="10">10</option>
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                
                <button id="btnDownloadCSV" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition-colors flex items-center gap-2">
                    <i class="fas fa-download"></i>
                    <span>Download CSV Geral</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Tabela -->
    <div class="bg-white dark:bg-surface-dark rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Data</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Usuário</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Agendamento ID</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Fornecedor</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Ambiente</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">✅ Enviado</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">⏳ Pend. Apr.</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">📅 Agend. MKC</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">⏸️ Pendente</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">❌ Recusado</th>
                    </tr>
                </thead>
                <tbody id="tableBody" class="divide-y divide-gray-200 dark:divide-gray-700">
                    <tr>
                        <td colspan="10" class="px-4 py-12 text-center">
                            <i class="fas fa-spinner fa-spin text-3xl text-gray-400 mb-4"></i>
                            <p class="text-gray-600 dark:text-gray-400">Carregando dados...</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <!-- Paginação -->
        <div class="px-6 py-4 bg-gray-50 dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between">
            <div class="text-sm text-gray-600 dark:text-gray-400">
                Mostrando <span id="showing-start" class="font-semibold">0</span> até <span id="showing-end" class="font-semibold">0</span> de <span id="total-records" class="font-semibold">0</span> registros
            </div>
            <div class="flex gap-2" id="pagination-buttons">
                <!-- Botões gerados via JS -->
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    const pcAjax = {
        ajaxUrl: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
        nonce: '<?php echo esc_js(wp_create_nonce('pc_nonce')); ?>',
        csvEndpoint: '<?php echo esc_js(admin_url('admin-post.php')); ?>',
        csvNonce: '<?php echo esc_js(wp_create_nonce('pc_csv_download')); ?>'
    };

    let currentPage = 1;
    let perPage = 25;
    let totalRecords = 0;
    let currentFilters = {};
    let carteiras1x1Data = [];

    // Carregar dados
    function loadData() {
        $('#tableBody').html('<tr><td colspan="10" class="px-4 py-12 text-center"><i class="fas fa-spinner fa-spin text-3xl text-gray-400 mb-4"></i><p class="text-gray-600 dark:text-gray-400">Carregando dados...</p></td></tr>');
        
        const formData = {
            action: 'pc_get_report_data',
            nonce: pcAjax.nonce,
            page: currentPage,
            per_page: perPage
        };
        
        Object.keys(currentFilters).forEach(key => {
            if (currentFilters[key]) {
                formData[key] = currentFilters[key];
            }
        });
        
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    const data = response.data.data || [];
                    const totals = response.data.totals || {};
                    totalRecords = response.data.total_records || 0;
                    
                    // Atualiza estatísticas
                    $('#stat-enviado').text(formatNumber(totals.total_enviado || 0));
                    $('#stat-pendente-aprovacao').text(formatNumber(totals.total_pendente_aprovacao || 0));
                    $('#stat-agendado-mkc').text(formatNumber(totals.total_agendado_mkc || 0));
                    $('#stat-pendente').text(formatNumber(totals.total_pendente || 0));
                    $('#stat-negado').text(formatNumber(totals.total_negado || 0));
                    
                    renderTable(data);
                    renderPagination();
                } else {
                    $('#tableBody').html('<tr><td colspan="10" class="px-4 py-12 text-center text-red-600 dark:text-red-400">Erro ao carregar dados</td></tr>');
                }
            },
            error: function() {
                $('#tableBody').html('<tr><td colspan="10" class="px-4 py-12 text-center text-red-600 dark:text-red-400">Erro de conexão</td></tr>');
            }
        });
    }

    // Renderizar tabela
    function renderTable(data) {
        if (!data || data.length === 0) {
            $('#tableBody').html('<tr><td colspan="10" class="px-4 py-12 text-center text-gray-600 dark:text-gray-400">📭 Nenhum registro encontrado</td></tr>');
            return;
        }
        
        let html = '';
        data.forEach(row => {
            const usuario = row.USUARIO || 'Sem usuário';
            const agendamento = row.AGENDAMENTO_ID || 'N/A';
            const ambiente = row.NOME_AMBIENTE || 'Sem ambiente';
            const hasAgendamento = agendamento && agendamento !== 'N/A';
            const csvUrl = hasAgendamento ? `${pcAjax.csvEndpoint}?action=pc_download_csv_agendamento&agendamento_id=${encodeURIComponent(agendamento)}&_wpnonce=${pcAjax.csvNonce}` : '';
            
            html += `
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-white">${formatDate(row.DATA)}</td>
                    <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-white">${escapeHtml(usuario)}</td>
                    <td class="px-4 py-3 text-sm">
                        <div class="flex items-center gap-2">
                            <code class="px-2 py-1 bg-gray-100 dark:bg-gray-700 rounded text-xs font-mono">${escapeHtml(agendamento)}</code>
                            ${hasAgendamento ? `<a href="${csvUrl}" class="px-2 py-1 bg-green-500 text-white text-xs rounded hover:bg-green-600 transition-colors" title="Baixar CSV">📥 CSV</a>` : ''}
                        </div>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-white">${escapeHtml(row.FORNECEDOR || '')}</td>
                    <td class="px-4 py-3 text-sm">
                        <span class="px-2 py-1 bg-blue-100 dark:bg-blue-900/20 text-blue-800 dark:text-blue-300 rounded text-xs font-semibold">${escapeHtml(ambiente)}</span>
                    </td>
                    <td class="px-4 py-3 text-sm text-center font-semibold text-green-600 dark:text-green-400">${formatNumber(parseInt(row.QTD_ENVIADO) || 0)}</td>
                    <td class="px-4 py-3 text-sm text-center font-semibold text-blue-600 dark:text-blue-400">${formatNumber(parseInt(row.QTD_PENDENTE_APROVACAO) || 0)}</td>
                    <td class="px-4 py-3 text-sm text-center font-semibold text-yellow-600 dark:text-yellow-400">${formatNumber(parseInt(row.QTD_AGENDADO_MKC) || 0)}</td>
                    <td class="px-4 py-3 text-sm text-center font-semibold text-indigo-600 dark:text-indigo-400">${formatNumber(parseInt(row.QTD_PENDENTE) || 0)}</td>
                    <td class="px-4 py-3 text-sm text-center font-semibold text-red-600 dark:text-red-400">${formatNumber(parseInt(row.QTD_NEGADO) || 0)}</td>
                </tr>
            `;
        });
        
        $('#tableBody').html(html);
    }

    // Renderizar paginação
    function renderPagination() {
        const totalPages = totalRecords === 0 ? 0 : Math.ceil(totalRecords / perPage);
        const start = totalRecords === 0 ? 0 : ((currentPage - 1) * perPage) + 1;
        const end = totalRecords === 0 ? 0 : Math.min(currentPage * perPage, totalRecords);
        
        $('#showing-start').text(formatNumber(start));
        $('#showing-end').text(formatNumber(end));
        $('#total-records').text(formatNumber(totalRecords));
        
        let html = '';
        
        if (totalPages > 0) {
            // Botão Anterior
            html += `<button class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg ${currentPage === 1 ? 'opacity-50 cursor-not-allowed' : 'hover:bg-blue-500 hover:text-white hover:border-blue-500'} transition-colors" ${currentPage === 1 ? 'disabled' : ''} onclick="goToPage(${currentPage - 1})">← Anterior</button>`;
            
            // Botões de página
            const maxButtons = 5;
            let startPage = Math.max(1, currentPage - Math.floor(maxButtons / 2));
            let endPage = Math.min(totalPages, startPage + maxButtons - 1);
            
            if (endPage - startPage < maxButtons - 1) {
                startPage = Math.max(1, endPage - maxButtons + 1);
            }
            
            if (startPage > 1) {
                html += `<button class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-blue-500 hover:text-white hover:border-blue-500 transition-colors" onclick="goToPage(1)">1</button>`;
                if (startPage > 2) {
                    html += `<button class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg opacity-50 cursor-not-allowed" disabled>...</button>`;
                }
            }
            
            for (let i = startPage; i <= endPage; i++) {
                html += `<button class="px-3 py-2 border rounded-lg transition-colors ${i === currentPage ? 'bg-blue-500 text-white border-blue-500' : 'border-gray-300 dark:border-gray-600 hover:bg-blue-500 hover:text-white hover:border-blue-500'}" onclick="goToPage(${i})">${i}</button>`;
            }
            
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    html += `<button class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg opacity-50 cursor-not-allowed" disabled>...</button>`;
                }
                html += `<button class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-blue-500 hover:text-white hover:border-blue-500 transition-colors" onclick="goToPage(${totalPages})">${totalPages}</button>`;
            }
            
            // Botão Próximo
            html += `<button class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg ${currentPage === totalPages ? 'opacity-50 cursor-not-allowed' : 'hover:bg-blue-500 hover:text-white hover:border-blue-500'} transition-colors" ${currentPage === totalPages ? 'disabled' : ''} onclick="goToPage(${currentPage + 1})">Próximo →</button>`;
        }
        
        $('#pagination-buttons').html(html);
    }

    // Funções auxiliares
    function formatNumber(num) {
        return new Intl.NumberFormat('pt-BR').format(Number.isFinite(num) ? num : 0);
    }

    function formatDate(dateStr) {
        if (!dateStr) return '-';
        const date = new Date(dateStr + 'T00:00:00');
        return date.toLocaleDateString('pt-BR');
    }

    function escapeHtml(text) {
        const map = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
        return String(text || '').replace(/[&<>"']/g, m => map[m]);
    }

    // Event listeners
    $('#btnApplyFilters').on('click', function() {
        currentFilters = {
            filter_user: $('#filterUser').val().trim(),
            filter_fornecedor: $('#filterFornecedor').val().trim(),
            filter_ambiente: $('#filterAmbiente').val().trim(),
            filter_agendamento: $('#filterAgendamento').val().trim(),
            filter_idgis: $('#filterIdgis').val().trim(),
            filter_date_start: $('#filterDateStart').val(),
            filter_date_end: $('#filterDateEnd').val()
        };
        currentPage = 1;
        loadData();
    });

    $('#btnClearFilters').on('click', function() {
        $('#filterUser, #filterFornecedor, #filterAmbiente, #filterAgendamento, #filterIdgis, #filterDateStart, #filterDateEnd').val('');
        currentFilters = {};
        currentPage = 1;
        loadData();
    });

    $('#btnDownloadCSV').on('click', function() {
        const params = new URLSearchParams({
            action: 'pc_download_csv_geral',
            _wpnonce: pcAjax.csvNonce
        });
        
        Object.entries(currentFilters).forEach(([key, value]) => {
            if (value) params.append(key, value);
        });

        window.location.href = `${pcAjax.csvEndpoint}?${params.toString()}`;
    });

    $('#perPageSelect').on('change', function() {
        perPage = parseInt($(this).val());
        currentPage = 1;
        loadData();
    });

    $('#card-1x1').on('click', function() {
        if (!carteiras1x1Data || carteiras1x1Data.length === 0) {
            alert('Nenhum dado de envios 1X1 disponível.');
            return;
        }
        
        let message = 'Detalhes dos Envios 1X1 por Carteira:\n\n';
        carteiras1x1Data.forEach(item => {
            message += `${item.carteira}: ${formatNumber(item.total)} envios\n`;
        });
        
        alert(message);
    });

    // Enter nos filtros
    $('#filterUser, #filterFornecedor, #filterAmbiente, #filterAgendamento, #filterIdgis').on('keypress', function(e) {
        if (e.which === 13) {
            $('#btnApplyFilters').click();
        }
    });

    // Função global para paginação
    window.goToPage = function(page) {
        currentPage = page;
        loadData();
    };

    // Carregar estatísticas 1X1
    function load1x1Stats() {
        $.ajax({
            url: pcAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pc_get_report_1x1_stats',
                nonce: pcAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    const total = response.data.total || 0;
                    carteiras1x1Data = response.data.carteiras || [];
                    
                    $('#stat-1x1').text(formatNumber(total));
                    
                    if (carteiras1x1Data.length > 0) {
                        const topCarteira = carteiras1x1Data[0];
                        $('#stat-1x1-detail').text(`Top: ${topCarteira.carteira} (${formatNumber(topCarteira.total)})`);
                    } else {
                        $('#stat-1x1-detail').text('Nenhum envio 1X1');
                    }
                }
            },
            error: function() {
                $('#stat-1x1').text('0');
                $('#stat-1x1-detail').text('Erro ao carregar');
            }
        });
    }

    // Carregar dados ao iniciar
    loadData();
    load1x1Stats();
});
</script>

<?php
$content = ob_get_clean();
global $pc_plugin_path;
$plugin_path = $pc_plugin_path;
include $plugin_path . 'base.php';