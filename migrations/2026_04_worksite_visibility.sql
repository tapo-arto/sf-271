ALTER TABLE sf_worksites
    ADD COLUMN show_in_worksite_lists TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active,
    ADD COLUMN show_in_display_targets TINYINT(1) NOT NULL DEFAULT 1 AFTER show_in_worksite_lists;
