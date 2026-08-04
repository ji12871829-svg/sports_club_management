-- Migration 060: KEY partitioning for security_events
-- Date: 2026-08-04
--
-- Adds KEY (hash-based) partitioning on the primary key `id` to the
-- security_events table. This is the most practical approach for MariaDB
-- 10.4, which does not allow RANGE partitioning on TIMESTAMP columns
-- with DEFAULT CURRENT_TIMESTAMP when the PK is a separate column.
--
-- KEY partitioning distributes rows evenly across 4 partitions, making
-- index maintenance and bulk-deletion (DROP PARTITION) efficient. As the
-- table grows, the retention cron can drop/truncate old partitions by
-- checking the MIN(id) per partition against the retention window.
--
-- The migration is idempotent: it checks whether the table is already
-- partitioned before attempting the rebuild.

SET @current_schema = (SELECT DATABASE());
SET @is_partitioned = (
    SELECT COUNT(*) FROM information_schema.partitions
    WHERE table_schema = @current_schema
      AND table_name = 'security_events'
      AND partition_method IS NOT NULL
);

SET @sql = IF(@is_partitioned = 0,
    'ALTER TABLE security_events
     PARTITION BY KEY(id) PARTITIONS 4',
    'SELECT 1 AS already_partitioned'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;