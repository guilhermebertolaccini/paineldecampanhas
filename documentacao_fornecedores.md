# Documentação de Integração de Fornecedores de Disparo

Este documento descreve como realizar o despacho de campanhas (mensagens em massa ou HSM) para cada um dos fornecedores homologados no sistema. As explicações aqui são **agnósticas de linguagem**, ou seja, você pode implementar essas integrações em Python, Node.js, PHP, C#, Java, etc., respeitando os endpoints, headers e payloads informados.

---

## 1. Conceitos Gerais

Antes de entender cada fornecedor, é importante compreender os atributos que o seu sistema de origem deve possuir e enviar aos fornecedores:

* **`IDGIS_AMBIENTE` (ou `ID_CARTEIRA` / Código da Equipe):** É o identificador lógico que separa as bases de clientes no fornecedor. Muitos fornecedores usam esse código interno para associar os disparos a um departamento ou filial de cobrança específica.
* **`IDCOB_CONTRATO`:** O número do contrato do cliente. Geralmente passado como tag, variável ou dado extra para que a resposta do cliente possa ser vinculada ao contrato dele no CRM receptor.
* **Templates (HSM):** Para provedores oficiais de WhatsApp ou RCS (como Ótima), você não pode enviar qualquer texto livremente para um cliente que não interagiu nas últimas 24h. Você precisa aprovar um "Template" (nomeado, por exemplo, como `template_cobranca_v1`) no fornecedor e, na API, você apenas informa o `template_code` e quais são as variáveis (`nome`, `link`, etc.) que preencherão aquele template.

---

## 2. GOSAC

O envio para GOSAC requer duas etapas operacionais se feito de forma assíncrona, mas a criação de base exige requisição mapeada.

* **URL base:** `[URL_FORNECIDA_NAS_CREDENCIAIS]`
* **Método HTTP:** `POST`
* **Autenticação:** Header `Authorization` passando apenas o Token gerado no painel da GOSAC.

**Headers Requeridos:**
```http
Content-Type: application/json
Accept: application/json
Authorization: SeuTokenAqui123...
```

**JSON Payload (Exemplo de Criação de Campanha):**
```json
{
  "name": "campanha_171982739812_2026-02-23",
  "message": "Olá, temos um acordo especial para seu contrato!",
  "kind": "whats",
  "connectionId": null,
  "contacts": [
    {
      "name": "João da Silva",
      "number": "5511999999999",
      "hasWhatsapp": true
    }
  ],
  "defaultQueueId": 1,
  "initialMinutes": 480,
  "endMinutes": 1140,
  "customProps": [],
  "scheduled": false,
  "scheduledAt": "2026-02-23T12:00:00.000Z",
  "speed": "low",
  "tagId": 0,
  "templateId": null
}
```

> **Atenção:** O GOSAC retorna um `id` de campanha na criação. Para iniciar os disparos ativamente (se não for agendado), é necessário fazer uma requisição `PUT` para a URL `[URL]/[id_da_campanha]/status/started` usando as mesmas credenciais.

---

## 3. NOAH

O NOAH possui um envio simples do tipo Array -> Base.

* **URL:** `[URL_FORNECIDA]/contacts`
* **Método HTTP:** `POST`
* **Autenticação:** Header `Authorization` com o prefixo obrigatoriamente sendo `INTEGRATION`.

**Headers Requeridos:**
```http
Content-Type: application/json
Authorization: INTEGRATION SeuTokenAqui123...
```

**JSON Payload:**
```json
{
  "name": "campanha_171982739812",
  "data": [
    {
      "telefone": "5511999999999",
      "nome": "João da Silva",
      "idgis_ambiente": 3641,
      "idcob_contrato": 1234567,
      "cpf_cnpj": "12345678901",
      "mensagem": "Sua fatura vence hoje."
    }
  ]
}
```

---

## 4. CDA

A API da CDA requer que os clientes sejam formatados como linhas limitadas por ponto e vírgula (CSV virtual dentro de um array JSON).

* **URL:** `[URL_DA_API_CDA]` ("https://cromosapp.com.br/api/importar/campanha")
* **Método HTTP:** `POST`
* **Autenticação:** A chave vai diretamente no Payload JSON (não no Header).

**Headers Requeridos:**
```http
Content-Type: application/json
```

**Regra das "mensagens" (linhas):**
Formato: `idgis_ambiente;telefone_com_55;nome;cpf_cnpj;dois_ultimos_digitos_cpf`

**JSON Payload:**
```json
{
  "chave_api": "SuaChaveCDA123",
  "codigo_equipe": 3641,
  "codigo_usuario": "1",
  "nome": "campanha_3641_17198273",
  "ativo": true,
  "corpo_mensagem": "Mensagem padronizada de cobrança aqui.",
  "mensagens": [
    "3641;5511999999999;João da Silva;12345678900;00",
    "3641;5511988888888;Maria Oliveira;09876543211;11"
  ]
}
```

---

## 5. RCS CDA (CromosApp)

A integração via CromosApp para envio de RCS (Rich Communication Services) segue estrutura de array similar à CDA, mas os parâmetros nas strings concatenadas são diferentes.

* **URL Padrão:** `https://cromosapp.com.br/api/importarcs/importarRcsCampanhaAPI`
* **Método HTTP:** `POST`
* **Autenticação:** A chave de API também vai embutida no Payload JSON.

**Regra das "mensagens":**
Formato: `1;telefone;nome;tag_codigo;tag_cpf`
(O número `1` no começo da linha é obrigatório para cada telefone listado).

**JSON Payload:**
```json
{
  "chave_api": "SuaChaveRCS123",
  "codigo_equipe": 3641,
  "codigo_usuario": "1",
  "nome": "campanha_3641_17198273",
  "ativo": true,
  "corpo_mensagem": "Texto para o chat RCS.",
  "tag_numero_contrato": "Opcional",
  "mensagens": [
    "1;5511999999999;João da Silva;123456;12345678900",
    "1;5511988888888;Maria Santos;654321;09876543211"
  ]
}
```

---

## 6. Salesforce & Marketing Cloud (Jornada)

A integração com a Salesforce é feita em duas frentes:
1. Inserir os registros (Contatos) no Salesforce via Bulk/Composite.
2. Iniciar ativamente o Automation Studio (Marketing Cloud) para processar os registros inseridos.

### 6.1. Inserção no Salesforce (OAuth 2.0 Password Grant)
* **Auth URL:** `[TOKEN_URL]` (ex: `https://.../services/oauth2/token`)
* **API URL:** `[API_URL]` (ex: `https://.../services/data/v59.0/composite/sobjects`)
* **Headers Auth:** `Content-Type: application/x-www-form-urlencoded`
    * Enviar (via Formulário x-www-form): `grant_type=password`, `client_id`, `client_secret`, `username`, `password`
* **Headers API:** `Content-Type: application/json` e `Authorization: Bearer <Access_Token>`

**JSON Payload (Salesforce):**
```json
{
  "allOrNone": false,
  "records": [
    {
      "attributes": { "type": "Contact" },
      "MobilePhone": "5511999999999",
      "LastName": "João da Silva",
      "CPF_CNPJ__c": "12345678900",
      "Operacao__c": "NOME_AMBIENTE_OU_OPERACAO",
      "disparo__c": true
    }
  ]
}
```

### 6.2. Executar Disparo no Marketing Cloud
Esta etapa usualmente ocorre após 15 a 20 minutos da inserção para garantir que a Salesforce sincou com o Marketing Cloud.
* **Auth URL:** `[MKC_TOKEN_URL]` (grant type `client_credentials`)
* **API URL:** `[MKC_API_URL]/[Automation_ID]/actions/runallonce`
* **Payload para API:** Vazio `{}` apenas passando os Headers com JWT/Bearer gerados via Client Credentials.

---

## 7. Ótima WhatsApp (HSM Oficial)

Envios de mensagens oficiais de templates do WhatsApp. Diferente do GOSAC (onde enviamos o texto cru), na Ótima enviamos o ID de referência do template aprovado na Meta.

* **URL:** `https://services.otima.digital/v1/whatsapp/bulk/message/hsm`
* **Método HTTP:** `POST`
* **Autenticação:** Header `authorization` (em lowercase) com o token. (Não requer "Bearer " na frente muitas das vezes, verifique se a Ótima forneceu com ou sem prefixo).

**Headers Requeridos:**
```http
Content-Type: application/json
authorization: SeuTokenOtima123...
```

**JSON Payload:**
```json
{
  "broker_code": "SEU_BROKER",
  "customer_code": "SEU_CUSTOMER",
  "template_code": "template_cobranca_padrao_v1",
  "messages": [
    {
      "phone": "11999999999", 
      "document": "12345678900",
      "extra_fields": {
        "nome": "João da Silva",
        "id_carteira": 3641,
        "idcob_contrato": 1234567
      },
      "variables": {
        "nome": "João da Silva"
      }
    }
  ]
}
```
* **Nota sobre `phone`:** A Ótima geralmente espera o telefone **sem** o `55` na frente em disparos nacionais, verifique as exigências contratuais, mas o padrão deles é DDI auto inserido.
* **`variables`:** São os campos preenchidos dentro do texto do Template aprovado, ex: `Olá {{1}}, sua fatura...` onde `{{1}}` equivale ao primeiro parâmetro.

---

## 8. Ótima RCS

Muito semelhante ao WhatsApp Ótima, mas voltado à malha de entrega do Android RCS via Google Jibe.

* **URL:** `https://services.otima.digital/v1/rcs/bulk/message/template`
* **Método HTTP:** `POST`
* **Autenticação:** Header `authorization`

**JSON Payload:**
*(Diferente do Wpp, o RCS não engloba `broker_code` na raiz ou `template_code` da mesma forma global. Toda formatação se condensa nas regras contratuais da malha deles, embora se utilizem as mesmas estruturas das mensagens em array)*
```json
{
  "messages": [
    {
      "phone": "11999999999",
      "document": "12345678900",
      "extra_fields": {
        "nome": "João da Silva",
        "id_carteira": 3641,
        "idcob_contrato": 1234567
      },
      "variables": {
        "nome": "João da Silva"
      }
    }
  ]
}
```

---

## Resumo das Chaves Identificadoras e Variáveis de Negócio
Para quem está construindo uma integração, mapeie **exatamente** em qual de seu(s) atributo(s) do CRM estarão:
1. **Credenciais de Autenticação Estática:** URL, JWT/Token Base.
2. **Credenciais Dinâmicas de Departamento (`idgis_ambiente` / `Operação` / `Equipe`):** Aquela campanha sendo enviada precisa direcionar os clientes para a fila certa no GOSAC ou as credenciais certas do Noah. Esse ID é que cruza essas águas.
3. **Tracking Code (`agendamento_id`):** Não esqueça de embutir via *tags* ou no nome da campanha um UUID que identifique este disparo, para que você consiga ler os webhooks de entrega posteriormente.
