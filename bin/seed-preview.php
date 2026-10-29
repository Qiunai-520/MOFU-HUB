<?php

declare(strict_types=1);

/*
 * 本地预览演示数据。
 * 仅在 APP_ENV=local 且 users 表为空时执行，避免误写生产库。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('MOFU_ROOT', dirname(__DIR__));

require MOFU_ROOT . '/vendor/autoload.php';

use Mofu\Bootstrap\Config;

$config = Config::fromEnvironment(MOFU_ROOT);

if ($config->get('app.env') !== 'local') {
    fwrite(STDERR, "Refusing to seed demo data outside APP_ENV=local.\n");
    exit(1);
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    (string) $config->get('db.host'),
    (int) $config->get('db.port'),
    (string) $config->get('db.database'),
);

$pdo = new PDO($dsn, (string) $config->get('db.username'), (string) $config->get('db.password'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

if ($userCount > 0) {
    echo "Users already exist; demo seed skipped.\n";
    exit(0);
}

$now = date('Y-m-d H:i:s');
$hash = password_hash('MofuPreview123!', PASSWORD_DEFAULT);

$insertUser = static function (string $username, string $email, string $nickname, string $bio, string $location) use ($pdo, $now, $hash): int {
    $stmt = $pdo->prepare(
        "INSERT INTO users (username,email,email_verified_at,password_hash,password_set,status,is_formal,membership,created_at,updated_at)
         VALUES (?,?,?,?,1,'active',1,'normal',?,?)",
    );
    $stmt->execute([$username, $email, $now, $hash, $now, $now]);
    $id = (int) $pdo->lastInsertId();

    $profile = $pdo->prepare(
        'INSERT INTO user_profiles (user_id,nickname,bio,location,website,updated_at) VALUES (?,?,?,?,?,?)',
    );
    $profile->execute([$id, $nickname, $bio, $location, '', $now]);

    return $id;
};

$admin = $insertUser('preview', 'preview@example.com', '预览管理员', '在图书馆和操场之间来回走动。', '上海');
$luna = $insertUser('luna', 'luna@example.com', '林间月', '记录校园里值得记住的小事。', '杭州');
$kai = $insertUser('kai', 'kai@example.com', '阿凯', '摄影、咖啡、偶尔写点东西。', '南京');

$roles = $pdo->query('SELECT slug, id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
$assignRole = $pdo->prepare('INSERT INTO user_roles (user_id, role_id, assigned_at) VALUES (?,?,?)');
$assignRole->execute([$admin, (int) $roles['super_admin'], $now]);
$assignRole->execute([$luna, (int) $roles['user'], $now]);
$assignRole->execute([$kai, (int) $roles['user'], $now]);

$topicIds = [];
foreach ([['校园日常', 'campus-life'], ['读书', 'reading'], ['晚霞', 'sunset'], ['咖啡', 'coffee']] as [$name, $slug]) {
    $stmt = $pdo->prepare('INSERT INTO topics (name,slug,use_count,created_at) VALUES (?,?,?,?)');
    $stmt->execute([$name, $slug, 0, $now]);
    $topicIds[$name] = (int) $pdo->lastInsertId();
}

$posts = [
    [$admin, '雨后的图书馆，光线刚好', "下过雨的下午，图书馆二楼的窗边几乎没有人。\n\n把电脑合上，听了一会儿屋檐滴水的声音。有时候效率并不是最重要的事，留一点空白给大脑，反而更容易想清楚接下来要做什么。\n\n今天读到一句话：**缓慢不是停滞，是让方向变得清楚。** 分享给也在赶路的你。", ['校园日常', '读书'], true],
    [$luna, '今天的晚霞值得绕操场三圈', "从食堂出来的时候，天空整片都是橘粉色的。\n\n和室友临时决定去操场走一圈，结果走了三圈。有人在跑步，有人在拍照，也有人只是坐着发呆。\n\n校园里最好的时刻，好像都不需要提前计划。", ['晚霞', '校园日常'], false],
    [$kai, '关于一杯手冲的记录', "水温 92℃，粉水比 1:15，闷蒸 30 秒。\n\n今天这支豆子有很明显的柑橘酸，尾段是红茶一样的涩感。下次想把水温降到 90℃ 再试一次。", ['咖啡'], false],
];

$insertPost = $pdo->prepare(
    "INSERT INTO posts (user_id,type,title,content,visibility,status,is_pinned,like_count,comment_count,favorite_count,created_at,updated_at)
     VALUES (?,?,?,?,'public','published',?,?,?,?,?,?)",
);
$linkTopic = $pdo->prepare('INSERT INTO post_topics (post_id,topic_id) VALUES (?,?)');

foreach ($posts as $index => [$userId, $title, $content, $topics, $pinned]) {
    $insertPost->execute([
        $userId, 'text', $title, $content, $pinned ? 1 : 0,
        [12, 28, 7][$index], [3, 6, 1][$index], [5, 9, 2][$index],
        date('Y-m-d H:i:s', time() - (($index + 1) * 3600)),
        $now,
    ]);
    $postId = (int) $pdo->lastInsertId();

    foreach ($topics as $topic) {
        $linkTopic->execute([$postId, $topicIds[$topic]]);
    }
}

$follow = $pdo->prepare('INSERT INTO follows (follower_id,followee_id,special,created_at) VALUES (?,?,0,?)');
$follow->execute([$admin, $luna, $now]);
$follow->execute([$luna, $admin, $now]);

$stmt = $pdo->prepare(
    "INSERT INTO events (user_id,title,description,location,start_at,end_at,signup_deadline,capacity,requires_approval,status,signup_count,created_at,updated_at)
     VALUES (?,?,?,?,?,?,?,?,0,'published',8,?,?)",
);
$stmt->execute([
    $admin,
    '周末校园摄影散步',
    '带上相机或手机，从图书馆门口出发，沿着梧桐道走到操场。没有器材门槛，只是看看校园里被忽略的角落。',
    '图书馆正门',
    date('Y-m-d H:i:s', time() + 86400 * 3),
    date('Y-m-d H:i:s', time() + 86400 * 3 + 7200),
    date('Y-m-d H:i:s', time() + 86400 * 2),
    20,
    $now,
    $now,
]);

$stmt = $pdo->prepare(
    "INSERT INTO news (user_id,title,summary,content,category,is_pinned,view_count,status,published_at,created_at,updated_at)
     VALUES (?,?,?,?,?,?,?,'published',?,?,?)",
);
$stmt->execute([
    $admin,
    '图书馆延长开放时间',
    '考试周期间，主馆开放至 23:00。',
    "为方便同学们复习，考试周期间主馆开放时间延长至 23:00，研修间需提前预约。\n\n请合理安排作息，注意闭馆前的广播提醒。",
    'notice',
    1,
    128,
    $now,
    $now,
    $now,
]);

echo "Demo data created.\n";
echo "Preview login: preview@example.com / MofuPreview123!\n";
