-- Proposal 167 — PostgreSQL fixtures for gates 6 and 7 and the P2 row-filter tests.
--
-- Run as a superuser (the harness does this once per suite). Idempotent: the
-- reporting schema and both roles are dropped and recreated, so repeated runs
-- never accumulate state.
--
-- Deliberate properties:
--   * two tenants with skewed sizes (200 000 vs 400) so a missing filter is
--     obvious in a count rather than a subtle overlap;
--   * RLS enabled and FORCED on every tenant table;
--   * a relation with no tenant column (reporting.notes) for FILTER_UNMAPPABLE;
--   * a security-barrier, security-invoker view over an RLS table;
--   * a 4 MiB text value for the cell cap;
--   * a reader role with SELECT only and no BYPASSRLS (the L1 guarantee);
--   * a writer role with DML, for the approved-write path.

DROP SCHEMA IF EXISTS reporting CASCADE;
DROP ROLE IF EXISTS nvoos_reader;
DROP ROLE IF EXISTS nvoos_writer;

CREATE ROLE nvoos_reader LOGIN PASSWORD 'reader' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
CREATE ROLE nvoos_writer LOGIN PASSWORD 'writer' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;

CREATE SCHEMA reporting;

CREATE TABLE reporting.orders (
    order_id  bigserial PRIMARY KEY,
    tenant_id text        NOT NULL,
    status    text        NOT NULL,
    email     text,
    total     numeric(12, 2) NOT NULL DEFAULT 0,
    placed_at timestamptz NOT NULL DEFAULT now()
);

INSERT INTO reporting.orders (tenant_id, status, email, total, placed_at)
SELECT 'tenant-a',
       CASE WHEN g % 10 = 0 THEN 'refunded' ELSE 'paid' END,
       'a' || g || '@example.test',
       (g % 500)::numeric,
       now() - (g || ' minutes')::interval
FROM generate_series(1, 200000) AS g;

INSERT INTO reporting.orders (tenant_id, status, email, total, placed_at)
SELECT 'tenant-b',
       'paid',
       'b' || g || '@example.test',
       (g % 50)::numeric,
       now() - (g || ' minutes')::interval
FROM generate_series(1, 400) AS g;

ALTER TABLE reporting.orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE reporting.orders FORCE ROW LEVEL SECURITY;
CREATE POLICY orders_tenant ON reporting.orders
    USING (tenant_id = current_setting('app.tenant_id', true));

CREATE TABLE reporting.notes (
    note_id serial PRIMARY KEY,
    body    text NOT NULL
);

INSERT INTO reporting.notes (body)
SELECT 'note ' || g FROM generate_series(1, 10) AS g;

CREATE TABLE reporting.financial (
    id        serial PRIMARY KEY,
    tenant_id text NOT NULL,
    amount    numeric(12, 2) NOT NULL
);

INSERT INTO reporting.financial (tenant_id, amount)
SELECT 'tenant-a', 100.00 FROM generate_series(1, 3);
INSERT INTO reporting.financial (tenant_id, amount)
SELECT 'tenant-b', 50.00 FROM generate_series(1, 2);

ALTER TABLE reporting.financial ENABLE ROW LEVEL SECURITY;
ALTER TABLE reporting.financial FORCE ROW LEVEL SECURITY;
CREATE POLICY financial_tenant ON reporting.financial
    USING (tenant_id = current_setting('app.tenant_id', true));

CREATE VIEW reporting.orders_barrier
    WITH (security_barrier = true, security_invoker = true) AS
SELECT order_id, tenant_id, status
FROM reporting.orders;

CREATE TABLE reporting.blobs (
    id      serial PRIMARY KEY,
    payload text
);

INSERT INTO reporting.blobs (payload) VALUES (repeat('x', 4 * 1024 * 1024));

GRANT USAGE ON SCHEMA reporting TO nvoos_reader, nvoos_writer;
GRANT SELECT ON ALL TABLES IN SCHEMA reporting TO nvoos_reader;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA reporting TO nvoos_writer;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA reporting TO nvoos_writer;

ANALYZE reporting.orders;

-- ─────────────────────────────────────────────────────────────────────
-- App-side audit ledger (Proposal 167 phase P4).
--
-- Mirrors lib/laravel-adapter/database/migrations/2026_01_01_000011_…,
-- which is the product artefact; this copy exists so the suite can exercise
-- the real TableAuditLog writer and reader. Kept in the application schema,
-- not `reporting`, because the audit trail belongs to the app rather than to
-- the warehouse being audited.
-- ─────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS oos_database_audit (
    seq           bigserial PRIMARY KEY,
    id            varchar(32)  NOT NULL UNIQUE,
    ts            bigint       NOT NULL,
    connection    varchar(64)  NOT NULL DEFAULT '',
    scope         varchar(64)  NOT NULL DEFAULT '',
    scope_version integer      NOT NULL DEFAULT 0,
    principal     varchar(64)  NOT NULL DEFAULT '',
    action        varchar(32)  NOT NULL DEFAULT '',
    tool          varchar(64)  NOT NULL DEFAULT '',
    relation      varchar(200) NOT NULL DEFAULT '',
    refusal_code  varchar(64),
    sql_template  text,
    fingerprint   varchar(64)  NOT NULL DEFAULT '',
    row_count     integer      NOT NULL DEFAULT 0,
    elapsed_ms    integer      NOT NULL DEFAULT 0,
    truncated     varchar(32)  NOT NULL DEFAULT '',
    detail        text
);

CREATE INDEX IF NOT EXISTS oos_database_audit_ts ON oos_database_audit (ts);
CREATE INDEX IF NOT EXISTS oos_database_audit_connection_ts ON oos_database_audit (connection, ts);
CREATE INDEX IF NOT EXISTS oos_database_audit_refusal ON oos_database_audit (refusal_code);
