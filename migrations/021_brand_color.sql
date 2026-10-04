-- 021 品牌色统一：历史默认主题源色（旧琥珀 #FFC107）迁移为品牌基准色 #775A0D
-- 背景：品牌色由主题源色推导（BrandPalette），必须保证存量安装的源色也是品牌色。

UPDATE system_settings
SET setting_value = REPLACE(setting_value, '#FFC107', '#775A0D')
WHERE setting_key = 'site.default_theme' AND setting_value LIKE '%#FFC107%';

UPDATE user_themes
SET source_color = '#775A0D'
WHERE source_color = '#FFC107';

-- @@DOWN

-- 数据迁移，无回退（品牌色为单向变更）
SELECT 1;
