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
