<?php

declare(strict_types=1);

namespace Mofu\Tests\Feature;

use Mofu\Tests\Support\HttpTestCase;

/**
 * 「系统与升级」页：虚拟主机的网页升级 / 备份入口。
 */
final class SystemUpgradeTest extends HttpTestCase
{
    public function testAdminCanOpenSystemPage(): void
    {
        [$cookie, , $username] = $this->registerUser('sys_admin');
        $this->promoteToAdmin($this->userId($username));

        // /admin 直接进入运营概览，不再经过空的后台首页
        $dashboard = $this->get('/admin', 'text/html', $cookie);
        self::assertSame(302, $dashboard->getStatusCode());
        self::assertSame('/admin/dashboard', $dashboard->getHeaderLine('Location'));

        $dashboard = $this->get('/admin/dashboard', 'text/html', $cookie);
        self::assertSame(200, $dashboard->getStatusCode());
        self::assertStringContainsString('admin-nav', (string) $dashboard->getBody());
        self::assertStringContainsString('/admin/system', (string) $dashboard->getBody());

        $page = $this->get('/admin/system', 'text/html', $cookie);

        self::assertSame(200, $page->getStatusCode());
        $body = (string) $page->getBody();

        self::assertStringNotContainsString('admin-top', $body);
        self::assertStringContainsString('系统与升级', $body);
        self::assertStringContainsString('数据库迁移', $body);
        self::assertStringContainsString('已应用', $body);
        // 备份入口必须存在（虚拟主机没有终端时的替代方案）
        self::assertStringContainsString('/admin/system/backup', $body);
    }

    public function testBannersAdminOffersClickBehaviour(): void
    {
        [$cookie, , $username] = $this->registerUser('bnradm');
        $this->promoteToAdmin($this->userId($username));

        $page = $this->get('/admin/banners', 'text/html', $cookie);
        self::assertSame(200, $page->getStatusCode());
        $body = (string) $page->getBody();

        // 每张轮播图都能选点击行为：当前页 / 新标签页 / 仅展示
        self::assertStringContainsString('name="banner_target[]"', $body);
        self::assertStringContainsString('>新标签页打开<', $body);
        self::assertStringContainsString('>仅展示<', $body);
        self::assertStringContainsString('name="banner_type[]"', $body);
        self::assertStringContainsString('>3D 场景<', $body);
    }

    public function testTitlesAdminFormOffersRarityAndCondition(): void
    {
        [$cookie, , $username] = $this->registerUser('title_admin');
        $this->promoteToAdmin($this->userId($username));

        $page = $this->get('/admin/titles', 'text/html', $cookie);
        self::assertSame(200, $page->getStatusCode());
        $body = (string) $page->getBody();

        // 稀有度与获取条件的下拉必须有选项：它们渲染在 Twig 宏里，宏不继承模板上下文，
        // 不把两个字典当参数传进去就会渲染成「空下拉框」
        self::assertStringContainsString('<option value="common"', $body);
        self::assertStringContainsString('<option value="legendary"', $body);
        self::assertStringContainsString('<option value="checkin_streak"', $body);
        self::assertStringContainsString('<option value="manual"', $body);
    }

    public function testSystemPageRequiresAdmin(): void
    {
        [$cookie] = $this->registerUser('sys_user');

        $page = $this->get('/admin/system', 'text/html', $cookie);

        self::assertSame(403, $page->getStatusCode());
    }

    public function testModeratorCannotRunMigrationsOrDownloadBackup(): void
    {
        [$cookie, $csrf, $username] = $this->registerUser('sys_mod');
        $query = $this->query();
        $roleId = (int) $query->value("SELECT id FROM roles WHERE slug = 'moderator'");
        self::assertGreaterThan(0, $roleId);
        $query->run(
            'INSERT IGNORE INTO user_roles (user_id, role_id, assigned_at) VALUES (:user_id, :role_id, :assigned_at)',
            [
                'user_id' => $this->userId($username),
                'role_id' => $roleId,
                'assigned_at' => date('Y-m-d H:i:s'),
            ],
        );

        self::assertSame(403, $this->get('/admin/system', 'text/html', $cookie)->getStatusCode());
        self::assertSame(403, $this->send('POST', '/admin/system/migrate', [
            'form' => ['_csrf' => $csrf],
            'cookie' => $cookie,
        ])->getStatusCode());
        self::assertSame(403, $this->send('POST', '/admin/system/backup', [
            'form' => ['_csrf' => $csrf],
            'cookie' => $cookie,
        ])->getStatusCode());
    }

    public function testUserEditorShowsRoleNameAndBlocksSelfManagement(): void
    {
        [$cookie, , $username] = $this->registerUser('sys_self');
        $this->promoteToAdmin($this->userId($username));

        $page = $this->get('/admin/users', 'text/html', $cookie);
        $body = (string) $page->getBody();

        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('超级管理员', $body);
        self::assertStringContainsString('当前账号，需在账号安全页处理', $body);
        self::assertStringNotContainsString('super_admin</option>', $body);
    }

    public function testMobileAdminMenuAndAnimatedDashboardDataMarkup(): void
    {
        [$cookie, , $username] = $this->registerUser('admin_ui');
        $this->promoteToAdmin($this->userId($username));

        $dashboard = $this->get('/admin/dashboard', 'text/html', $cookie);
        $body = (string) $dashboard->getBody();

        self::assertSame(200, $dashboard->getStatusCode());
        self::assertStringContainsString('class="admin-menu"', $body);
        self::assertStringContainsString('data-admin-count=', $body);
        self::assertStringContainsString('admin-chart__ring', $body);
        self::assertStringContainsString('admin-chart__line', $body);
    }
}
