CREATE TABLE IF NOT EXISTS categories (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS catalogs (
    id BIGSERIAL PRIMARY KEY,
    category_id BIGINT NOT NULL REFERENCES categories(id) ON DELETE RESTRICT,
    source_url TEXT NOT NULL UNIQUE,
    source_type VARCHAR(30) NOT NULL,
    name VARCHAR(180) NOT NULL,
    description VARCHAR(300) NOT NULL DEFAULT '',
    cover_path TEXT NOT NULL DEFAULT '',
    cover_source_url TEXT NOT NULL DEFAULT '',
    page_manifest JSONB NOT NULL DEFAULT '[]'::jsonb,
    pdf_url TEXT,
    manual_priority INTEGER NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    parse_status VARCHAR(20) NOT NULL DEFAULT 'ok',
    parse_error TEXT NOT NULL DEFAULT '',
    view_count BIGINT NOT NULL DEFAULT 0,
    download_cache TEXT NOT NULL DEFAULT '',
    reader_mode VARCHAR(20) NOT NULL DEFAULT 'source',
    local_page_count INTEGER NOT NULL DEFAULT 0,
    parsed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE catalogs ADD COLUMN IF NOT EXISTS reader_mode VARCHAR(20) NOT NULL DEFAULT 'source';
ALTER TABLE catalogs ADD COLUMN IF NOT EXISTS local_page_count INTEGER NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS catalogs_public_sort ON catalogs(category_id, is_active, manual_priority DESC, view_count DESC);

CREATE TABLE IF NOT EXISTS catalog_daily_views (
    catalog_id BIGINT NOT NULL REFERENCES catalogs(id) ON DELETE CASCADE,
    viewed_on DATE NOT NULL,
    visitor_hash CHAR(64) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (catalog_id, viewed_on, visitor_hash)
);

CREATE TABLE IF NOT EXISTS login_attempts (
    identity_hash CHAR(64) PRIMARY KEY,
    attempts INTEGER NOT NULL DEFAULT 0,
    blocked_until TIMESTAMPTZ,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
