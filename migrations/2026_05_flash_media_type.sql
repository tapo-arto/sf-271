-- Add media_type column to sf_flash_images to support videos alongside images.
-- Default is 'image' for backward compatibility with existing rows.
ALTER TABLE sf_flash_images
    ADD COLUMN media_type ENUM('image', 'video') NOT NULL DEFAULT 'image' AFTER original_filename;
