-- 041 硬核会员（闪电 Lv6）有效期 365 天，到期需重考

ALTER TABLE users
    ADD COLUMN flash_expires_at DATETIME NULL AFTER is_flash,
    ADD KEY idx_users_flash_expires (flash_expires_at);

-- @@DOWN

ALTER TABLE users
    DROP KEY idx_users_flash_expires,
    DROP COLUMN flash_expires_at;
