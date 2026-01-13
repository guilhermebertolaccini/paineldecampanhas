/**
 * Painel de Campanhas - Main JavaScript
 */

(function($) {
    'use strict';

    const PainelCampanhas = {
        init: function() {
            this.initTheme();
            this.initSidebar();
            this.initLogout();
            this.initAnimations();
        },

        initTheme: function() {
            const themeToggle = $('#themeToggle');
            const themeIcon = $('#themeIcon');
            const htmlElement = document.documentElement;

            // Check for saved theme preference or use system preference
            const savedTheme = localStorage.getItem('theme');
            const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

            if (savedTheme === 'dark' || (!savedTheme && systemPrefersDark)) {
                htmlElement.classList.add('dark');
                themeIcon.removeClass('fa-moon').addClass('fa-sun');
            }

            themeToggle.on('click', function() {
                htmlElement.classList.toggle('dark');
                
                if (htmlElement.classList.contains('dark')) {
                    localStorage.setItem('theme', 'dark');
                    themeIcon.removeClass('fa-moon').addClass('fa-sun');
                } else {
                    localStorage.setItem('theme', 'light');
                    themeIcon.removeClass('fa-sun').addClass('fa-moon');
                }
            });
        },

        initSidebar: function() {
            const sidebar = $('#sidebar');
            const sidebarToggle = $('#sidebarToggle');

            sidebarToggle.on('click', function() {
                sidebar.toggleClass('open');
            });

            // Close sidebar when clicking outside on mobile
            $(document).on('click', function(e) {
                if ($(window).width() < 1024) {
                    if (!sidebar.is(e.target) && sidebar.has(e.target).length === 0 && !sidebarToggle.is(e.target)) {
                        sidebar.removeClass('open');
                    }
                }
            });
        },

        initLogout: function() {
            $('#logoutBtn').on('click', function(e) {
                e.preventDefault();
                
                if (!confirm('Tem certeza que deseja sair?')) {
                    return;
                }

                $.ajax({
                    url: pcData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'pc_logout',
                        nonce: pcData.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            window.location.href = response.data.redirect;
                        }
                    },
                    error: function() {
                        alert('Erro ao fazer logout. Tente novamente.');
                    }
                });
            });
        },

        initAnimations: function() {
            // Animate cards on scroll
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };

            const observer = new IntersectionObserver(function(entries) {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('animate-fadeIn');
                        observer.unobserve(entry.target);
                    }
                });
            }, observerOptions);

            // Observe all cards
            $('.bg-white, .bg-surface-dark').each(function() {
                observer.observe(this);
            });
        }
    };

    // Initialize when DOM is ready
    $(document).ready(function() {
        PainelCampanhas.init();
    });

})(jQuery);

