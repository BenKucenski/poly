-- Records when each holder count finished and the order book at that moment,
-- so analysis can use a price you could actually have traded at once the head
-- count was known. Older snapshots keep NULLs in the new columns.
-- Safe to run more than once (MySQL 8 has no ADD COLUMN IF NOT EXISTS, so each
-- column is added only if information_schema says it's missing).
USE poly;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns
               WHERE table_schema = DATABASE() AND table_name = 'snapshot' AND column_name = 'completed_at') = 0,
              'ALTER TABLE snapshot ADD COLUMN completed_at DATETIME NULL COMMENT ''when the holder walk finished: the moment the head count is known'' AFTER captured_at',
              'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns
               WHERE table_schema = DATABASE() AND table_name = 'snapshot' AND column_name = 'book_at') = 0,
              'ALTER TABLE snapshot ADD COLUMN book_at DATETIME NULL COMMENT ''when the order books in snapshot_outcome.book_* were read (just after completed_at)'' AFTER completed_at',
              'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns
               WHERE table_schema = DATABASE() AND table_name = 'snapshot_outcome' AND column_name = 'book_bid') = 0,
              'ALTER TABLE snapshot_outcome ADD COLUMN book_bid DECIMAL(10, 6) NULL COMMENT ''best bid on the CLOB at snapshot.book_at: what you could sell at''',
              'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns
               WHERE table_schema = DATABASE() AND table_name = 'snapshot_outcome' AND column_name = 'book_ask') = 0,
              'ALTER TABLE snapshot_outcome ADD COLUMN book_ask DECIMAL(10, 6) NULL COMMENT ''best ask on the CLOB at snapshot.book_at: what you could buy at'' AFTER book_bid',
              'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns
               WHERE table_schema = DATABASE() AND table_name = 'snapshot_outcome' AND column_name = 'book_bid_size') = 0,
              'ALTER TABLE snapshot_outcome ADD COLUMN book_bid_size DECIMAL(24, 6) NULL COMMENT ''shares offered at book_bid'' AFTER book_ask',
              'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns
               WHERE table_schema = DATABASE() AND table_name = 'snapshot_outcome' AND column_name = 'book_ask_size') = 0,
              'ALTER TABLE snapshot_outcome ADD COLUMN book_ask_size DECIMAL(24, 6) NULL COMMENT ''shares offered at book_ask'' AFTER book_bid_size',
              'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- For each snapshot: which outcome had the most wallets (head count), the most
-- shares (money), and the highest price, next to how the market resolved.
-- A *_pick is NULL when two outcomes tie for first.
-- "Price" is the order-book midpoint when the count finished (book_bid/book_ask),
-- falling back to the Gamma price for snapshots taken before order books were
-- recorded. headcount_pick_ask is what buying the crowd's pick would have cost
-- at that moment (NULL without order-book data).
-- Filter on winning_outcome_index IS NOT NULL to see only resolved markets.
CREATE OR REPLACE VIEW v_snapshot_vs_result AS
SELECT s.id                                                              AS snapshot_id,
       s.condition_id,
       m.question,
       m.event_title,
       m.tags,
       m.sports_market_type,
       m.outcome_count,
       m.neg_risk,
       s.captured_at,
       s.completed_at,
       COALESCE(s.completed_at, s.captured_at)                           AS signal_at,
       COALESCE(m.closed_time, m.end_date)                               AS close_at,
       TIMESTAMPDIFF(MINUTE, COALESCE(s.completed_at, s.captured_at), COALESCE(m.closed_time, m.end_date)) / 60.0
                                                                         AS hours_before_close,
       s.is_complete,
       s.unique_holders,
       s.volume_num,
       a.has_book,
       a.all_holders,
       IF(a.head_ties = 1, a.head_pick, NULL)                            AS headcount_pick,
       a.head_pick_holders / NULLIF(a.all_holders, 0)                    AS headcount_pick_share,
       IF(a.head_ties = 1, a.head_pick_price, NULL)                      AS headcount_pick_price,
       IF(a.head_ties = 1, a.head_pick_ask, NULL)                        AS headcount_pick_ask,
       IF(a.share_ties = 1, a.share_pick, NULL)                          AS shares_pick,
       IF(a.price_ties = 1, a.price_pick, NULL)                          AS price_pick,
       a.price_pick_price,
       m.winning_outcome_index,
       m.winning_outcome,
       IF(a.head_ties = 1 AND m.winning_outcome_index IS NOT NULL,
          a.head_pick = m.winning_outcome_index, NULL)                   AS headcount_correct,
       IF(a.share_ties = 1 AND m.winning_outcome_index IS NOT NULL,
          a.share_pick = m.winning_outcome_index, NULL)                  AS shares_correct,
       IF(a.price_ties = 1 AND m.winning_outcome_index IS NOT NULL,
          a.price_pick = m.winning_outcome_index, NULL)                  AS price_correct,
       IF(a.head_ties = 1 AND a.price_ties = 1, a.head_pick <> a.price_pick, NULL)
                                                                         AS crowd_disagrees_with_price
FROM snapshot s
         JOIN market m ON m.condition_id = s.condition_id
         JOIN (SELECT r.snapshot_id,
                      MAX(r.book_ask IS NOT NULL)                                      AS has_book,
                      SUM(r.holder_count)                                              AS all_holders,
                      SUM(r.holder_count = r.max_holders)                              AS head_ties,
                      MIN(IF(r.holder_count = r.max_holders, r.outcome_index, NULL))   AS head_pick,
                      MAX(r.max_holders)                                               AS head_pick_holders,
                      MAX(IF(r.holder_count = r.max_holders, r.sig_price, NULL))       AS head_pick_price,
                      MAX(IF(r.holder_count = r.max_holders, r.book_ask, NULL))        AS head_pick_ask,
                      SUM(r.total_shares = r.max_shares)                               AS share_ties,
                      MIN(IF(r.total_shares = r.max_shares, r.outcome_index, NULL))    AS share_pick,
                      SUM(r.sig_price = r.max_price)                                   AS price_ties,
                      MIN(IF(r.sig_price = r.max_price, r.outcome_index, NULL))        AS price_pick,
                      MAX(r.max_price)                                                 AS price_pick_price
               FROM (SELECT p.*,
                            MAX(p.holder_count) OVER (PARTITION BY p.snapshot_id) AS max_holders,
                            MAX(p.total_shares) OVER (PARTITION BY p.snapshot_id) AS max_shares,
                            MAX(p.sig_price) OVER (PARTITION BY p.snapshot_id)    AS max_price
                     FROM (SELECT so.*,
                                  COALESCE((so.book_bid + so.book_ask) / 2, so.price) AS sig_price
                           FROM snapshot_outcome so) p) r
               GROUP BY r.snapshot_id) a ON a.snapshot_id = s.id
WHERE s.error IS NULL;
