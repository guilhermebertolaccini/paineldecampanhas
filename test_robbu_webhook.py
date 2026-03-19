#!/usr/bin/env python3
"""
Script para testar o webhook Robbu/Invenio.
Simula requisições que a Robbu envia para o endpoint.
"""
import requests
import json
from datetime import datetime

WEBHOOK_URL = "https://paineldecampanhas.taticamarketing.com.br/wp-json/robbu-webhook/v2/receive"


def test_get():
    """Testa GET (validação/ping) - deve retornar 200 OK"""
    print("\n" + "="*50)
    print("1. Teste GET (validação)")
    print("="*50)
    try:
        r = requests.get(WEBHOOK_URL, timeout=15)
        print(f"Status: {r.status_code}")
        print(f"Resposta: {r.text[:200]}")
        if r.status_code == 200:
            print("[OK] GET - Webhook acessivel")
        else:
            print("[FALHA] GET")
    except Exception as e:
        print(f"[ERRO] {e}")


def test_post_whatsapp_number():
    """Simula evento whatsappNumber (linha WhatsApp)"""
    print("\n" + "="*50)
    print("2. Teste POST - evento whatsappNumber")
    print("="*50)
    payload = [
        {
            "whatsappNumber": {
                "id": 99999,
                "walletId": 1,
                "status": "CONNECTED",
                "countryCode": "55",
                "areaCode": "11",
                "phoneNumber": "999999999",
                "isActive": True,
                "broadcastLimitPerDay": 1000,
                "canSendHsm": True,
                "eventAt": datetime.now().isoformat()
            }
        }
    ]
    try:
        r = requests.post(
            WEBHOOK_URL,
            json=payload,
            headers={"Content-Type": "application/json"},
            timeout=15
        )
        print(f"Status: {r.status_code}")
        print(f"Resposta: {r.text}")
        if r.status_code == 200:
            resp = r.json()
            print(f"[OK] Processados: {resp.get('processed', 0)} linhas")
        else:
            print("[FALHA] POST")
    except Exception as e:
        print(f"[ERRO] {e}")


def test_post_status():
    """Simula evento status (status de mensagem)"""
    print("\n" + "="*50)
    print("3. Teste POST - evento status")
    print("="*50)
    payload = [
        {
            "status": {
                "messageId": 12345,
                "status": "delivered",
                "deliveredAt": datetime.now().isoformat()
            }
        }
    ]
    try:
        r = requests.post(
            WEBHOOK_URL,
            json=payload,
            headers={"Content-Type": "application/json"},
            timeout=15
        )
        print(f"Status: {r.status_code}")
        print(f"Resposta: {r.text}")
        if r.status_code == 200:
            print("[OK] Evento registrado")
        else:
            print("[FALHA] POST")
    except Exception as e:
        print(f"[ERRO] {e}")


def test_post_batch():
    """Simula bloco de 3 eventos (como Robbu envia)"""
    print("\n" + "="*50)
    print("4. Teste POST - bloco de eventos")
    print("="*50)
    payload = [
        {"whatsappNumber": {"id": 88888, "walletId": 1, "status": "CONNECTED", "countryCode": "55", "areaCode": "21", "phoneNumber": "988888888", "isActive": True, "broadcastLimitPerDay": 500, "canSendHsm": True}},
        {"whatsappNumber": {"id": 77777, "walletId": 1, "status": "CONNECTED", "countryCode": "55", "areaCode": "11", "phoneNumber": "977777777", "isActive": True, "broadcastLimitPerDay": 1000, "canSendHsm": True}},
        {"event": {"type": "test", "timestamp": datetime.now().isoformat()}}
    ]
    try:
        r = requests.post(
            WEBHOOK_URL,
            json=payload,
            headers={"Content-Type": "application/json"},
            timeout=15
        )
        print(f"Status: {r.status_code}")
        print(f"Resposta: {r.text}")
        if r.status_code == 200:
            resp = r.json()
            print(f"[OK] Recebidos: {resp.get('received', 0)} | Processados: {resp.get('processed', 0)} linhas")
        else:
            print("[FALHA] POST")
    except Exception as e:
        print(f"[ERRO] {e}")


if __name__ == "__main__":
    print("\n[Teste do Webhook Robbu]")
    print(f"URL: {WEBHOOK_URL}\n")
    test_get()
    test_post_whatsapp_number()
    test_post_status()
    test_post_batch()
    print("\n" + "="*50)
    print("Concluído. Verifique o painel API Manager > Webhook Robbu")
    print("para ver os eventos recebidos.")
    print("="*50 + "\n")
