-- ============================================================
-- What buying the crowd's pick at the ask, the moment each count
-- finished, would have returned. Per share, before fees.
--
--   next_*  sold at the bid for the same outcome at the market's next
--           count (open markets included)
--   res_*   held to resolution: a winning share pays 1, a losing one 0
--
-- One row with disagree = 1 (crowd's pick isn't the price favorite), one
-- with 0, and a total row with disagree = NULL (WITH ROLLUP). Complete
-- counts with an order book and a clear head-count and price pick only.
-- ============================================================

SELECT IF(p.head_pick <> p.price_pick, 1, 0)                                         AS disagree,
       COUNT(*)                                                                      AS entries,
       COUNT(DISTINCT p.condition_id)                                                AS markets,
       AVG(p.head_pick_ask)                                                          AS entry_ask,
       COUNT(p.next_head_bid)                                                        AS next_n,
       AVG(IF(p.next_head_bid IS NULL, NULL, TIMESTAMPDIFF(MINUTE, p.signal_at, p.next_signal_at) / 60.0))
                                                                                     AS next_hours,
       AVG(p.next_head_bid - p.head_pick_ask)                                        AS next_return,
       AVG(p.next_head_bid / p.head_pick_ask - 1)                                    AS next_roi,
       AVG(IF(p.next_head_bid IS NULL, NULL, p.next_head_bid > p.head_pick_ask))     AS next_up_share,
       COUNT(m.winning_outcome_index)                                                AS res_n,
       AVG(IF(m.winning_outcome_index IS NULL, NULL, (p.head_pick = m.winning_outcome_index) - p.head_pick_ask))
                                                                                     AS res_return,
       AVG(IF(m.winning_outcome_index IS NULL, NULL, (p.head_pick = m.winning_outcome_index) / p.head_pick_ask - 1))
                                                                                     AS res_roi
FROM snapshot_pick p
         JOIN market m ON m.condition_id = p.condition_id
WHERE p.is_complete = 1
  AND p.head_pick IS NOT NULL
  AND p.price_pick IS NOT NULL
  AND p.head_pick_ask > 0
GROUP BY disagree
WITH ROLLUP
