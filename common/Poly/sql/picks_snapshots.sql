-- ============================================================
-- The next batch of snapshots for Picks::Update(), oldest first.
--
-- PARAMETERS: @after_id (highest snapshot id already handled)
--
-- 5000 = Picks::BATCH. It's fixed here because QuickDRY quotes
-- parameters, and LIMIT can't take a quoted value.
-- ============================================================

SELECT id, condition_id, captured_at, completed_at, is_complete, error
FROM snapshot
WHERE id > @after_id
ORDER BY id
LIMIT 5000
