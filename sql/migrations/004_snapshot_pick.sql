-- Speeds up the dashboard build so it no longer recomputes every snapshot's
-- picks (window functions over all of snapshot_outcome, with on-disk temporary
-- tables) on every run:
--  * snapshot_pick holds each snapshot's picks, computed once by
--    tasks/dashboard.php; the first build after this backfills it in batches.
--  * an index on snapshot.captured_at for the "last 24 hours" counts.
--  * drops the v_snapshot_vs_result view: reports are now SQL files under
--    models/reports/sql read through QuickDRY's APIHelper, and the per-
--    snapshot picks the view computed on every query live in snapshot_pick.
-- The table and index are added online (the collector can keep running).
-- Safe to run more than once. Needs migration 003 first.
USE poly;

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

SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics
               WHERE table_schema = DATABASE() AND table_name = 'snapshot' AND index_name = 'ix_snapshot_captured') = 0,
              'ALTER TABLE snapshot ADD INDEX ix_snapshot_captured (captured_at), ALGORITHM = INPLACE, LOCK = NONE',
              'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DROP VIEW IF EXISTS v_snapshot_vs_result;
