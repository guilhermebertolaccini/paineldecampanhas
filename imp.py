
import csv
import re
import time
from datetime import datetime
from pathlib import Path

import requests

# ============================================
# CONFIGURAÇÕES DO MARKETING CLOUD
# ============================================
AUTH_BASE_URL = "https://mchdb47kwgw19dh5mmnsw0fvhv2m.auth.marketingcloudapis.com"
REST_BASE_URL = "https://mchdb47kwgw19dh5mmnsw0fvhv2m.rest.marketingcloudapis.com"

CLIENT_ID = "bv53kgt3ocyggeua4synj2v0"
CLIENT_SECRET = "VqfpNASD3Q8bEyD4ktXqQhKJ"
ACCOUNT_ID = "536007880"

DE_KEY = "Tracking_WhatsApp_Importado_FINAL"

# ✅ Se der timeout, diminua (1000 ou 500)
PAGE_SIZE = 1000

# Timeout: (conectar, ler)
TIMEOUT = (20, 240)

# Retry manual por página
MAX_TRIES_PER_PAGE = 10
BASE_SLEEP_SECONDS = 5  # cresce a cada tentativa

# ============================================
# CONFIG DE FORMATAÇÃO DE DATA/HORA
# ============================================
DATE_FIELDS = {"eventdateu", "eventdateutc"}  # ajuste aqui
KEEP_TIME = True  # True = dd/mm/aaaa HH:MM(:SS), False = dd/mm/aaaa

# ============================================
# ONDE SALVAR O CSV
# ============================================
OUTPUT_PATH = Path(
    r"C:\Users\Daniel\Documents\sales\SALES_MESSAGING_EXPORT.csv"
)
CHECKPOINT_PATH = OUTPUT_PATH.with_suffix(".checkpoint.txt")


# ============================================
# AUTH
# ============================================
def get_token(session: requests.Session) -> str:
    url = f"{AUTH_BASE_URL}/v2/token"
    payload = {
        "grant_type": "client_credentials",
        "client_id": CLIENT_ID,
        "client_secret": CLIENT_SECRET,
        "account_id": ACCOUNT_ID,
    }
    print("[AUTH] Solicitando token...")
    resp = session.post(url, json=payload, timeout=TIMEOUT)
    resp.raise_for_status()
    token = resp.json()["access_token"]
    print("[AUTH] Token obtido com sucesso.")
    return token


# ============================================
# NORMALIZAÇÃO DE DATA (vários formatos)
# ============================================
def normalize_date(value, keep_time=True):
    """
    Aceita formatos comuns:
      - 10/30/2025 8:48:23 PM
      - 10/30/2025 8:48 PM
      - 11/01/2025 21:08
      - 11/01/2025 21:08:15
      - 11/03/2025
      - 2025-10-30 / 2025-10-30 21:08 / 2025-10-30T21:08:15Z
    Retorna:
      - keep_time=False -> dd/mm/aaaa
      - keep_time=True  -> dd/mm/aaaa HH:MM ou dd/mm/aaaa HH:MM:SS
    """
    if value is None:
        return value

    s = str(value).strip()
    if not s:
        return s

    s = re.sub(r"\s+", " ", s)

    patterns = [
        "%m/%d/%Y %I:%M:%S %p",  # 10/30/2025 8:48:23 PM
        "%m/%d/%Y %I:%M %p",     # 10/30/2025 8:48 PM
        "%m/%d/%Y %H:%M:%S",     # 11/01/2025 21:08:15
        "%m/%d/%Y %H:%M",        # 11/01/2025 21:08
        "%m/%d/%Y",              # 11/03/2025
        "%Y-%m-%d %H:%M:%S",     # 2025-10-30 21:08:15
        "%Y-%m-%d %H:%M",        # 2025-10-30 21:08
        "%Y-%m-%d",              # 2025-10-30
    ]

    # tenta ISO "T" e "Z"
    if "T" in s:
        try:
            dt = datetime.fromisoformat(s.replace("Z", "+00:00"))
            return dt.strftime("%d/%m/%Y %H:%M:%S") if keep_time else dt.strftime("%d/%m/%Y")
        except Exception:
            pass

    for p in patterns:
        try:
            dt = datetime.strptime(s, p)
            if not keep_time:
                return dt.strftime("%d/%m/%Y")

            # Se o padrão tinha hora
            if ("%H" in p) or ("%I" in p):
                if "%S" in p:
                    return dt.strftime("%d/%m/%Y %H:%M:%S")
                return dt.strftime("%d/%m/%Y %H:%M")

            return dt.strftime("%d/%m/%Y")
        except ValueError:
            pass

    # fallback: extrai mm/dd/yyyy e tenta converter (com ou sem hora)
    m = re.match(r"^(\d{1,2})/(\d{1,2})/(\d{4})(?: (.+))?$", s)
    if m:
        a = int(m.group(1))
        b = int(m.group(2))
        y = int(m.group(3))
        tail = (m.group(4) or "").strip()

        # decide MDY vs DMY
        if a > 12:
            day, month = a, b
        elif b > 12:
            month, day = a, b
        else:
            month, day = a, b  # assume MDY

        # hora 24h
        if tail:
            t = re.match(r"^(\d{1,2}):(\d{2})(?::(\d{2}))?$", tail)
            if t:
                hh = int(t.group(1))
                mm = int(t.group(2))
                ss = int(t.group(3) or 0)
                dt = datetime(y, month, day, hh, mm, ss)
                if not keep_time:
                    return dt.strftime("%d/%m/%Y")
                return dt.strftime("%d/%m/%Y %H:%M:%S" if t.group(3) else "%d/%m/%Y %H:%M")

            # hora 12h com AM/PM
            t = re.match(r"^(\d{1,2}):(\d{2})(?::(\d{2}))? ?([AP]M)$", tail, re.I)
            if t:
                hh = int(t.group(1))
                mm = int(t.group(2))
                ss = int(t.group(3) or 0)
                ap = t.group(4).upper()
                if ap == "PM" and hh != 12:
                    hh += 12
                if ap == "AM" and hh == 12:
                    hh = 0
                dt = datetime(y, month, day, hh, mm, ss)
                if not keep_time:
                    return dt.strftime("%d/%m/%Y")
                return dt.strftime("%d/%m/%Y %H:%M:%S" if t.group(3) else "%d/%m/%Y %H:%M")

        dt = datetime(y, month, day)
        return dt.strftime("%d/%m/%Y")

    return s


def fix_row_dates(row: dict) -> dict:
    for field in DATE_FIELDS:
        if field in row:
            row[field] = normalize_date(row[field], keep_time=KEEP_TIME)
    return row


# ============================================
# FETCH PAGE
# ============================================
def fetch_de_page(session: requests.Session, token: str, page: int) -> dict:
    url = f"{REST_BASE_URL}/data/v1/customobjectdata/key/{DE_KEY}/rowset"
    params = {"$page": page, "$pageSize": PAGE_SIZE}
    headers = {"Authorization": f"Bearer {token}"}
    resp = session.get(url, headers=headers, params=params, timeout=TIMEOUT)

    if resp.status_code in (401, 403):
        raise PermissionError("TOKEN_EXPIRED")

    resp.raise_for_status()
    return resp.json()


# ============================================
# CHECKPOINT
# ============================================
def load_checkpoint(default_page: int = 1) -> int:
    if CHECKPOINT_PATH.exists():
        try:
            return max(1, int(CHECKPOINT_PATH.read_text(encoding="utf-8").strip()))
        except Exception:
            return default_page
    return default_page


def save_checkpoint(next_page: int) -> None:
    CHECKPOINT_PATH.write_text(str(next_page), encoding="utf-8")


# ============================================
# MAIN
# ============================================
def main():
    OUTPUT_PATH.parent.mkdir(parents=True, exist_ok=True)

    session = requests.Session()
    token = get_token(session)

    page = load_checkpoint(1)

    # Se você quer recomeçar do zero:
    # apague o CSV e o .checkpoint.txt antes de rodar.
    write_header = not OUTPUT_PATH.exists() or page == 1
    mode = "w" if write_header else "a"

    print(f"[EXPORT] Gerando CSV em: {OUTPUT_PATH} (modo={mode}, page_start={page})")

    total_registros = None
    linhas_escritas = 0

    with OUTPUT_PATH.open(mode, newline="", encoding="utf-8") as csvfile:
        writer = None
        header = None

        while True:
            # retry manual por página
            data = None
            last_error = None

            for attempt in range(1, MAX_TRIES_PER_PAGE + 1):
                try:
                    data = fetch_de_page(session, token, page)
                    last_error = None
                    break
                except PermissionError as e:
                    if str(e) == "TOKEN_EXPIRED":
                        print(f"[AUTH] Token expirou na página {page}. Renovando...")
                        token = get_token(session)
                        continue
                    last_error = e
                except (requests.exceptions.ReadTimeout,
                        requests.exceptions.ConnectionError,
                        requests.exceptions.ChunkedEncodingError) as e:
                    last_error = e
                except requests.exceptions.HTTPError as e:
                    last_error = e

                sleep_s = int(BASE_SLEEP_SECONDS * (attempt ** 1.3))
                print(f"[WARN] Página {page} falhou (tentativa {attempt}/{MAX_TRIES_PER_PAGE}): {last_error}")
                print(f"[WARN] Aguardando {sleep_s}s e tentando novamente...")
                time.sleep(sleep_s)

            if last_error is not None or data is None:
                print(f"[FAIL] Não consegui baixar a página {page} após {MAX_TRIES_PER_PAGE} tentativas.")
                print(f"[FAIL] Erro final: {last_error}")
                print("[TIP] Se continuar, reduza PAGE_SIZE para 500.")
                return

            items = data.get("items", [])

            if total_registros is None:
                total_registros = data.get("count", 0)
                print(f"[EXPORT] Total de registros na DE: {total_registros}")

            if not items:
                print(f"[EXPORT] Página {page} vazia. Fim.")
                if CHECKPOINT_PATH.exists():
                    CHECKPOINT_PATH.unlink()
                break

            print(f"[EXPORT] Página {page}: {len(items)} registros.")

            for item in items:
                row = {}
                row.update(item.get("keys", {}) or {})
                row.update(item.get("values", {}) or {})

                row = fix_row_dates(row)

                if writer is None:
                    header = list(row.keys())
                    writer = csv.DictWriter(csvfile, fieldnames=header, delimiter=";")
                    if write_header:
                        writer.writeheader()
                        write_header = False

                writer.writerow(row)
                linhas_escritas += 1

            csvfile.flush()
            save_checkpoint(page + 1)

            links = data.get("links", {})
            if not links.get("next"):
                print("[EXPORT] Não há 'next'. Última página.")
                if CHECKPOINT_PATH.exists():
                    CHECKPOINT_PATH.unlink()
                break

            page += 1

    print(f"[DONE] CSV gerado/atualizado com +{linhas_escritas} linhas em: {OUTPUT_PATH}")


if __name__ == "__main__":
    main()