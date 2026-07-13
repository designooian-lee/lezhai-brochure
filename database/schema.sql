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

CREATE TABLE IF NOT EXISTS catalog_jobs (
    id BIGSERIAL PRIMARY KEY,
    catalog_id BIGINT REFERENCES catalogs(id) ON DELETE CASCADE,
    job_type VARCHAR(20) NOT NULL DEFAULT 'local_pages',
    source_url TEXT NOT NULL DEFAULT '',
    result_payload JSONB,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    progress_current INTEGER NOT NULL DEFAULT 0,
    progress_total INTEGER NOT NULL DEFAULT 0,
    phase VARCHAR(30) NOT NULL DEFAULT 'queued',
    error TEXT NOT NULL DEFAULT '',
    artifact_path TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    started_at TIMESTAMPTZ,
    finished_at TIMESTAMPTZ
);
ALTER TABLE catalog_jobs ALTER COLUMN catalog_id DROP NOT NULL;
ALTER TABLE catalog_jobs ADD COLUMN IF NOT EXISTS source_url TEXT NOT NULL DEFAULT '';
ALTER TABLE catalog_jobs ADD COLUMN IF NOT EXISTS result_payload JSONB;
CREATE UNIQUE INDEX IF NOT EXISTS catalog_jobs_one_active ON catalog_jobs(catalog_id) WHERE status IN ('pending','running');

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

CREATE TABLE IF NOT EXISTS tutorials (
    id BIGSERIAL PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    description VARCHAR(300) NOT NULL DEFAULT '',
    body TEXT NOT NULL DEFAULT '',
    cover_path TEXT NOT NULL DEFAULT '',
    manual_priority INTEGER NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS tutorial_media (
    id BIGSERIAL PRIMARY KEY,
    tutorial_id BIGINT NOT NULL REFERENCES tutorials(id) ON DELETE CASCADE,
    media_type VARCHAR(20) NOT NULL CHECK (media_type IN ('video','document')),
    source_type VARCHAR(20) NOT NULL CHECK (source_type IN ('external','upload')),
    title VARCHAR(180) NOT NULL DEFAULT '',
    url TEXT NOT NULL DEFAULT '',
    file_path TEXT NOT NULL DEFAULT '',
    mime_type VARCHAR(100) NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS tutorials_public_sort ON tutorials(is_active, manual_priority DESC, id DESC);
CREATE INDEX IF NOT EXISTS tutorial_media_order ON tutorial_media(tutorial_id, sort_order DESC, id);

CREATE TABLE IF NOT EXISTS articles (
    id BIGSERIAL PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    slug VARCHAR(180) NOT NULL UNIQUE,
    excerpt VARCHAR(500) NOT NULL DEFAULT '',
    body_html TEXT NOT NULL DEFAULT '',
    cover_path TEXT NOT NULL DEFAULT '',
    seo_title VARCHAR(180) NOT NULL DEFAULT '',
    seo_keywords VARCHAR(300) NOT NULL DEFAULT '',
    meta_description VARCHAR(300) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','published')),
    published_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS articles_public_sort ON articles(status, published_at DESC, id DESC);
ALTER TABLE articles ADD COLUMN IF NOT EXISTS seo_keywords VARCHAR(300) NOT NULL DEFAULT '';

CREATE TABLE IF NOT EXISTS article_monthly_views (
    article_id BIGINT NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
    viewed_month DATE NOT NULL,
    view_count BIGINT NOT NULL DEFAULT 0 CHECK (view_count >= 0),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (article_id, viewed_month)
);

CREATE INDEX IF NOT EXISTS article_monthly_views_hot ON article_monthly_views(viewed_month, view_count DESC, article_id);
