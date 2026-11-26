-- Polymarket head-count collector schema (MySQL 8 / MariaDB 10.6+).
-- All DATETIME columns are UTC.

CREATE DATABASE IF NOT EXISTS poly CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE poly;

-- One row per market (Polymarket "condition"). Updated by discovery, by each
-- snapshot, and finally by the resolution check.
CREATE TABLE IF NOT EXISTS market
(
    condition_id          VARCHAR(66)   NOT NULL,
    gamma_id              VARCHAR(32)   NULL,
    question              VARCHAR(1000) NULL,
    slug                  VARCHAR(255)  NULL,
    event_id              VARCHAR(32)   NULL,
    event_slug            VARCHAR(255)  NULL,
    event_title           VARCHAR(1000) NULL,
    category              VARCHAR(255)  NULL,
    tags                  VARCHAR(2000) NULL COMMENT 'comma-separated tag labels',
    sports_market_type    VARCHAR(64)   NULL,
    neg_risk              TINYINT(1)    NOT NULL DEFAULT 0,
    outcome_count         TINYINT       NOT NULL DEFAULT 0,
    outcomes              TEXT          NULL COMMENT 'JSON array of outcome labels, in outcome_index order',
    token_ids             TEXT          NULL COMMENT 'JSON array of CLOB token ids, in outcome_index order',
    start_date            DATETIME      NULL,
    end_date              DATETIME      NULL,
    volume_num            DECIMAL(20, 2) NULL,
    liquidity_num         DECIMAL(20, 2) NULL,

    closed                TINYINT(1)    NOT NULL DEFAULT 0,
    closed_time           DATETIME      NULL,
    uma_resolution_status VARCHAR(64)   NULL,
    final_prices          VARCHAR(255)  NULL COMMENT 'JSON array of settlement prices',
    winning_outcome_index TINYINT       NULL COMMENT 'NULL until resolved, or if it settled without a single winner',
    winning_outcome       VARCHAR(255)  NULL,

    first_seen_at         DATETIME      NOT NULL,
    last_seen_at          DATETIME      NOT NULL,
    last_snapshot_at      DATETIME      NULL,
    next_snapshot_at      DATETIME      NULL,
    snapshot_count        INT           NOT NULL DEFAULT 0,
    resolution_checked_at DATETIME      NULL,
    tracking              TINYINT(1)    NOT NULL DEFAULT 1 COMMENT '0 = stopped (resolved, or gave up waiting)',

    PRIMARY KEY (condition_id),
    KEY ix_market_due (tracking, closed, next_snapshot_at),
    KEY ix_market_end (end_date),
    KEY ix_market_event (event_id),
    KEY ix_market_slug (slug)
) ENGINE = InnoDB;

-- One row per market per tag, so you can filter and group by tag without
-- parsing market.tags (which keeps the raw comma-separated list).
CREATE TABLE IF NOT EXISTS market_tag
(
    condition_id VARCHAR(66)  NOT NULL,
    tag          VARCHAR(255) NOT NULL,
    PRIMARY KEY (condition_id, tag),
    KEY ix_market_tag_tag (tag, condition_id)
) ENGINE = InnoDB;

-- One row each time we count a market's holders.
CREATE TABLE IF NOT EXISTS snapshot
(
    id                 BIGINT        NOT NULL AUTO_INCREMENT,
    run_id             INT           NULL,
    condition_id       VARCHAR(66)   NOT NULL,
    captured_at        DATETIME      NOT NULL COMMENT 'when the holder walk started',
    completed_at       DATETIME      NULL COMMENT 'when the holder walk finished: the moment the head count is known',
    book_at            DATETIME      NULL COMMENT 'when the order books in snapshot_outcome.book_* were read (just after completed_at)',
    seconds_to_end     INT           NULL COMMENT 'market end_date minus captured_at; negative after the scheduled end',
    is_complete        TINYINT(1)    NOT NULL COMMENT '1 = every holder page was read',
    pages              INT           NOT NULL DEFAULT 0,
    unique_holders     INT           NOT NULL DEFAULT 0 COMMENT 'distinct wallets across all outcomes',
    multi_side_holders INT           NOT NULL DEFAULT 0 COMMENT 'wallets holding more than one outcome',
    volume_num         DECIMAL(20, 2) NULL,
    liquidity_num      DECIMAL(20, 2) NULL,
    best_bid           DECIMAL(10, 6) NULL COMMENT 'first outcome',
    best_ask           DECIMAL(10, 6) NULL COMMENT 'first outcome',
    last_trade_price   DECIMAL(10, 6) NULL COMMENT 'first outcome',
    spread             DECIMAL(10, 6) NULL,
    error              VARCHAR(1000) NULL,
    PRIMARY KEY (id),
    KEY ix_snapshot_market (condition_id, captured_at),
    KEY ix_snapshot_captured (captured_at),
    KEY ix_snapshot_run (run_id)
) ENGINE = InnoDB;

-- Per-outcome counts for a snapshot. "holder" = a wallet with a non-zero balance.
CREATE TABLE IF NOT EXISTS snapshot_outcome
(
    snapshot_id       BIGINT         NOT NULL,
    outcome_index     TINYINT        NOT NULL,
    outcome           VARCHAR(255)   NULL,
    token_id          VARCHAR(100)   NULL,
    price             DECIMAL(10, 6) NULL COMMENT 'market price of this outcome when captured',
    holder_count      INT            NOT NULL DEFAULT 0,
    total_shares      DECIMAL(24, 6) NOT NULL DEFAULT 0,
    holders_ge_10     INT            NOT NULL DEFAULT 0 COMMENT 'wallets holding at least 10 shares',
    holders_ge_100    INT            NOT NULL DEFAULT 0,
    holders_ge_1000   INT            NOT NULL DEFAULT 0,
    holders_ge_10000  INT            NOT NULL DEFAULT 0,
    top10_shares      DECIMAL(24, 6) NOT NULL DEFAULT 0 COMMENT 'shares held by the 10 largest wallets',
    max_holder_shares DECIMAL(24, 6) NOT NULL DEFAULT 0,
    book_bid          DECIMAL(10, 6) NULL COMMENT 'best bid on the CLOB at snapshot.book_at: what you could sell at',
    book_ask          DECIMAL(10, 6) NULL COMMENT 'best ask on the CLOB at snapshot.book_at: what you could buy at',
    book_bid_size     DECIMAL(24, 6) NULL COMMENT 'shares offered at book_bid',
    book_ask_size     DECIMAL(24, 6) NULL COMMENT 'shares offered at book_ask',
    PRIMARY KEY (snapshot_id, outcome_index)
) ENGINE = InnoDB;

-- Optional per-wallet rows (see POLY_STORE_HOLDER_ROWS in env.php).
CREATE TABLE IF NOT EXISTS snapshot_holder
(
    snapshot_id   BIGINT         NOT NULL,
    outcome_index TINYINT        NOT NULL,
    proxy_wallet  VARCHAR(42)    NOT NULL,
    shares        DECIMAL(24, 6) NOT NULL,
    PRIMARY KEY (snapshot_id, outcome_index, proxy_wallet),
    KEY ix_holder_wallet (proxy_wallet)
) ENGINE = InnoDB;

-- One row per scheduled run, so you can see what each hour did.
CREATE TABLE IF NOT EXISTS run_log
(
    id               INT           NOT NULL AUTO_INCREMENT,
    started_at       DATETIME      NOT NULL,
    finished_at      DATETIME      NULL,
    status           VARCHAR(32)   NOT NULL DEFAULT 'running',
    discovered       INT           NOT NULL DEFAULT 0 COMMENT 'markets inserted or updated by discovery',
    resolved         INT           NOT NULL DEFAULT 0,
    snapshots        INT           NOT NULL DEFAULT 0,
    due_remaining    INT           NULL COMMENT 'markets still due when the run stopped',
    requests         INT           NOT NULL DEFAULT 0,
    http_errors      INT           NOT NULL DEFAULT 0,
    notes            TEXT          NULL,
    PRIMARY KEY (id)
) ENGINE = InnoDB;

-- Small key/value table for collector state (e.g. last discovery time).
CREATE TABLE IF NOT EXISTS collector_state
(
    name       VARCHAR(64)   NOT NULL,
    value      VARCHAR(1000) NULL,
    updated_at DATETIME      NOT NULL,
    PRIMARY KEY (name)
) ENGINE = InnoDB;

-- One row per error-free snapshot with the picks worked out once: which
-- outcome had the most wallets, the most shares and the highest price (order-
-- book midpoint when recorded), what buying the head-count pick cost, and the
-- bid for that same outcome at the market's next count. Written incrementally
-- by tasks/dashboard.php (it only reads the collector's tables, never locks
-- them); the dashboard's aggregates read this instead of recomputing picks
-- over all of snapshot_outcome. Safe to TRUNCATE: the next build refills it.
CREATE TABLE IF NOT EXISTS snapshot_pick
(
    snapshot_id       BIGINT         NOT NULL,
    condition_id      VARCHAR(66)    NOT NULL,
    signal_at         DATETIME       NOT NULL COMMENT 'completed_at, or captured_at on snapshots from before it existed',
    is_complete       TINYINT(1)     NOT NULL,
    has_book          TINYINT(1)     NOT NULL DEFAULT 0,
    all_holders       INT            NOT NULL DEFAULT 0,
    head_pick         TINYINT        NULL COMMENT 'NULL on a tie',
    head_pick_holders INT            NULL,
    head_pick_price   DECIMAL(10, 6) NULL COMMENT 'midpoint (or Gamma price) of the head-count pick at signal_at',
    head_pick_ask     DECIMAL(10, 6) NULL COMMENT 'what buying the head-count pick cost at signal_at',
    share_pick        TINYINT        NULL,
    price_pick        TINYINT        NULL,
    price_pick_price  DECIMAL(10, 6) NULL,
    next_snapshot_id  BIGINT         NULL COMMENT 'the market''s next error-free snapshot',
    next_signal_at    DATETIME       NULL,
    next_head_bid     DECIMAL(10, 6) NULL COMMENT 'bid for head_pick at the next snapshot',
    PRIMARY KEY (snapshot_id),
    KEY ix_pick_market (condition_id, snapshot_id)
) ENGINE = InnoDB;

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
