-- 010 默认主题更新为 hue45 金色（#FFC107）+ 表白墙审核模式设置

INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES
    ('site.default_theme', '{"sourceColor":"#FFC107","variant":"tonalSpot","contrast":"standard","density":0,"motion":"standard"}', NOW()),
    ('site.confession_moderation', 'post', NOW())
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at);

-- @@DOWN

INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES
    ('site.default_theme', '{"sourceColor":"#5B67C7","variant":"tonalSpot","contrast":"standard","density":0,"motion":"standard"}', NOW())
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at);

DELETE FROM system_settings WHERE setting_key = 'site.confession_moderation';
