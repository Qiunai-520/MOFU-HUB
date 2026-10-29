-- 047 固定品牌强调色：历史主题数据统一迁移到 Mofukey 黄 #FFCC38
-- 外观仅保留 auto / light / dark；旧调色板与源色不再参与运行时渲染。

UPDATE system_settings
SET setting_value = '{"sourceColor":"#FFCC38","scheme":"auto","variant":"tonalSpot","contrast":"standard","density":0,"motion":"standard"}'
WHERE setting_key = 'site.default_theme';

DELETE FROM system_settings WHERE setting_key = 'site.default_theme_vars';

UPDATE user_themes
SET source_color = '#FFCC38',
    variant = 'tonalSpot',
    contrast = 'standard',
    density = 0,
    motion = 'standard';

-- @@DOWN

-- 数据迁移，无回退（品牌色为单向变更）
SELECT 1;
