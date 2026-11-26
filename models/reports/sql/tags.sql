-- ============================================================
-- The 20 tags with the most markets. A market with several tags counts
-- under each. headcount_acc / price_acc: how often that pick named the
-- winner, over resolved markets' complete counts (NULL until a market
-- with the tag resolves).
-- ============================================================

SELECT t.tag,
       COUNT(*)                                 AS markets,
       SUM(m.tracking = 1 AND m.closed = 0)     AS open_markets,
       SUM(m.winning_outcome_index IS NOT NULL) AS resolved,
       SUM(m.snapshot_count)                    AS snapshots,
       SUM(m.volume_num)                        AS volume,
       a.headcount_acc,
       a.price_acc
FROM market_tag t
         JOIN market m ON m.condition_id = t.condition_id
         LEFT JOIN (SELECT mt.tag,
                           AVG(p.head_pick = rm.winning_outcome_index)  AS headcount_acc,
                           AVG(p.price_pick = rm.winning_outcome_index) AS price_acc
                    FROM market rm
                             JOIN snapshot_pick p ON p.condition_id = rm.condition_id
                             JOIN market_tag mt ON mt.condition_id = rm.condition_id
                    WHERE rm.winning_outcome_index IS NOT NULL
                      AND p.is_complete = 1
                    GROUP BY mt.tag) a ON a.tag = t.tag
GROUP BY t.tag, a.headcount_acc, a.price_acc
ORDER BY markets DESC, t.tag
LIMIT 20
