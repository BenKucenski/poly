-- ============================================================
-- Dashboard headline numbers: one row.
--
-- PARAMETERS: @now_utc (UTC 'Y-m-d H:i:s'), @since_24h (@now_utc minus 24 hours)
--
--   open_tracking    markets the collector is still counting
--   awaiting_result  closed, no winner yet, still being checked
--   due_now          open markets whose next count is overdue
--   snapshots        sum of market.snapshot_count (error-free counts)
--   *_24h            snapshot rows captured in the last 24 hours
--                    (uses ix_snapshot_captured)
--   first/last_snapshot_ever  range of snapshot.captured_at
--   last_discovery_at / discovery_cursor  from collector_state
-- ============================================================

SELECT m.markets,
       m.open_tracking,
       m.closed,
       m.resolved,
       m.awaiting_result,
       m.due_now,
       m.new_24h,
       m.snapshots,
       m.events,
       m.open_volume,
       s.snapshots_24h,
       s.incomplete_24h,
       s.errors_24h,
       (SELECT MIN(captured_at) FROM snapshot)                                    AS first_snapshot_ever,
       (SELECT MAX(captured_at) FROM snapshot)                                    AS last_snapshot_ever,
       (SELECT value FROM collector_state WHERE name = 'discovery_finished_at')   AS last_discovery_at,
       (SELECT value FROM collector_state WHERE name = 'discovery_cursor')        AS discovery_cursor
FROM (SELECT COUNT(*)                                                             AS markets,
             COALESCE(SUM(tracking = 1 AND closed = 0), 0)                        AS open_tracking,
             COALESCE(SUM(closed = 1), 0)                                         AS closed,
             COALESCE(SUM(winning_outcome_index IS NOT NULL), 0)                  AS resolved,
             COALESCE(SUM(closed = 1 AND winning_outcome_index IS NULL AND tracking = 1), 0) AS awaiting_result,
             COALESCE(SUM(tracking = 1 AND closed = 0 AND next_snapshot_at <= @now_utc), 0) AS due_now,
             COALESCE(SUM(first_seen_at >= @since_24h), 0)                        AS new_24h,
             COALESCE(SUM(snapshot_count), 0)                                     AS snapshots,
             COUNT(DISTINCT event_id)                                             AS events,
             COALESCE(SUM(IF(tracking = 1 AND closed = 0, volume_num, 0)), 0)     AS open_volume
      FROM market) m
         CROSS JOIN (SELECT COUNT(*)                            AS snapshots_24h,
                            COALESCE(SUM(is_complete = 0), 0)   AS incomplete_24h,
                            COALESCE(SUM(error IS NOT NULL), 0) AS errors_24h
                     FROM snapshot
                     WHERE captured_at >= @since_24h) s
