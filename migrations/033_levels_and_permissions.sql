-- 033 等级门槛与新体系权限
-- Lv1 改为「答题转正且累计 60 经验」（原为 0，导致转正即 Lv1 但门槛形同虚设）

UPDATE level_configs SET xp_required = 60 WHERE level = 1;

-- 新增权限点
INSERT INTO permissions (slug, name) VALUES
    ('tag.manage', '标签管理'),
    ('jury.manage', '风纪委员会管理'),
    ('lab.manage', '社区实验室管理'),
    ('invite.manage', '邀请码管理'),
    ('membership.manage', '会员管理'),
    ('payment.manage', '支付与订单管理'),
    ('nickname.manage', '昵称修改管理');

-- 风纪委员角色（参与内容众裁）
INSERT INTO roles (slug, name, level) VALUES ('jury', '风纪委员', 70);

-- 超管拥有全部新权限
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug = 'super_admin'
  AND p.slug IN ('tag.manage', 'jury.manage', 'lab.manage', 'invite.manage', 'membership.manage', 'payment.manage', 'nickname.manage');

-- 管理员：标签 / 会员 / 支付 / 邀请 / 昵称
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug = 'admin'
  AND p.slug IN ('tag.manage', 'membership.manage', 'payment.manage', 'invite.manage', 'nickname.manage');

-- 内容审核员与风纪委员：众裁管理
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug IN ('moderator', 'jury') AND p.slug = 'jury.manage';

-- @@DOWN

DELETE rp FROM role_permissions rp
    JOIN roles r ON r.id = rp.role_id
    JOIN permissions p ON p.id = rp.permission_id
WHERE p.slug IN ('tag.manage', 'jury.manage', 'lab.manage', 'invite.manage', 'membership.manage', 'payment.manage', 'nickname.manage');

DELETE FROM role_permissions WHERE role_id IN (SELECT id FROM roles WHERE slug = 'jury');
DELETE FROM roles WHERE slug = 'jury';
DELETE FROM permissions WHERE slug IN ('tag.manage', 'jury.manage', 'lab.manage', 'invite.manage', 'membership.manage', 'payment.manage', 'nickname.manage');

UPDATE level_configs SET xp_required = 0 WHERE level = 1;
