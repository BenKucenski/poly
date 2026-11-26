-- ============================================================
-- Every stored dashboard section (written by tasks/dashboard.php).
--   data      JSON of the section's report objects; kept from the last
--             good build when a rebuild fails
--   error     why the last rebuild failed, NULL if it worked
-- ============================================================

SELECT name, data, built_at, build_ms, error, tried_at
FROM dashboard_cache
