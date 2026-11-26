-- ============================================================
-- How often each kind of majority picked the winner, by whole days
-- between the count (snapshot_pick.signal_at, when the count finished)
-- and the close. Resolved binary markets, complete counts only.
--
-- PARAMETERS: @max_days_out (days past this fold into the last bucket)
--
-- Rates are averaged over snapshots; a tie for first is no pick and
-- doesn't count. markets = distinct markets in the bucket.
-- ============================================================

SELECT LEAST(GREATEST(FLOOR(TIMESTAMPDIFF(MINUTE, p.signal_at, COALESCE(m.closed_time, m.end_date)) / 60.0 / 24), 0),
             CAST(@max_days_out AS SIGNED))         AS days_out,
       COUNT(DISTINCT p.condition_id)               AS markets,
       COUNT(*)                                     AS snapshots,
       AVG(p.head_pick = m.winning_outcome_index)   AS headcount_acc,
       AVG(p.share_pick = m.winning_outcome_index)  AS shares_acc,
       AVG(p.price_pick = m.winning_outcome_index)  AS price_acc
FROM market m
         JOIN snapshot_pick p ON p.condition_id = m.condition_id
WHERE m.winning_outcome_index IS NOT NULL
  AND m.outcome_count = 2
  AND p.is_complete = 1
GROUP BY days_out
ORDER BY days_out
