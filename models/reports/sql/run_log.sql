-- ============================================================
-- The 72 most recent collector runs, newest first.
--
-- seconds: how long the run took, or has been running so far.
-- (72 is fixed here: QuickDRY quotes parameters, and LIMIT can't
-- take a quoted value.)
-- ============================================================

SELECT id,
       started_at,
       finished_at,
       status,
       discovered,
       resolved,
       snapshots,
       due_remaining,
       requests,
       http_errors,
       notes,
       TIMESTAMPDIFF(SECOND, started_at, COALESCE(finished_at, UTC_TIMESTAMP())) AS seconds
FROM run_log
ORDER BY id DESC
LIMIT 72
