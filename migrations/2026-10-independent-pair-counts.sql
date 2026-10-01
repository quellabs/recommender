-- Back up both recommender tables before running this migration.
-- Pause rating writes until the schema change and full rebuild finish.
-- The old cnt mixes two measures and cannot be reused.
DELETE FROM vogoo_links;
ALTER TABLE vogoo_links
    DROP COLUMN cnt,
    ADD COLUMN liked_count INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN slope_count INT UNSIGNED NOT NULL DEFAULT 0,
    ADD INDEX idx_category (category, item_id1, item_id2);
-- Run: sculpt recommender:rebuild-links
