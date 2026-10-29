-- 系统运维权限：迁移、备份和升级操作与普通后台内容审核分离。

INSERT INTO permissions (slug, name) VALUES
    ('system.manage', '系统运维与备份');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.slug = 'super_admin'
  AND p.slug = 'system.manage';

-- @@DOWN

DELETE rp FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
WHERE p.slug = 'system.manage';

DELETE FROM permissions WHERE slug = 'system.manage';
