ALTER TABLE sf_worksites
    ADD COLUMN is_default_display TINYINT(1) NOT NULL DEFAULT 0 AFTER show_in_display_targets;
