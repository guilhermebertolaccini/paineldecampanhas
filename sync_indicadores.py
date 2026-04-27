"""
Sincronização da tabela `indicadores_digital` (SQL Server destino, .26)
a partir de duas fontes:

    1) WordPress (painel de campanhas) via CSV streaming.
    2) SQL Server GRC (tabela TB_WEBHOOK_GOSAC_EVENTO_INDICADOR), apenas
       registros da fornecedora cujo nome contém "tima" (Ótima).

O MERGE (UPSERT) usa a mesma chave composta em ambas as fontes e a mesma
tabela de staging, de modo que os dois processos alimentam uniformemente
o mesmo alvo.

Falhas em um dia ou em uma fonte **não** interrompem os próximos dias
(WP e GRC rodam juntos por data em ``resolve_date_range()``).

A janela ``ETL_INDICADORES_DAYS_BACK`` (ou ``ETL_INDICADORES_DATE``) define
quantas requisições leves o WordPress e o GRC processam, uma data por
iteração: ``&data=YYYY-MM-DD`` no painel, filtro alinhado no GRC.
O CSV do painel é delimitado por **ponto e vírgula** (``;``).
"""

from __future__ import annotations

import io
import logging
import os
import re
import time
import urllib.parse
from datetime import date, timedelta

import pandas as pd
import requests
from dotenv import load_dotenv
from sqlalchemy import create_engine, text
from sqlalchemy.engine import Engine


logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
log = logging.getLogger("sync_indicadores")

load_dotenv()


# -------------------------------------------------------------------
# Conexões
# -------------------------------------------------------------------

def _build_mssql_engine(server: str, database: str, user: str, password: str,
                        driver: str) -> Engine:
    """Monta um engine SQLAlchemy para SQL Server usando o formato URL limpo
    (mssql+pyodbc://user:pass@host/db). Evita ambiguidades de parsing que
    ocorrem com senhas contendo caracteres especiais.
    """
    u = urllib.parse.quote_plus(user)
    p = urllib.parse.quote_plus(password)
    d = urllib.parse.quote_plus(driver)
    url = (
        f"mssql+pyodbc://{u}:{p}@{server}/{database}"
        f"?driver={d}&TrustServerCertificate=yes"
    )
    return create_engine(url, fast_executemany=True)


def get_engine() -> Engine:
    """Engine do SQL Server de destino (.26)."""
    server = os.getenv("MSSQL_SERVER", "").strip()
    database = os.getenv("MSSQL_DATABASE", "").strip()
    user = os.getenv("MSSQL_USER", "").strip()
    password = os.getenv("MSSQL_PASSWORD", "")
    driver = os.getenv("MSSQL_ODBC_DRIVER", "ODBC Driver 18 for SQL Server")

    if not all([server, database, user, password]):
        raise RuntimeError(
            "MSSQL_SERVER, MSSQL_DATABASE, MSSQL_USER e MSSQL_PASSWORD são obrigatórios."
        )
    return _build_mssql_engine(server, database, user, password, driver)


def get_grc_engine() -> Engine:
    """Engine do SQL Server de origem GRC (relatório)."""
    server = os.getenv("MSSQL_GRC_SERVER", "").strip()
    database = os.getenv("MSSQL_GRC_DATABASE", "").strip()
    user = os.getenv("MSSQL_GRC_USER", "").strip()
    password = os.getenv("MSSQL_GRC_PASSWORD", "")
    driver = os.getenv("MSSQL_GRC_ODBC_DRIVER",
                       os.getenv("MSSQL_ODBC_DRIVER",
                                 "ODBC Driver 18 for SQL Server"))

    if not all([server, database, user, password]):
        raise RuntimeError(
            "MSSQL_GRC_SERVER, MSSQL_GRC_DATABASE, MSSQL_GRC_USER e "
            "MSSQL_GRC_PASSWORD são obrigatórios."
        )
    return _build_mssql_engine(server, database, user, password, driver)


# -------------------------------------------------------------------
# Helpers de MERGE / schema
# -------------------------------------------------------------------

# Chave composta do MERGE (UPSERT) no destino.
#
# Histórico do bug que motivou esta chave:
#   - O endpoint REST do WP (`/wp-json/pc/v1/export/indicadores`) deduplica
#     via subquery `MAX(id) GROUP BY (telefone, fornecedor)`.
#   - O GRC (Ótima) faz a mesma deduplicação por (TELEFONE, FORNECEDOR).
#   - Para fornecedores como NOAH, que enviam massivamente sem CPF, todas as
#     linhas chegavam com cpf_cnpj='Não preenchido' + mesma idigs_ambiente
#     + mesmo login. Com a PK antiga ["data","cpf_cnpj","idigs_ambiente","login"],
#     todas colidiam no MERGE → milhares de linhas viravam 1 só no destino.
#
# A chave (data, fornecedor, telefone) espelha a unicidade real produzida por
# ambas as fontes upstream e mantém o histórico por dia (mesma linha em datas
# diferentes vira 2 linhas, comportamento desejado para série temporal). As
# antigas colunas-chave continuam no SELECT como atributos, atualizáveis pelo
# UPDATE SET do MERGE.
PK_COLUMNS = ["data", "fornecedor", "telefone"]


def _validate_pk_columns(chunk_columns: list[str]) -> None:
    """Garante que o chunk tem exatamente as colunas de MERGE (PK) esperadas
    antes de to_sql/ensure — evita staging de uma coluna só se o `sep` estiver
    errado e efeito dominó no GRC."""
    falt = [c for c in PK_COLUMNS if c not in chunk_columns]
    if falt:
        raise ValueError(
            f"FALHA CRÍTICA DE SCHEMA: colunas PK ausentes {falt!r}. "
            f"Colunas lidas: {chunk_columns!r}"
        )


def build_merge_query(target_fqn: str, staging_fqn: str, columns: list[str]) -> str:
    """Constrói um MERGE (UPSERT) com PK composta fixa em PK_COLUMNS."""
    on_conditions = " AND ".join(
        [f"Target.[{c}] = Source.[{c}]" for c in PK_COLUMNS]
    )
    update_columns = [c for c in columns if c not in PK_COLUMNS]
    update_set = ", ".join(
        [f"Target.[{c}] = Source.[{c}]" for c in update_columns]
    )
    insert_cols = ", ".join([f"[{c}]" for c in columns])
    insert_vals = ", ".join([f"Source.[{c}]" for c in columns])

    return f"""
    MERGE {target_fqn} AS Target
    USING {staging_fqn} AS Source
    ON {on_conditions}
    WHEN MATCHED THEN
        UPDATE SET {update_set}
    WHEN NOT MATCHED BY TARGET THEN
        INSERT ({insert_cols})
        VALUES ({insert_vals});
    """


def ensure_target_table(conn, target_fqn: str, staging_fqn: str) -> None:
    """Cria a tabela destino (vazia, schema clonado da staging) caso não exista."""
    conn.execute(text(f"""
        IF OBJECT_ID('{target_fqn}', 'U') IS NULL
        BEGIN
            SELECT * INTO {target_fqn} FROM {staging_fqn} WHERE 1 = 0;
        END
    """))


def _normalize_chunk(chunk: pd.DataFrame) -> pd.DataFrame:
    """Converte tudo para string, troca NaN por '' e aplica dedup defensivo
    pela PK do MERGE (mantém a última ocorrência).

    A dedup defensiva é importante porque o `MERGE` do SQL Server explode com
    "The MERGE statement attempted to UPDATE or DELETE the same row of the
    target table more than once" sempre que o Source tiver múltiplas linhas
    para a mesma PK. Hoje o REST e o GRC já entregam dados deduplicados, mas
    isso é uma trava barata para que uma regressão futura no SQL upstream
    não derrube o ETL inteiro.
    """
    chunk = chunk.fillna("").astype(str)
    if not chunk.empty and all(c in chunk.columns for c in PK_COLUMNS):
        before = len(chunk)
        chunk = chunk.drop_duplicates(subset=PK_COLUMNS, keep="last")
        after = len(chunk)
        if before != after:
            log.warning(
                "Dedup defensivo no chunk: %d → %d (–%d) — Source tinha "
                "duplicatas na PK %s. Verificar dedup do upstream.",
                before, after, before - after, PK_COLUMNS,
            )
    return chunk


def _log_chunk_cardinality(tag: str, chunk_count: int, chunk: pd.DataFrame) -> None:
    """Log de cardinalidade por chunk (linhas, distinct PK, top fornecedores).

    Existe para flagar imediatamente regressões do tipo "milhares de linhas
    colidindo na mesma PK do MERGE" — exatamente o bug que originou a troca
    de PK_COLUMNS. Se distinct_pk << len(chunk), algo voltou a quebrar.
    """
    distinct_pk = chunk[PK_COLUMNS].drop_duplicates().shape[0] if not chunk.empty else 0
    top_fornecedores = (
        chunk["fornecedor"].value_counts().head(5).to_dict()
        if "fornecedor" in chunk.columns and not chunk.empty
        else {}
    )
    log.info(
        "[%s] Chunk %d: linhas=%d, distinct PK %s=%d, top fornecedores=%s",
        tag, chunk_count, len(chunk), PK_COLUMNS, distinct_pk, top_fornecedores,
    )


# -------------------------------------------------------------------
# Filtro de data (compartilhado entre WP e GRC)
# -------------------------------------------------------------------

def parse_date_filter() -> str | None:
    """Lê ETL_INDICADORES_DATE e normaliza:
        - 'all'      -> 'all' (sem filtro)
        - 'YYYY-MM-DD' -> data específica
        - vazio      -> None (D-0, data de hoje)
    """
    raw = os.getenv("ETL_INDICADORES_DATE", "").strip()
    if not raw:
        return None
    if raw.lower() == "all":
        return "all"
    if not re.match(r"^\d{4}-\d{2}-\d{2}$", raw):
        raise ValueError(
            f"ETL_INDICADORES_DATE inválido: {raw!r}. Use 'all', "
            "'YYYY-MM-DD' ou deixe vazio."
        )
    return raw


def _read_int_env(var: str, default: int, *, min_value: int = 0) -> int:
    raw = os.getenv(var, "").strip()
    if not raw:
        return default
    try:
        n = int(raw)
    except ValueError as exc:
        raise ValueError(f"{var} deve ser inteiro: {raw!r}") from exc
    if n < min_value:
        raise ValueError(f"{var} deve ser >= {min_value} (recebido: {n})")
    return n


def resolve_date_range() -> list[str | None]:
    """Lista de calendário compartilhada pelo **WordPress** e pelo **GRC**
    (cada iteração = uma requisição/filtro no painel e o mesmo dia no GRC).

    Variáveis de ambiente:
      - ``ETL_INDICADORES_DAYS_BACK``  (>=1): tamanho da janela.
      - ``ETL_INDICADORES_DAYS_OFFSET`` (>=0): de onde começar a contar.
            0 → começa em **hoje (D-0)**;
            1 → começa em **ontem (D-1)** — modo recomendado para cron diário.
      - ``ETL_INDICADORES_DATE``: ``all`` / ``YYYY-MM-DD`` / vazio (D-0). Só é
        usado quando ``ETL_INDICADORES_DAYS_BACK`` está vazio.

    Exemplos:
      DAYS_BACK=1 / OFFSET=1     → [ontem]   (cron diário)
      DAYS_BACK=10 / OFFSET=1    → [ontem ... ontem-9]  (re-processa última dezena)
      DAYS_BACK=10 / OFFSET=0    → [hoje ... hoje-9]
    """
    days_back = _read_int_env("ETL_INDICADORES_DAYS_BACK", default=0, min_value=0)
    if days_back >= 1:
        offset = _read_int_env("ETL_INDICADORES_DAYS_OFFSET", default=0, min_value=0)
        today = date.today()
        start = today - timedelta(days=offset)
        return [(start - timedelta(days=i)).isoformat() for i in range(days_back)]

    return [parse_date_filter()]


def _grc_date_predicate(date_filter: str | None, column: str = "[DATA]") -> str:
    """Legado: filtro de data em uma coluna única. Preferir
    :func:`_grc_date_filter_subquery` para a extração GRC.
    """
    if date_filter == "all":
        return ""
    if date_filter is None:
        return f"CAST({column} AS DATE) = CAST(GETDATE() AS DATE)"
    return f"CAST({column} AS DATE) = '{date_filter}'"


def _grc_fornecedor_subquery() -> str:
    """Filtro amplo da fornecedora (Ótima / OTIMA / variação de maiúsculas)."""
    return """(
        UPPER(LTRIM(RTRIM([FORNECEDOR]))) LIKE '%OTIMA%'
        OR [FORNECEDOR] LIKE '%tima%'
    )"""


def _grc_fornecedor_outer() -> str:
    """Mesmo critério com alias I. na tabela externa."""
    return """(
        UPPER(LTRIM(RTRIM(I.[FORNECEDOR]))) LIKE '%OTIMA%'
        OR I.[FORNECEDOR] LIKE '%tima%'
    )"""


def _grc_date_filter_subquery(date_filter: str | None) -> str:
    """Condição de data na subquery de deduplicação (sem AND inicial).

    Usa ``TRY_CAST`` em vez de ``CAST`` para colunas híbridas (varchar/NULL)
    sem derrubar a query. ``EITHER`` = qualquer coluna bate a data.
    """
    if date_filter == "all":
        return ""

    mode = os.getenv("MSSQL_GRC_FILTER_DATE", "EITHER").strip().upper()
    d = "CAST(GETDATE() AS DATE)" if date_filter is None else f"CAST('{date_filter}' AS DATE)"

    if mode == "DATA":
        return f"TRY_CAST([DATA] AS DATE) = {d}"
    if mode == "DATA_ENVIO":
        return f"TRY_CAST([DATA_ENVIO] AS DATE) = {d}"
    return f"(TRY_CAST([DATA] AS DATE) = {d} OR TRY_CAST([DATA_ENVIO] AS DATE) = {d})"


# -------------------------------------------------------------------
# Pipeline: WordPress
# -------------------------------------------------------------------

def _build_wp_url(wp_base: str, date_filter: str | None) -> str:
    url = f"{wp_base.rstrip('/')}/wp-json/pc/v1/export/indicadores?format=csv"
    if date_filter == "all":
        return f"{url}&data=all"
    if date_filter is not None and date_filter != "all":
        return f"{url}&data={date_filter}"
    return url


def _wp_http_get_with_retries(url: str, headers: dict) -> tuple[bytes, str]:
    """GET com retry em 5xx. Retorna (corpo, content-type)."""
    retries = max(1, int(os.getenv("WP_HTTP_RETRIES", "3")))
    retry_wait = float(os.getenv("WP_HTTP_RETRY_SEC", "2.5"))
    response: requests.Response | None = None
    for attempt in range(1, retries + 1):
        response = requests.get(url, headers=headers, stream=True, timeout=600)
        if response.status_code < 500:
            break
        code = response.status_code
        body_preview = (response.text or "")[:400].replace("\n", " ")
        response.close()
        if attempt < retries:
            log.warning(
                "[WP] HTTP %s (tentativa %d/%d). Trecho: %s",
                code, attempt, retries, body_preview,
            )
            time.sleep(retry_wait)
        else:
            log.error(
                "[WP] HTTP %s após %d tentativas. Trecho: %s",
                code, retries, body_preview,
            )
    assert response is not None
    response.raise_for_status()
    out = response.content
    ct = response.headers.get("Content-Type", "?")
    response.close()
    return out, ct


def _process_wp(dest_engine: Engine, *, target_fqn: str, staging_fqn: str,
                staging_table: str, schema: str, chunk_size: int,
                date_filter: str | None) -> int:
    wp_base_url = os.getenv("WP_BASE_URL", "").rstrip("/")
    api_key = os.getenv("WP_X_API_KEY")
    if not wp_base_url or not api_key:
        raise RuntimeError("WP_BASE_URL e WP_X_API_KEY são obrigatórios no .env")

    if date_filter == "all":
        log.info("Filtro de data: histórico completo (all).")
    elif date_filter is None:
        log.info("Sem filtro explícito: WP D-0 (hoje) na API.")
    else:
        log.info("Filtro de data ativado: %s", date_filter)

    url = _build_wp_url(wp_base_url, date_filter)
    log.info("Iniciando download: %s", url)
    headers = {"X-API-KEY": api_key}
    body, content_type = _wp_http_get_with_retries(url, headers)
    log.info(
        "[WP] Corpo bruto: %d bytes, Content-Type: %s",
        len(body),
        content_type,
    )
    if not body:
        log.error(
            "[WP] Resposta 200 com corpo 0 bytes. Ver API key, endpoint e painel."
        )
        return 0

    csv_text: str
    for enc in ("utf-8-sig", "utf-8", "latin-1", "cp1252"):
        try:
            csv_text = body.decode(enc)
            break
        except UnicodeDecodeError:
            continue
    else:
        csv_text = body.decode("utf-8", errors="replace")

    sample = (csv_text[:800].replace("\r", " ").replace("\n", " | ")).strip()
    s_low = csv_text.lstrip().lower()
    if not sample or s_low.startswith("<!doctype") or s_low.startswith("<html"):
        log.error(
            "[WP] A resposta não é CSV (HTML ou vazio). Trecho: %s", sample[:500]
        )
        return 0
    if s_low.startswith("{"):
        log.error(
            "[WP] Resposta é JSON, não CSV (API rejeitou ou erro). Trecho: %s", sample[:500]
        )
        return 0

    str_io = io.StringIO(csv_text)
    total_rows = 0
    chunk_count = 0
    with dest_engine.begin() as conn:
        for chunk in pd.read_csv(
            str_io,
            sep=";",
            chunksize=chunk_size,
            dtype=str,
        ):
            chunk_count += 1
            chunk = _normalize_chunk(chunk)
            columns = chunk.columns.tolist()
            _validate_pk_columns(columns)

            _log_chunk_cardinality("WP", chunk_count, chunk)

            chunk.to_sql(staging_table, conn, if_exists="replace",
                         index=False, schema=schema)
            ensure_target_table(conn, target_fqn, staging_fqn)
            conn.execute(text(build_merge_query(target_fqn, staging_fqn, columns)))

            total_rows += len(chunk)
            log.info("[WP] Acumulado: %d linhas", total_rows)

        conn.execute(text(f"DROP TABLE IF EXISTS {staging_fqn}"))

    if total_rows == 0:
        log.warning(
            "[WP] 0 linhas de dado (OK). Início do corpo: %s",
            sample[:700],
        )

    return total_rows


# -------------------------------------------------------------------
# Pipeline: GRC (Ótima)
# -------------------------------------------------------------------
# Origem: TB_WEBHOOK_GOSAC_EVENTO_INDICADOR (colunas físicas em UPPER_SNAKE,
# p.ex. [id], [DATA], [FORNECEDOR], [CODIGO_CARTEIRA], [CPC_PRODUTIVO],
# [TIPO_ATENDIMENTO], [PROTOCOLO], [TMA] — o SELECT mapeia para o CSV do WP).

# Diagnóstico GRC (uma vez por execução, no primeiro 0 linhas)
_GRC_RAN_AUTO_DIAG: bool = False

# Colunas que devem ser produzidas na mesma ordem/nome do CSV do WP.
_GRC_EXPECTED_COLUMNS = [
    "data", "fornecedor", "carteira", "idigs_ambiente", "idcob_contrato",
    "cpf_cnpj", "telefone", "status", "evento", "login", "envio", "falha",
    "entregue", "lido", "cpc", "CPCA", "boleto", "tipoAtendimento",
    "protocolo", "tma", "tipo_envio",
]


def _build_grc_query(date_filter: str | None) -> str:
    """Monta a SELECT da origem GRC (Ótima) com:
        - Filtro por fornecedor (Ótima / OTIMA)
        - Deduplicação por ``MAX([id])`` sobre (telefone, fornecedor)
        - Filtro de data: ver ``MSSQL_GRC_FILTER_DATE`` (DATA / DATA_ENVIO / EITHER)
        - Aliases e transformações para o schema do CSV do WP
    """
    if os.getenv("MSSQL_GRC_SKIP_DATE_FILTER", "").strip() == "1":
        log.warning(
            "[GRC] MSSQL_GRC_SKIP_DATE_FILTER=1 — nenhum filtro de [DATA]/[DATA_ENVIO] "
            "na subquery (só fornecedora + deduplicação)."
        )
        date_sub = ""
    else:
        date_sub = _grc_date_filter_subquery(date_filter)
    where_date_condition = f"AND {date_sub}" if date_sub else ""
    fornecedor_sub = _grc_fornecedor_subquery()
    fornecedor_outer = _grc_fornecedor_outer()

    return f"""
    SELECT
        I.[DATA] AS [data],
        I.[FORNECEDOR] AS [fornecedor],
        I.[CARTEIRA] AS [carteira],
        I.[CODIGO_CARTEIRA] AS [idigs_ambiente],
        COALESCE(NULLIF(LTRIM(RTRIM(I.[CONTRATO])), ''), 'Não enviado pelo fornecedor') AS [idcob_contrato],
        CASE
            WHEN I.[CPF] IS NULL OR LTRIM(RTRIM(I.[CPF])) = '' OR I.[CPF] LIKE '%00000%'
                THEN 'Não preenchido'
            ELSE I.[CPF]
        END AS [cpf_cnpj],
        COALESCE(NULLIF(LTRIM(RTRIM(I.[TELEFONE])), ''), 'Não enviado pelo fornecedor') AS [telefone],
        I.[STATUS] AS [status],
        CASE
            WHEN I.[EVENTO] LIKE '%Sem In%' THEN 'Abertura de conversa'
            ELSE I.[EVENTO]
        END AS [evento],
        I.[LOGIN] AS [login],
        1 AS [envio],
        CASE
            WHEN I.[STATUS] LIKE '%entr%' OR I.[ENTREGUE] = 1 THEN 0
            ELSE 1
        END AS [falha],
        CASE
            WHEN I.[STATUS] LIKE '%entr%' OR I.[ENTREGUE] = 1 THEN 1
            ELSE 0
        END AS [entregue],
        CASE WHEN I.[LIDO] = 1 THEN 1 ELSE 0 END AS [lido],
        CASE WHEN I.[CPC] = 1 THEN 1 ELSE 0 END AS [cpc],
        CASE WHEN I.[CPC_PRODUTIVO] = 1 THEN 1 ELSE 0 END AS [CPCA],
        CASE WHEN I.[BOLETO] = 1 THEN 1 ELSE 0 END AS [boleto],
        I.[TIPO_ATENDIMENTO] AS [tipoAtendimento],
        COALESCE(NULLIF(LTRIM(RTRIM(I.[PROTOCOLO])), ''), 'Não enviado pelo fornecedor') AS [protocolo],
        I.[TMA] AS [tma],
        '1X1' AS [tipo_envio]
    FROM TB_WEBHOOK_GOSAC_EVENTO_INDICADOR I
    INNER JOIN (
        -- DEDUPLICAÇÃO: último [id] por (TELEFONE, FORNECEDOR) + data + fornecedora
        -- [id] em minúsculo: compatível com identificadores case-sensitive
        SELECT MAX([id]) AS max_id
        FROM TB_WEBHOOK_GOSAC_EVENTO_INDICADOR
        WHERE {fornecedor_sub}
          {where_date_condition}
        GROUP BY [TELEFONE], [FORNECEDOR]
    ) AS Ultimo
      ON I.[id] = Ultimo.max_id
    WHERE {fornecedor_outer}
    """


def _grc_log_table_counts(grc_engine: Engine) -> None:
    """Contagens rápidas + amostra de fornecedores e faixa de datas."""
    q = text("""
    SELECT
        (SELECT COUNT(*) FROM TB_WEBHOOK_GOSAC_EVENTO_INDICADOR) AS n_all,
        (SELECT COUNT(*)
         FROM TB_WEBHOOK_GOSAC_EVENTO_INDICADOR
         WHERE (
             UPPER(LTRIM(RTRIM([FORNECEDOR]))) LIKE '%OTIMA%'
             OR [FORNECEDOR] LIKE '%tima%'
         )) AS n_otima
    """)
    with grc_engine.connect() as conn:
        row = conn.execute(q).one()
    log.info(
        "[GRC] Diagnóstico: linhas na tabela (total)=%s, fornecedora alvo (OTIMA/tima)=%s",
        row[0], row[1],
    )
    if row[0] == 0:
        return
    q2 = text("""
    SELECT
        MIN(TRY_CAST([DATA] AS DATE)) AS d_min,
        MAX(TRY_CAST([DATA] AS DATE)) AS d_max,
        MIN(TRY_CAST([DATA_ENVIO] AS DATE)) AS e_min,
        MAX(TRY_CAST([DATA_ENVIO] AS DATE)) AS e_max
    FROM TB_WEBHOOK_GOSAC_EVENTO_INDICADOR
    """)
    with grc_engine.connect() as conn:
        r2 = conn.execute(q2).one()
    log.info(
        "[GRC] Datas no banco (TRY_CAST) — [DATA] min=%s max=%s | [DATA_ENVIO] min=%s max=%s",
        r2[0], r2[1], r2[2], r2[3],
    )
    q3 = text("""
    SELECT TOP 20 LTRIM(RTRIM([FORNECEDOR])) AS f, COUNT(*) AS c
    FROM TB_WEBHOOK_GOSAC_EVENTO_INDICADOR
    WHERE [FORNECEDOR] IS NOT NULL AND LTRIM(RTRIM([FORNECEDOR])) <> ''
    GROUP BY LTRIM(RTRIM([FORNECEDOR]))
    ORDER BY c DESC
    """)
    with grc_engine.connect() as conn:
        fr = conn.execute(q3).all()
    if fr:
        log.info(
            "[GRC] Fornecedores com mais linhas: %s",
            [(str(x[0])[:50], int(x[1])) for x in fr[:8]],
        )
    q4 = text("""
    SELECT TOP 20
        TRY_CAST([DATA] AS DATE) AS dia,
        COUNT(*) AS c
    FROM TB_WEBHOOK_GOSAC_EVENTO_INDICADOR
    WHERE (
        UPPER(LTRIM(RTRIM([FORNECEDOR]))) LIKE '%OTIMA%'
        OR [FORNECEDOR] LIKE '%tima%'
    )
    GROUP BY TRY_CAST([DATA] AS DATE)
    ORDER BY dia DESC
    """)
    with grc_engine.connect() as conn:
        days = conn.execute(q4).all()
    if days:
        log.info(
            "[GRC] Contagem por dia (só fornecedora alvo, coluna [DATA]): %s",
            [(str(x[0]), int(x[1])) for x in days[:12]],
        )
    q5 = text("""
    SELECT TOP 15
        TRY_CAST([DATA_ENVIO] AS DATE) AS dia,
        COUNT(*) AS c
    FROM TB_WEBHOOK_GOSAC_EVENTO_INDICADOR
    WHERE (
        UPPER(LTRIM(RTRIM([FORNECEDOR]))) LIKE '%OTIMA%'
        OR [FORNECEDOR] LIKE '%tima%'
    )
    GROUP BY TRY_CAST([DATA_ENVIO] AS DATE)
    ORDER BY dia DESC
    """)
    with grc_engine.connect() as conn:
        days_e = conn.execute(q5).all()
    if days_e:
        log.info(
            "[GRC] Contagem por dia (só fornecedora, coluna [DATA_ENVIO]): %s",
            [(str(x[0]), int(x[1])) for x in days_e[:12]],
        )


def _process_grc_otima(grc_engine: Engine, dest_engine: Engine, *,
                       target_fqn: str, staging_fqn: str, staging_table: str,
                       schema: str, chunk_size: int,
                       date_filter: str | None) -> int:
    global _GRC_RAN_AUTO_DIAG
    mode = (os.getenv("MSSQL_GRC_FILTER_DATE", "EITHER") or "EITHER").strip()
    log.info(
        "[GRC] Filtro de data na origem: MSSQL_GRC_FILTER_DATE=%s (DATA|DATA_ENVIO|EITHER)",
        mode,
    )
    sql = _build_grc_query(date_filter)
    log.info("[GRC] Executando extração em streaming (chunksize=%d)...", chunk_size)

    total_rows = 0
    chunk_count = 0

    with dest_engine.begin() as dest_conn:
        for chunk in pd.read_sql(sql, grc_engine, chunksize=chunk_size):
            chunk_count += 1
            chunk = _normalize_chunk(chunk)

            for col in _GRC_EXPECTED_COLUMNS:
                if col not in chunk.columns:
                    chunk[col] = ""
            chunk = chunk[_GRC_EXPECTED_COLUMNS]

            columns = chunk.columns.tolist()
            _validate_pk_columns(columns)
            _log_chunk_cardinality("GRC", chunk_count, chunk)

            chunk.to_sql(staging_table, dest_conn, if_exists="replace",
                         index=False, schema=schema)
            ensure_target_table(dest_conn, target_fqn, staging_fqn)
            dest_conn.execute(text(build_merge_query(target_fqn, staging_fqn, columns)))

            total_rows += len(chunk)
            log.info("[GRC] Acumulado: %d linhas", total_rows)

        dest_conn.execute(text(f"DROP TABLE IF EXISTS {staging_fqn}"))

    if total_rows == 0 and date_filter != "all":
        log.warning(
            "[GRC] 0 linhas no período. Tente DATA_ENVIO, EITHER, ou "
            "MSSQL_GRC_SKIP_DATE_FILTER=1 (debug). Ajuste o nome da fornecedora se "
            "a listagem abaixo não tiver 'Ótima' / OTIMA."
        )
        if (
            not _GRC_RAN_AUTO_DIAG
            and os.getenv("ETL_GRC_AUTO_DIAG", "1").strip() != "0"
        ):
            _GRC_RAN_AUTO_DIAG = True
            try:
                _grc_log_table_counts(grc_engine)
            except Exception as exc:
                log.exception("[GRC] Diagnóstico SQL: %s", exc)

    return total_rows


# -------------------------------------------------------------------
# Orquestração
# -------------------------------------------------------------------

def _wp_label(wf: str | None) -> str:
    if wf is None:
        return "D-0 (hoje) — parâmetro data omitido na URL"
    if wf == "all":
        return "all"
    return str(wf)


def main() -> int:
    target_table = "indicadores_digital"
    staging_table = f"stg_{target_table}"
    schema = os.getenv("MSSQL_SCHEMA", "dbo")
    target_fqn = f"{schema}.{target_table}"
    staging_fqn = f"{schema}.{staging_table}"
    chunk_size = 50_000

    try:
        date_list = resolve_date_range()
    except ValueError as exc:
        log.error(str(exc))
        return 1

    log.info(
        "Orquestração dia a dia: %d data(s) — WordPress + GRC com o mesmo filtro.",
        len(date_list),
    )
    for d in date_list:
        log.info("  - %s", _wp_label(d))
    log.info("PK do MERGE: %s", PK_COLUMNS)

    try:
        dest_engine = get_engine()
    except Exception as exc:
        log.error("Falha ao inicializar engine de destino: %s", exc)
        return 1

    try:
        grc_engine = get_grc_engine()
    except Exception as exc:
        log.error("Falha ao inicializar engine GRC: %s (GRC será pulado)", exc)
        grc_engine = None

    day_results: list[dict] = []

    try:
        for date_filter in date_list:
            label = _wp_label(date_filter)
            log.info("############################################################")
            log.info("### Data: %s", label)
            log.info("############################################################")

            row: dict = {
                "date": label,
                "wp_ok": False,
                "wp_rows": 0,
                "grc_ok": False,
                "grc_rows": 0,
            }

            log.info("------------------------------------------------------------")
            log.info("WordPress — data: %s", label)
            log.info("------------------------------------------------------------")
            try:
                row["wp_rows"] = _process_wp(
                    dest_engine,
                    target_fqn=target_fqn,
                    staging_fqn=staging_fqn,
                    staging_table=staging_table,
                    schema=schema,
                    chunk_size=chunk_size,
                    date_filter=date_filter,
                )
                row["wp_ok"] = True
                log.info(
                    "[WP][%s] Concluído: %d linhas em %s.",
                    label, row["wp_rows"], target_fqn,
                )
            except requests.exceptions.RequestException as exc:
                log.error("[WP][%s] Falha HTTP: %s", label, exc)
            except Exception as exc:
                log.exception("[WP][%s] Falha: %s", label, exc)

            if grc_engine is not None:
                log.info("------------------------------------------------------------")
                log.info("GRC (Ótima) — data: %s", label)
                log.info("------------------------------------------------------------")
                try:
                    row["grc_rows"] = _process_grc_otima(
                        grc_engine,
                        dest_engine,
                        target_fqn=target_fqn,
                        staging_fqn=staging_fqn,
                        staging_table=staging_table,
                        schema=schema,
                        chunk_size=chunk_size,
                        date_filter=date_filter,
                    )
                    row["grc_ok"] = True
                    log.info(
                        "[GRC][%s] Concluído: %d linhas.",
                        label, row["grc_rows"],
                    )
                except Exception as exc:
                    log.exception("[GRC][%s] Falha: %s", label, exc)
            else:
                log.warning("[GRC][%s] Motor GRC indisponível — pulado.", label)

            day_results.append(row)

    finally:
        if grc_engine is not None:
            grc_engine.dispose()
        dest_engine.dispose()

    tw = sum(r["wp_rows"] for r in day_results)
    tg = sum(r["grc_rows"] for r in day_results)
    log.info("============================================================")
    log.info("Resumo por dia (WP | GRC):")
    for r in day_results:
        log.info(
            "  %s | WP: %s %5d | GRC: %s %5d",
            r["date"],
            "OK   " if r["wp_ok"] else "FALHA",
            r["wp_rows"],
            "OK   " if r["grc_ok"] else "FALHA",
            r["grc_rows"],
        )
    log.info("------------------------------------------------------------")
    log.info("Totais — WP: %d linhas | GRC: %d linhas (soma da janela)", tw, tg)
    log.info("============================================================")

    if grc_engine is None:
        return 2
    any_wp_fail = any(not r["wp_ok"] for r in day_results)
    any_grc_fail = any(not r["grc_ok"] for r in day_results)
    if any_wp_fail or any_grc_fail:
        return 2
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
