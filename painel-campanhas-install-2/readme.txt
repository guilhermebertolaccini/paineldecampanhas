=== Painel de Campanhas ===
Contributors: danielcayres
Tags: campanhas, mensageria, sms, whatsapp, api
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sistema completo de gerenciamento de campanhas de mensageria com interface moderna e integração com API NestJS.

== Description ==

Plugin WordPress completo para gerenciamento de campanhas de mensageria (WhatsApp, SMS, etc.) com interface web moderna e integração com APIs externas.

= Funcionalidades Principais =

* Sistema de autenticação e controle de acesso
* Dashboard com estatísticas em tempo real
* Criação de campanhas (normal e por CPF)
* Campanhas recorrentes
* Aprovação de campanhas (apenas admin)
* Gerenciamento de templates de mensagem
* Relatórios e estatísticas
* Controle de custos por provider
* Orçamentos por base de dados
* CRUD de carteiras
* Campanha via arquivo CSV
* Integração com REST API

= Requisitos =

* WordPress 5.0+
* PHP 7.4+
* Campaign Manager (obrigatório para campanhas normais)

== Installation ==

1. Copie a pasta `painel-campanhas` para `/wp-content/plugins/`
2. Ative o plugin no WordPress
3. Acesse `/painel/login` para fazer login
4. Configure as carteiras em Configurações
5. Cadastre custos e orçamentos em Controle de Custo

== Frequently Asked Questions ==

= O plugin precisa de outros plugins? =

Sim, o Campaign Manager é obrigatório para criar campanhas normais (usando bases VW_BASE*).

= Como configurar as carteiras? =

Acesse Configurações > Carteiras e crie as carteiras. Depois vincule as bases (VW_BASE*) às carteiras.

= Como funciona o controle de custos? =

Cadastre os custos por provider em Controle de Custo > Cadastro. Depois cadastre os orçamentos por base. O relatório mostrará os gastos automaticamente.

== Changelog ==

= 1.0.0 =
* Versão inicial
* Sistema completo de gerenciamento de campanhas
* Controle de custos
* CRUD de carteiras
* Campanha via arquivo

== Upgrade Notice ==

= 1.0.0 =
Versão inicial do plugin.

