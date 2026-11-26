-- ============================================================
-- Outcome rows for a range of snapshots (one Picks::Update() batch).
--
-- PARAMETERS: @first_id, @last_id (inclusive)
-- ============================================================

SELECT snapshot_id, outcome_index, price, holder_count, total_shares, book_bid, book_ask
FROM snapshot_outcome
WHERE snapshot_id BETWEEN @first_id AND @last_id
ORDER BY snapshot_id, outcome_index
