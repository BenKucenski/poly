-- ============================================================
-- For every market with a snapshot in [@first_id, @last_id], its most
-- recent snapshot_pick row from before @first_id: the count whose
-- next_* fields this batch fills in.
--
-- PARAMETERS: @first_id, @last_id
-- ============================================================

SELECT p.*
FROM snapshot_pick p
         JOIN (SELECT sp.condition_id, MAX(sp.snapshot_id) AS snapshot_id
               FROM snapshot_pick sp
               WHERE sp.snapshot_id < @first_id
                 AND sp.condition_id IN (SELECT DISTINCT s.condition_id
                                         FROM snapshot s
                                         WHERE s.id BETWEEN @first_id AND @last_id)
               GROUP BY sp.condition_id) l ON l.snapshot_id = p.snapshot_id
