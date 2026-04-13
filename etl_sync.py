#!/usr/bin/env python3
"""
ETL dual-fonte (WordPress CSV + NestJS CSV de saúde das linhas) -> SQL Server (.26).

Instalação::

    pip install requests pandas sqlalchemy pyodbc

Driver ODBC: instale o *Microsoft ODBC Driver for SQL Server* (17 ou 18) no SO.

Variáveis de ambiente::

    # WordPress (eventos)
    WP_EXPORT_BASE_URL=https://paineldecampanhas.taticamarketing.com.br/wp-json/pc/v1/export/csv
    WP_X_API_KEY=<master key>

    # NestJS (saúde das linhas; CSV com `;` e BOM UTF-8)
    NEST_LINE_HEALTH_CSV_URL=https://paineldecampanhas.taticamarketing.com.br/api/v1/health/export/csv
    # Opcional: nome da tabela física no MSSQL (padrão line_health_export)
    ETL_LINE_HEALTH_TABLE=line_health_export

    MSSQL_ODBC_DRIVER=ODBC Driver 18 for SQL Server
    MSSQL_SERVER=seu-servidor,1433
    MSSQL_DATABASE=seu_banco
    MSSQL_USER=usuario
    MSSQL_PASSWORD=senha
    MSSQL_SCHEMA=dbo
    ETL_CHUNK_ROWS=50000
    ETL_TEMP_DIR=          # opcional
    ETL_HTTP_TIMEOUT_SEC=600
"""

from __future__ import annotations

import logging
import os
import re
import sys
import tempfile
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Mapping, Optional, Sequence, Tuple

import pandas as pd
import requests
from sqlalchemy import create_engine, event, inspect, text
from sqlalchemy.engine import Engine

# -----------------------------------------------------------------------------
# Catálogo de fontes
# -----------------------------------------------------------------------------

WP_EVENT_TABLES: tuple[str, ...] = (
    "eventos_envios",
    "eventos_indicadores",
    "eventos_tempos",
)

DEFAULT_CHUNK_ROWS = 50_000
DEFAULT_LINE_HEALTH_OFFICIAL = "line_health_export"


@dataclass(frozen=True)
class EtlConfig:
    wp_export_base_url: str
    wp_x_api_key: str
    nest_line_health_csv_url: Optional[str]
    line_health_official_table: str
    mssql_odbc_driver: str
    mssql_server: str
    mssql_database: str
    mssql_user: str
    mssql_password: str
    mssql_schema: str
    chunk_rows: int
    temp_dir: Optional[Path]
    request_timeout_sec: int

    @staticmethod
    def from_env() -> "EtlConfig":
        base = os.environ.get("WP_EXPORT_BASE_URL", "").strip().rstrip("/")
        if not base:
            raise ValueError(
                "Defina WP_EXPORT_BASE_URL (ex.: .../wp-json/pc/v1/export/csv)"
            )
        key = os.environ.get("WP_X_API_KEY", "").strip()
        if not key:
            raise ValueError("Defina WP_X_API_KEY (Master Key / mesma do Nest quando aplicável).")

        nest_url = os.environ.get("NEST_LINE_HEALTH_CSV_URL", "").strip() or None
        lh_table = (
            os.environ.get("ETL_LINE_HEALTH_TABLE", DEFAULT_LINE_HEALTH_OFFICIAL)
            .strip()
            or DEFAULT_LINE_HEALTH_OFFICIAL
        )
        _sanitize_sql_identifier(lh_table)

        driver = os.environ.get(
            "MSSQL_ODBC_DRIVER", "ODBC Driver 18 for SQL Server"
        ).strip()
        server = os.environ.get("MSSQL_SERVER", "").strip()
        database = os.environ.get("MSSQL_DATABASE", "").strip()
        user = os.environ.get("MSSQL_USER", "").strip()
        password = os.environ.get("MSSQL_PASSWORD", "")
        if not all([server, database, user]):
            raise ValueError(
                "Defina MSSQL_SERVER, MSSQL_DATABASE, MSSQL_USER e MSSQL_PASSWORD."
            )

        schema = os.environ.get("MSSQL_SCHEMA", "dbo").strip() or "dbo"
        chunk = int(os.environ.get("ETL_CHUNK_ROWS", str(DEFAULT_CHUNK_ROWS)))
        if chunk < 1_000:
            chunk = 1_000

        temp_raw = os.environ.get("ETL_TEMP_DIR", "").strip()
        temp_dir = Path(temp_raw).resolve() if temp_raw else None

        timeout = int(os.environ.get("ETL_HTTP_TIMEOUT_SEC", "600"))

        return EtlConfig(
            wp_export_base_url=base,
            wp_x_api_key=key,
            nest_line_health_csv_url=nest_url,
            line_health_official_table=lh_table,
            mssql_odbc_driver=driver,
            mssql_server=server,
            mssql_database=database,
            mssql_user=user,
            mssql_password=password,
            mssql_schema=schema,
            chunk_rows=chunk,
            temp_dir=temp_dir,
            request_timeout_sec=timeout,
        )

    def build_wp_csv_url(self, logical_table: str) -> str:
        sep = "&" if "?" in self.wp_export_base_url else "?"
        return f"{self.wp_export_base_url}{sep}table={logical_table}"

    def sqlalchemy_url(self) -> str:
        from urllib.parse import quote_plus

        driver_enc = quote_plus(self.mssql_odbc_driver)
        u = quote_plus(self.mssql_user)
        p = quote_plus(self.mssql_password)
        return (
            f"mssql+pyodbc://{u}:{p}@{self.mssql_server}/{self.mssql_database}"
            f"?driver={driver_enc}&TrustServerCertificate=yes"
        )


# -----------------------------------------------------------------------------
# SQL / CSV helpers
# -----------------------------------------------------------------------------

_IDENT_RE = re.compile(r"^[A-Za-z_][A-Za-z0-9_]*$")


def _sanitize_sql_identifier(name: str) -> str:
    n = str(name).strip()
    if not _IDENT_RE.match(n):
        raise ValueError(f"Identificador SQL inválido: {name!r}")
    return n


def _quote_bracket(ident: str) -> str:
    s = _sanitize_sql_identifier(ident)
    return "[" + s.replace("]", "]]") + "]"


def _qualified_table(schema: str, table: str) -> str:
    return f"{_quote_bracket(schema)}.{_quote_bracket(table)}"


def _staging_name(official_table: str) -> str:
    return f"stg_{official_table}"


def _normalize_dataframe_columns(df: pd.DataFrame) -> pd.DataFrame:
    out = df.copy()
    new_cols: list[str] = []
    for i, c in enumerate(out.columns):
        raw = str(c).strip().lstrip("\ufeff")
        safe = re.sub(r"[^0-9a-zA-Z_]", "_", raw)
        if not safe or safe[0].isdigit():
            safe = f"col_{i}_{safe}".strip("_")
        if not _IDENT_RE.match(safe):
            safe = f"col_{i}"
        new_cols.append(safe)
    out.columns = new_cols
    return out


def _resolve_pk_column(columns: Sequence[str], pk_hint: Optional[str]) -> str:
    if pk_hint and pk_hint in columns:
        return pk_hint
    if "line_key" in columns:
        return "line_key"
    if "id" in columns:
        return "id"
    if not columns:
        raise ValueError("DataFrame sem colunas.")
    return columns[0]


def _download_csv_streaming(
    url: str,
    api_key: str,
    dest_path: Path,
    timeout_sec: int,
) -> None:
    headers = {"X-API-KEY": api_key, "Accept": "text/csv,*/*"}
    with requests.get(url, headers=headers, stream=True, timeout=timeout_sec) as resp:
        resp.raise_for_status()
        dest_path.parent.mkdir(parents=True, exist_ok=True)
        with dest_path.open("wb") as fh:
            for chunk in resp.iter_content(chunk_size=1024 * 256):
                if chunk:
                    fh.write(chunk)


def _attach_pyodbc_fast_executemany(engine: Engine) -> None:
    @event.listens_for(engine, "connect")
    def _on_connect(dbapi_connection: Any, _record: Any) -> None:
        if hasattr(dbapi_connection, "fast_executemany"):
            dbapi_connection.fast_executemany = True


def _create_official_from_staging_empty(
    conn: Any,
    schema: str,
    target_table: str,
    staging_table: str,
) -> None:
    tgt = _qualified_table(schema, target_table)
    stg = _qualified_table(schema, staging_table)
    conn.execute(text(f"SELECT * INTO {tgt} FROM {stg} WHERE 1 = 0"))


def _create_official_from_df_head0_fail(
    conn: Any,
    schema: str,
    official: str,
    sample_cols_df: pd.DataFrame,
) -> None:
    """Cria tabela vazia com tipos inferidos pelo pandas (if_exists='fail')."""
    empty = sample_cols_df.head(0)
    empty.to_sql(
        official,
        conn,
        schema=schema,
        if_exists="fail",
        index=False,
    )


def _build_merge_sql(
    schema: str,
    target_table: str,
    staging_table: str,
    columns: Sequence[str],
    pk: str,
) -> str:
    tgt = _qualified_table(schema, target_table)
    stg = _qualified_table(schema, staging_table)
    pk_b = _quote_bracket(pk)
    non_pk = [c for c in columns if c != pk]
    insert_cols = ", ".join(_quote_bracket(c) for c in columns)
    insert_vals = ", ".join(f"S.{_quote_bracket(c)}" for c in columns)
    update_parts = [
        f"T.{_quote_bracket(c)} = S.{_quote_bracket(c)}" for c in non_pk
    ]
    update_clause = ", ".join(update_parts) if update_parts else f"T.{pk_b} = S.{pk_b}"

    return f"""
DECLARE @merge_actions TABLE (act NVARCHAR(10) NOT NULL);

MERGE {tgt} AS T
USING {stg} AS S
ON (T.{pk_b} = S.{pk_b})
WHEN MATCHED THEN
    UPDATE SET {update_clause}
WHEN NOT MATCHED BY TARGET THEN
    INSERT ({insert_cols})
    VALUES ({insert_vals})
OUTPUT $action INTO @merge_actions;

SELECT act, COUNT(*) AS cnt FROM @merge_actions GROUP BY act;
"""


def _drop_table_if_exists(conn: Any, schema: str, table: str) -> None:
    fq = _qualified_table(schema, table)
    conn.execute(text(f"DROP TABLE IF EXISTS {fq}"))


def _process_one_csv_job(
    *,
    label: str,
    url: str,
    official_table: str,
    api_key: str,
    cfg: EtlConfig,
    engine: Engine,
    csv_sep: str,
    csv_encoding: str,
    pk_hint: Optional[str],
) -> Tuple[bool, Optional[Mapping[str, int]]]:
    """
    Download -> leitura CSV em chunks -> stg_{official} -> MERGE -> DROP stg.
    """
    _sanitize_sql_identifier(official_table)
    staging = _staging_name(official_table)

    print(f"Baixando {label}...", flush=True)
    logging.info("[Baixando] %s <- %s", label, url)

    suffix = ".csv"
    csv_path: Optional[Path] = None
    stats: dict[str, int] = {}

    try:
        if cfg.temp_dir is not None:
            cfg.temp_dir.mkdir(parents=True, exist_ok=True)
            csv_path = cfg.temp_dir / f"etl_{official_table}_{os.getpid()}{suffix}"
            _download_csv_streaming(url, api_key, csv_path, cfg.request_timeout_sec)
        else:
            fd, tmp_name = tempfile.mkstemp(
                suffix=suffix, prefix=f"etl_{official_table}_"
            )
            os.close(fd)
            csv_path = Path(tmp_name)
            _download_csv_streaming(url, api_key, csv_path, cfg.request_timeout_sec)

        assert csv_path is not None

        print(f"Lendo CSV ({label})...", flush=True)
        logging.info("[Lendo CSV] %s", label)

        reader = pd.read_csv(
            csv_path,
            chunksize=cfg.chunk_rows,
            low_memory=False,
            sep=csv_sep,
            encoding=csv_encoding,
        )

        print(f"Sincronizando Banco ({label})...", flush=True)
        logging.info("[Sincronizando Banco] %s -> [%s].[%s]", label, cfg.mssql_schema, official_table)

        total_rows = 0
        first_schema_chunk: Optional[pd.DataFrame] = None

        with engine.begin() as conn:
            _drop_table_if_exists(conn, cfg.mssql_schema, staging)

            first_write = True
            for chunk in reader:
                if chunk.empty:
                    continue
                chunk = _normalize_dataframe_columns(chunk)
                if first_schema_chunk is None:
                    first_schema_chunk = chunk.copy()
                total_rows += len(chunk)
                if first_write:
                    chunk.to_sql(
                        staging,
                        conn,
                        schema=cfg.mssql_schema,
                        if_exists="replace",
                        index=False,
                        method="multi",
                        chunksize=1000,
                    )
                    first_write = False
                else:
                    chunk.to_sql(
                        staging,
                        conn,
                        schema=cfg.mssql_schema,
                        if_exists="append",
                        index=False,
                        method="multi",
                        chunksize=1000,
                    )

            if total_rows == 0:
                logging.warning("[WARN] %s: CSV vazio; staging removida.", label)
                _drop_table_if_exists(conn, cfg.mssql_schema, staging)
                return True, None

            insp = inspect(engine)
            if not insp.has_table(official_table, schema=cfg.mssql_schema):
                logging.info(
                    "[INFO] Criando tabela oficial [%s].[%s] (layout alinhado ao CSV).",
                    cfg.mssql_schema,
                    official_table,
                )
                try:
                    _create_official_from_staging_empty(
                        conn, cfg.mssql_schema, official_table, staging
                    )
                except Exception:
                    if first_schema_chunk is None:
                        raise
                    logging.warning(
                        "[WARN] SELECT INTO falhou; tentando pandas to_sql(if_exists='fail') com 0 linhas."
                    )
                    _create_official_from_df_head0_fail(
                        conn,
                        cfg.mssql_schema,
                        official_table,
                        first_schema_chunk,
                    )

            cols_rows = conn.execute(
                text(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS "
                    "WHERE TABLE_SCHEMA = :sch AND TABLE_NAME = :tbl ORDER BY ORDINAL_POSITION"
                ),
                {"sch": cfg.mssql_schema, "tbl": staging},
            ).fetchall()
            columns = [str(r[0]) for r in cols_rows]
            pk = _resolve_pk_column(columns, pk_hint)

            merge_sql = _build_merge_sql(
                cfg.mssql_schema, official_table, staging, columns, pk
            )
            result = conn.execute(text(merge_sql))
            stats_rows = result.fetchall()
            stats.clear()
            for row in stats_rows:
                if row and len(row) >= 2:
                    act = str(row[0]).strip().upper()
                    stats[act] = int(row[1])

            _drop_table_if_exists(conn, cfg.mssql_schema, staging)

        inserts = stats.get("INSERT", 0)
        updates = stats.get("UPDATE", 0)
        logging.info(
            "[SUCCESS] %s: %s linhas. MERGE INSERT=%s UPDATE=%s",
            label,
            f"{total_rows:,}".replace(",", "."),
            inserts,
            updates,
        )
        return True, stats

    except requests.HTTPError as e:
        logging.error("[ERROR] HTTP %s: %s — próxima fonte.", label, e)
        return False, None
    except Exception as e:
        logging.exception("[ERROR] %s: %s — próxima fonte.", label, e)
        return False, None
    finally:
        try:
            if csv_path is not None:
                csv_path.unlink(missing_ok=True)
        except OSError:
            pass


def main(_argv: Optional[Sequence[str]] = None) -> int:
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
        stream=sys.stdout,
    )

    try:
        cfg = EtlConfig.from_env()
    except ValueError as e:
        logging.error("[FATAL] Configuração: %s", e)
        return 2

    engine = create_engine(
        cfg.sqlalchemy_url(),
        pool_pre_ping=True,
        future=True,
    )
    _attach_pyodbc_fast_executemany(engine)

    any_fail = False

    for tbl in WP_EVENT_TABLES:
        url = cfg.build_wp_csv_url(tbl)
        ok, _ = _process_one_csv_job(
            label=f"WordPress/{tbl}",
            url=url,
            official_table=tbl,
            api_key=cfg.wp_x_api_key,
            cfg=cfg,
            engine=engine,
            csv_sep=",",
            csv_encoding="utf-8",
            pk_hint="id",
        )
        if not ok:
            any_fail = True

    if cfg.nest_line_health_csv_url:
        ok, _ = _process_one_csv_job(
            label="NestJS/line_health",
            url=cfg.nest_line_health_csv_url,
            official_table=cfg.line_health_official_table,
            api_key=cfg.wp_x_api_key,
            cfg=cfg,
            engine=engine,
            csv_sep=";",
            csv_encoding="utf-8-sig",
            pk_hint="line_key",
        )
        if not ok:
            any_fail = True
    else:
        logging.info(
            "[SKIP] NEST_LINE_HEALTH_CSV_URL não definido; pulando export Nest."
        )

    if any_fail:
        logging.warning("[DONE] ETL finalizado com falhas parciais.")
        return 1
    logging.info("[DONE] ETL concluído.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
