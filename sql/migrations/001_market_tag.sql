-- Adds market_tag and fills it from the tags already in market.tags.
-- Safe to run more than once. Needs MySQL 8+ or MariaDB 10.2+ (recursive CTE).
USE poly;

CREATE TABLE IF NOT EXISTS market_tag
(
    condition_id VARCHAR(66)  NOT NULL,
    tag          VARCHAR(255) NOT NULL,
    PRIMARY KEY (condition_id, tag),
    KEY ix_market_tag_tag (tag, condition_id)
) ENGINE = InnoDB;

-- Split each comma-separated list into one row per tag.
INSERT IGNORE INTO market_tag (condition_id, tag)
WITH RECURSIVE split AS (
    SELECT condition_id,
           SUBSTRING_INDEX(tags, ',', 1)                                  AS tag,
           IF(LOCATE(',', tags) > 0, SUBSTRING(tags, LOCATE(',', tags) + 1), NULL) AS rest
    FROM market
    WHERE tags IS NOT NULL AND tags <> ''
    UNION ALL
    SELECT condition_id,
           SUBSTRING_INDEX(rest, ',', 1),
           IF(LOCATE(',', rest) > 0, SUBSTRING(rest, LOCATE(',', rest) + 1), NULL)
    FROM split
    WHERE rest IS NOT NULL
)
SELECT condition_id, LEFT(TRIM(tag), 255)
FROM split
WHERE TRIM(tag) <> '';
