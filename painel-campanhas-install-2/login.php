<?php
/**
 * Página de Login
 */

if (!defined('ABSPATH')) exit;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Painel de Campanhas</title>
    <?php wp_head(); ?>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: {
                            light: "#2563eb",
                            dark: "#1d4ed8"
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-purple-50 dark:from-gray-900 dark:via-gray-800 dark:to-gray-900 flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-8 transform transition-all duration-300 hover:scale-105">
            <!-- Logo -->
            <div class="text-center mb-8">
                <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-purple-600 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg">
                    <i class="fas fa-bullhorn text-white text-2xl"></i>
                </div>
                <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-2">Painel de Campanhas</h1>
                <p class="text-gray-600 dark:text-gray-400">Faça login para continuar</p>
            </div>

            <!-- Form -->
            <form id="loginForm" class="space-y-6">
                <div>
                    <label for="username" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        <i class="fas fa-user mr-2"></i>Usuário
                    </label>
                    <input 
                        type="text" 
                        id="username" 
                        name="username" 
                        required
                        autocomplete="username"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white dark:bg-gray-700 text-gray-900 dark:text-white transition-all"
                        placeholder="Digite seu usuário"
                    >
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        <i class="fas fa-lock mr-2"></i>Senha
                    </label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        required
                        autocomplete="current-password"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white dark:bg-gray-700 text-gray-900 dark:text-white transition-all"
                        placeholder="Digite sua senha"
                    >
                </div>

                <div class="flex items-center justify-between">
                    <label class="flex items-center">
                        <input 
                            type="checkbox" 
                            id="remember" 
                            name="remember" 
                            value="1"
                            class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                        >
                        <span class="ml-2 text-sm text-gray-600 dark:text-gray-400">Lembrar-me</span>
                    </label>
                </div>

                <div id="errorMessage" class="hidden bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg text-sm"></div>

                <button 
                    type="submit" 
                    class="w-full bg-gradient-to-r from-blue-500 to-purple-600 text-white py-3 rounded-lg font-semibold hover:from-blue-600 hover:to-purple-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transform transition-all duration-200 hover:scale-105 shadow-lg"
                >
                    <i class="fas fa-sign-in-alt mr-2"></i>Entrar
                </button>
            </form>
        </div>

        <!-- Footer -->
        <p class="text-center text-gray-600 dark:text-gray-400 mt-6 text-sm">
            © <?php echo date('Y'); ?> Painel de Campanhas. Todos os direitos reservados.
        </p>
    </div>

    <?php wp_footer(); ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('loginForm');
            const errorMessage = document.getElementById('errorMessage');

            form.addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const submitBtn = form.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Entrando...';
                errorMessage.classList.add('hidden');

                try {
                    const formData = new FormData(form);
                    formData.append('action', 'pc_login');
                    formData.append('nonce', pcData.nonce);

                    const response = await fetch(pcData.ajaxUrl, {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        window.location.href = data.data.redirect;
                    } else {
                        errorMessage.textContent = data.data.message || 'Erro ao fazer login';
                        errorMessage.classList.remove('hidden');
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalText;
                    }
                } catch (error) {
                    errorMessage.textContent = 'Erro de conexão. Tente novamente.';
                    errorMessage.classList.remove('hidden');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
            });
        });
    </script>
</body>
</html>

