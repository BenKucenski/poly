-- ============================================================
-- The 12 most recently resolved markets (settled with a single winner),
-- each with its last complete, error-free count taken before the close:
-- one row per outcome of that count, NULLs if there is none.
-- LatestCountDTO groups the rows.
-- ============================================================

WITH m AS (SELECT condition_id, question, event_title, slug, event_slug, end_date, closed_time,
                  volume_num, snapshot_count, outcomes, winning_outcome_index, winning_outcome
           FROM market
           WHERE winning_outcome_index IS NOT NULL
           ORDER BY COALESCE(closed_time, end_date) DESC, condition_id
           LIMIT 12),
     latest AS (SELECT s.condition_id, MAX(s.captured_at) AS captured_at
                FROM snapshot s
                         JOIN m ON m.condition_id = s.condition_id
                WHERE s.error IS NULL
                  AND s.is_complete = 1
                  AND s.captured_at <= COALESCE(m.closed_time, m.end_date)
                GROUP BY s.condition_id),
     snap AS (SELECT s.id, s.condition_id, s.captured_at, s.completed_at, s.unique_holders, s.is_complete
              FROM snapshot s
                       JOIN latest l ON l.condition_id = s.condition_id AND l.captured_at = s.captured_at
              WHERE s.error IS NULL)
SELECT m.*,
       snap.id             AS snapshot_id,
       snap.captured_at,
       snap.completed_at,
       snap.unique_holders,
       snap.is_complete,
       so.outcome_index,
       so.outcome,
       so.price,
       so.holder_count,
       so.total_shares,
       so.book_bid,
       so.book_ask
FROM m
         LEFT JOIN snap ON snap.condition_id = m.condition_id
         LEFT JOIN snapshot_outcome so ON so.snapshot_id = snap.id
ORDER BY COALESCE(m.closed_time, m.end_date) DESC, m.condition_id, snap.id, so.outcome_index
