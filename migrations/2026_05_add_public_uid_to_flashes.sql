-- Add public_uid to sf_flashes for safe external references
ALTER TABLE sf_flashes ADD COLUMN IF NOT EXISTS public_uid CHAR(36) UNIQUE NULL;

-- Generate UUID for existing published flashes
UPDATE sf_flashes SET public_uid = UUID() WHERE public_uid IS NULL AND state = 'published';
