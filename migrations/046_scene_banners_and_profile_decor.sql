-- 046 首页 3D 场景轮播默认项 + 头像框/昵称挂件

ALTER TABLE user_profiles
    ADD COLUMN avatar_frame VARCHAR(32) NOT NULL DEFAULT '' AFTER avatar_media_id,
    ADD COLUMN name_badge VARCHAR(32) NOT NULL DEFAULT '' AFTER nickname;

-- 只在管理员从未配置过首页轮播时放入默认的便利店场景。
-- 之后该条目完全由后台「首页轮播」维护，可编辑、禁用或删除。
INSERT INTO system_settings (setting_key, setting_value, updated_at, updated_by)
SELECT 'site.home_banners',
       '[{"title":"街角便利店 · 电影感 3D 场景","summary":"雨停以后，城市慢了下来。拖动视角，走进这家仍亮着灯的日本街角便利店。","link":"/konbini","image":"/images/konbini-banner.svg","target":"self","type":"scene3d"}]',
       NOW(),
       NULL
WHERE NOT EXISTS (
    SELECT 1 FROM system_settings WHERE setting_key = 'site.home_banners'
);

INSERT IGNORE INTO shop_products
    (slug, name, description, icon, price, type, stock, active, per_user_limit, payload, sort, created_at, updated_at)
VALUES
    ('frame-sakura', '头像框 · 樱花', '头像外圈浮现春日樱花色，适合个人主页与评论区展示。', 'local_florist', 180, 'avatar_frame', NULL, 1, 1, '{"frame":"sakura"}', 21, NOW(), NOW()),
    ('frame-ocean', '头像框 · 海盐', '清透的海盐蓝渐变头像框。', 'water', 260, 'avatar_frame', NULL, 1, 1, '{"frame":"ocean"}', 22, NOW(), NOW()),
    ('frame-neon', '头像框 · 霓虹', '带一点赛博感的霓虹光环头像框。', 'flare', 420, 'avatar_frame', NULL, 1, 1, '{"frame":"neon"}', 23, NOW(), NOW()),
    ('badge-spark', '名字挂件 · 星芒', '昵称后显示 ✨ 星芒挂件。', 'auto_awesome', 80, 'badge', NULL, 1, 1, '{"badge":"spark"}', 31, NOW(), NOW()),
    ('badge-cat', '名字挂件 · 猫猫', '昵称后显示 🐈 猫猫挂件。', 'pets', 160, 'badge', NULL, 1, 1, '{"badge":"cat"}', 32, NOW(), NOW()),
    ('badge-sakura', '名字挂件 · 樱花', '昵称后显示 🌸 樱花挂件。', 'local_florist', 200, 'badge', NULL, 1, 1, '{"badge":"sakura"}', 33, NOW(), NOW()),
    ('badge-crown', '名字挂件 · 小皇冠', '昵称后显示 👑 小皇冠挂件。', 'workspace_premium', 480, 'badge', NULL, 1, 1, '{"badge":"crown"}', 34, NOW(), NOW());

-- @@DOWN

DELETE FROM shop_products
WHERE slug IN ('frame-sakura', 'frame-ocean', 'frame-neon', 'badge-spark', 'badge-cat', 'badge-sakura', 'badge-crown');

DELETE FROM system_settings
WHERE setting_key = 'site.home_banners'
  AND setting_value = '[{"title":"街角便利店 · 电影感 3D 场景","summary":"雨停以后，城市慢了下来。拖动视角，走进这家仍亮着灯的日本街角便利店。","link":"/konbini","image":"/images/konbini-banner.svg","target":"self","type":"scene3d"}]';

ALTER TABLE user_profiles
    DROP COLUMN name_badge,
    DROP COLUMN avatar_frame;
