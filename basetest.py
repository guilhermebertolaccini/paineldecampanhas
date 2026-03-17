import requests
import json
import time
from datetime import datetime

# Configurações do seu Webhook
WEBHOOK_URL = "https://paineldecampanhas.taticamarketing.com.br/wp-json/webhook/v1/VW_BASE_TESTE_PERFORMANCE"
HOOK_KEY = "yZ8lncm8S9fgh82lbhRD"

def gerar_dados_teste(quantidade=1000):
    """Gera um lote de registros fictícios para testar a velocidade"""
    print(f"⚙️ Gerando {quantidade} registros na memória...")
    dados = []
    agora = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    
    for i in range(quantidade):
        dados.append({
            "TELEFONE": f"1199999{str(i).zfill(4)}",
            "NOME": f"Cliente Teste Performance {i}",
            "CPF": f"123456789{str(i).zfill(2)}",
            "VALOR_DIVIDA": "1500.50",
            "DATA_VENCIMENTO": "2026-04-10",
            "ULT_ATUALIZACAO": agora,
            "IDGIS_AMBIENTE": "3651"
        })
    return dados

def testar_performance_webhook():
    payload = gerar_dados_teste(1000)
    
    headers = {
        "User-Agent": "Teste-Performance-Python",
        "x-hook-key": HOOK_KEY,
        "Content-Type": "application/json"
    }

    print("\n🚀 Disparando lote de 1.000 registros para o WordPress...")
    
    # Inicia o cronômetro
    start_time = time.time()
    
    try:
        # Envia com ?limpar_tabela=true para recriar a tabela limpa
        response = requests.post(WEBHOOK_URL + "?limpar_tabela=true", headers=headers, json=payload)
        
        # Para o cronômetro
        end_time = time.time()
        tempo_total = end_time - start_time
        
        print(f"\n✅ Status HTTP: {response.status_code}")
        
        if response.status_code == 200:
            resp_json = response.json()
            print(f"📦 Tabela: {resp_json.get('table')}")
            print(f"📝 Registros inseridos com sucesso: {resp_json.get('registros_inseridos')}")
            
            if resp_json.get('erros'):
                print(f"⚠️ Erros reportados pelo banco: {resp_json.get('erros')}")
        else:
            print(f"❌ Erro na requisição: {response.text}")

        print(f"\n⏱️ TEMPO TOTAL DE PROCESSAMENTO: {tempo_total:.2f} segundos")
        
    except Exception as e:
        print(f"❌ Erro de conexão: {e}")

if __name__ == "__main__":
    testar_performance_webhook()