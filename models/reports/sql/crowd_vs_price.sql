-- ============================================================
-- Overall pick accuracy on resolved markets (complete counts), and the
-- counts where the most-held side was not the price favorite.
--
--   crowd_win_rate / crowd_pick_price   all disagreeing counts, priced at
--                                       the midpoint (or Gamma price on
--                                       counts from before order books)
--   book_* / crowd_pick_ask             only counts with an order book,
--                                       priced at the ask when the count
--                                       finished: the edge you could
--                                       actually have taken
-- ============================================================

SELECT COUNT(DISTINCT p.condition_id)                                                    AS markets,
       COUNT(*)                                                                          AS snapshots,
       AVG(p.head_pick = m.winning_outcome_index)                                        AS headcount_acc,
       AVG(p.share_pick = m.winning_outcome_index)                                       AS shares_acc,
       AVG(p.price_pick = m.winning_outcome_index)                                       AS price_acc,
       COUNT(DISTINCT IF(p.head_pick <> p.price_pick, p.condition_id, NULL))             AS disagree_markets,
       SUM(p.head_pick <> p.price_pick)                                                  AS disagree_snapshots,
       AVG(IF(p.head_pick <> p.price_pick, p.head_pick = m.winning_outcome_index, NULL)) AS crowd_win_rate,
       AVG(IF(p.head_pick <> p.price_pick, p.head_pick_price, NULL))                     AS crowd_pick_price,
       SUM(p.head_pick <> p.price_pick AND p.head_pick_ask IS NOT NULL)                  AS book_snapshots,
       COUNT(DISTINCT IF(p.head_pick <> p.price_pick AND p.head_pick_ask IS NOT NULL, p.condition_id, NULL))
                                                                                         AS book_markets,
       AVG(IF(p.head_pick <> p.price_pick AND p.head_pick_ask IS NOT NULL,
              p.head_pick = m.winning_outcome_index, NULL))                              AS book_crowd_win_rate,
       AVG(IF(p.head_pick <> p.price_pick, p.head_pick_ask, NULL))                       AS crowd_pick_ask
FROM market m
         JOIN snapshot_pick p ON p.condition_id = m.condition_id
WHERE m.winning_outcome_index IS NOT NULL
  AND p.is_complete = 1
