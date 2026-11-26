-- Adds dashboard_cache, which tasks/dashboard.php fills and the homepage reads.
-- Safe to run more than once.
USE poly;

-- Precomputed dashboard sections, written by tasks/dashboard.php and read by
-- the homepage, so a page view runs no heavy queries. One row per section.
CREATE TABLE IF NOT EXISTS dashboard_cache
(
    name       VARCHAR(64) NOT NULL,
    data       LONGTEXT    NULL COMMENT 'JSON; kept from the last good build if a rebuild fails',
    built_at   DATETIME    NULL COMMENT 'when data was computed (UTC)',
    build_ms   INT         NOT NULL DEFAULT 0,
    error      VARCHAR(1000) NULL COMMENT 'set when the last rebuild of this section failed',
    tried_at   DATETIME    NOT NULL COMMENT 'last rebuild attempt (UTC)',
    PRIMARY KEY (name)
) ENGINE = InnoDB;
