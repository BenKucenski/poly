-- ============================================================
-- Open markets by how long they have left, which sets how often the
-- collector counts them (the bands of Collector::Interval()).
-- Bands with no markets don't appear; CadenceDTO fills them in.
-- ============================================================

SELECT CASE
           WHEN end_date IS NULL                              THEN 'no end date'
           WHEN end_date < UTC_TIMESTAMP() - INTERVAL 7 DAY   THEN '7+ days past end'
           WHEN end_date <= UTC_TIMESTAMP() + INTERVAL 1 DAY  THEN 'last day / past end'
           WHEN end_date <= UTC_TIMESTAMP() + INTERVAL 2 DAY  THEN '1-2 days'
           WHEN end_date <= UTC_TIMESTAMP() + INTERVAL 7 DAY  THEN '2-7 days'
           WHEN end_date <= UTC_TIMESTAMP() + INTERVAL 30 DAY THEN '7-30 days'
           ELSE '30+ days'
       END      AS band,
       COUNT(*) AS markets
FROM market
WHERE tracking = 1
  AND closed = 0
GROUP BY band
