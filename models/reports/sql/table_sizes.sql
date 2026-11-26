-- ============================================================
-- This database's tables, biggest first. approx_rows is InnoDB's
-- estimate (an exact COUNT(*) on the big tables is too slow here).
-- ============================================================

SELECT table_name                 AS name,
       table_type                 AS type,
       table_rows                 AS approx_rows,
       data_length + index_length AS bytes,
       update_time
FROM information_schema.tables
WHERE table_schema = DATABASE()
ORDER BY table_type, (data_length + index_length) DESC
