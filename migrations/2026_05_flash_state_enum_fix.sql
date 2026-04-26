-- Fix sf_flashes.state ENUM to include all states defined in app/includes/statuses.php.
-- Previously missing values: awaiting_publish, to_final_approver, in_investigation,
-- closed, rejected, archived.  When MySQL is not in strict mode, an unknown ENUM value
-- is silently stored as '' which corrupts state data.
--
-- Idempotent: the ALTER is guarded by an INFORMATION_SCHEMA check so re-running this
-- migration on an already-patched schema is safe.

SET @col_type := (
    SELECT COLUMN_TYPE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'sf_flashes'
      AND COLUMN_NAME  = 'state'
);

-- Only ALTER if awaiting_publish is not yet in the ENUM.
SET @needs_alter := IF(@col_type LIKE '%awaiting_publish%', 0, 1);

SET @alter_sql := IF(
    @needs_alter = 1,
    "ALTER TABLE sf_flashes
       MODIFY COLUMN state ENUM(
         'draft',
         'pending_supervisor',
         'pending_review',
         'request_info',
         'in_investigation',
         'to_final_approver',
         'to_comms',
         'awaiting_publish',
         'published',
         'rejected',
         'archived',
         'closed'
       ) NOT NULL DEFAULT 'draft'",
    'SELECT ''sf_flashes.state ENUM already up-to-date, skipping ALTER'' AS info'
);

PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Fix rows that have state='' caused by the previous silent ENUM truncation.
-- Rows that belong to a group with at least one published sibling → awaiting_publish.
UPDATE sf_flashes f
SET    f.state  = 'awaiting_publish',
       f.status = 'ODOTTAA_JULKAISUA'
WHERE  (f.state = '' OR f.state IS NULL)
  AND  EXISTS (
           SELECT 1
           FROM   sf_flashes f2
           WHERE  (f2.id                  = COALESCE(f.translation_group_id, f.id)
                OR f2.translation_group_id = COALESCE(f.translation_group_id, f.id))
             AND  f2.id    != f.id
             AND  f2.state  = 'published'
       );

-- Remaining rows with empty state → draft (safe fallback).
UPDATE sf_flashes
SET    state = 'draft'
WHERE  state = '' OR state IS NULL;
