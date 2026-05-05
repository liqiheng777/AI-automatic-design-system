<?php

declare(strict_types=1);

session_start();

ini_set('display_errors', '1');
error_reporting(E_ALL);
ini_set('memory_limit', '1024M');
set_time_limit(0);

date_default_timezone_set('Asia/Shanghai');

if (!extension_loaded('gd')) {
    http_response_code(500);
    exit('当前 PHP 未启用 GD 扩展，无法运行本页面。');
}
if (!extension_loaded('curl')) {
    http_response_code(500);
    exit('当前 PHP 未启用 cURL 扩展，无法运行本页面。');
}

// ============================================================
// 加载配置
// 所有 API Key、品牌信息、业务参数都来自 _airate_runtime/system/app_config.json
// 第一次部署后请进入 admin.php 完成配置
// ============================================================
require_once __DIR__ . '/config.php';
gaoqing_apply_config_constants();

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonResponseAndContinue(array $data, int $status, ?callable $after = null): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
        if ($after) {
            ignore_user_abort(true);
            $after();
        }
        exit;
    }

    @ob_end_flush();
    @flush();
    if ($after) {
        ignore_user_abort(true);
        $after();
    }
    exit;
}

function ensureDir(string $dir): string
{
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('无法创建目录：' . $dir);
    }
    return $dir;
}

function runtimePath(string ...$parts): string
{
    $path = __DIR__ . DIRECTORY_SEPARATOR . RUNTIME_DIR;
    foreach ($parts as $part) {
        $path .= DIRECTORY_SEPARATOR . $part;
    }
    return $path;
}

function initRuntime(): void
{
    ensureDir(runtimePath());
    ensureDir(runtimePath('system'));
    ensureDir(runtimePath('users'));
    ensureDir(runtimePath('masks'));
    ensureDir(runtimePath('orders'));
    ensureDir(runtimePath('cards'));

    if (!is_file(indicesPath())) {
        atomicWriteJson(indicesPath(), [
            'mobile_index' => [],
            'username_index' => [],
            'auth_index' => [],
        ]);
    }
    if (!is_file(cardStorePath())) {
        atomicWriteJson(cardStorePath(), [
            'cards' => [],
            'updated_at' => nowIso(),
            'updated_ts' => nowTs(),
        ]);
    }
}

function nowIso(): string
{
    return date('c');
}

function nowTs(): int
{
    return time();
}

function randomId(string $prefix = 'ID'): string
{
    return $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
}

function atomicWriteJson(string $path, array $data): void
{
    $tmp = $path . '.tmp_' . bin2hex(random_bytes(3));
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('JSON 编码失败：' . basename($path));
    }
    if (@file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('写入文件失败：' . basename($path));
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('保存文件失败：' . basename($path));
    }
}

function readJsonFile(string $path, array $default = []): array
{
    if (!is_file($path)) {
        return $default;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return $default;
    }
    $json = json_decode($raw, true);
    return is_array($json) ? $json : $default;
}

function setAppCookie(string $name, string $value, int $expire): void
{
    $params = [
        'expires' => $expire,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if (PHP_VERSION_ID >= 70300) {
        setcookie($name, $value, $params);
        return;
    }
    setcookie($name, $value, $expire, '/; samesite=Lax', '', $params['secure'], true);
}

function removeDirRecursive(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            removeDirRecursive($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function withFileLock(string $lockPath, callable $callback, bool $nonBlocking = false): mixed
{
    ensureDir(dirname($lockPath));
    $fp = fopen($lockPath, 'c+');
    if ($fp === false) {
        throw new RuntimeException('无法创建锁文件：' . basename($lockPath));
    }
    $operation = LOCK_EX | ($nonBlocking ? LOCK_NB : 0);
    try {
        if (!flock($fp, $operation)) {
            if ($nonBlocking) {
                return null;
            }
            throw new RuntimeException('无法获取锁：' . basename($lockPath));
        }
        return $callback($fp);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function systemLockPath(): string
{
    return runtimePath('system', 'system.lock');
}

function queueLockPath(): string
{
    return runtimePath('system', 'queue.lock');
}

function queueWorkerLockPath(): string
{
    return runtimePath('system', 'queue_worker.lock');
}

function cleanupMetaPath(): string
{
    return runtimePath('system', 'cleanup_meta.json');
}

function indicesPath(): string
{
    return runtimePath('system', 'indices.json');
}

function cardStorePath(): string
{
    return runtimePath('cards', 'card_keys.json');
}

function userPath(string $userId): string
{
    return runtimePath('users', $userId . '.json');
}

function userLockPath(string $userId): string
{
    return runtimePath('users', $userId . '.lock');
}

function maskUserDir(string $userId): string
{
    return runtimePath('masks', $userId);
}

function maskMetaPath(string $userId, string $maskId): string
{
    return maskUserDir($userId) . DIRECTORY_SEPARATOR . $maskId . '.json';
}

function maskImagePath(string $userId, string $maskId): string
{
    return maskUserDir($userId) . DIRECTORY_SEPARATOR . $maskId . '.png';
}

function maskLockPath(string $userId, string $maskId): string
{
    return maskUserDir($userId) . DIRECTORY_SEPARATOR . $maskId . '.lock';
}

function orderDir(string $orderId): string
{
    return runtimePath('orders', $orderId);
}

function orderMetaPath(string $orderId): string
{
    return orderDir($orderId) . DIRECTORY_SEPARATOR . 'meta.json';
}

function orderFilePath(string $orderId, string $filename): string
{
    return orderDir($orderId) . DIRECTORY_SEPARATOR . $filename;
}

function orderLockPath(string $orderId): string
{
    return orderDir($orderId) . DIRECTORY_SEPARATOR . 'order.lock';
}

function getCsrfToken(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['csrf_token'];
}

function requireCsrf(): void
{
    $header = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $posted = trim((string)($_POST['csrf_token'] ?? ''));
    $token = $header !== '' ? $header : $posted;
    if ($token === '' || !hash_equals(getCsrfToken(), $token)) {
        jsonResponse(['ok' => false, 'message' => '请求已失效，请刷新页面后重试'], 419);
    }
}

function normalizeMobile(string $mobile): string
{
    return preg_replace('/\D+/', '', $mobile) ?? '';
}

function isValidCnMobile(string $mobile): bool
{
    return (bool)preg_match('/^1[3-9]\d{9}$/', $mobile);
}

function normalizeCardCode(string $code): string
{
    return strtoupper(str_replace([' ', '-', '_'], '', trim($code)));
}

function defaultIndices(): array
{
    return [
        'mobile_index' => [],
        'username_index' => [],
        'auth_index' => [],
    ];
}

function loadIndices(): array
{
    $data = readJsonFile(indicesPath(), defaultIndices());
    if (!is_array($data['mobile_index'] ?? null)) {
        $data['mobile_index'] = [];
    }
    if (!is_array($data['username_index'] ?? null)) {
        $data['username_index'] = [];
    }
    if (!is_array($data['auth_index'] ?? null)) {
        $data['auth_index'] = [];
    }
    return $data;
}

function saveIndices(array $indices): void
{
    $payload = defaultIndices();
    $payload['mobile_index'] = $indices['mobile_index'] ?? [];
    $payload['username_index'] = $indices['username_index'] ?? [];
    $payload['auth_index'] = $indices['auth_index'] ?? [];
    atomicWriteJson(indicesPath(), $payload);
}

function readUser(string $userId): array
{
    $user = readJsonFile(userPath($userId), []);
    if (($user['user_id'] ?? '') !== $userId) {
        throw new RuntimeException('用户不存在');
    }
    if (!is_array($user['credit_logs'] ?? null)) {
        $user['credit_logs'] = [];
    }
    return $user;
}

function saveUser(array $user): void
{
    if (empty($user['user_id'])) {
        throw new RuntimeException('保存用户失败');
    }
    $user['updated_at'] = nowIso();
    $user['updated_ts'] = nowTs();
    atomicWriteJson(userPath((string)$user['user_id']), $user);
}

function withUserLock(string $userId, callable $callback): mixed
{
    return withFileLock(userLockPath($userId), static function () use ($callback) {
        return $callback();
    });
}

function createUser(string $username, string $mobile, string $password, ?string $inviterUsername = null): array
{
    $mobile = normalizeMobile($mobile);
    $username = trim($username);
    if ($username === '' || mb_strlen($username, 'UTF-8') > MAX_USERNAME_LENGTH) {
        throw new RuntimeException('用户名格式不正确');
    }
    if (!preg_match('/^[\x{4e00}-\x{9fa5}A-Za-z0-9_\-]{2,24}$/u', $username)) {
        throw new RuntimeException('用户名仅支持中文、字母、数字、下划线和中划线，长度 2-24');
    }
    if (!isValidCnMobile($mobile)) {
        throw new RuntimeException('请输入正确的手机号');
    }
    if (mb_strlen($password, '8bit') < MIN_PASSWORD_LENGTH || mb_strlen($password, '8bit') > MAX_PASSWORD_LENGTH) {
        throw new RuntimeException('密码长度需在 ' . MIN_PASSWORD_LENGTH . ' 到 ' . MAX_PASSWORD_LENGTH . ' 位之间');
    }

    return withFileLock(systemLockPath(), static function () use ($username, $mobile, $password, $inviterUsername): array {
        $indices = loadIndices();
        if (!empty($indices['mobile_index'][$mobile])) {
            throw new RuntimeException('该手机号已注册');
        }
        $usernameKey = mb_strtolower($username, 'UTF-8');
        if (!empty($indices['username_index'][$usernameKey])) {
            throw new RuntimeException('该用户名已存在');
        }

        $userId = randomId('USR');
        $user = [
            'user_id' => $userId,
            'username' => $username,
            'mobile' => $mobile,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'credits' => START_CREDITS,
            'total_spent' => 0,
            'total_generated' => 0,
            'total_orders' => 0,
            'remember_token_hash' => '',
            'status' => 'active',
            'created_at' => nowIso(),
            'updated_at' => nowIso(),
            'created_ts' => nowTs(),
            'updated_ts' => nowTs(),
            'last_login_at' => null,
            'last_login_ip' => null,
            'inviter_username' => $inviterUsername ? trim($inviterUsername) : '',
            'credit_logs' => [
                [
                    'time' => nowIso(),
                    'delta' => START_CREDITS,
                    'type' => 'new_user_gift',
                    'note' => '新用户注册赠送额度',
                    'balance' => START_CREDITS,
                ],
            ],
        ];
        saveUser($user);

        $indices['mobile_index'][$mobile] = $userId;
        $indices['username_index'][$usernameKey] = $userId;
        saveIndices($indices);

        return $user;
    });
}

function issueRememberToken(string $userId): string
{
    $plainToken = bin2hex(random_bytes(24));
    $hash = hash('sha256', $plainToken);

    withFileLock(systemLockPath(), static function () use ($userId, $hash): void {
        $indices = loadIndices();
        $user = readUser($userId);
        $oldHash = trim((string)($user['remember_token_hash'] ?? ''));
        if ($oldHash !== '') {
            unset($indices['auth_index'][$oldHash]);
        }
        $user['remember_token_hash'] = $hash;
        saveUser($user);
        $indices['auth_index'][$hash] = $userId;
        saveIndices($indices);
    });

    $_SESSION['user_id'] = $userId;
    setAppCookie(AUTH_COOKIE_NAME, $plainToken, nowTs() + AUTH_COOKIE_DAYS * 86400);
    return $plainToken;
}

function clearRememberToken(?string $userId): void
{
    if ($userId) {
        withFileLock(systemLockPath(), static function () use ($userId): void {
            $indices = loadIndices();
            $user = readUser($userId);
            $oldHash = trim((string)($user['remember_token_hash'] ?? ''));
            if ($oldHash !== '') {
                unset($indices['auth_index'][$oldHash]);
            }
            $user['remember_token_hash'] = '';
            saveUser($user);
            saveIndices($indices);
        });
    }

    unset($_SESSION['user_id']);
    setAppCookie(AUTH_COOKIE_NAME, '', nowTs() - 3600);
}

function setUserLoggedIn(string $userId): array
{
    $token = issueRememberToken($userId);
    withUserLock($userId, static function () use ($userId): void {
        $user = readUser($userId);
        $user['last_login_at'] = nowIso();
        $user['last_login_ip'] = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        saveUser($user);
    });
    $user = readUser($userId);
    $user['remember_token_plain'] = $token;
    return $user;
}

function getCurrentUser(): ?array
{
    $sessionUserId = trim((string)($_SESSION['user_id'] ?? ''));
    if ($sessionUserId !== '') {
        try {
            return readUser($sessionUserId);
        } catch (Throwable) {
            unset($_SESSION['user_id']);
        }
    }

    $cookieToken = trim((string)($_COOKIE[AUTH_COOKIE_NAME] ?? ''));
    if ($cookieToken === '') {
        return null;
    }

    $hash = hash('sha256', $cookieToken);
    $indices = loadIndices();
    $userId = trim((string)($indices['auth_index'][$hash] ?? ''));
    if ($userId === '') {
        return null;
    }

    try {
        $user = readUser($userId);
        if (!hash_equals((string)($user['remember_token_hash'] ?? ''), $hash)) {
            return null;
        }
        $_SESSION['user_id'] = $userId;
        return $user;
    } catch (Throwable) {
        return null;
    }
}

function requireLogin(): array
{
    $user = getCurrentUser();
    if (!$user) {
        jsonResponse(['ok' => false, 'message' => '请先登录'], 401);
    }
    return $user;
}

function publicUserProfile(array $user): array
{
    $customStyles = is_array($user['custom_styles'] ?? null) ? $user['custom_styles'] : [];
    return [
        'user_id' => (string)$user['user_id'],
        'username' => (string)$user['username'],
        'mobile' => (string)$user['mobile'],
        'credits' => (int)($user['credits'] ?? 0),
        'total_spent' => (int)($user['total_spent'] ?? 0),
        'total_generated' => (int)($user['total_generated'] ?? 0),
        'total_orders' => (int)($user['total_orders'] ?? 0),
        'created_at' => (string)($user['created_at'] ?? ''),
        'last_login_at' => $user['last_login_at'] ?? null,
        'credit_logs' => array_slice(is_array($user['credit_logs'] ?? null) ? $user['credit_logs'] : [], 0, 20),
        'custom_styles' => array_values($customStyles),
    ];
}

function adjustUserCredits(string $userId, int $delta, string $type, string $note, array $extra = []): array
{
    return withUserLock($userId, static function () use ($userId, $delta, $type, $note, $extra): array {
        $user = readUser($userId);
        $current = (int)($user['credits'] ?? 0);
        $next = $current + $delta;
        if ($next < 0) {
            throw new RuntimeException('额度不足');
        }
        $user['credits'] = $next;
        if ($delta < 0) {
            $user['total_spent'] = (int)($user['total_spent'] ?? 0) + abs($delta);
        }
        if ($type === 'order_complete') {
            $user['total_generated'] = (int)($user['total_generated'] ?? 0) + (int)($extra['generated'] ?? 0);
        }
        $logs = is_array($user['credit_logs'] ?? null) ? $user['credit_logs'] : [];
        array_unshift($logs, [
            'time' => nowIso(),
            'delta' => $delta,
            'type' => $type,
            'note' => $note,
            'balance' => $next,
            'extra' => $extra,
        ]);
        $user['credit_logs'] = array_slice($logs, 0, 100);
        saveUser($user);
        return $user;
    });
}

function templateCatalog(): array
{
    return [
        'premium_brand' => [
            'id' => 'premium_brand',
            'name' => '高级品牌感',
            'desc' => '品牌联名 / 礼盒 / 精品周边',
            'prompt' => '整体视觉偏高级品牌系统，质感精致，版式克制，结构有秩序，细节干净，色彩层次明确，适合成熟审美与商业成品呈现。',
        ],
        'cyber_energy' => [
            'id' => 'cyber_energy',
            'name' => '赛博能量',
            'desc' => '科技 / 机甲 / 未来感',
            'prompt' => '整体视觉偏赛博与能量感，图形锐利，光效与速度线更强，使用科技圈层、几何切片、UI 装饰与高识别图形模块。',
        ],
        'cute_pop' => [
            'id' => 'cute_pop',
            'name' => '潮玩可爱',
            'desc' => '萌系 / 角色 / 礼品向',
            'prompt' => '整体视觉偏潮玩可爱，色彩明快，图标化、贴纸化与徽章拼贴更丰富，画面热闹但保持统一与精致。',
        ],
        'dark_luxury' => [
            'id' => 'dark_luxury',
            'name' => '暗黑奢感',
            'desc' => '龙 / 金属 / 暗色审美',
            'prompt' => '整体视觉偏暗黑奢感，结构稳重，细节华丽，适合黑金、深红、冷银等高对比配色，强调神秘感与气场。',
        ],
        'clean_minimal' => [
            'id' => 'clean_minimal',
            'name' => '极简高级',
            'desc' => '清爽 / 克制 / 现代化',
            'prompt' => '整体视觉偏极简高级，信息组织清晰，形态利落，留白更克制，减少冗余堆叠，突出核心图形识别度。',
        ],
        'national_trend' => [
            'id' => 'national_trend',
            'name' => '国潮东方',
            'desc' => '中式 / 山水 / 红金祥云',
            'prompt' => '整体视觉偏国潮东方，融合祥云、山水、卷草、回纹、印章等东方元素，主色采用朱砂、墨黑、鎏金、黛青，结构有古典秩序感，整体兼具传统韵味与现代版式。',
        ],
        'anime_ip' => [
            'id' => 'anime_ip',
            'name' => '二次元 IP',
            'desc' => '角色立绘 / 群像拼贴',
            'prompt' => '整体视觉偏二次元游戏 IP 贴纸风，左右安排角色立绘或半身像，中部桥接主视觉与徽章，围绕装饰元素加入 Q 版小角色与道具点缀，色彩鲜亮饱满。',
        ],
        'vintage_retro' => [
            'id' => 'vintage_retro',
            'name' => '复古怀旧',
            'desc' => '美式 / 80s / 老海报',
            'prompt' => '整体视觉偏复古怀旧，使用做旧色调、网点、印刷纹理、复古字体、老广告版式与拼贴感，整体带有温暖的胶片或泛黄质感。',
        ],
        'street_graffiti' => [
            'id' => 'street_graffiti',
            'name' => '街头涂鸦',
            'desc' => '潮流 / 嘻哈 / 滑板',
            'prompt' => '整体视觉偏街头涂鸦风，使用喷漆、贴纸拼贴、霓虹色、夸张字体、漫画线条与街头符号，整体张扬有态度，适合潮流年轻向产品。',
        ],
        'pastel_dream' => [
            'id' => 'pastel_dream',
            'name' => '马卡龙梦幻',
            'desc' => '少女 / 治愈 / 甜美',
            'prompt' => '整体视觉偏马卡龙梦幻，使用粉、紫、蓝、薄荷绿等低饱和柔和色，搭配星星、云朵、爱心、闪光等装饰元素，整体甜美治愈，适合少女向产品。',
        ],
        'industrial_tech' => [
            'id' => 'industrial_tech',
            'name' => '工业科技',
            'desc' => '硬核 / 机械 / 信息图',
            'prompt' => '整体视觉偏工业科技，使用硬朗的机械结构、铆钉、金属纹理、技术蓝图、信息图表与单色对比，结构精密，质感冷硬，适合数码、工具与硬件类产品。',
        ],
        'fest_celebration' => [
            'id' => 'fest_celebration',
            'name' => '节庆喜庆',
            'desc' => '新年 / 婚庆 / 红金',
            'prompt' => '整体视觉偏节庆喜庆，主色红金为主，搭配祥云、灯笼、福字、烟花、花卉等元素，构图饱满热烈，适合春节、婚庆、店庆、开业等场景。',
        ],
    ];
}

function getTemplateById(string $templateId, ?array $user = null): array
{
    $all = templateCatalog();
    if (isset($all[$templateId])) {
        return $all[$templateId];
    }
    if ($user && str_starts_with($templateId, 'custom_')) {
        $userStyles = is_array($user['custom_styles'] ?? null) ? $user['custom_styles'] : [];
        foreach ($userStyles as $st) {
            if (($st['id'] ?? '') === $templateId) {
                return [
                    'id' => (string)$st['id'],
                    'name' => (string)$st['name'],
                    'desc' => (string)($st['desc'] ?? '自定义风格'),
                    'prompt' => (string)$st['prompt'],
                ];
            }
        }
    }
    return $all['premium_brand'];
}

function sanitizeFilenameSegment(string $s, int $maxLen = 60): string
{
    $s = trim($s);
    $s = str_replace(["\r", "\n", "\t"], ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    $s = preg_replace('/[\\\\\/:\*\?"<>\|]/u', '', $s) ?? $s;
    $s = trim($s);
    if ($s === '') {
        return '未命名';
    }
    if (function_exists('mb_substr') && mb_strlen($s, 'UTF-8') > $maxLen) {
        $s = mb_substr($s, 0, $maxLen, 'UTF-8');
    }
    return $s;
}

function buildExportBaseName(array $orderMeta): string
{
    $maskName = sanitizeFilenameSegment((string)($orderMeta['mask_name'] ?? '蒙版'), 30);
    $theme = sanitizeFilenameSegment((string)($orderMeta['theme'] ?? '主题'), 40);
    $createdTs = (int)($orderMeta['created_ts'] ?? nowTs());
    $time = date('Ymd-His', $createdTs > 0 ? $createdTs : nowTs());
    return $maskName . '-' . $theme . '-' . $time;
}

function createTransparentCanvas(int $w, int $h): GdImage
{
    $img = imagecreatetruecolor($w, $h);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefilledrectangle($img, 0, 0, $w, $h, $transparent);
    return $img;
}

function createOpaqueCanvas(int $w, int $h, int $rgb = 0x000000): GdImage
{
    $img = imagecreatetruecolor($w, $h);
    imagealphablending($img, false);
    imagesavealpha($img, false);
    imagefilledrectangle($img, 0, 0, $w, $h, $rgb);
    return $img;
}

function loadImageFromFile(string $path): GdImage
{
    $bin = @file_get_contents($path);
    if ($bin === false) {
        throw new RuntimeException('读取图片失败：' . basename($path));
    }
    $img = @imagecreatefromstring($bin);
    if (!$img) {
        throw new RuntimeException('载入图片失败：' . basename($path));
    }
    imagealphablending($img, false);
    imagesavealpha($img, true);
    return $img;
}

function resizeExact(GdImage $src, int $w, int $h): GdImage
{
    $dst = createTransparentCanvas($w, $h);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));
    return $dst;
}

function savePng(GdImage $img, string $path): void
{
    imagealphablending($img, false);
    imagesavealpha($img, true);
    if (!imagepng($img, $path)) {
        throw new RuntimeException('保存 PNG 失败：' . basename($path));
    }
}

function getImageSizeSafe(string $path): array
{
    $info = @getimagesize($path);
    if (!is_array($info) || empty($info[0]) || empty($info[1])) {
        throw new RuntimeException('无法获取图片尺寸：' . basename($path));
    }
    return [(int)$info[0], (int)$info[1]];
}

function localImageToDataUrl(string $path): string
{
    $bin = @file_get_contents($path);
    if ($bin === false || $bin === '') {
        throw new RuntimeException('读取本地参考图失败：' . basename($path));
    }
    $mime = 'image/png';
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    if ($finfo) {
        $detected = (string)finfo_file($finfo, $path);
        finfo_close($finfo);
        if ($detected !== '') {
            $mime = $detected;
        }
    }
    return 'data:' . $mime . ';base64,' . base64_encode($bin);
}

function normalizePngFileToSize(string $path, int $targetW, int $targetH): void
{
    $img = loadImageFromFile($path);
    if (imagesx($img) === $targetW && imagesy($img) === $targetH) {
        imagedestroy($img);
        return;
    }
    $resized = resizeExact($img, $targetW, $targetH);
    savePng($resized, $path);
    imagedestroy($img);
    imagedestroy($resized);
}

function applyBinaryMask(GdImage $src, GdImage $mask, int $threshold = MASK_THRESHOLD): GdImage
{
    $w = imagesx($mask);
    $h = imagesy($mask);

    $workingSrc = $src;
    $destroyWorkingSrc = false;
    if (imagesx($src) !== $w || imagesy($src) !== $h) {
        $workingSrc = resizeExact($src, $w, $h);
        $destroyWorkingSrc = true;
    }

    $out = createTransparentCanvas($w, $h);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $maskColor = imagecolorat($mask, $x, $y);
            $mr = ($maskColor >> 16) & 0xFF;
            $mg = ($maskColor >> 8) & 0xFF;
            $mb = $maskColor & 0xFF;
            $gray = (int)(($mr + $mg + $mb) / 3);
            if ($gray < $threshold) {
                imagesetpixel($out, $x, $y, 0x7F000000);
                continue;
            }
            $srcColor = imagecolorat($workingSrc, $x, $y);
            $sr = ($srcColor >> 16) & 0xFF;
            $sg = ($srcColor >> 8) & 0xFF;
            $sb = $srcColor & 0xFF;
            $sa = ($srcColor >> 24) & 0x7F;
            imagesetpixel($out, $x, $y, ($sa << 24) | ($sr << 16) | ($sg << 8) | $sb);
        }
    }

    if ($destroyWorkingSrc) {
        imagedestroy($workingSrc);
    }
    return $out;
}

function normalizeImageBinaryToPng(string $binary, string $savePath): void
{
    $img = @imagecreatefromstring($binary);
    if (!$img) {
        throw new RuntimeException('远程图片无法解析');
    }
    imagealphablending($img, false);
    imagesavealpha($img, true);
    savePng($img, $savePath);
    imagedestroy($img);
}

function downloadUrlToBinary(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: image/*,*/*'],
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        throw new RuntimeException('下载生成图片失败：' . $error);
    }
    if (!is_string($body) || $body === '' || $status < 200 || $status >= 300) {
        throw new RuntimeException('下载生成图片失败，HTTP 状态码：' . $status);
    }
    return $body;
}

function downloadRemoteImageToPng(string $url, string $savePath): void
{
    $bin = downloadUrlToBinary($url);
    normalizeImageBinaryToPng($bin, $savePath);
}

function applyMaskFile(string $sourceImagePath, string $maskFilePath, string $outputPath): void
{
    $src = loadImageFromFile($sourceImagePath);
    $mask = loadImageFromFile($maskFilePath);
    $out = applyBinaryMask($src, $mask);
    savePng($out, $outputPath);
    imagedestroy($src);
    imagedestroy($mask);
    imagedestroy($out);
}

function createZipFile(string $zipPath, array $localFiles, array $textFiles = []): bool
{
    if (!class_exists('ZipArchive')) {
        return false;
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }
    foreach ($localFiles as $diskPath => $zipName) {
        if (is_file($diskPath)) {
            $zip->addFile($diskPath, $zipName);
        }
    }
    foreach ($textFiles as $zipName => $content) {
        $zip->addFromString($zipName, $content);
    }
    $zip->close();
    return is_file($zipPath);
}

function normalizeUploadedMaskToBinaryPng(string $tmpPath, string $savePath, string $polarity = 'auto'): array
{
    $bin = @file_get_contents($tmpPath);
    if (!is_string($bin) || $bin === '') {
        throw new RuntimeException('读取上传模板失败');
    }

    $src = @imagecreatefromstring($bin);
    if (!$src) {
        throw new RuntimeException('上传的模板不是有效图片');
    }
    imagealphablending($src, false);
    imagesavealpha($src, true);

    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 64 || $h < 64) {
        imagedestroy($src);
        throw new RuntimeException('模板尺寸过小，请上传至少 64x64 像素的图片');
    }
    if ($w > MAX_MASK_WIDTH || $h > MAX_MASK_HEIGHT) {
        imagedestroy($src);
        throw new RuntimeException('模板尺寸过大，请控制在 ' . MAX_MASK_WIDTH . 'x' . MAX_MASK_HEIGHT . ' 像素以内');
    }

    // 第一遍扫描：统计 alpha 通道情况和颜色分布，用于 auto 模式判断
    $hasAlpha = false;
    $transparentCount = 0;
    $opaqueCount = 0;
    $brightCount = 0;
    $darkCount = 0;
    $total = $w * $h;
    $sampleStep = max(1, (int)sqrt($total / 20000));
    $sampled = 0;
    for ($y = 0; $y < $h; $y += $sampleStep) {
        for ($x = 0; $x < $w; $x += $sampleStep) {
            $color = imagecolorat($src, $x, $y);
            $a = ($color >> 24) & 0x7F;
            $r = ($color >> 16) & 0xFF;
            $g = ($color >> 8) & 0xFF;
            $b = $color & 0xFF;
            $gray = (int)(($r + $g + $b) / 3);
            if ($a > 60) {
                $transparentCount++;
                $hasAlpha = true;
            } else {
                $opaqueCount++;
                if ($gray >= MASK_THRESHOLD) {
                    $brightCount++;
                } else {
                    $darkCount++;
                }
            }
            $sampled++;
        }
    }

    // 自动判定极性
    $resolved = $polarity;
    if ($polarity === 'auto') {
        if ($hasAlpha && $transparentCount > $opaqueCount * 0.25) {
            // 透明背景比较明显：不透明像素 = 设计区域
            $resolved = 'opaque_editable';
        } elseif ($darkCount > $brightCount * 1.4) {
            // 暗色像素显著多于亮色像素：可能是黑色形状=设计区域的反色蒙版
            $resolved = 'black_editable';
        } else {
            $resolved = 'white_editable';
        }
    }

    $out = createOpaqueCanvas($w, $h, 0x000000);
    imagealphablending($out, false);
    imagesavealpha($out, false);

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $color = imagecolorat($src, $x, $y);
            $a = ($color >> 24) & 0x7F;
            $r = ($color >> 16) & 0xFF;
            $g = ($color >> 8) & 0xFF;
            $b = $color & 0xFF;
            $gray = (int)(($r + $g + $b) / 3);

            $isEditable = false;
            switch ($resolved) {
                case 'opaque_editable':
                    // 不透明像素 = 设计区域
                    $isEditable = $a < 60;
                    break;
                case 'black_editable':
                    // 暗色像素 = 设计区域；透明像素 = 非设计区域
                    $isEditable = $a < 60 && $gray < MASK_THRESHOLD;
                    break;
                case 'white_editable':
                default:
                    // 默认：亮色不透明 = 设计区域
                    $isEditable = $a < 60 && $gray >= MASK_THRESHOLD;
                    break;
            }
            imagesetpixel($out, $x, $y, $isEditable ? 0xFFFFFF : 0x000000);
        }
    }

    if (!imagepng($out, $savePath)) {
        imagedestroy($src);
        imagedestroy($out);
        throw new RuntimeException('保存模板失败');
    }

    imagedestroy($src);
    imagedestroy($out);
    return [$w, $h, $resolved];
}

function sendSmsPlatform(string $mobile, string $content): array
{
    $postData = [
        '_type' => '1',
        'username' => SMS_USERNAME,
        'Password' => SMS_PASSWORD_MD5,
        'Phones' => $mobile,
        'Content' => $content,
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => SMS_API_URL,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        return ['ok' => false, 'msg' => '短信接口请求失败：' . $error, 'raw' => null];
    }
    if ($httpCode !== 200) {
        return ['ok' => false, 'msg' => '短信接口 HTTP 状态异常：' . $httpCode, 'raw' => $response];
    }

    $data = json_decode((string)$response, true);
    if (!is_array($data)) {
        return ['ok' => false, 'msg' => '短信接口返回不是有效 JSON', 'raw' => $response];
    }

    $providerCode = isset($data['Code']) ? (int)$data['Code'] : 0;
    $providerMsg = isset($data['Message']) ? (string)$data['Message'] : '未知返回';
    if ($providerCode === 1) {
        return ['ok' => true, 'msg' => $providerMsg, 'raw' => $data];
    }

    return ['ok' => false, 'msg' => '短信发送失败：' . $providerMsg . '（Code=' . $providerCode . '）', 'raw' => $data];
}

function sendSmsByProvider(string $mobile, string $code, string $purpose): array
{
    // 开发模式：未配置短信平台时，直接把验证码写入日志，并把验证码当作消息返回。
    // 这样开源用户在尚未对接真实短信平台时，仍然可以走通注册和绑定流程。
    if (!defined('SMS_ENABLED') || !SMS_ENABLED || SMS_USERNAME === '' || SMS_PASSWORD_MD5 === '') {
        $logDir = runtimePath('system');
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        $line = '[' . nowIso() . '] DEV-SMS to ' . $mobile . ' code=' . $code . " ($purpose)\n";
        @file_put_contents($logDir . DIRECTORY_SEPARATOR . 'dev_sms.log', $line, FILE_APPEND);
        return [
            'ok' => true,
            'msg' => '【开发模式】短信平台未配置。验证码已写入 _airate_runtime/system/dev_sms.log；'
                . '本次验证码为 ' . $code . '（生产环境请到后台配置真实的短信平台）。',
            'raw' => ['dev_mode' => true, 'code' => $code],
        ];
    }
    $content = '【' . SMS_SIGN_NAME . '】您的验证码是' . $code . '，5分钟内有效。';
    return sendSmsPlatform($mobile, $content);
}

function clearMobileVerifyBucket(string $purpose): void
{
    unset($_SESSION['mobile_verify'][$purpose]);
}

function verify_mobile_code(string $mobile, string $code, string $purpose): void
{
    $mobile = normalizeMobile($mobile);
    $code = trim($code);
    if (!isValidCnMobile($mobile)) {
        throw new RuntimeException('请输入正确的手机号');
    }
    if ($code === '') {
        throw new RuntimeException('请输入验证码');
    }
    $bucket = $_SESSION['mobile_verify'][$purpose] ?? null;
    if (!is_array($bucket)) {
        throw new RuntimeException('请先发送验证码');
    }
    if ((string)($bucket['mobile'] ?? '') !== $mobile) {
        throw new RuntimeException('手机号与发送验证码时不一致');
    }
    if ((int)($bucket['expire_time'] ?? 0) < nowTs()) {
        clearMobileVerifyBucket($purpose);
        throw new RuntimeException('验证码已过期，请重新发送');
    }
    if ((string)($bucket['code'] ?? '') !== $code) {
        throw new RuntimeException('验证码错误');
    }
    clearMobileVerifyBucket($purpose);
}

function createMask(string $userId, string $name, string $tmpPath, string $polarity = 'auto'): array
{
    $name = trim($name);
    if ($name === '') {
        throw new RuntimeException('请输入蒙版名称');
    }
    if (mb_strlen($name, 'UTF-8') > MAX_MASK_NAME_LENGTH) {
        throw new RuntimeException('蒙版名称请控制在 ' . MAX_MASK_NAME_LENGTH . ' 字以内');
    }
    if (!in_array($polarity, ['auto', 'white_editable', 'black_editable', 'opaque_editable'], true)) {
        $polarity = 'auto';
    }

    ensureDir(maskUserDir($userId));
    $maskId = randomId('MASK');
    $imagePath = maskImagePath($userId, $maskId);
    [$w, $h, $resolved] = normalizeUploadedMaskToBinaryPng($tmpPath, $imagePath, $polarity);

    $meta = [
        'mask_id' => $maskId,
        'user_id' => $userId,
        'name' => $name,
        'width' => $w,
        'height' => $h,
        'polarity' => $polarity,
        'resolved_polarity' => $resolved,
        'created_at' => nowIso(),
        'updated_at' => nowIso(),
        'created_ts' => nowTs(),
        'updated_ts' => nowTs(),
        'editable_rule' => 'white_editable_black_locked',
        'file_name' => basename($imagePath),
    ];
    atomicWriteJson(maskMetaPath($userId, $maskId), $meta);
    return $meta;
}

function readMaskMetaOwned(string $userId, string $maskId): array
{
    $meta = readJsonFile(maskMetaPath($userId, $maskId), []);
    if (($meta['mask_id'] ?? '') !== $maskId || ($meta['user_id'] ?? '') !== $userId) {
        throw new RuntimeException('蒙版不存在');
    }
    return $meta;
}

function listMasksByUser(string $userId): array
{
    $dir = maskUserDir($userId);
    if (!is_dir($dir)) {
        return [];
    }
    $items = scandir($dir);
    if ($items === false) {
        return [];
    }
    $rows = [];
    foreach ($items as $item) {
        if (!str_ends_with($item, '.json')) {
            continue;
        }
        $meta = readJsonFile($dir . DIRECTORY_SEPARATOR . $item, []);
        if (($meta['user_id'] ?? '') !== $userId) {
            continue;
        }
        $rows[] = $meta;
    }
    usort($rows, static fn(array $a, array $b): int => (int)($b['updated_ts'] ?? 0) <=> (int)($a['updated_ts'] ?? 0));
    return $rows;
}

function maskPreviewUrl(string $maskId, int $v = 0): string
{
    return '?action=mask_file&mask_id=' . rawurlencode($maskId) . '&v=' . $v;
}

function buildMaskPublic(array $meta): array
{
    $v = (int)($meta['updated_ts'] ?? nowTs());
    return [
        'mask_id' => (string)($meta['mask_id'] ?? ''),
        'name' => (string)($meta['name'] ?? ''),
        'width' => (int)($meta['width'] ?? 0),
        'height' => (int)($meta['height'] ?? 0),
        'polarity' => (string)($meta['polarity'] ?? 'auto'),
        'resolved_polarity' => (string)($meta['resolved_polarity'] ?? 'white_editable'),
        'created_at' => (string)($meta['created_at'] ?? ''),
        'updated_at' => (string)($meta['updated_at'] ?? ''),
        'preview_url' => maskPreviewUrl((string)$meta['mask_id'], $v),
    ];
}

function buildEmptyOrderFiles(int $designCount): array
{
    $files = [
        'package_zip' => '',
        'mask_snapshot' => 'order_mask.png',
    ];
    for ($i = 1; $i <= $designCount; $i++) {
        foreach (['raw', 'masked', 'idea_json', 'idea_txt'] as $suffix) {
            $files['set' . $i . '_' . $suffix] = '';
        }
    }
    return $files;
}

function orderSteps(int $designCount): array
{
    $steps = [];
    for ($i = 1; $i <= $designCount; $i++) {
        $steps[] = [
            'key' => 'set' . $i,
            'label' => '第 ' . $i . ' 张设计稿',
            'status' => 'waiting',
        ];
    }
    $steps[] = ['key' => 'finish', 'label' => '整理结果文件', 'status' => 'waiting'];
    return $steps;
}

function createOrderMeta(string $orderId, array $user, array $maskMeta, array $template, string $theme, int $designCount, int $costCredits): array
{
    return [
        'order_id' => $orderId,
        'user_id' => (string)$user['user_id'],
        'username' => (string)$user['username'],
        'mask_id' => (string)$maskMeta['mask_id'],
        'mask_name' => (string)$maskMeta['name'],
        'mask_meta' => [
            'width' => (int)($maskMeta['width'] ?? 0),
            'height' => (int)($maskMeta['height'] ?? 0),
            'editable_rule' => 'white_editable_black_locked',
        ],
        'template_id' => (string)$template['id'],
        'template_name' => (string)$template['name'],
        'theme' => $theme,
        'design_count' => $designCount,
        'generated_count' => 0,
        'reserved_credits' => $costCredits,
        'spent_credits' => 0,
        'refund_credits' => 0,
        'status' => 'queued',
        'status_text' => '排队中',
        'progress' => 1,
        'current_step' => '等待进入生成队列',
        'error_message' => '',
        'created_at' => nowIso(),
        'updated_at' => nowIso(),
        'created_ts' => nowTs(),
        'updated_ts' => nowTs(),
        'started_at' => null,
        'finished_at' => null,
        'steps' => orderSteps($designCount),
        'files' => buildEmptyOrderFiles($designCount),
    ];
}

function readOrderMeta(string $orderId): array
{
    $meta = readJsonFile(orderMetaPath($orderId), []);
    if (($meta['order_id'] ?? '') !== $orderId) {
        throw new RuntimeException('订单不存在');
    }
    return $meta;
}

function saveOrderMeta(array $meta): void
{
    $meta['updated_at'] = nowIso();
    $meta['updated_ts'] = nowTs();
    atomicWriteJson(orderMetaPath((string)$meta['order_id']), $meta);
}

function withOrderLock(string $orderId, callable $callback): mixed
{
    ensureDir(orderDir($orderId));
    return withFileLock(orderLockPath($orderId), static function () use ($callback) {
        return $callback();
    });
}

function listAllOrders(): array
{
    $dir = runtimePath('orders');
    if (!is_dir($dir)) {
        return [];
    }
    $items = scandir($dir);
    if ($items === false) {
        return [];
    }
    $rows = [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (!is_dir($path)) {
            continue;
        }
        $meta = readJsonFile($path . DIRECTORY_SEPARATOR . 'meta.json', []);
        if (($meta['order_id'] ?? '') === '') {
            continue;
        }
        $rows[] = $meta;
    }
    return $rows;
}

function listOrdersByUser(string $userId, int $limit = 50): array
{
    $all = listAllOrders();
    $rows = array_values(array_filter($all, static fn(array $row): bool => (string)($row['user_id'] ?? '') === $userId));
    usort($rows, static fn(array $a, array $b): int => (int)($b['updated_ts'] ?? 0) <=> (int)($a['updated_ts'] ?? 0));
    return array_slice($rows, 0, $limit);
}

function getActiveQueuePositions(): array
{
    $orders = array_values(array_filter(listAllOrders(), static function (array $row): bool {
        return in_array((string)($row['status'] ?? ''), ['queued', 'running'], true);
    }));
    usort($orders, static function (array $a, array $b): int {
        $aStatus = (string)($a['status'] ?? 'queued');
        $bStatus = (string)($b['status'] ?? 'queued');
        if ($aStatus === 'running' && $bStatus !== 'running') {
            return -1;
        }
        if ($aStatus !== 'running' && $bStatus === 'running') {
            return 1;
        }
        return (int)($a['created_ts'] ?? 0) <=> (int)($b['created_ts'] ?? 0);
    });
    $map = [];
    $pos = 1;
    foreach ($orders as $row) {
        $map[(string)$row['order_id']] = $pos;
        $pos++;
    }
    return $map;
}

function orderFileUrl(string $orderId, string $key, int $v = 0, bool $download = false): string
{
    return '?action=order_file&order_id=' . rawurlencode($orderId)
        . '&key=' . rawurlencode($key)
        . '&v=' . $v
        . ($download ? '&download=1' : '');
}

function computeOrderTiming(array $meta): array
{
    $designCount = max(MIN_DESIGN_SET_COUNT, min(MAX_DESIGN_SET_COUNT, (int)($meta['design_count'] ?? 1)));
    $estimatedTotal = $designCount * ESTIMATED_SECONDS_PER_IMAGE;
    $startedTs = (int)($meta['started_ts'] ?? 0);
    if ($startedTs <= 0 && !empty($meta['started_at'])) {
        $startedTs = (int)strtotime((string)$meta['started_at']) ?: 0;
    }
    $finishedTs = 0;
    if (!empty($meta['finished_at'])) {
        $finishedTs = (int)strtotime((string)$meta['finished_at']) ?: 0;
    }
    $status = (string)($meta['status'] ?? '');
    $elapsed = 0;
    if ($startedTs > 0) {
        $endTs = ($finishedTs > 0 && in_array($status, ['done', 'error', 'cancelled'], true)) ? $finishedTs : nowTs();
        $elapsed = max(0, $endTs - $startedTs);
    }
    $progress = max(0, min(100, (int)($meta['progress'] ?? 0)));
    $remaining = 0;
    if ($status === 'running' && $progress > 5) {
        $proj = (int)($elapsed * (100 - $progress) / max(5, $progress));
        $remaining = max(20, min($estimatedTotal * 2, $proj));
    } elseif ($status === 'queued') {
        $remaining = $estimatedTotal;
    } elseif ($status === 'running') {
        $remaining = $estimatedTotal;
    }
    return [
        'estimated_total_seconds' => $estimatedTotal,
        'elapsed_seconds' => $elapsed,
        'remaining_seconds' => $remaining,
        'started_ts' => $startedTs,
        'finished_ts' => $finishedTs,
    ];
}

function buildOrderPublic(array $meta): array
{
    $positions = getActiveQueuePositions();
    $v = (int)($meta['updated_ts'] ?? nowTs());
    $files = $meta['files'] ?? [];
    $sets = [];
    $count = max(MIN_DESIGN_SET_COUNT, min(MAX_DESIGN_SET_COUNT, (int)($meta['design_count'] ?? 1)));
    for ($i = 1; $i <= $count; $i++) {
        $prefix = 'set' . $i . '_';
        $sets[] = [
            'index' => $i,
            'title' => '第 ' . $i . ' 张设计稿',
            'image_url' => !empty($files[$prefix . 'masked']) ? orderFileUrl((string)$meta['order_id'], $prefix . 'masked', $v, false) : '',
            'image_download' => !empty($files[$prefix . 'masked']) ? orderFileUrl((string)$meta['order_id'], $prefix . 'masked', $v, true) : '',
        ];
    }
    $timing = computeOrderTiming($meta);
    return [
        'order_id' => (string)$meta['order_id'],
        'theme' => (string)($meta['theme'] ?? ''),
        'status' => (string)($meta['status'] ?? ''),
        'status_text' => (string)($meta['status_text'] ?? ''),
        'progress' => (int)($meta['progress'] ?? 0),
        'current_step' => (string)($meta['current_step'] ?? ''),
        'error_message' => (string)($meta['error_message'] ?? ''),
        'created_at' => (string)($meta['created_at'] ?? ''),
        'started_at' => $meta['started_at'] ?? null,
        'finished_at' => $meta['finished_at'] ?? null,
        'mask_name' => (string)($meta['mask_name'] ?? ''),
        'template_name' => (string)($meta['template_name'] ?? ''),
        'design_count' => $count,
        'generated_count' => (int)($meta['generated_count'] ?? 0),
        'reserved_credits' => (int)($meta['reserved_credits'] ?? 0),
        'spent_credits' => (int)($meta['spent_credits'] ?? 0),
        'refund_credits' => (int)($meta['refund_credits'] ?? 0),
        'queue_position' => $positions[(string)$meta['order_id']] ?? 0,
        'steps' => is_array($meta['steps'] ?? null) ? $meta['steps'] : [],
        'zip_url' => !empty($files['package_zip']) ? orderFileUrl((string)$meta['order_id'], 'package_zip', $v, true) : '',
        'mask_snapshot_url' => !empty($files['mask_snapshot']) ? orderFileUrl((string)$meta['order_id'], 'mask_snapshot', $v, false) : '',
        'final_sets' => $sets,
        'elapsed_seconds' => $timing['elapsed_seconds'],
        'estimated_total_seconds' => $timing['estimated_total_seconds'],
        'remaining_seconds' => $timing['remaining_seconds'],
    ];
}

function setStepStatus(array &$meta, string $key, string $status): void
{
    foreach ($meta['steps'] as &$step) {
        if (($step['key'] ?? '') === $key) {
            $step['status'] = $status;
        } elseif ($status === 'running' && ($step['status'] ?? '') === 'running') {
            $step['status'] = 'done';
        }
    }
    unset($step);
}

function markOrderRunning(array &$meta, string $stepKey, string $stepLabel, int $progress): void
{
    $meta['status'] = 'running';
    $meta['status_text'] = '生成中';
    $meta['current_step'] = $stepLabel;
    $meta['progress'] = max(1, min(99, $progress));
    setStepStatus($meta, $stepKey, 'running');
    if (empty($meta['started_at'])) {
        $meta['started_at'] = nowIso();
    }
    if (empty($meta['started_ts'])) {
        $meta['started_ts'] = nowTs();
    }
}

function markOrderStepDone(array &$meta, string $stepKey, int $progress): void
{
    setStepStatus($meta, $stepKey, 'done');
    $meta['progress'] = max(1, min(100, $progress));
}

function markOrderError(array &$meta, string $message): void
{
    foreach ($meta['steps'] as &$step) {
        if (($step['status'] ?? '') === 'running') {
            $step['status'] = 'error';
        }
    }
    unset($step);
    $meta['status'] = 'error';
    $meta['status_text'] = '生成失败';
    $meta['current_step'] = '生成失败';
    $meta['error_message'] = $message;
    $meta['finished_at'] = nowIso();
    $meta['progress'] = 100;
}

function markOrderDone(array &$meta): void
{
    foreach ($meta['steps'] as &$step) {
        $step['status'] = 'done';
    }
    unset($step);
    $meta['status'] = 'done';
    $meta['status_text'] = '已完成';
    $meta['current_step'] = '已完成';
    $meta['progress'] = 100;
    $meta['finished_at'] = nowIso();
}

function updateOrderMetaSafe(string $orderId, callable $mutator): array
{
    return withOrderLock($orderId, static function () use ($orderId, $mutator): array {
        $meta = readOrderMeta($orderId);
        $mutator($meta);
        saveOrderMeta($meta);
        return $meta;
    });
}

function getVariationHints(): array
{
    return [
        1 => '第 1 份偏整体铺满、主题氛围更强、主体元素更饱满。',
        2 => '第 2 份偏徽章拼贴、图形分区更明确、装饰元素更丰富。',
        3 => '第 3 份偏高识别图形化、色块更利落、版式更干净。',
        4 => '第 4 份偏纹样重复、节奏感更强、图形装饰更有秩序。',
        5 => '第 5 份偏简洁高级、留白更克制、核心视觉更集中。',
    ];
}

function extractFirstJsonObject(string $text): ?array
{
    $text = trim($text);
    if ($text === '') {
        return null;
    }
    $text = preg_replace('/^```json\s*/iu', '', $text) ?? $text;
    $text = preg_replace('/^```\s*/u', '', $text) ?? $text;
    $text = preg_replace('/\s*```$/u', '', $text) ?? $text;
    $text = trim($text);
    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    if (preg_match('/\{[\s\S]*\}/u', $text, $m)) {
        $decoded = json_decode((string)$m[0], true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return null;
}

function callGptRaw(string $systemPrompt, string $userPrompt, array $namedImages = []): array
{
    $userMessageContent = $userPrompt;
    if (!empty($namedImages)) {
        $contentBlocks = [
            ['type' => 'text', 'text' => $userPrompt],
        ];
        foreach ($namedImages as $item) {
            $label = trim((string)($item['label'] ?? '参考图'));
            $imagePath = (string)($item['path'] ?? '');
            if ($imagePath === '' || !is_file($imagePath)) {
                continue;
            }
            $contentBlocks[] = ['type' => 'text', 'text' => '下面这张图片是：' . $label];
            $contentBlocks[] = ['type' => 'image_url', 'image_url' => ['url' => localImageToDataUrl($imagePath)]];
        }
        if (count($contentBlocks) > 1) {
            $userMessageContent = $contentBlocks;
        }
    }

    $body = [
        'model' => GPT_MODEL,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessageContent],
        ],
        'temperature' => GPT_TEMPERATURE,
        'max_tokens' => GPT_MAX_TOKENS,
    ];

    $ch = curl_init(GPT_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => GPT_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => GPT_TIMEOUT,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . GPT_API_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        throw new RuntimeException('调用 GPT 接口失败：' . $error);
    }
    if (!is_string($resp) || trim($resp) === '') {
        throw new RuntimeException('GPT 接口返回为空');
    }
    $json = json_decode($resp, true);
    if (!is_array($json)) {
        throw new RuntimeException('GPT 接口返回不是合法 JSON');
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        $msg = is_array($json['error'] ?? null)
            ? (string)($json['error']['message'] ?? ('HTTP ' . $httpCode))
            : (string)($json['error'] ?? ('HTTP ' . $httpCode));
        throw new RuntimeException('GPT 接口失败：' . $msg);
    }

    $content = '';
    if (isset($json['choices'][0]['message']['content']) && is_string($json['choices'][0]['message']['content'])) {
        $content = trim($json['choices'][0]['message']['content']);
    }
    if ($content === '' && isset($json['choices'][0]['message']['content']) && is_array($json['choices'][0]['message']['content'])) {
        foreach ($json['choices'][0]['message']['content'] as $block) {
            if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $content .= ($content === '' ? '' : "\n") . trim((string)$block['text']);
            }
        }
        $content = trim($content);
    }
    if ($content === '') {
        throw new RuntimeException('GPT 未返回有效内容');
    }

    return ['raw' => $resp, 'content' => $content, 'json' => $json];
}

function buildMaskInfoTextForGpt(array $maskImages): string
{
    $lines = [];
    foreach ($maskImages as $item) {
        $label = trim((string)($item['label'] ?? '模板'));
        $path = (string)($item['path'] ?? '');
        if ($path === '' || !is_file($path)) {
            continue;
        }
        [$w, $h] = getImageSizeSafe($path);
        $lines[] = '- ' . $label . '：尺寸 ' . $w . 'x' . $h . '，白色是允许设计的区域，黑色是不可编辑区域或孔位/镂空/外部区域，绝对不能改变黑白边界、外轮廓、孔位、尺寸和相对关系。';
    }
    return implode("\n", $lines);
}

function buildDesignIdeaFallback(string $theme, string $maskInfoText = '', string $variationHint = '', string $templatePrompt = ''): array
{
    $variationText = trim($variationHint) !== '' ? '。本套方向：' . trim($variationHint) : '';
    $maskSuffix = $maskInfoText !== '' ? '。必须严格服从上传的二值模板：白色区域可设计，黑色区域不可编辑，绝对不能改变边界、孔位、尺寸和相对关系' : '';
    $templateSuffix = trim($templatePrompt) !== '' ? '。风格要求：' . trim($templatePrompt) : '';

    return [
        'theme_title' => $theme . '主题设计稿',
        'creative_summary' => '整体围绕“' . $theme . '”做一张可直接印刷的二维平面模板设计，强调在白色可编辑区域内完整铺开主题元素，并围绕可用形状自然组织画面，黑色禁改区域保持不动' . $maskSuffix . $templateSuffix . $variationText . '。画面可使用主视觉、图标、徽章、纹样、场景片段、装饰线条、几何图形和小元素点缀，避免大面积空白与单张海报硬裁感。',
        'style_keywords' => ['二维平面设计', '模板贴图', '主题排版', '图形装饰', '完整铺底', '可印刷成品'],
        'design_prompt' => '请设计一张可直接用于印刷的二维平面模板图案，主题为“' . $theme . '”。必须严格根据上传的二值模板进行设计：白色区域是唯一允许承载图案的区域，黑色区域是不可编辑区域、孔位、镂空或模板外部，绝对不能改变外轮廓、孔位、黑白边界、尺寸和相对关系，输出尺寸必须与模板完全一致。画面应在白色区域内完整铺满，使用统一主题色彩、主视觉元素、徽章、图标、纹样、边框、装饰线条、几何分区、小型点缀和场景氛围来组织版面；可围绕黑色孔位或避让区设计环形、花边、科技圈、像素圈、光效圈或装饰边缘；整体必须是纯二维平面印刷设计，不要实物展示，不要产品摄影，不要透视，不要 mockup，不要水印。' . ($templateSuffix !== '' ? ' ' . trim($templatePrompt) : '') . ($variationText !== '' ? ' ' . trim($variationHint) : ''),
    ];
}

function callGptDesignIdea(string $theme, array $maskImages = [], string $variationHint = '', array $template = []): array
{
    $maskInfoText = buildMaskInfoTextForGpt($maskImages);
    $templatePrompt = trim((string)($template['prompt'] ?? ''));

    $system = <<<'SYS'
你是一个专业的二维平面模板设计策划师。
现在只需要为“上传的二值模板”生成一套二维平面印刷设计创意与绘图 prompt。

你会看到一张二值模板图：
1. 白色区域 = 可编辑设计区域
2. 黑色区域 = 不可编辑区域、孔位、镂空区或模板外部背景

你的任务：
- 先观察模板的形状、孔位、黑白分布和可用面积；
- 再围绕用户给出的主题，产出一份可直接给绘图模型使用的平面设计 prompt；
- 设计必须像已经排版好的二维模板成品，而不是单张海报被硬裁进去。

你要遵守以下原则：
1. 绝对不能改变模板的外轮廓、孔位、黑白边界、尺寸和相对关系；
2. 所有图案都只能落在白色区域内，黑色区域保持不可编辑；
3. 画面需要完整铺开，充分利用白色区域组织主题元素，不要大面积空白；
4. 可使用主视觉、图标、徽章、纹样、几何色块、装饰边框、场景氛围、小元素点缀来增强完整度；
5. 若模板中存在孔位或避让区，可围绕这些区域布置环形、花边、科技圈、像素圈、能量圈、装饰边缘等元素；
6. 整体必须是纯二维平面印刷设计，不要实物展示，不要产品摄影，不要透视，不要 mockup，不要水印；
7. 为了降低绘图接口拦截风险，prompt 应更多强调平面设计、主题排版、图形装饰、纹样、小元素、符号、徽章和场景化拼贴，减少过近人脸特写、写实皮肤、裸露、性感和写真感描述。

你必须严格输出 JSON，不能输出 markdown 代码块，不能输出解释，不能输出前后缀。
JSON 格式固定为：
{
  "theme_title":"...",
  "creative_summary":"...",
  "style_keywords":["...","..."],
  "design_prompt":"..."
}

硬性要求：
1. 只输出模板设计相关内容。
2. 必须是纯二维平面印刷贴图设计。
3. design_prompt 里必须明确说明：白色区域可设计、黑色区域不可编辑、绝对不能改变模板结构。
4. design_prompt 要详细、可直接给绘图模型使用。
5. style_keywords 数组给 4 到 8 个短词即可。
SYS;

    $user = "主题：{$theme}\n"
        . (trim($variationHint) !== '' ? "本次方案方向：{$variationHint}\n" : '')
        . (trim((string)($template['name'] ?? '')) !== '' ? "模板风格：" . (string)$template['name'] . "\n风格补充：{$templatePrompt}\n" : '')
        . ($maskInfoText !== '' ? "\n模板信息：\n{$maskInfoText}\n" : '')
        . "\n请结合你看到的二值模板图，生成一套统一视觉的模板设计创意和对应绘图提示词。";

    $lastError = '';
    for ($i = 1; $i <= GPT_RETRY_TIMES; $i++) {
        try {
            $res = callGptRaw($system, $user, $maskImages);
            $parsed = extractFirstJsonObject((string)$res['content']);
            if (!is_array($parsed)) {
                throw new RuntimeException('GPT 返回内容不是合法 JSON');
            }
            $themeTitle = trim((string)($parsed['theme_title'] ?? ''));
            $creativeSummary = trim((string)($parsed['creative_summary'] ?? ''));
            $styleKeywords = $parsed['style_keywords'] ?? [];
            $designPrompt = trim((string)($parsed['design_prompt'] ?? ''));
            if ($themeTitle === '' || $creativeSummary === '' || $designPrompt === '') {
                throw new RuntimeException('GPT 返回 JSON 字段不完整');
            }
            if (!is_array($styleKeywords)) {
                $styleKeywords = [];
            }
            if ($templatePrompt !== '') {
                $designPrompt .= ' 风格强化要求：' . $templatePrompt;
            }
            return [
                'theme_title' => $themeTitle,
                'creative_summary' => $creativeSummary,
                'style_keywords' => array_values(array_slice(array_map(static fn($v): string => trim((string)$v), $styleKeywords), 0, 8)),
                'design_prompt' => $designPrompt,
                'raw_response' => (string)$res['content'],
            ];
        } catch (Throwable $e) {
            $lastError = $e->getMessage();
        }
    }

    $fallback = buildDesignIdeaFallback($theme, $maskInfoText, $variationHint, $templatePrompt);
    $fallback['raw_response'] = 'GPT 失败后使用兜底创意：' . $lastError;
    return $fallback;
}

function buildBenanaPromptWithMask(string $basePrompt, string $partLabel, string $maskFilePath): string
{
    [$maskW, $maskH] = getImageSizeSafe($maskFilePath);
    $rules = [];
    $rules[] = '你会同时收到一张“' . $partLabel . '”二值模板参考图。';
    $rules[] = '必须严格按照这张模板进行设计。';
    $rules[] = '白色区域是允许承载图案的设计区域，黑色区域是不可编辑区域、孔位、镂空区域或模板外部背景。';
    $rules[] = '绝对不允许更改模板的外轮廓、孔位、黑色区域的位置、大小、形状、数量和相对关系。';
    $rules[] = '绝对不允许把黑色区域填上，不允许缩放、拉伸、旋转、偏移模板，不允许自行重绘模板结构。';
    $rules[] = '输出图必须与模板尺寸完全一致，目标尺寸为 ' . $maskW . 'x' . $maskH . ' 像素。';
    $rules[] = '图案需要严格贴合模板白色区域来完成设计，不能越界，不能新增边框，不能改变模板结构。';
    $rules[] = '只生成纯二维平面贴图设计，不要产品实物，不要产品摄影，不要透视，不要 mockup。';
    return implode("\n", $rules) . "\n\n设计内容要求如下：\n" . trim($basePrompt);
}

function buildBenanaModerationSafePrompt(string $basePrompt, string $partLabel, string $maskFilePath): string
{
    [$maskW, $maskH] = getImageSizeSafe($maskFilePath);
    $rules = [];
    $rules[] = '你会同时收到一张“' . $partLabel . '”二值模板参考图。';
    $rules[] = '必须严格按照这张模板进行设计。';
    $rules[] = '白色区域是允许承载图案的设计区域，黑色区域是不可编辑区域、孔位、镂空区域或模板外部背景。';
    $rules[] = '绝对不允许更改模板的外轮廓、孔位、黑色区域的位置、大小、形状、数量和相对关系。';
    $rules[] = '输出图必须与模板尺寸完全一致，目标尺寸为 ' . $maskW . 'x' . $maskH . ' 像素。';
    $rules[] = '只生成纯二维平面贴图设计。';
    $rules[] = '为了避免审核拦截，请优先使用模板排版、主题图形、徽章、花边、图标、符号、几何元素、纹样、场景拼贴、剪影和装饰构图。';
    $rules[] = '减少单一角色超近距离脸部特写，减少写实人像感，减少裸露、性感、暧昧、身体强调和过强写真感。';
    $rules[] = '更偏平面设计、拼贴排版、图形装饰和主题化视觉，而不是单人肖像。';
    $rules[] = '不要产品实物，不要产品摄影，不要透视，不要 mockup，不要水印。';
    return implode("\n", $rules) . "\n\n设计内容要求如下：\n" . trim($basePrompt);
}

function getBenanaApiKey(): string
{
    $env1 = trim((string)getenv('BENANA_API_KEY'));
    if ($env1 !== '') {
        return $env1;
    }
    $env2 = trim((string)getenv('NANO_BANANA_API_KEY'));
    if ($env2 !== '') {
        return $env2;
    }
    return trim(BENANA_API_KEY);
}

function benanaPostJson(string $url, array $payload, int $timeout = BENANA_TIMEOUT): array
{
    $apiKey = getBenanaApiKey();
    if ($apiKey === '') {
        throw new RuntimeException('未配置 Benana API Key');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => BENANA_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        throw new RuntimeException('Benana 接口请求失败：' . $error);
    }
    if (!is_string($resp) || trim($resp) === '') {
        throw new RuntimeException('Benana 接口返回为空');
    }
    $json = json_decode($resp, true);
    if (!is_array($json)) {
        throw new RuntimeException('Benana 接口返回不是合法 JSON');
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        $msg = (string)($json['msg'] ?? ('HTTP ' . $httpCode));
        throw new RuntimeException('Benana 接口 HTTP 异常：' . $msg);
    }
    return ['raw' => $resp, 'json' => $json];
}

function benanaSubmitTask(string $prompt, array $referenceUrls = []): string
{
    $payload = [
        'model' => BENANA_MODEL,
        'prompt' => $prompt,
        'aspectRatio' => BENANA_ASPECT_RATIO,
        'imageSize' => BENANA_IMAGE_SIZE,
        'webHook' => '-1',
        'shutProgress' => BENANA_SHUT_PROGRESS,
    ];
    $referenceUrls = array_values(array_filter(array_map(static fn($v): string => trim((string)$v), $referenceUrls), static fn(string $v): bool => $v !== ''));
    if (!empty($referenceUrls)) {
        $payload['urls'] = $referenceUrls;
    }

    $res = benanaPostJson(BENANA_DRAW_URL, $payload, BENANA_TIMEOUT);
    $json = $res['json'];
    $code = (int)($json['code'] ?? 0);
    if ($code !== 0) {
        throw new RuntimeException('Benana 提交任务失败：' . (string)($json['msg'] ?? '未知错误'));
    }
    $taskId = trim((string)($json['data']['id'] ?? ''));
    if ($taskId === '') {
        throw new RuntimeException('Benana 未返回任务 ID');
    }
    return $taskId;
}

function benanaPollTask(string $taskId, ?callable $onProgress = null): array
{
    for ($i = 1; $i <= BENANA_MAX_POLLS; $i++) {
        $res = benanaPostJson(BENANA_RESULT_URL, ['id' => $taskId], 90);
        $json = $res['json'];
        $code = (int)($json['code'] ?? 0);
        if ($code === -22) {
            if ($onProgress) {
                $onProgress(0, 'waiting');
            }
            usleep(BENANA_POLL_INTERVAL_US);
            continue;
        }
        if ($code !== 0) {
            throw new RuntimeException('Benana 结果接口失败：' . (string)($json['msg'] ?? '未知错误'));
        }
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $status = strtolower(trim((string)($data['status'] ?? 'running')));
        $progress = (int)($data['progress'] ?? 0);
        if ($onProgress) {
            $onProgress($progress, $status);
        }
        if ($status === 'succeeded') {
            $url = trim((string)($data['results'][0]['url'] ?? ''));
            if ($url === '') {
                throw new RuntimeException('Benana 生成成功，但未返回图片地址');
            }
            return [
                'image_url' => $url,
                'content' => (string)($data['results'][0]['content'] ?? ''),
                'raw_json' => $json,
            ];
        }
        if ($status === 'failed') {
            $failureReason = trim((string)($data['failure_reason'] ?? ''));
            $errorText = trim((string)($data['error'] ?? ''));
            $msg = 'Benana 生图失败';
            if ($failureReason !== '') {
                $msg .= '：' . $failureReason;
            }
            if ($errorText !== '') {
                $msg .= '（' . $errorText . '）';
            }
            throw new RuntimeException($msg);
        }
        usleep(BENANA_POLL_INTERVAL_US);
    }
    throw new RuntimeException('Benana 生图超时，请稍后重试');
}

function generateBenanaImageToPng(string $prompt, string $maskFilePath, string $partLabel, string $savePath, ?callable $onProgress = null): array
{
    $maskDataUrl = localImageToDataUrl($maskFilePath);
    $attempts = [
        ['kind' => 'normal', 'prompt' => buildBenanaPromptWithMask($prompt, $partLabel, $maskFilePath)],
        ['kind' => 'moderation_safe', 'prompt' => buildBenanaModerationSafePrompt($prompt, $partLabel, $maskFilePath)],
    ];

    foreach ($attempts as $attemptIndex => $attempt) {
        try {
            $taskId = benanaSubmitTask($attempt['prompt'], [$maskDataUrl]);
            $result = benanaPollTask($taskId, $onProgress);
            downloadRemoteImageToPng((string)$result['image_url'], $savePath);
            [$maskW, $maskH] = getImageSizeSafe($maskFilePath);
            normalizePngFileToSize($savePath, $maskW, $maskH);
            return [
                'task_id' => $taskId,
                'image_url' => (string)$result['image_url'],
                'content' => (string)($result['content'] ?? ''),
                'raw_json' => $result['raw_json'] ?? [],
                'final_prompt' => $attempt['prompt'],
                'prompt_mode' => $attempt['kind'],
                'mask_width' => $maskW,
                'mask_height' => $maskH,
            ];
        } catch (Throwable $e) {
            $isOutputModeration = stripos($e->getMessage(), 'output_moderation') !== false;
            $isLastAttempt = $attemptIndex >= count($attempts) - 1;
            if (!$isOutputModeration || $isLastAttempt) {
                throw $e;
            }
        }
    }
    throw new RuntimeException('Benana 生图失败');
}

function saveIdeaFiles(string $orderId, int $setIndex, array $idea): array
{
    $ideaJsonPath = orderFilePath($orderId, 'set' . $setIndex . '_idea.json');
    $ideaTxtPath = orderFilePath($orderId, 'set' . $setIndex . '_idea.txt');
    atomicWriteJson($ideaJsonPath, $idea);
    $ideaText = "主题标题：" . (string)($idea['theme_title'] ?? '') . "\n\n"
        . "构图说明：\n" . (string)($idea['creative_summary'] ?? '') . "\n\n"
        . "风格关键词：\n" . implode(' / ', is_array($idea['style_keywords'] ?? null) ? $idea['style_keywords'] : []) . "\n\n"
        . "[设计 Prompt]\n" . (string)($idea['design_prompt'] ?? '') . "\n";
    file_put_contents($ideaTxtPath, $ideaText);
    return [$ideaJsonPath, $ideaTxtPath];
}

function getSetProgressRange(int $setIndex, int $designCount): array
{
    $start = 8 + (int)floor((($setIndex - 1) * 84) / $designCount);
    $end = 8 + (int)floor(($setIndex * 84) / $designCount) - 1;
    if ($setIndex === $designCount) {
        $end = 92;
    }
    if ($end <= $start) {
        $end = $start + 1;
    }
    return [$start, $end];
}

function updateProgressWithinRange(string $orderId, string $stepKey, string $labelPrefix, int $base, int $span, int $innerProgress): void
{
    $innerProgress = max(0, min(100, $innerProgress));
    $orderProgress = $base + (int)floor($span * ($innerProgress / 100));
    updateOrderMetaSafe($orderId, static function (array &$meta) use ($stepKey, $labelPrefix, $orderProgress, $innerProgress): void {
        markOrderRunning($meta, $stepKey, $labelPrefix . '（' . $innerProgress . '%）', $orderProgress);
    });
}

function refundRemainingOrderCredits(string $orderId, array &$meta, string $reason): int
{
    $remaining = max(0, (int)($meta['reserved_credits'] ?? 0) - (int)($meta['spent_credits'] ?? 0) - (int)($meta['refund_credits'] ?? 0));
    if ($remaining <= 0) {
        return 0;
    }
    adjustUserCredits((string)$meta['user_id'], $remaining, 'order_refund', $reason, ['order_id' => $orderId]);
    $meta['refund_credits'] = (int)($meta['refund_credits'] ?? 0) + $remaining;
    return $remaining;
}

/**
 * 专门用于"版权角色被拒"的异常类型：命中即立刻终止，不再重试。
 */
class CopyrightRefusalException extends RuntimeException {}

/**
 * 检查错误信息是否命中"版权/人物图像被拒"的典型回执文案。
 * 兼容繁体、简体和各种常见变体。
 */
function isCopyrightRefusalMessage(string $message): bool
{
    if ($message === '') {
        return false;
    }
    $keywords = [
        // 用户反馈的原始繁体文案（关键片段）
        '我可以幫你處理很多人物圖像，但這張不行',
        '我可以幫你處理很多人物圖像',
        '要不要換一張試試看',
        // 简体对应版本
        '我可以帮你处理很多人物图像，但这张不行',
        '我可以帮你处理很多人物图像',
        '要不要换一张试试看',
    ];
    foreach ($keywords as $kw) {
        if (mb_strpos($message, $kw, 0, 'UTF-8') !== false) {
            return true;
        }
    }
    return false;
}

/**
 * 带自动重试的步骤执行器。
 *  - 普通异常：最多重试 STEP_MAX_RETRIES 次
 *  - 命中版权拒绝文案：立即抛 CopyrightRefusalException，不再重试
 *  - $onRetry(attempt, exception) 回调用于更新 UI 进度文案
 */
function executeStepWithRetry(
    callable $operation,
    string $stepName,
    ?callable $onRetry = null,
    int $maxAttempts = STEP_MAX_RETRIES
): mixed {
    $lastError = null;
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            return $operation($attempt);
        } catch (CopyrightRefusalException $e) {
            // 版权问题：立刻抛出，不再重试
            throw $e;
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            // 任意一次错误命中拒绝文案 → 转为 CopyrightRefusalException 立即抛出
            if (isCopyrightRefusalMessage($msg)) {
                throw new CopyrightRefusalException($msg);
            }
            $lastError = $e;
            if ($attempt < $maxAttempts) {
                if ($onRetry) {
                    try { $onRetry($attempt, $e); } catch (Throwable) { /* 忽略回调异常 */ }
                }
                usleep(STEP_RETRY_DELAY_US);
            }
        }
    }
    throw new RuntimeException(
        $stepName . '（已自动重试 ' . $maxAttempts . ' 次仍失败）：'
        . ($lastError ? $lastError->getMessage() : '未知错误')
    );
}

function processSingleOrder(string $orderId): void
{
    ignore_user_abort(true);
    set_time_limit(0);

    $meta = readOrderMeta($orderId);
    if ((string)($meta['status'] ?? '') !== 'running') {
        return;
    }

    try {
        $theme = trim((string)($meta['theme'] ?? ''));
        $designCount = max(MIN_DESIGN_SET_COUNT, min(MAX_DESIGN_SET_COUNT, (int)($meta['design_count'] ?? 1)));
        $maskPath = orderFilePath($orderId, 'order_mask.png');
        $template = getTemplateById((string)($meta['template_id'] ?? 'premium_brand'));
        if ($theme === '') {
            throw new RuntimeException('主题不能为空');
        }
        if (!is_file($maskPath)) {
            throw new RuntimeException('订单模板不存在');
        }

        $variationHints = getVariationHints();
        for ($setIndex = 1; $setIndex <= $designCount; $setIndex++) {
            $stepKey = 'set' . $setIndex;
            [$setStart, $setEnd] = getSetProgressRange($setIndex, $designCount);
            $variationHint = (string)($variationHints[$setIndex] ?? '');
            $ideaProgress = $setStart;
            $benanaBase = min($setStart + 3, $setEnd - 3);
            if ($benanaBase <= $setStart) {
                $benanaBase = $setStart + 1;
            }
            $benanaSpan = max(4, $setEnd - $benanaBase);

            updateOrderMetaSafe($orderId, static function (array &$m) use ($stepKey, $setIndex, $ideaProgress): void {
                markOrderRunning($m, $stepKey, '正在生成第 ' . $setIndex . ' 张设计稿：构图创意', $ideaProgress);
            });

            // —— 步骤 A：GPT 构图创意（带自动重试）——
            $idea = executeStepWithRetry(
                static function () use ($theme, $maskPath, $variationHint, $template): array {
                    return callGptDesignIdea($theme, [
                        ['label' => '用户选择的二值模板', 'path' => $maskPath],
                    ], $variationHint, $template);
                },
                '第 ' . $setIndex . ' 张设计稿·构图创意',
                static function (int $attempt, Throwable $e) use ($orderId, $stepKey, $setIndex, $ideaProgress): void {
                    $next = $attempt + 1;
                    updateOrderMetaSafe($orderId, static function (array &$m) use ($stepKey, $setIndex, $next, $ideaProgress): void {
                        markOrderRunning(
                            $m,
                            $stepKey,
                            '第 ' . $setIndex . ' 张设计稿构图创意失败，自动重试中（第 ' . $next . '/' . STEP_MAX_RETRIES . ' 次）',
                            $ideaProgress
                        );
                    });
                }
            );

            [$ideaJsonPath, $ideaTxtPath] = saveIdeaFiles($orderId, $setIndex, $idea);

            updateOrderMetaSafe($orderId, static function (array &$m) use ($setIndex, $ideaJsonPath, $ideaTxtPath, $stepKey, $benanaBase): void {
                $m['files']['set' . $setIndex . '_idea_json'] = basename($ideaJsonPath);
                $m['files']['set' . $setIndex . '_idea_txt'] = basename($ideaTxtPath);
                markOrderRunning($m, $stepKey, '正在生成第 ' . $setIndex . ' 张设计稿：图像生成', $benanaBase);
            });

            $rawPath = orderFilePath($orderId, 'set' . $setIndex . '_raw.png');
            $maskedPath = orderFilePath($orderId, 'set' . $setIndex . '_masked.png');

            // —— 步骤 B：Benana 图像生成（带自动重试）——
            executeStepWithRetry(
                static function () use ($idea, $maskPath, $rawPath, $orderId, $stepKey, $setIndex, $benanaBase, $benanaSpan): array {
                    return generateBenanaImageToPng(
                        (string)$idea['design_prompt'],
                        $maskPath,
                        '设计区域',
                        $rawPath,
                        static function (int $p) use ($orderId, $stepKey, $setIndex, $benanaBase, $benanaSpan): void {
                            updateProgressWithinRange(
                                $orderId,
                                $stepKey,
                                '正在生成第 ' . $setIndex . ' 张设计稿：图像生成',
                                $benanaBase,
                                $benanaSpan,
                                $p
                            );
                        }
                    );
                },
                '第 ' . $setIndex . ' 张设计稿·图像生成',
                static function (int $attempt, Throwable $e) use ($orderId, $stepKey, $setIndex, $benanaBase): void {
                    $next = $attempt + 1;
                    updateOrderMetaSafe($orderId, static function (array &$m) use ($stepKey, $setIndex, $next, $benanaBase): void {
                        markOrderRunning(
                            $m,
                            $stepKey,
                            '第 ' . $setIndex . ' 张设计稿图像生成失败，自动重试中（第 ' . $next . '/' . STEP_MAX_RETRIES . ' 次）',
                            $benanaBase
                        );
                    });
                }
            );

            // —— 步骤 C：蒙版合成（带自动重试，纯本地操作通常不会失败，但加上更稳）——
            executeStepWithRetry(
                static function () use ($rawPath, $maskPath, $maskedPath): bool {
                    applyMaskFile($rawPath, $maskPath, $maskedPath);
                    return true;
                },
                '第 ' . $setIndex . ' 张设计稿·蒙版合成',
                static function (int $attempt, Throwable $e) use ($orderId, $stepKey, $setIndex, $setEnd): void {
                    $next = $attempt + 1;
                    updateOrderMetaSafe($orderId, static function (array &$m) use ($stepKey, $setIndex, $next, $setEnd): void {
                        markOrderRunning(
                            $m,
                            $stepKey,
                            '第 ' . $setIndex . ' 张设计稿蒙版合成失败，自动重试中（第 ' . $next . '/' . STEP_MAX_RETRIES . ' 次）',
                            max(1, $setEnd - 1)
                        );
                    });
                }
            );

            updateOrderMetaSafe($orderId, static function (array &$m) use ($setIndex, $rawPath, $maskedPath, $stepKey, $setEnd): void {
                $m['files']['set' . $setIndex . '_raw'] = basename($rawPath);
                $m['files']['set' . $setIndex . '_masked'] = basename($maskedPath);
                $m['generated_count'] = max((int)($m['generated_count'] ?? 0), $setIndex);
                $m['spent_credits'] = max((int)($m['spent_credits'] ?? 0), $setIndex * CREDIT_PER_IMAGE);
                markOrderStepDone($m, $stepKey, $setEnd);
                $m['current_step'] = '第 ' . $setIndex . ' 张设计稿已完成';
                $m['status'] = 'running';
                $m['status_text'] = '生成中';
            });
        }

        $zipPath = orderFilePath($orderId, 'design_package.zip');
        $zipFiles = [];
        if (is_file($maskPath)) {
            $zipFiles[$maskPath] = '00_订单模板.png';
        }
        for ($i = 1; $i <= $designCount; $i++) {
            $zipFiles[orderFilePath($orderId, 'set' . $i . '_masked.png')] = '设计稿' . $i . '/01_设计成品.png';
            $zipFiles[orderFilePath($orderId, 'set' . $i . '_idea.json')] = '设计稿' . $i . '/02_设计思路.json';
            $zipFiles[orderFilePath($orderId, 'set' . $i . '_idea.txt')] = '设计稿' . $i . '/03_设计思路.txt';
        }
        $zipOk = createZipFile($zipPath, $zipFiles, [
            'README.txt' => "本压缩包由 " . COMPANY_NAME . " 自动生成。\n\n"
                . "1. 本次使用的是用户选择的二值模板，白色区域为可编辑区域，黑色区域为不可编辑区域。\n"
                . "2. 本次共生成 {$designCount} 张设计稿。\n"
                . "3. 每份都包含：设计成品、设计思路 JSON、设计思路 TXT。\n",
        ]);

        updateOrderMetaSafe($orderId, static function (array &$m) use ($zipOk, $zipPath): void {
            markOrderRunning($m, 'finish', '正在整理结果文件', 99);
            if ($zipOk) {
                $m['files']['package_zip'] = basename($zipPath);
            }
            markOrderDone($m);
        });

        $doneMeta = readOrderMeta($orderId);
        adjustUserCredits((string)$doneMeta['user_id'], 0, 'order_complete', '订单已完成', [
            'order_id' => $orderId,
            'generated' => (int)($doneMeta['generated_count'] ?? 0),
        ]);
    } catch (Throwable $e) {
        $isCopyright = ($e instanceof CopyrightRefusalException) || isCopyrightRefusalMessage($e->getMessage());
        $friendlyMessage = $isCopyright
            ? '该订单涉及可能受版权保护的人物角色，AI 已拒绝生成，订单无法完成。请修改主题后重新创建订单，避免使用知名 IP、动漫、影视、游戏、明星等受版权保护的人物角色。（原始回执：' . $e->getMessage() . '）'
            : $e->getMessage();

        updateOrderMetaSafe($orderId, static function (array &$m) use ($orderId, $friendlyMessage, $isCopyright): void {
            refundRemainingOrderCredits(
                $orderId,
                $m,
                $isCopyright ? '订单涉及版权角色被拒，自动退款' : '订单异常退款'
            );
            markOrderError($m, $friendlyMessage);
        });
    }
}

function tryPickNextQueuedOrder(): ?string
{
    return withFileLock(queueLockPath(), static function () {
        $orders = listAllOrders();
        $runningCount = 0;
        foreach ($orders as $row) {
            if ((string)($row['status'] ?? '') === 'running') {
                $runningCount++;
            }
        }
        if ($runningCount >= MAX_CONCURRENT_ORDERS) {
            return null;
        }
        $queued = array_values(array_filter($orders, static fn(array $row): bool => (string)($row['status'] ?? '') === 'queued'));
        if (!$queued) {
            return null;
        }
        usort($queued, static fn(array $a, array $b): int => (int)($a['created_ts'] ?? 0) <=> (int)($b['created_ts'] ?? 0));
        $targetId = (string)$queued[0]['order_id'];
        updateOrderMetaSafe($targetId, static function (array &$meta): void {
            $meta['status'] = 'running';
            $meta['status_text'] = '生成中';
            $meta['current_step'] = '排队结束，准备开始';
            $meta['progress'] = max(6, (int)($meta['progress'] ?? 0));
            $meta['started_at'] = $meta['started_at'] ?: nowIso();
            $meta['started_ts'] = $meta['started_ts'] ?? nowTs();
        });
        return $targetId;
    });
}

function runQueueWorkerLoop(int $maxOrders = ORDER_WORKER_MAX_PER_LOOP): array
{
    cleanupExpiredOrdersIfNeeded(false);
    $processed = [];
    $count = 0;
    while ($count < $maxOrders) {
        $next = tryPickNextQueuedOrder();
        if (!$next) {
            break;
        }
        processSingleOrder($next);
        $processed[] = $next;
        $count++;
    }
    return ['processed' => $processed, 'running' => !empty($processed)];
}

function cleanupExpiredOrdersIfNeeded(bool $force = false): void
{
    $meta = readJsonFile(cleanupMetaPath(), ['last_run_ts' => 0]);
    $lastRun = (int)($meta['last_run_ts'] ?? 0);
    if (!$force && nowTs() - $lastRun < CLEANUP_MIN_INTERVAL) {
        return;
    }

    withFileLock(systemLockPath(), static function () use ($force) {
        $meta = readJsonFile(cleanupMetaPath(), ['last_run_ts' => 0]);
        $lastRun = (int)($meta['last_run_ts'] ?? 0);
        if (!$force && nowTs() - $lastRun < CLEANUP_MIN_INTERVAL) {
            return;
        }

        $threshold = nowTs() - ORDER_RETENTION_SECONDS;
        foreach (listAllOrders() as $row) {
            $updatedTs = (int)($row['updated_ts'] ?? 0);
            if ($updatedTs <= 0 || $updatedTs >= $threshold) {
                continue;
            }
            $orderId = (string)$row['order_id'];
            withOrderLock($orderId, static function () use ($orderId, $threshold): void {
                $meta = readOrderMeta($orderId);
                $updatedTs = (int)($meta['updated_ts'] ?? 0);
                if ($updatedTs <= 0 || $updatedTs >= $threshold) {
                    return;
                }
                if (in_array((string)($meta['status'] ?? ''), ['queued', 'running'], true)) {
                    refundRemainingOrderCredits($orderId, $meta, '超时订单自动清理退款');
                    $meta['status'] = 'cancelled';
                    $meta['status_text'] = '已清理';
                    $meta['current_step'] = '已清理';
                    $meta['finished_at'] = nowIso();
                    saveOrderMeta($meta);
                }
                removeDirRecursive(orderDir($orderId));
            });
        }

        atomicWriteJson(cleanupMetaPath(), [
            'last_run_ts' => nowTs(),
            'last_run_at' => nowIso(),
        ]);
    });
}

function computeSystemStatus(): array
{
    $orders = listAllOrders();
    $running = 0;
    $queued = 0;
    $completed = [];
    $errored = [];
    $durations = [];

    foreach ($orders as $row) {
        $status = (string)($row['status'] ?? '');
        if ($status === 'running') {
            $running++;
        } elseif ($status === 'queued') {
            $queued++;
        }
        if (in_array($status, ['done', 'error'], true)) {
            $finishedTs = !empty($row['finished_at']) ? ((int)strtotime((string)$row['finished_at']) ?: (int)($row['updated_ts'] ?? 0)) : (int)($row['updated_ts'] ?? 0);
            if ($status === 'done') {
                $completed[] = ['ts' => $finishedTs, 'meta' => $row];
                $startedTs = (int)($row['started_ts'] ?? 0);
                if ($startedTs <= 0 && !empty($row['started_at'])) {
                    $startedTs = (int)strtotime((string)$row['started_at']) ?: 0;
                }
                if ($startedTs > 0 && $finishedTs > $startedTs) {
                    $designCount = max(1, (int)($row['design_count'] ?? 1));
                    $durations[] = ($finishedTs - $startedTs) / $designCount;
                }
            } else {
                $errored[] = ['ts' => $finishedTs, 'meta' => $row];
            }
        }
    }

    usort($completed, static fn(array $a, array $b): int => $b['ts'] <=> $a['ts']);
    usort($errored, static fn(array $a, array $b): int => $b['ts'] <=> $a['ts']);
    $recentDone = array_slice($completed, 0, STATS_RECENT_WINDOW);
    $recentErr = array_slice($errored, 0, STATS_RECENT_WINDOW);
    $totalRecent = count($recentDone) + count($recentErr);
    $successRate = $totalRecent > 0 ? round(count($recentDone) * 100 / $totalRecent, 1) : 99.0;

    if (!empty($durations)) {
        $recentDur = array_slice($durations, 0, STATS_RECENT_WINDOW);
        $avgPerImage = (int)round(array_sum($recentDur) / count($recentDur));
    } else {
        $avgPerImage = ESTIMATED_SECONDS_PER_IMAGE;
    }

    if ($successRate >= 95) {
        $level = 'excellent';
        $levelText = '运行流畅';
    } elseif ($successRate >= 85) {
        $level = 'good';
        $levelText = '运行良好';
    } elseif ($successRate >= 70) {
        $level = 'fair';
        $levelText = '略有波动';
    } else {
        $level = 'unstable';
        $levelText = '当前繁忙';
    }

    return [
        'running_orders' => $running,
        'queued_orders' => $queued,
        'max_concurrent' => MAX_CONCURRENT_ORDERS,
        'success_rate' => $successRate,
        'recent_window' => $totalRecent,
        'avg_seconds_per_image' => max(40, $avgPerImage),
        'level' => $level,
        'level_text' => $levelText,
    ];
}

function estimateOrderWaitSeconds(int $designCount, array $status): int
{
    $perImage = max(40, (int)($status['avg_seconds_per_image'] ?? ESTIMATED_SECONDS_PER_IMAGE));
    $own = $designCount * $perImage;

    $running = (int)($status['running_orders'] ?? 0);
    $queued = (int)($status['queued_orders'] ?? 0);
    $maxConcurrent = max(1, (int)($status['max_concurrent'] ?? MAX_CONCURRENT_ORDERS));

    $effectiveQueue = $running + $queued;
    if ($effectiveQueue <= $maxConcurrent) {
        $waitBefore = 0;
    } else {
        $waitBefore = (int)ceil(($effectiveQueue - $maxConcurrent + 1) / $maxConcurrent) * $perImage * 2;
    }
    return $own + $waitBefore;
}

function detectMimeByExtension(string $filename): string
{
    $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    return match ($ext) {
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'json' => 'application/json; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'zip' => 'application/zip',
        default => 'application/octet-stream',
    };
}

function handleBootstrap(): never
{
    cleanupExpiredOrdersIfNeeded();
    $user = getCurrentUser();
    $templates = array_values(templateCatalog());
    $systemStatus = computeSystemStatus();
    $payload = [
        'ok' => true,
        'csrf_token' => getCsrfToken(),
        'config' => [
            'app_name' => APP_NAME,
            'company_name' => COMPANY_NAME,
            'app_tagline' => APP_TAGLINE,
            'app_slogan' => APP_SLOGAN,
            'contact_email' => CONTACT_EMAIL,
            'card_purchase_url' => CARD_PURCHASE_URL,
            'credit_per_image' => CREDIT_PER_IMAGE,
            'start_credits' => START_CREDITS,
            'max_concurrent_orders' => MAX_CONCURRENT_ORDERS,
            'templates' => $templates,
        ],
        'system_status' => $systemStatus,
        'user' => null,
        'masks' => [],
        'orders' => [],
    ];

    if ($user) {
        $payload['user'] = publicUserProfile($user);
        $payload['masks'] = array_map('buildMaskPublic', listMasksByUser((string)$user['user_id']));
        $payload['orders'] = array_map('buildOrderPublic', listOrdersByUser((string)$user['user_id']));
    }
    jsonResponse($payload);
}

function handleSendMobileCode(): never
{
    requireCsrf();
    try {
        $mobile = normalizeMobile((string)($_POST['mobile'] ?? ''));
        $purpose = trim((string)($_POST['purpose'] ?? 'bind'));
        $purpose = $purpose === 'register' ? 'register' : 'bind';

        if (!isValidCnMobile($mobile)) {
            throw new RuntimeException('请输入正确的手机号');
        }

        if ($purpose === 'register') {
            $indices = loadIndices();
            if (!empty($indices['mobile_index'][$mobile])) {
                throw new RuntimeException('该手机号已注册');
            }
        } else {
            $user = requireLogin();
            if (!empty($user['mobile'])) {
                throw new RuntimeException('当前账号已绑定手机号');
            }
            $indices = loadIndices();
            if (!empty($indices['mobile_index'][$mobile])) {
                throw new RuntimeException('该手机号已被其他账号绑定');
            }
        }

        $bucket = $_SESSION['mobile_verify'][$purpose] ?? null;
        if (is_array($bucket) && (int)($bucket['last_send_time'] ?? 0) + SMS_SEND_COOLDOWN_SECONDS > nowTs()) {
            throw new RuntimeException('发送过于频繁，请稍后再试');
        }

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $smsResult = sendSmsByProvider($mobile, $code, $purpose);
        if (empty($smsResult['ok'])) {
            throw new RuntimeException((string)($smsResult['msg'] ?? '短信发送失败，请稍后再试'));
        }

        $_SESSION['mobile_verify'][$purpose] = [
            'mobile' => $mobile,
            'code' => $code,
            'expire_time' => nowTs() + SMS_CODE_EXPIRE_SECONDS,
            'last_send_time' => nowTs(),
        ];

        jsonResponse(['ok' => true, 'code' => 0, 'msg' => '验证码发送成功', 'data' => []]);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'code' => 1, 'msg' => $e->getMessage(), 'data' => [], 'message' => $e->getMessage()], 400);
    }
}

function handleRegister(): never
{
    requireCsrf();
    try {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $mobile = normalizeMobile((string)($_POST['mobile'] ?? ''));
        $smsCode = trim((string)($_POST['sms_code'] ?? ''));
        $inviterUsername = trim((string)($_POST['inviter_username'] ?? ''));

        if ($username === '' || $password === '' || $mobile === '' || $smsCode === '') {
            throw new RuntimeException('请完整填写注册信息');
        }
        if (!isValidCnMobile($mobile)) {
            throw new RuntimeException('请输入正确的手机号');
        }
        $indices = loadIndices();
        if (!empty($indices['mobile_index'][$mobile])) {
            throw new RuntimeException('该手机号已注册');
        }

        verify_mobile_code($mobile, $smsCode, 'register');
        $user = createUser($username, $mobile, $password, $inviterUsername !== '' ? $inviterUsername : null);
        $user = setUserLoggedIn((string)$user['user_id']);
        jsonResponse([
            'ok' => true,
            'message' => '注册成功',
            'token' => (string)($user['remember_token_plain'] ?? ''),
            'user' => publicUserProfile($user),
        ]);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

function handleLogin(): never
{
    requireCsrf();
    try {
        $mobile = normalizeMobile((string)($_POST['mobile'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if (!isValidCnMobile($mobile)) {
            throw new RuntimeException('请输入正确的手机号');
        }
        if ($password === '') {
            throw new RuntimeException('请输入密码');
        }

        $indices = loadIndices();
        $userId = trim((string)($indices['mobile_index'][$mobile] ?? ''));
        if ($userId === '') {
            throw new RuntimeException('账号不存在');
        }
        $user = readUser($userId);
        if (!password_verify($password, (string)($user['password_hash'] ?? ''))) {
            throw new RuntimeException('手机号或密码错误');
        }
        $user = setUserLoggedIn($userId);
        jsonResponse([
            'ok' => true,
            'message' => '登录成功',
            'token' => (string)($user['remember_token_plain'] ?? ''),
            'user' => publicUserProfile($user),
        ]);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

function handleRestoreLogin(): never
{
    requireCsrf();
    try {
        $token = trim((string)($_POST['token'] ?? ''));
        if ($token === '') {
            throw new RuntimeException('缺少登录凭证');
        }
        $hash = hash('sha256', $token);
        $indices = loadIndices();
        $userId = trim((string)($indices['auth_index'][$hash] ?? ''));
        if ($userId === '') {
            throw new RuntimeException('登录状态已失效');
        }
        $user = readUser($userId);
        if (!hash_equals((string)($user['remember_token_hash'] ?? ''), $hash)) {
            throw new RuntimeException('登录状态已失效');
        }
        $_SESSION['user_id'] = $userId;
        setAppCookie(AUTH_COOKIE_NAME, $token, nowTs() + AUTH_COOKIE_DAYS * 86400);
        withUserLock($userId, static function () use ($userId): void {
            $u = readUser($userId);
            $u['last_login_at'] = nowIso();
            $u['last_login_ip'] = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            saveUser($u);
        });
        jsonResponse(['ok' => true, 'message' => '已恢复登录']);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'message' => $e->getMessage()], 401);
    }
}

function handleLogout(): never
{
    requireCsrf();
    $user = getCurrentUser();
    clearRememberToken($user['user_id'] ?? null);
    jsonResponse(['ok' => true, 'message' => '已退出登录']);
}

function handleBindMobile(): never
{
    requireCsrf();
    $user = requireLogin();
    try {
        $mobile = normalizeMobile((string)($_POST['mobile'] ?? ''));
        $code = trim((string)($_POST['code'] ?? ''));
        if (!empty($user['mobile'])) {
            throw new RuntimeException('当前账号已绑定手机号');
        }
        if (!isValidCnMobile($mobile)) {
            throw new RuntimeException('请输入正确的手机号');
        }
        $indices = loadIndices();
        if (!empty($indices['mobile_index'][$mobile])) {
            throw new RuntimeException('该手机号已被其他账号绑定');
        }
        verify_mobile_code($mobile, $code, 'bind');

        withFileLock(systemLockPath(), static function () use ($user, $mobile): void {
            $indices = loadIndices();
            if (!empty($indices['mobile_index'][$mobile])) {
                throw new RuntimeException('该手机号已被其他账号绑定');
            }
            $u = readUser((string)$user['user_id']);
            $u['mobile'] = $mobile;
            saveUser($u);
            $indices['mobile_index'][$mobile] = (string)$user['user_id'];
            saveIndices($indices);
        });

        jsonResponse(['ok' => true, 'message' => '手机号绑定成功']);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

function handleUploadMask(): never
{
    requireCsrf();
    $user = requireLogin();
    try {
        $name = trim((string)($_POST['mask_name'] ?? ''));
        $upload = $_FILES['mask_file'] ?? null;
        if (!is_array($upload)) {
            throw new RuntimeException('请上传蒙版图片');
        }
        $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            throw new RuntimeException(match ($uploadError) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '上传图片过大',
                UPLOAD_ERR_NO_FILE => '请上传蒙版图片',
                default => '图片上传失败',
            });
        }
        $tmpPath = (string)($upload['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new RuntimeException('图片上传无效，请重新上传');
        }
        $fileSize = (int)($upload['size'] ?? 0);
        if ($fileSize <= 0) {
            throw new RuntimeException('上传文件为空');
        }
        if ($fileSize > MAX_MASK_UPLOAD_SIZE) {
            throw new RuntimeException('蒙版文件过大，请控制在 ' . (int)(MAX_MASK_UPLOAD_SIZE / 1024 / 1024) . 'MB 以内');
        }
        $polarity = trim((string)($_POST['polarity'] ?? 'auto'));
        if (!in_array($polarity, ['auto', 'white_editable', 'black_editable', 'opaque_editable'], true)) {
            $polarity = 'auto';
        }
        $meta = createMask((string)$user['user_id'], $name, $tmpPath, $polarity);
        jsonResponse(['ok' => true, 'message' => '蒙版上传成功', 'mask' => buildMaskPublic($meta)]);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

function handleRenameMask(): never
{
    requireCsrf();
    $user = requireLogin();
    try {
        $maskId = trim((string)($_POST['mask_id'] ?? ''));
        $name = trim((string)($_POST['mask_name'] ?? ''));
        if ($maskId === '' || $name === '') {
            throw new RuntimeException('参数不完整');
        }
        if (mb_strlen($name, 'UTF-8') > MAX_MASK_NAME_LENGTH) {
            throw new RuntimeException('蒙版名称过长');
        }
        withFileLock(maskLockPath((string)$user['user_id'], $maskId), static function () use ($user, $maskId, $name): void {
            $meta = readMaskMetaOwned((string)$user['user_id'], $maskId);
            $meta['name'] = $name;
            $meta['updated_at'] = nowIso();
            $meta['updated_ts'] = nowTs();
            atomicWriteJson(maskMetaPath((string)$user['user_id'], $maskId), $meta);
        });
        jsonResponse(['ok' => true, 'message' => '蒙版已重命名']);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

function handleDeleteMask(): never
{
    requireCsrf();
    $user = requireLogin();
    try {
        $maskId = trim((string)($_POST['mask_id'] ?? ''));
        if ($maskId === '') {
            throw new RuntimeException('缺少 mask_id');
        }
        readMaskMetaOwned((string)$user['user_id'], $maskId);
        @unlink(maskMetaPath((string)$user['user_id'], $maskId));
        @unlink(maskImagePath((string)$user['user_id'], $maskId));
        @unlink(maskLockPath((string)$user['user_id'], $maskId));
        jsonResponse(['ok' => true, 'message' => '蒙版已删除']);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

function handleCreateOrder(): never
{
    requireCsrf();
    $user = requireLogin();
    try {
        cleanupExpiredOrdersIfNeeded();
        $theme = trim((string)($_POST['theme'] ?? ''));
        $maskId = trim((string)($_POST['mask_id'] ?? ''));
        $templateId = trim((string)($_POST['template_id'] ?? 'premium_brand'));
        $designCount = (int)($_POST['design_count'] ?? 1);
        if ($theme === '') {
            throw new RuntimeException('请输入主题');
        }
        if (mb_strlen($theme, 'UTF-8') > MAX_THEME_LENGTH) {
            throw new RuntimeException('主题内容过长，请控制在 ' . MAX_THEME_LENGTH . ' 字以内');
        }
        if ($designCount < MIN_DESIGN_SET_COUNT || $designCount > MAX_DESIGN_SET_COUNT) {
            throw new RuntimeException('生成数量必须在 ' . MIN_DESIGN_SET_COUNT . ' 到 ' . MAX_DESIGN_SET_COUNT . ' 之间');
        }
        if ($maskId === '') {
            throw new RuntimeException('请选择蒙版');
        }
        $maskMeta = readMaskMetaOwned((string)$user['user_id'], $maskId);
        $maskPath = maskImagePath((string)$user['user_id'], $maskId);
        if (!is_file($maskPath)) {
            throw new RuntimeException('蒙版文件不存在');
        }
        $template = getTemplateById($templateId, $user);
        $costCredits = $designCount * CREDIT_PER_IMAGE;
        $orderId = randomId('ORDER');
        ensureDir(orderDir($orderId));

        $snapshotPath = orderFilePath($orderId, 'order_mask.png');
        if (!@copy($maskPath, $snapshotPath)) {
            throw new RuntimeException('创建订单失败：无法复制蒙版');
        }

        adjustUserCredits((string)$user['user_id'], -$costCredits, 'order_reserve', '创建订单预扣额度', [
            'order_id' => $orderId,
            'count' => $designCount,
        ]);

        withUserLock((string)$user['user_id'], static function () use ($user): void {
            $u = readUser((string)$user['user_id']);
            $u['total_orders'] = (int)($u['total_orders'] ?? 0) + 1;
            saveUser($u);
        });
        $latestUser = readUser((string)$user['user_id']);

        $meta = createOrderMeta($orderId, $latestUser, $maskMeta, $template, $theme, $designCount, $costCredits);
        saveOrderMeta($meta);

        $public = buildOrderPublic($meta);
        $systemStatus = computeSystemStatus();
        $estimateWait = estimateOrderWaitSeconds($designCount, $systemStatus);
        jsonResponseAndContinue([
            'ok' => true,
            'message' => '订单已创建，已进入队列',
            'order' => $public,
            'estimated_wait_seconds' => $estimateWait,
            'system_status' => $systemStatus,
        ], 200, static function (): void {
            runQueueWorkerLoop();
        });
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

function handleOrderStatus(): never
{
    $user = requireLogin();
    try {
        $orderId = trim((string)($_GET['order_id'] ?? ''));
        if ($orderId === '') {
            throw new RuntimeException('缺少 order_id');
        }
        $meta = readOrderMeta($orderId);
        if ((string)($meta['user_id'] ?? '') !== (string)$user['user_id']) {
            throw new RuntimeException('订单不存在');
        }
        jsonResponse(['ok' => true, 'order' => buildOrderPublic($meta)]);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'message' => $e->getMessage()], 404);
    }
}

function handleListOrders(): never
{
    $user = requireLogin();
    cleanupExpiredOrdersIfNeeded();
    $orders = array_map('buildOrderPublic', listOrdersByUser((string)$user['user_id']));
    jsonResponse(['ok' => true, 'orders' => $orders]);
}

function handleCancelOrder(): never
{
    requireCsrf();
    $user = requireLogin();
    try {
        $orderId = trim((string)($_POST['order_id'] ?? ''));
        if ($orderId === '') {
            throw new RuntimeException('缺少 order_id');
        }
        $meta = updateOrderMetaSafe($orderId, static function (array &$meta) use ($user, $orderId): void {
            if ((string)($meta['user_id'] ?? '') !== (string)$user['user_id']) {
                throw new RuntimeException('订单不存在');
            }
            if ((string)($meta['status'] ?? '') !== 'queued') {
                throw new RuntimeException('仅排队中的订单可取消');
            }
            refundRemainingOrderCredits($orderId, $meta, '用户取消订单退款');
            $meta['status'] = 'cancelled';
            $meta['status_text'] = '已取消';
            $meta['current_step'] = '用户已取消';
            $meta['progress'] = 100;
            $meta['finished_at'] = nowIso();
        });
        jsonResponse(['ok' => true, 'message' => '订单已取消', 'order' => buildOrderPublic($meta)]);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

function handleRedeemCard(): never
{
    requireCsrf();
    $user = requireLogin();
    try {
        $code = normalizeCardCode((string)($_POST['card_code'] ?? ''));
        if ($code === '') {
            throw new RuntimeException('请输入卡密');
        }
        $credits = withFileLock(systemLockPath(), static function () use ($code, $user): int {
            $store = readJsonFile(cardStorePath(), ['cards' => []]);
            $cards = is_array($store['cards'] ?? null) ? $store['cards'] : [];
            $found = false;
            foreach ($cards as &$card) {
                if (normalizeCardCode((string)($card['code'] ?? '')) !== $code) {
                    continue;
                }
                $found = true;
                if (!empty($card['used'])) {
                    throw new RuntimeException('该卡密已使用');
                }
                $credits = (int)($card['credits'] ?? 0);
                if ($credits <= 0) {
                    throw new RuntimeException('该卡密不可用');
                }
                $card['used'] = true;
                $card['used_by'] = (string)$user['user_id'];
                $card['used_at'] = nowIso();
                $store['cards'] = $cards;
                $store['updated_at'] = nowIso();
                $store['updated_ts'] = nowTs();
                atomicWriteJson(cardStorePath(), $store);
                return $credits;
            }
            unset($card);
            if (!$found) {
                throw new RuntimeException('卡密不存在');
            }
            return 0;
        });

        $updatedUser = adjustUserCredits((string)$user['user_id'], $credits, 'card_redeem', '卡密充值', ['card_code' => $code]);
        jsonResponse(['ok' => true, 'message' => '充值成功', 'credits' => (int)$updatedUser['credits']]);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

function handleQueueTick(): never
{
    $res = runQueueWorkerLoop();
    jsonResponse(['ok' => true, 'result' => $res]);
}

function handleCreateCustomStyle(): never
{
    requireCsrf();
    $user = requireLogin();
    try {
        $name = trim((string)($_POST['name'] ?? ''));
        $desc = trim((string)($_POST['desc'] ?? ''));
        $prompt = trim((string)($_POST['prompt'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('请输入风格名称');
        }
        if (mb_strlen($name, 'UTF-8') > 30) {
            throw new RuntimeException('风格名称请控制在 30 字以内');
        }
        if (mb_strlen($desc, 'UTF-8') > 60) {
            throw new RuntimeException('简短说明请控制在 60 字以内');
        }
        if ($prompt === '') {
            throw new RuntimeException('请输入风格描述（用于指导 AI 出图）');
        }
        if (mb_strlen($prompt, 'UTF-8') > 600) {
            throw new RuntimeException('风格描述请控制在 600 字以内');
        }

        $userId = (string)$user['user_id'];
        withUserLock($userId, static function () use ($userId, $name, $desc, $prompt): void {
            $u = readUser($userId);
            $styles = is_array($u['custom_styles'] ?? null) ? $u['custom_styles'] : [];
            if (count($styles) >= 30) {
                throw new RuntimeException('自定义风格最多保存 30 个，请先删除一些');
            }
            foreach ($styles as $st) {
                if (mb_strtolower((string)($st['name'] ?? ''), 'UTF-8') === mb_strtolower($name, 'UTF-8')) {
                    throw new RuntimeException('已经有一个同名的风格了');
                }
            }
            $styles[] = [
                'id' => 'custom_' . bin2hex(random_bytes(5)),
                'name' => $name,
                'desc' => $desc !== '' ? $desc : '我的自定义风格',
                'prompt' => $prompt,
                'created_at' => nowIso(),
                'created_ts' => nowTs(),
            ];
            $u['custom_styles'] = array_values($styles);
            saveUser($u);
        });
        $latest = readUser($userId);
        jsonResponse(['ok' => true, 'message' => '自定义风格已添加', 'user' => publicUserProfile($latest)]);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

function handleDeleteCustomStyle(): never
{
    requireCsrf();
    $user = requireLogin();
    try {
        $styleId = trim((string)($_POST['style_id'] ?? ''));
        if ($styleId === '' || !str_starts_with($styleId, 'custom_')) {
            throw new RuntimeException('参数不正确');
        }
        $userId = (string)$user['user_id'];
        withUserLock($userId, static function () use ($userId, $styleId): void {
            $u = readUser($userId);
            $styles = is_array($u['custom_styles'] ?? null) ? $u['custom_styles'] : [];
            $u['custom_styles'] = array_values(array_filter($styles, static fn(array $s): bool => ($s['id'] ?? '') !== $styleId));
            saveUser($u);
        });
        $latest = readUser($userId);
        jsonResponse(['ok' => true, 'message' => '自定义风格已删除', 'user' => publicUserProfile($latest)]);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

function handleMaskFile(): never
{
    $user = requireLogin();
    $maskId = trim((string)($_GET['mask_id'] ?? ''));
    if ($maskId === '') {
        http_response_code(400);
        exit('bad request');
    }
    try {
        readMaskMetaOwned((string)$user['user_id'], $maskId);
        $path = maskImagePath((string)$user['user_id'], $maskId);
        if (!is_file($path)) {
            throw new RuntimeException('文件不存在');
        }
        header('Content-Type: image/png');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        readfile($path);
        exit;
    } catch (Throwable) {
        http_response_code(404);
        exit('not found');
    }
}

function handleOrderFile(): never
{
    $user = requireLogin();
    $orderId = trim((string)($_GET['order_id'] ?? ''));
    $key = trim((string)($_GET['key'] ?? ''));
    $download = ((string)($_GET['download'] ?? '') === '1');
    if ($orderId === '' || $key === '') {
        http_response_code(400);
        exit('bad request');
    }
    try {
        $meta = readOrderMeta($orderId);
        if ((string)($meta['user_id'] ?? '') !== (string)$user['user_id']) {
            throw new RuntimeException('订单不存在');
        }
        $allowed = is_array($meta['files'] ?? null) ? $meta['files'] : [];
        $filename = trim((string)($allowed[$key] ?? ''));
        if ($filename === '') {
            throw new RuntimeException('文件不存在');
        }
        $path = orderFilePath($orderId, $filename);
        if (!is_file($path)) {
            throw new RuntimeException('文件不存在');
        }
        $mime = detectMimeByExtension($filename);
        header('Content-Type: ' . $mime);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        if ($download) {
            $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
            $baseName = buildExportBaseName($meta);
            $friendly = $baseName;
            if ($key === 'package_zip') {
                $friendly .= '.zip';
            } elseif ($key === 'mask_snapshot') {
                $friendly .= '-模板' . ($ext !== '' ? '.' . $ext : '.png');
            } elseif (preg_match('/^set(\d+)_(\w+)$/', $key, $m)) {
                $idx = (int)$m[1];
                $kind = $m[2];
                $kindMap = [
                    'masked' => '第' . $idx . '张设计稿',
                    'raw' => '第' . $idx . '张原始稿',
                    'idea_json' => '第' . $idx . '张设计思路',
                    'idea_txt' => '第' . $idx . '张设计思路',
                ];
                $friendly .= '-' . ($kindMap[$kind] ?? ('第' . $idx . '张')) . ($ext !== '' ? '.' . $ext : '');
            } else {
                $friendly = $filename;
            }
            $friendlyAscii = preg_replace('/[\\\\\/:\*\?"<>\|]/', '_', $friendly) ?? $friendly;
            $headerName = rawurlencode($friendlyAscii);
            $fallback = preg_replace('/[^A-Za-z0-9._\-]+/', '_', $friendlyAscii) ?? 'file';
            header("Content-Disposition: attachment; filename=\"{$fallback}\"; filename*=UTF-8''{$headerName}");
        }
        readfile($path);
        exit;
    } catch (Throwable) {
        http_response_code(404);
        exit('not found');
    }
}

function renderPage(): never
{
    header('Content-Type: text/html; charset=utf-8');
    $csrfToken = getCsrfToken();
    $purchaseUrl = CARD_PURCHASE_URL;
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(APP_NAME) ?> | <?= h(APP_TAGLINE) ?></title>
<meta name="description" content="<?= h(APP_NAME) ?>，<?= h(APP_SLOGAN) ?>。上传你的固定模板，输入主题，几分钟拿到可直接交付的成品稿。">
<style>
*{box-sizing:border-box}
:root{
  --bg:#0a0e1f;--bg-soft:#f6f8fc;--panel:#fff;--line:#e7edf6;--line-soft:#f0f3f9;
  --text:#0f172a;--text2:#475569;--muted:#6b7280;
  --primary:#5b5bff;--primary2:#7c3aed;--primary-soft:#eef0ff;
  --green:#10b981;--green-soft:#d1fae5;--orange:#f59e0b;--orange-soft:#fef3c7;
  --red:#ef4444;--red-soft:#fee2e2;--blue:#3b82f6;--blue-soft:#dbeafe;
  --shadow-sm:0 2px 8px rgba(15,23,42,.06);
  --shadow:0 12px 40px rgba(15,23,42,.08);
  --shadow-lg:0 24px 60px rgba(15,23,42,.12);
  --radius:18px;--radius-sm:12px;--radius-lg:24px;
}
html,body{height:100%}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"PingFang SC","Microsoft YaHei",sans-serif;background:var(--bg-soft);color:var(--text);-webkit-font-smoothing:antialiased}
a{text-decoration:none;color:inherit}
button,input,select,textarea{font:inherit}
.wrap{max-width:1320px;margin:0 auto;padding:0 24px}

/* ---------- 顶部导航 ---------- */
.top{position:sticky;top:0;z-index:60;background:rgba(10,14,31,.86);backdrop-filter:blur(18px);border-bottom:1px solid rgba(255,255,255,.06)}
.top-inner{height:72px;display:flex;align-items:center;justify-content:space-between;gap:20px}
.brand{display:flex;gap:14px;align-items:center;color:#fff;cursor:pointer;transition:transform .25s ease}
.brand:hover{transform:translateY(-1px)}
.logo{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#5b5bff 0%,#7c3aed 60%,#06b6d4 100%);display:flex;align-items:center;justify-content:center;font-weight:900;color:#fff;box-shadow:0 8px 24px rgba(91,91,255,.3);position:relative;overflow:hidden}
.logo::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,transparent 30%,rgba(255,255,255,.3) 50%,transparent 70%);transform:translateX(-100%);animation:logoShine 3.6s infinite}
@keyframes logoShine{0%,60%{transform:translateX(-100%)}100%{transform:translateX(100%)}}
.brand b{display:block;font-size:17px;letter-spacing:.5px}
.brand span{display:block;color:rgba(255,255,255,.6);font-size:12px;margin-top:2px}
.nav{display:flex;gap:6px;color:rgba(255,255,255,.85)}
.nav a{padding:9px 14px;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;transition:all .2s ease;position:relative}
.nav a:hover{background:rgba(255,255,255,.08);color:#fff}
.nav a.active{background:rgba(91,91,255,.2);color:#fff}
.top-actions{display:flex;gap:10px;align-items:center}

/* ---------- 按钮 ---------- */
.btn{appearance:none;border:none;border-radius:12px;padding:11px 20px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:8px;transition:all .22s cubic-bezier(.4,0,.2,1);font-size:14px;white-space:nowrap}
.btn:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(15,23,42,.1)}
.btn:active{transform:translateY(0)}
.btn-primary{background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;box-shadow:0 8px 24px rgba(91,91,255,.32)}
.btn-primary:hover{box-shadow:0 12px 32px rgba(91,91,255,.42)}
.btn-dark{background:rgba(255,255,255,.1);color:#fff;border:1px solid rgba(255,255,255,.16)}
.btn-dark:hover{background:rgba(255,255,255,.16)}
.btn-light{background:#fff;color:var(--text);border:1px solid var(--line)}
.btn-ghost{background:var(--primary-soft);color:var(--primary)}
.btn-ghost:hover{background:#e0e3ff}
.btn-sm{padding:8px 14px;font-size:13px;border-radius:10px}
.btn-danger{background:#fff;color:var(--red);border:1px solid #fecaca}
.btn-danger:hover{background:var(--red-soft)}

/* ---------- 页面切换 ---------- */
.page{display:none;animation:pageIn .35s cubic-bezier(.22,.61,.36,1)}
.page.active{display:block}
@keyframes pageIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}

/* ---------- 首页 hero ---------- */
.hero{background:radial-gradient(1200px 500px at 80% -10%,rgba(124,58,237,.32),transparent 60%),radial-gradient(900px 400px at 0% 10%,rgba(56,189,248,.22),transparent 60%),linear-gradient(135deg,#0a0e1f 0%,#0f1430 50%,#1a1654 100%);color:#fff;position:relative;overflow:hidden}
.hero::before,.hero::after{content:'';position:absolute;border-radius:50%;filter:blur(80px);pointer-events:none}
.hero::before{width:400px;height:400px;background:rgba(91,91,255,.4);top:-100px;right:-50px;animation:floatA 14s ease-in-out infinite}
.hero::after{width:340px;height:340px;background:rgba(6,182,212,.32);bottom:-80px;left:5%;animation:floatB 18s ease-in-out infinite}
@keyframes floatA{0%,100%{transform:translate(0,0)}50%{transform:translate(-30px,40px)}}
@keyframes floatB{0%,100%{transform:translate(0,0)}50%{transform:translate(40px,-30px)}}
.hero-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:48px;align-items:center;padding:80px 0 96px;position:relative;z-index:2}
.hero-badge{display:inline-flex;align-items:center;gap:10px;padding:9px 16px;border-radius:999px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);font-size:13px;font-weight:600;backdrop-filter:blur(12px)}
.hero-badge .pulse{width:8px;height:8px;border-radius:50%;background:#10b981;box-shadow:0 0 0 0 rgba(16,185,129,.6);animation:pulse 2s infinite}
@keyframes pulse{0%{box-shadow:0 0 0 0 rgba(16,185,129,.6)}70%{box-shadow:0 0 0 12px rgba(16,185,129,0)}100%{box-shadow:0 0 0 0 rgba(16,185,129,0)}}
.hero h1{margin:22px 0 18px;font-size:60px;line-height:1.05;letter-spacing:-2px;font-weight:900}
.hero h1 em{font-style:normal;background:linear-gradient(135deg,#a78bfa,#22d3ee);-webkit-background-clip:text;background-clip:text;color:transparent}
.hero p.lead{margin:0;color:rgba(255,255,255,.78);font-size:18px;line-height:1.85;max-width:560px}
.hero-actions{display:flex;gap:14px;flex-wrap:wrap;margin-top:32px}
.hero-meta{display:flex;gap:24px;margin-top:36px;flex-wrap:wrap}
.hero-meta-item{display:flex;flex-direction:column;gap:4px}
.hero-meta-item b{font-size:28px;font-weight:900;background:linear-gradient(135deg,#fff,#cbd5e1);-webkit-background-clip:text;background-clip:text;color:transparent}
.hero-meta-item span{font-size:12.5px;color:rgba(255,255,255,.6);font-weight:500}

/* hero 右侧示意卡片 */
.hero-stage{position:relative;height:auto;padding-top:8px}
.stage-card{position:absolute;background:rgba(255,255,255,.06);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,.12);border-radius:24px;padding:22px;color:#fff;box-shadow:0 24px 60px rgba(0,0,0,.36)}
.stage-card h4{margin:0 0 6px;font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px}
.stage-card p{margin:0;font-size:13px;color:rgba(255,255,255,.7);line-height:1.7}
.stage-1{top:0;left:0;width:280px;animation:cardFloat 6s ease-in-out infinite}
.stage-2{top:130px;right:0;width:260px;animation:cardFloat 6s ease-in-out infinite .8s}
.stage-3{bottom:20px;left:30px;width:280px;animation:cardFloat 6s ease-in-out infinite 1.6s}
@keyframes cardFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-10px)}}
.dot{width:8px;height:8px;border-radius:50%;background:#5b5bff;box-shadow:0 0 12px #5b5bff}
.dot.green{background:#10b981;box-shadow:0 0 12px #10b981}
.dot.orange{background:#f59e0b;box-shadow:0 0 12px #f59e0b}

/* ---------- 通用 section ---------- */
.section{padding:80px 0}
.section-head{text-align:center;max-width:720px;margin:0 auto 48px}
.section-head .eyebrow{display:inline-block;padding:6px 14px;border-radius:999px;background:var(--primary-soft);color:var(--primary);font-size:12.5px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;margin-bottom:14px}
.section-head h2{margin:0 0 14px;font-size:38px;line-height:1.2;letter-spacing:-1px;font-weight:900}
.section-head p{margin:0;color:var(--text2);font-size:16px;line-height:1.85}

/* feature 卡片 */
.features{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
.feat-card{background:#fff;border:1px solid var(--line);border-radius:var(--radius-lg);padding:30px 26px;transition:all .3s cubic-bezier(.22,.61,.36,1);position:relative;overflow:hidden}
.feat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--primary),var(--primary2));transform:scaleX(0);transform-origin:left;transition:transform .35s ease}
.feat-card:hover{transform:translateY(-6px);box-shadow:var(--shadow-lg);border-color:transparent}
.feat-card:hover::before{transform:scaleX(1)}
.feat-icon{width:54px;height:54px;border-radius:16px;background:linear-gradient(135deg,var(--primary-soft),#fff);display:flex;align-items:center;justify-content:center;font-size:26px;margin-bottom:18px;border:1px solid var(--line-soft)}
.feat-card h3{margin:0 0 10px;font-size:19px;font-weight:800}
.feat-card p{margin:0;color:var(--text2);font-size:14.5px;line-height:1.85}

/* 流程区块 */
.steps{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;position:relative}
.step-card{background:#fff;border:1px solid var(--line);border-radius:var(--radius);padding:26px 22px;text-align:left;position:relative;transition:all .3s ease}
.step-card:hover{transform:translateY(-4px);box-shadow:var(--shadow)}
.step-num{display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:12px;background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;font-weight:900;font-size:16px;margin-bottom:14px;box-shadow:0 6px 16px rgba(91,91,255,.3)}
.step-card h4{margin:0 0 8px;font-size:16px;font-weight:800}
.step-card p{margin:0;color:var(--text2);font-size:13.5px;line-height:1.8}

/* 价值证明区块 */
.bg-soft{background:linear-gradient(180deg,#f6f8fc,#eef2fa)}
.value-grid{display:grid;grid-template-columns:1.05fr .95fr;gap:48px;align-items:center}
.value-list{display:flex;flex-direction:column;gap:16px}
.value-item{display:flex;gap:16px;padding:20px;background:#fff;border-radius:var(--radius);border:1px solid var(--line);transition:all .25s ease}
.value-item:hover{transform:translateX(4px);border-color:var(--primary)}
.value-item .check{width:36px;height:36px;border-radius:10px;background:var(--green-soft);color:var(--green);display:flex;align-items:center;justify-content:center;font-weight:900;flex-shrink:0;font-size:18px}
.value-item h5{margin:0 0 4px;font-size:15.5px;font-weight:800}
.value-item p{margin:0;color:var(--text2);font-size:13.5px;line-height:1.75}
.value-card{background:linear-gradient(155deg,#0f1430,#1f2358);color:#fff;padding:36px;border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);position:relative;overflow:hidden}
.value-card::before{content:'';position:absolute;top:-50px;right:-50px;width:200px;height:200px;border-radius:50%;background:radial-gradient(circle,rgba(124,58,237,.4),transparent 70%);filter:blur(20px)}
.value-card h3{margin:0 0 14px;font-size:24px;font-weight:900;position:relative}
.value-card p{margin:0 0 24px;color:rgba(255,255,255,.78);line-height:1.85;position:relative}
.value-stats{display:grid;grid-template-columns:repeat(2,1fr);gap:16px;position:relative}
.value-stat{padding:18px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:14px}
.value-stat b{display:block;font-size:30px;font-weight:900;background:linear-gradient(135deg,#a78bfa,#22d3ee);-webkit-background-clip:text;background-clip:text;color:transparent;line-height:1.1}
.value-stat span{display:block;font-size:12.5px;color:rgba(255,255,255,.65);margin-top:4px}

/* CTA */
.cta{background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;border-radius:32px;padding:56px 48px;text-align:center;position:relative;overflow:hidden;margin:0 24px}
.cta::before{content:'';position:absolute;inset:0;background:radial-gradient(circle at 30% 30%,rgba(255,255,255,.18),transparent 40%),radial-gradient(circle at 70% 70%,rgba(255,255,255,.12),transparent 40%);pointer-events:none}
.cta h2{margin:0 0 14px;font-size:36px;font-weight:900;letter-spacing:-1px;position:relative}
.cta p{margin:0 0 28px;color:rgba(255,255,255,.85);font-size:16px;line-height:1.8;position:relative;max-width:560px;margin-left:auto;margin-right:auto}
.cta .btn{position:relative}

/* ---------- 仪表盘布局 ---------- */
.app{display:grid;grid-template-columns:240px minmax(0,1fr);gap:24px;padding:28px 0 56px}
.sidebar{position:sticky;top:96px;align-self:start;background:#fff;border:1px solid var(--line);border-radius:var(--radius-lg);padding:18px;box-shadow:var(--shadow-sm)}
.sidebar-user{display:flex;align-items:center;gap:12px;padding:12px;border-radius:var(--radius-sm);background:linear-gradient(135deg,#0f1430,#1f2358);color:#fff;margin-bottom:14px}
.sb-avatar{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#5b5bff,#7c3aed);display:flex;align-items:center;justify-content:center;font-weight:900;font-size:17px;flex-shrink:0;box-shadow:0 4px 12px rgba(91,91,255,.4)}
.sb-user-info{min-width:0;flex:1}
.sb-user-info b{display:block;font-size:14px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-user-info span{display:block;font-size:11.5px;color:rgba(255,255,255,.7);margin-top:2px}
.sidebar-nav{display:flex;flex-direction:column;gap:4px}
.sn-item{display:flex;align-items:center;gap:12px;padding:11px 12px;border-radius:10px;color:var(--text2);cursor:pointer;font-size:14px;font-weight:600;transition:all .2s ease;border:none;background:transparent;width:100%;text-align:left}
.sn-item:hover{background:var(--line-soft);color:var(--text)}
.sn-item.active{background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;box-shadow:0 6px 16px rgba(91,91,255,.3)}
.sn-icon{font-size:17px;width:22px;text-align:center}
.sn-badge{margin-left:auto;background:var(--orange-soft);color:var(--orange);padding:1px 7px;border-radius:999px;font-size:11px;font-weight:800}
.sn-item.active .sn-badge{background:rgba(255,255,255,.22);color:#fff}

/* 主内容卡片 */
.content{min-width:0}
.subpage{display:none;animation:pageIn .3s cubic-bezier(.22,.61,.36,1)}
.subpage.active{display:block}
.page-head{margin-bottom:22px}
.page-head h1{margin:0 0 6px;font-size:26px;font-weight:900;letter-spacing:-.5px}
.page-head p{margin:0;color:var(--text2);font-size:14.5px;line-height:1.75}

.card{background:#fff;border:1px solid var(--line);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm);transition:all .25s ease}
.card-body{padding:26px}
.card-title{margin:0 0 6px;font-size:18px;font-weight:800}
.card-sub{margin:0 0 20px;color:var(--text2);font-size:13.5px;line-height:1.75}

/* 仪表盘首页 KPI */
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:22px}
.kpi-card{background:#fff;border:1px solid var(--line);border-radius:var(--radius);padding:20px;transition:all .25s ease;position:relative;overflow:hidden}
.kpi-card:hover{transform:translateY(-3px);box-shadow:var(--shadow)}
.kpi-card .kpi-icon{position:absolute;right:14px;top:14px;width:36px;height:36px;border-radius:10px;background:var(--primary-soft);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:17px}
.kpi-card span.label{font-size:13px;color:var(--text2);font-weight:600}
.kpi-card b.value{display:block;font-size:30px;font-weight:900;margin-top:8px;letter-spacing:-1px}
.kpi-card small{display:block;font-size:12px;color:var(--muted);margin-top:4px}

/* 系统状态 */
.sys-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.sys-card{background:#fff;border:1px solid var(--line);border-radius:var(--radius);padding:22px}
.sys-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px dashed var(--line)}
.sys-row:last-child{border:none}
.sys-row .k{color:var(--text2);font-size:13.5px}
.sys-row .v{font-weight:800;font-size:14px}
.health{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border-radius:999px;font-size:12.5px;font-weight:800}
.health-excellent{background:var(--green-soft);color:var(--green)}
.health-good{background:var(--blue-soft);color:var(--blue)}
.health-fair{background:var(--orange-soft);color:var(--orange)}
.health-unstable{background:var(--red-soft);color:var(--red)}
.health-dot{width:7px;height:7px;border-radius:50%;background:currentColor;animation:pulse2 2s infinite}
@keyframes pulse2{0%,100%{opacity:1}50%{opacity:.4}}

.gauge{margin-top:14px}
.gauge-track{height:10px;background:var(--line-soft);border-radius:999px;overflow:hidden;position:relative}
.gauge-fill{height:100%;background:linear-gradient(90deg,var(--green),#22d3ee);border-radius:999px;transition:width .6s cubic-bezier(.22,.61,.36,1)}
.gauge-text{margin-top:8px;display:flex;justify-content:space-between;font-size:12.5px;color:var(--text2)}

/* 表单 */
.form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}
.field{display:flex;flex-direction:column;gap:8px}
.field-full{grid-column:1/-1}
.field label{font-size:13px;font-weight:700;color:var(--text)}
.input,.select,.file,.textarea{width:100%;border:1px solid var(--line);background:#fff;border-radius:12px;padding:13px 14px;outline:none;transition:all .2s ease;color:var(--text);font-size:14px}
.input:focus,.select:focus,.textarea:focus{border-color:var(--primary);box-shadow:0 0 0 4px rgba(91,91,255,.12)}
.file{padding:14px;background:#fafbff;border-style:dashed;cursor:pointer}
.range{width:100%;-webkit-appearance:none;appearance:none;height:6px;background:var(--line-soft);border-radius:999px;outline:none}
.range::-webkit-slider-thumb{-webkit-appearance:none;appearance:none;width:22px;height:22px;background:linear-gradient(135deg,var(--primary),var(--primary2));border-radius:50%;cursor:pointer;border:3px solid #fff;box-shadow:0 4px 10px rgba(91,91,255,.4)}
.hint{font-size:13px;color:var(--text2);line-height:1.7}
.inline-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}

.notice{display:none;margin-top:16px;padding:13px 16px;border-radius:12px;font-size:13.5px;line-height:1.7;border:1px solid transparent}
.notice.show{display:block;animation:slideDown .25s ease}
@keyframes slideDown{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.notice.ok{background:var(--green-soft);color:#065f46;border-color:#a7f3d0}
.notice.err{background:var(--red-soft);color:#991b1b;border-color:#fecaca}
.notice.info{background:var(--blue-soft);color:#1e40af;border-color:#bfdbfe}

/* 蒙版 / 订单卡 */
.list{display:flex;flex-direction:column;gap:14px}
.mask-card,.order-card,.credit-log{border:1px solid var(--line);border-radius:var(--radius);background:#fff;transition:all .25s ease}
.mask-card:hover,.order-card:hover{border-color:#d6dbe9;box-shadow:var(--shadow-sm)}
.mask-inner,.order-inner,.credit-inner{padding:18px}
.row-between{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap}
.chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.chip{display:inline-flex;align-items:center;gap:5px;padding:6px 11px;border-radius:999px;background:var(--line-soft);color:var(--text2);font-size:12px;font-weight:600}
.chip.primary{background:var(--primary-soft);color:var(--primary)}

.status-badge{display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border-radius:999px;font-size:12px;font-weight:800}
.status-badge.queued{background:var(--orange-soft);color:#92400e}
.status-badge.running{background:var(--blue-soft);color:#1e40af}
.status-badge.done{background:var(--green-soft);color:#065f46}
.status-badge.error,.status-badge.cancelled{background:var(--red-soft);color:#991b1b}
.status-badge .dot-mini{width:6px;height:6px;border-radius:50%;background:currentColor}
.status-badge.running .dot-mini{animation:pulse2 1.5s infinite}

.preview,.imgbox{border:1px solid var(--line);border-radius:14px;padding:14px;background-image:linear-gradient(45deg,#eef2f7 25%,transparent 25%),linear-gradient(-45deg,#eef2f7 25%,transparent 25%),linear-gradient(45deg,transparent 75%,#eef2f7 75%),linear-gradient(-45deg,transparent 75%,#eef2f7 75%);background-size:20px 20px;background-position:0 0,0 10px,10px -10px,-10px 0;display:flex;align-items:center;justify-content:center;min-height:120px}
.preview img,.imgbox img{max-width:100%;height:auto;display:block;border-radius:6px}

.progress{height:10px;background:var(--line-soft);border-radius:999px;overflow:hidden;margin-top:14px}
.progress>span{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,var(--primary),var(--primary2));transition:width .5s cubic-bezier(.22,.61,.36,1);position:relative;overflow:hidden}
.progress>span::after{content:'';position:absolute;inset:0;background:linear-gradient(90deg,transparent,rgba(255,255,255,.4),transparent);animation:shimmer 1.6s infinite}
@keyframes shimmer{0%{transform:translateX(-100%)}100%{transform:translateX(100%)}}
.progress-text{margin-top:8px;font-size:12.5px;color:var(--text2);display:flex;justify-content:space-between;gap:10px}

.result-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-top:16px}
.result-item{border:1px solid var(--line);border-radius:14px;padding:14px;background:#fff;transition:all .25s ease}
.result-item:hover{transform:translateY(-3px);box-shadow:var(--shadow-sm)}
.result-item h4{margin:0 0 10px;font-size:13px;font-weight:800;color:var(--text2)}

.empty{padding:48px 24px;text-align:center;color:var(--text2);line-height:1.85;border:1px dashed var(--line);border-radius:var(--radius);background:#fafbff}
.empty .empty-icon{font-size:42px;margin-bottom:10px;opacity:.6}
.empty h4{margin:0 0 6px;color:var(--text);font-size:16px}

/* 弹窗 */
.modal{position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:24px;background:rgba(3,7,18,.6);backdrop-filter:blur(8px);z-index:120;animation:fade .25s ease}
.modal.show{display:flex}
@keyframes fade{from{opacity:0}to{opacity:1}}
.panel{width:min(880px,100%);background:#fff;border-radius:24px;overflow:hidden;box-shadow:0 28px 90px rgba(15,23,42,.4);animation:popIn .3s cubic-bezier(.22,.61,.36,1)}
@keyframes popIn{from{opacity:0;transform:scale(.94) translateY(10px)}to{opacity:1;transform:scale(1) translateY(0)}}
.auth-grid{display:grid;grid-template-columns:1fr 1.1fr}
.auth-side{background:linear-gradient(155deg,#0a0e1f,#1a1654);color:#fff;padding:36px 32px;position:relative;overflow:hidden}
.auth-side::before{content:'';position:absolute;top:-80px;right:-80px;width:280px;height:280px;border-radius:50%;background:radial-gradient(circle,rgba(124,58,237,.4),transparent 70%);filter:blur(20px)}
.auth-side h3{margin:0 0 12px;font-size:22px;position:relative}
.auth-side p{color:rgba(255,255,255,.78);line-height:1.85;font-size:14px;position:relative;margin:0 0 22px}
.auth-side ul{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:10px;position:relative}
.auth-side ul li{display:flex;gap:10px;font-size:13.5px;color:rgba(255,255,255,.85)}
.auth-side ul li::before{content:'✓';color:#22d3ee;font-weight:900}
.auth-content{padding:32px}
.tabs{display:flex;gap:8px;margin-bottom:20px;background:var(--line-soft);padding:5px;border-radius:14px}
.tab{flex:1;padding:11px 12px;border-radius:10px;background:transparent;color:var(--text2);font-weight:700;border:none;cursor:pointer;font-size:14px;transition:all .2s ease}
.tab.active{background:#fff;color:var(--text);box-shadow:var(--shadow-sm)}
.hidden{display:none!important}

.runtime-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;background:#0f1430;color:#cbd5e1;font-size:11.5px;font-weight:700;font-family:'SF Mono',Menlo,Consolas,monospace}
.runtime-pill .dot-mini{width:6px;height:6px;border-radius:50%;background:#22d3ee;box-shadow:0 0 8px #22d3ee;animation:pulse2 1.5s infinite}

.skeleton{background:linear-gradient(90deg,#f0f3f9 0%,#e7edf6 50%,#f0f3f9 100%);background-size:200% 100%;animation:skel 1.4s linear infinite;border-radius:8px}
@keyframes skel{0%{background-position:200% 0}100%{background-position:-200% 0}}

/* hero 流程步骤 */
.flow-step{position:relative;background:rgba(255,255,255,.06);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,.12);border-radius:20px;padding:18px 22px 18px 70px;color:#fff;margin-bottom:18px;transition:all .3s ease;animation:flowIn .6s cubic-bezier(.22,.61,.36,1) backwards}
.flow-step:hover{transform:translateX(6px);background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.2)}
.flow-1{animation-delay:.2s}.flow-2{animation-delay:.5s;margin-left:30px}.flow-3{animation-delay:.8s;margin-left:60px}
@keyframes flowIn{from{opacity:0;transform:translateX(-20px)}to{opacity:1;transform:translateX(0)}}
.flow-num{position:absolute;left:14px;top:14px;width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#5b5bff,#7c3aed);display:flex;align-items:center;justify-content:center;font-weight:900;font-size:14px;letter-spacing:.5px;box-shadow:0 6px 16px rgba(91,91,255,.4)}
.flow-body h4{margin:0 0 6px;font-size:15.5px;font-weight:800;display:flex;align-items:center;gap:8px}
.flow-body p{margin:0;font-size:13px;color:rgba(255,255,255,.72);line-height:1.7}
.flow-arrow{position:absolute;left:34px;bottom:-22px;color:rgba(255,255,255,.35);font-size:20px;font-weight:900;animation:arrowDown 1.6s ease-in-out infinite}
@keyframes arrowDown{0%,100%{transform:translateY(0);opacity:.5}50%{transform:translateY(4px);opacity:1}}

/* 风格选择网格 */
.style-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;margin-top:6px}
.style-card{position:relative;border:2px solid var(--line);border-radius:14px;padding:14px;cursor:pointer;background:#fff;transition:all .22s cubic-bezier(.22,.61,.36,1);overflow:hidden}
.style-card:hover{transform:translateY(-2px);border-color:#c7cdf0;box-shadow:var(--shadow-sm)}
.style-card.selected{border-color:var(--primary);background:linear-gradient(135deg,#fafbff,#f0f1ff);box-shadow:0 0 0 4px rgba(91,91,255,.12)}
.style-card.selected::after{content:'✓';position:absolute;top:8px;right:8px;width:20px;height:20px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900}
.style-card.custom{border-style:dashed}
.style-card .style-tag{display:inline-block;padding:2px 8px;border-radius:999px;background:var(--orange-soft);color:var(--orange);font-size:10.5px;font-weight:700;margin-bottom:6px}
.style-card.custom .style-tag{background:var(--primary-soft);color:var(--primary)}
.style-card .style-name{font-weight:800;font-size:14px;margin-bottom:3px;line-height:1.3}
.style-card .style-desc{font-size:11.5px;color:var(--text2);line-height:1.5}

/* 拖拽上传 */
.dropzone{margin-top:14px;border:2px dashed var(--line);border-radius:16px;padding:32px 20px;text-align:center;background:#fafbff;cursor:pointer;transition:all .25s ease;position:relative}
.dropzone:hover{border-color:var(--primary);background:#f4f5ff}
.dropzone.dragover{border-color:var(--primary);background:#eef0ff;transform:scale(1.01)}
.dropzone.has-file{border-style:solid;border-color:var(--green);background:var(--green-soft)}
.dz-icon{font-size:36px;margin-bottom:10px;opacity:.7}
.dz-title{font-size:14px;font-weight:700;color:var(--text);margin-bottom:6px}
.dz-hint{font-size:12.5px;color:var(--text2)}

/* 蒙版库 grid */
.mask-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;margin-top:6px}
.mask-mini{border:1px solid var(--line);border-radius:14px;background:#fff;overflow:hidden;transition:all .25s ease;display:flex;flex-direction:column;animation:cardIn .35s ease backwards}
.mask-mini:hover{transform:translateY(-3px);box-shadow:var(--shadow);border-color:#c7cdf0}
@keyframes cardIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.mask-mini-img{height:140px;background-image:linear-gradient(45deg,#eef2f7 25%,transparent 25%),linear-gradient(-45deg,#eef2f7 25%,transparent 25%),linear-gradient(45deg,transparent 75%,#eef2f7 75%),linear-gradient(-45deg,transparent 75%,#eef2f7 75%);background-size:18px 18px;background-position:0 0,0 9px,9px -9px,-9px 0;display:flex;align-items:center;justify-content:center;padding:8px;border-bottom:1px solid var(--line-soft)}
.mask-mini-img img{max-width:100%;max-height:100%;object-fit:contain;display:block}
.mask-mini-body{padding:12px 14px}
.mask-mini-name{font-weight:800;font-size:13.5px;line-height:1.35;margin-bottom:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mask-mini-meta{font-size:11.5px;color:var(--text2);display:flex;justify-content:space-between;gap:8px;margin-bottom:8px}
.mask-mini-actions{display:flex;gap:6px}
.mask-mini-actions .btn{flex:1;padding:6px 10px;font-size:12px;border-radius:8px}

/* 分页 */
.pagination{display:flex;justify-content:center;align-items:center;gap:6px;flex-wrap:wrap}
.page-btn{min-width:34px;height:34px;border-radius:9px;border:1px solid var(--line);background:#fff;color:var(--text2);font-weight:700;font-size:13px;cursor:pointer;transition:all .18s ease;padding:0 10px}
.page-btn:hover:not(:disabled){border-color:var(--primary);color:var(--primary)}
.page-btn.active{background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;border-color:transparent;box-shadow:0 4px 12px rgba(91,91,255,.3)}
.page-btn:disabled{opacity:.4;cursor:not-allowed}
.page-info{color:var(--muted);font-size:12.5px;margin:0 8px}

/* 自定义风格列表项 */
.custom-style-item{border:1px solid var(--line);border-radius:14px;padding:16px;background:#fff;display:flex;justify-content:space-between;gap:14px;align-items:flex-start}
.custom-style-item:hover{border-color:#c7cdf0}
.custom-style-item h5{margin:0 0 4px;font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.custom-style-item .desc-line{color:var(--text2);font-size:13px;margin-bottom:6px}
.custom-style-item .prompt-line{font-size:12.5px;color:var(--muted);line-height:1.7;background:var(--line-soft);padding:8px 10px;border-radius:8px;margin-top:8px;max-height:80px;overflow:auto}

/* 联系我们 */
.contact-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
.contact-card{background:#fff;border:1px solid var(--line);border-radius:var(--radius-lg);padding:28px 24px;transition:all .3s ease;text-align:left}
.contact-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-lg)}
.contact-icon{width:54px;height:54px;border-radius:16px;background:linear-gradient(135deg,var(--primary-soft),#fff);display:flex;align-items:center;justify-content:center;font-size:26px;margin-bottom:18px;border:1px solid var(--line-soft)}
.contact-label{font-size:12px;font-weight:800;color:var(--primary);letter-spacing:1px;text-transform:uppercase;margin-bottom:6px}
.contact-value{display:block;font-size:18px;font-weight:900;color:var(--text);margin-bottom:10px;word-break:break-all}
a.contact-value:hover{color:var(--primary)}
.contact-desc{margin:0;color:var(--text2);font-size:13.5px;line-height:1.8}
.contact-tags{display:flex;flex-wrap:wrap;gap:7px;margin-top:6px}
.contact-tag{padding:6px 11px;border-radius:999px;background:var(--line-soft);color:var(--text2);font-size:12px;font-weight:600}

/* 站脚 */
.site-footer{padding:40px 24px 24px;border-top:1px solid var(--line)}
.footer-grid{display:grid;grid-template-columns:1.4fr 1fr 1fr 1fr;gap:32px;margin-bottom:28px}
.footer-h{margin:0 0 14px;font-size:13px;font-weight:800;color:var(--text);letter-spacing:.5px}
.footer-link{display:block;color:var(--text2);font-size:13px;margin-bottom:8px;cursor:pointer;transition:color .2s ease;text-decoration:none}
.footer-link:hover{color:var(--primary)}
.footer-bottom{padding-top:20px;border-top:1px solid var(--line);text-align:center;color:var(--muted);font-size:12.5px}

/* 响应式 */
@media (max-width:1100px){
  .hero-grid,.value-grid{grid-template-columns:1fr}
  .hero-stage{height:auto}
  .features,.contact-grid{grid-template-columns:1fr 1fr}
  .steps{grid-template-columns:1fr 1fr}
  .kpi-grid{grid-template-columns:1fr 1fr}
  .app{grid-template-columns:1fr}
  .sidebar{position:static}
  .hero h1{font-size:44px}
  .footer-grid{grid-template-columns:1fr 1fr}
  .flow-2{margin-left:20px}.flow-3{margin-left:40px}
}
@media (max-width:760px){
  .wrap{padding:0 16px}
  .nav{display:none}
  .features,.steps,.kpi-grid,.sys-grid,.form-grid,.auth-grid,.contact-grid,.footer-grid{grid-template-columns:1fr}
  .hero h1{font-size:34px}
  .hero-grid{padding:48px 0 60px}
  .top-inner{height:64px}
  .section{padding:56px 0}
  .section-head h2{font-size:28px}
  .style-grid{grid-template-columns:repeat(2,1fr)}
  .mask-grid{grid-template-columns:1fr 1fr}
  .flow-2,.flow-3{margin-left:0}
}
</style>
</head>
<body>

<!-- ===== 顶部 ===== -->
<div class="top">
  <div class="wrap top-inner">
    <div class="brand" data-go="home">
      <div class="logo">稿</div>
      <div>
        <b><?= h(APP_NAME) ?></b>
        <span><?= h(APP_TAGLINE) ?></span>
      </div>
    </div>
    <div class="nav" id="topNav">
      <a data-go="home" class="active">首页</a>
      <a data-go="features">功能特色</a>
      <a data-go="how">使用流程</a>
      <a data-go="pricing">额度方案</a>
      <a data-go="contact">联系我们</a>
    </div>
    <div class="top-actions">
      <button class="btn btn-dark" id="topLoginBtn" type="button">登录 / 注册</button>
      <button class="btn btn-primary hidden" id="topConsoleBtn" type="button">进入工作台 →</button>
      <button class="btn btn-light hidden" id="topLogoutBtn" type="button">退出</button>
    </div>
  </div>
</div>

<!-- ===== 首页（未登录可见的营销页） ===== -->
<div class="page active" id="page-home">

  <section class="hero" id="hero">
    <div class="wrap hero-grid">
      <div>
        <span class="hero-badge"><span class="pulse"></span>系统稳定运行中 · 当前 <b id="heroOrders" style="margin:0 4px">—</b> 个任务并行处理</span>
        <h1>把固定模板，<br><em>一键变成成品稿</em>。</h1>
        <p class="lead"><?= h(APP_NAME) ?> 是面向团队和工作室的设计稿生产平台。上传你的固定模板，输入主题，几分钟拿到一整套可以直接交付的成品稿。一个人也能跑出一支设计团队的产能。</p>
        <div class="hero-actions">
          <button class="btn btn-primary" id="heroTryBtn" type="button">立即开始 →</button>
          <button class="btn btn-dark" data-go="how" type="button">看看怎么用</button>
        </div>
        <div class="hero-meta">
          <div class="hero-meta-item"><b id="heroSuccess">—</b><span>近期成功率</span></div>
          <div class="hero-meta-item"><b id="heroAvg">—</b><span>平均出稿时长</span></div>
          <div class="hero-meta-item"><b><?= MAX_CONCURRENT_ORDERS ?></b><span>同时并行任务上限</span></div>
        </div>
      </div>
      <div class="hero-stage">
        <div class="flow-step flow-1">
          <div class="flow-num">01</div>
          <div class="flow-body">
            <h4><span class="dot green"></span>上传你的模板</h4>
            <p>把固定结构图上传成蒙版，告诉系统哪里能改、哪里要锁定。</p>
          </div>
          <div class="flow-arrow">↓</div>
        </div>
        <div class="flow-step flow-2">
          <div class="flow-num">02</div>
          <div class="flow-body">
            <h4><span class="dot"></span>选风格 + 写主题</h4>
            <p>从风格库挑一个，再用一句话告诉系统这次想要的方向。</p>
          </div>
          <div class="flow-arrow">↓</div>
        </div>
        <div class="flow-step flow-3">
          <div class="flow-num">03</div>
          <div class="flow-body">
            <h4><span class="dot orange"></span>拿成品下载</h4>
            <p>系统自动并行出图，完成后整包打包，直接发给客户。</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="section" id="features">
    <div class="wrap">
      <div class="section-head">
        <span class="eyebrow">核心能力</span>
        <h2>一个人，跑出一支设计团队的产能</h2>
        <p>从模板到成品的整条链路全自动跑通。不再排期、不再返工、不再拖稿。</p>
      </div>
      <div class="features">
        <div class="feat-card">
          <div class="feat-icon">🎯</div>
          <h3>模板严格不变形</h3>
          <p>系统会精确识别你模板的可设计区域和不可改区域，所有产出严格落在白色区域内，外轮廓、孔位、镂空一像素都不会跑偏。</p>
        </div>
        <div class="feat-card">
          <div class="feat-icon">⚡</div>
          <h3>批量并行出稿</h3>
          <p>同时跑 3 个订单不冲突。一次提交可以出 1–5 张方案，每张都是不同方向，挑一张能直接交付。</p>
        </div>
        <div class="feat-card">
          <div class="feat-icon">🎨</div>
          <h3>5 套商业风格库</h3>
          <p>高级品牌感、赛博能量、潮玩可爱、暗黑奢感、极简高级。同一模板换风格出图，覆盖你 90% 的接稿场景。</p>
        </div>
        <div class="feat-card">
          <div class="feat-icon">📦</div>
          <h3>成品打包即交付</h3>
          <p>每个订单完成后自动打包成 ZIP：成品图、设计思路 JSON、思路 TXT 一应俱全，下载即可发给客户或上游。</p>
        </div>
        <div class="feat-card">
          <div class="feat-icon">🔒</div>
          <h3>资产只属于你</h3>
          <p>蒙版、订单、生成结果完全隔离。每个账号有独立空间，其他人看不到你的模板和方案，避免方案外泄。</p>
        </div>
        <div class="feat-card">
          <div class="feat-icon">📊</div>
          <h3>全程可视化进度</h3>
          <p>排队位置、当前步骤、运行时长、预估完成时间实时跳动。你可以一边干别的事一边等结果，不用守着屏幕。</p>
        </div>
      </div>
    </div>
  </section>

  <section class="section bg-soft" id="how">
    <div class="wrap">
      <div class="section-head">
        <span class="eyebrow">四步交付</span>
        <h2>从开模板到拿成品，平均 5 分钟</h2>
        <p>不需要懂任何 AI 提示词，不需要会任何设计软件。会上传图、会打字就能用。</p>
      </div>
      <div class="steps">
        <div class="step-card"><div class="step-num">1</div><h4>上传模板</h4><p>把你的固定结构图上传成蒙版，白色区域可设计、黑色区域不可改，系统自动识别。</p></div>
        <div class="step-card"><div class="step-num">2</div><h4>选风格 + 写主题</h4><p>从 5 套商业级风格库挑一个，再用一句话写明这次想要的主题方向，例如"赛博机甲、暗黑红龙"。</p></div>
        <div class="step-card"><div class="step-num">3</div><h4>提交订单</h4><p>选择想出几张（1–5 张），点击提交。系统会自动排队、并发处理，并实时回报进度。</p></div>
        <div class="step-card"><div class="step-num">4</div><h4>下载成品</h4><p>每张稿件完成后会立刻在订单里出现。整单完成后自动打包 ZIP，可单张下载也可整包带走。</p></div>
      </div>
    </div>
  </section>

  <section class="section">
    <div class="wrap">
      <div class="value-grid">
        <div>
          <span class="eyebrow" style="display:inline-block;padding:6px 14px;border-radius:999px;background:var(--primary-soft);color:var(--primary);font-size:12.5px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;margin-bottom:14px">为什么选我们</span>
          <h2 style="margin:0 0 18px;font-size:36px;line-height:1.2;letter-spacing:-1px;font-weight:900">省下设计师工资，<br>把出稿速度推到极限。</h2>
          <p style="color:var(--text2);font-size:15.5px;line-height:1.85;margin:0 0 28px">如果你做电商、做周边、做 IP 衍生、做礼品、做潮玩贴标、做手柄面板、做灯牌——这些有固定模板的设计需求，靠人力一张张画太慢、太贵、太难复用。<?= h(APP_NAME) ?> 把整条流程自动化。</p>
          <div class="value-list">
            <div class="value-item"><div class="check">✓</div><div><h5>替代外包，省下 80% 的成本</h5><p>外包一张商业稿至少几百块，<?= h(APP_NAME) ?> 上一张只要 5 额度，你算算账。</p></div></div>
            <div class="value-item"><div class="check">✓</div><div><h5>替代等待，分钟级出稿</h5><p>过去一稿要等 3 天，现在最快 90 秒一张。提了订单可以直接干别的事。</p></div></div>
            <div class="value-item"><div class="check">✓</div><div><h5>替代沟通，主题写一句话就行</h5><p>不用反复对接、改了又改。不满意？再下一单换风格。</p></div></div>
          </div>
        </div>
        <div class="value-card">
          <h3>实时运行数据</h3>
          <p>系统在后台始终保持高吞吐运行，下面是当前真实状态。</p>
          <div class="value-stats">
            <div class="value-stat"><b id="vsSuccess">—</b><span>近期成功率</span></div>
            <div class="value-stat"><b id="vsAvg">—</b><span>单张平均耗时</span></div>
            <div class="value-stat"><b id="vsRunning">—</b><span>当前并行任务</span></div>
            <div class="value-stat"><b id="vsQueued">—</b><span>当前排队任务</span></div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="section" id="pricing">
    <div class="wrap">
      <div class="section-head">
        <span class="eyebrow">额度说明</span>
        <h2>简单直接的按量计费</h2>
        <p>新用户注册即送 <?= START_CREDITS ?> 额度，每张设计稿消耗 <?= CREDIT_PER_IMAGE ?> 额度。需要更多直接卡密充值。</p>
      </div>
      <div class="features" style="grid-template-columns:repeat(3,1fr)">
        <div class="feat-card"><div class="feat-icon">🎁</div><h3>注册即送 <?= START_CREDITS ?> 额度</h3><p>足够你免费试出 <?= (int)(START_CREDITS/CREDIT_PER_IMAGE) ?> 张完整设计稿，先看效果再决定。</p></div>
        <div class="feat-card"><div class="feat-icon">💎</div><h3><?= CREDIT_PER_IMAGE ?> 额度 / 张</h3><p>不分风格、不分尺寸，所有商业级稿件统一价。失败自动退还，不会让你白扣。</p></div>
        <div class="feat-card"><div class="feat-icon">🛒</div><h3>卡密充值即时到账</h3><p>购买卡密后在工作台粘贴即可秒到账，无需等审核、不用绑定支付方式。</p></div>
      </div>
    </div>
  </section>

  <section class="section bg-soft" id="contact">
    <div class="wrap">
      <div class="section-head">
        <span class="eyebrow">联系我们</span>
        <h2>商业合作 · 定制开发 · 长期合作</h2>
        <p>批量采购、私有化部署、行业定制版本、API 接入、分销合作？欢迎邮件直接联系我们的商务团队。</p>
      </div>
      <div class="contact-grid">
        <div class="contact-card">
          <div class="contact-icon">✉️</div>
          <div class="contact-label">商务邮箱</div>
          <a class="contact-value" href="mailto:<?= h(CONTACT_EMAIL) ?>"><?= h(CONTACT_EMAIL) ?></a>
          <p class="contact-desc">商务合作、定制开发、私有部署、API 对接，工作日 24 小时内回复。</p>
          <a class="btn btn-primary" href="mailto:<?= h(CONTACT_EMAIL) ?>?subject=【<?= h(APP_NAME) ?>】商业合作咨询" style="margin-top:14px">发邮件咨询 →</a>
        </div>
        <div class="contact-card">
          <div class="contact-icon">🏢</div>
          <div class="contact-label">公司全称</div>
          <div class="contact-value"><?= h(COMPANY_NAME) ?></div>
          <p class="contact-desc"><?= h(COMPANY_NAME) ?>，专注于设计自动化与 AI 应用工具研发。</p>
        </div>
        <div class="contact-card">
          <div class="contact-icon">🤝</div>
          <div class="contact-label">合作类型</div>
          <div class="contact-tags">
            <span class="contact-tag">企业批量采购</span>
            <span class="contact-tag">私有化部署</span>
            <span class="contact-tag">行业定制版</span>
            <span class="contact-tag">API 接入</span>
            <span class="contact-tag">分销 / 代理</span>
            <span class="contact-tag">供应链对接</span>
          </div>
        </div>
      </div>
    </div>
  </section>

  <div class="wrap">
    <section class="cta">
      <h2>现在就开始你的第一张稿件</h2>
      <p>无需信用卡、无需复杂配置。注册即送 <?= START_CREDITS ?> 额度，足够免费跑通整个流程。</p>
      <button class="btn btn-light" id="ctaTryBtn" type="button" style="font-size:15px;padding:14px 32px">免费试用 <?= h(APP_NAME) ?> →</button>
    </section>
    <footer class="site-footer">
      <div class="footer-grid">
        <div>
          <div class="brand" style="color:var(--text);cursor:default">
            <div class="logo">稿</div>
            <div><b style="color:var(--text)"><?= h(APP_NAME) ?></b><span style="color:var(--muted)"><?= h(APP_TAGLINE) ?></span></div>
          </div>
          <p style="color:var(--text2);font-size:13px;line-height:1.85;margin:14px 0 0;max-width:320px"><?= h(APP_SLOGAN) ?>。</p>
        </div>
        <div>
          <h5 class="footer-h">产品</h5>
          <a class="footer-link" data-go="features">功能特色</a>
          <a class="footer-link" data-go="how">使用流程</a>
          <a class="footer-link" data-go="pricing">额度方案</a>
        </div>
        <div>
          <h5 class="footer-h">合作</h5>
          <a class="footer-link" data-go="contact">联系我们</a>
          <a class="footer-link" href="mailto:<?= h(CONTACT_EMAIL) ?>"><?= h(CONTACT_EMAIL) ?></a>
        </div>
        <div>
          <h5 class="footer-h">公司</h5>
          <div style="color:var(--text2);font-size:13px;line-height:1.9"><?= h(COMPANY_NAME) ?></div>
        </div>
      </div>
      <div class="footer-bottom">
        © <?= date('Y') ?> <?= h(COMPANY_NAME) ?> · <?= h(APP_NAME) ?> · <?= h(APP_TAGLINE) ?>
      </div>
    </footer>
  </div>

</div>

<!-- ===== 工作台（登录后可见） ===== -->
<div class="page" id="page-app">
  <div class="wrap app">

    <!-- 侧边栏 -->
    <aside class="sidebar">
      <div class="sidebar-user">
        <div class="sb-avatar" id="sbAvatar">A</div>
        <div class="sb-user-info">
          <b id="sbUsername">—</b>
          <span><span id="sbCredits">0</span> 额度可用</span>
        </div>
      </div>
      <nav class="sidebar-nav">
        <button class="sn-item active" data-sub="dashboard" type="button"><span class="sn-icon">📊</span>工作台</button>
        <button class="sn-item" data-sub="create" type="button"><span class="sn-icon">✨</span>创建订单</button>
        <button class="sn-item" data-sub="masks" type="button"><span class="sn-icon">🧩</span>我的蒙版</button>
        <button class="sn-item" data-sub="styles" type="button"><span class="sn-icon">🎨</span>风格库</button>
        <button class="sn-item" data-sub="orders" type="button"><span class="sn-icon">📦</span>订单中心 <span class="sn-badge hidden" id="sbOrderBadge">0</span></button>
        <button class="sn-item" data-sub="credits" type="button"><span class="sn-icon">💎</span>额度中心</button>
      </nav>
    </aside>

    <!-- 内容区 -->
    <div class="content">

      <!-- 子页：工作台首页 -->
      <section class="subpage active" id="sub-dashboard">
        <div class="page-head"><h1>工作台</h1><p>这里是你的实时数据中心，包含系统运行状态和你的账号概览。</p></div>

        <div class="kpi-grid">
          <div class="kpi-card"><div class="kpi-icon">💎</div><span class="label">当前可用额度</span><b class="value" id="kpiCredits">0</b><small>每张稿消耗 <?= CREDIT_PER_IMAGE ?> 额度</small></div>
          <div class="kpi-card"><div class="kpi-icon">📦</div><span class="label">累计下单</span><b class="value" id="kpiOrders">0</b><small>所有订单数</small></div>
          <div class="kpi-card"><div class="kpi-icon">🎨</div><span class="label">累计生成</span><b class="value" id="kpiGenerated">0</b><small>已交付的成品稿</small></div>
          <div class="kpi-card"><div class="kpi-icon">💰</div><span class="label">累计消耗</span><b class="value" id="kpiSpent">0</b><small>已使用的额度</small></div>
        </div>

        <div class="sys-grid">
          <div class="sys-card">
            <div class="row-between"><div><h3 class="card-title">系统运行状态</h3><p class="card-sub">实时反映平台当前的整体稳定性。</p></div><span class="health" id="sysHealth"><span class="health-dot"></span><span id="sysHealthText">检查中</span></span></div>
            <div class="sys-row"><span class="k">近期成功率</span><span class="v" id="sysSuccess">—</span></div>
            <div class="sys-row"><span class="k">当前并行任务</span><span class="v"><span id="sysRunning">0</span> / <?= MAX_CONCURRENT_ORDERS ?></span></div>
            <div class="sys-row"><span class="k">排队中任务</span><span class="v" id="sysQueued">0</span></div>
            <div class="sys-row"><span class="k">单张平均耗时</span><span class="v" id="sysAvg">—</span></div>
            <div class="gauge"><div class="gauge-track"><div class="gauge-fill" id="sysGauge" style="width:0%"></div></div><div class="gauge-text"><span>当前负载</span><span id="sysLoadText">—</span></div></div>
          </div>
          <div class="sys-card">
            <h3 class="card-title">如果现在提交订单</h3>
            <p class="card-sub">基于当前队列和近期生成速度做的预估。仅供参考，实际可能更快。</p>
            <div class="sys-row"><span class="k">想生成的张数</span>
              <span class="v"><select id="estCountSelect" class="select" style="padding:6px 10px;font-size:13px;width:auto">
                <option value="1">1 张</option><option value="2">2 张</option><option value="3" selected>3 张</option><option value="4">4 张</option><option value="5">5 张</option>
              </select></span>
            </div>
            <div class="sys-row"><span class="k">需要消耗</span><span class="v"><span id="estCost">15</span> 额度</span></div>
            <div class="sys-row"><span class="k">预估总耗时</span><span class="v" id="estTotal">—</span></div>
            <div class="sys-row"><span class="k">预估完成时间</span><span class="v" id="estFinish">—</span></div>
            <div class="inline-actions" style="margin-top:18px"><button class="btn btn-primary" data-sub="create" type="button">去创建订单 →</button></div>
          </div>
        </div>
      </section>

      <!-- 子页：创建订单 -->
      <section class="subpage" id="sub-create">
        <div class="page-head"><h1>创建订单</h1><p>选蒙版、选风格、写主题、提交。系统会自动排队并启动生成。</p></div>
        <div class="card"><div class="card-body">
          <div class="form-grid">
            <div class="field field-full"><label>选择蒙版</label>
              <div style="display:flex;gap:10px;align-items:center">
                <select class="select" id="orderMaskSelect" style="flex:1"><option value="">请先在"我的蒙版"上传</option></select>
                <button class="btn btn-light btn-sm" data-sub="masks" type="button">管理蒙版</button>
              </div>
            </div>
            <div class="field field-full">
              <label>选择风格 <span class="hint" style="font-weight:500;font-size:12.5px">·  从风格库挑一个，或<a href="javascript:;" data-sub="styles" style="color:var(--primary);font-weight:600">添加自定义风格</a></span></label>
              <div id="styleGrid" class="style-grid"></div>
              <input type="hidden" id="templateSelect" value="">
            </div>
            <div class="field field-full"><label for="themeInput">主题描述</label><input class="input" id="themeInput" type="text" placeholder="例如：赛博机甲、深海发光水母、暗黑红龙、新年红金祥云" maxlength="120"></div>
            <div class="field field-full"><label for="countInput">生成数量：<b id="countLabel">3 张</b>，预计消耗 <b id="costLabel">15</b> 额度</label><input id="countInput" class="range" type="range" min="1" max="5" step="1" value="3"></div>
          </div>
          <div class="card" style="margin-top:18px;background:#fafbff;border-style:dashed"><div class="card-body" style="padding:18px"><div class="row-between" style="align-items:center"><div><b style="font-size:13.5px">预估完成时间</b><div class="hint" style="margin-top:4px">基于当前系统状态和并行情况实时计算</div></div><div style="font-size:22px;font-weight:900;color:var(--primary)" id="createEst">—</div></div></div></div>
          <div class="inline-actions"><button class="btn btn-primary" id="createOrderBtn" type="button">提交生成订单 →</button></div>
          <div class="notice" id="orderNotice"></div>
        </div></div>
      </section>

      <!-- 子页：我的蒙版 -->
      <section class="subpage" id="sub-masks">
        <div class="page-head"><h1>我的蒙版</h1><p>上传你的固定模板。白色 / 不透明区域是设计区域，黑色 / 透明区域是不可改的边界、孔位或镂空。（注意：需要严格按照这种方式上传）</p></div>

        <div class="card" style="margin-bottom:18px"><div class="card-body">
          <h3 class="card-title">上传新蒙版</h3>
          <p class="card-sub">支持 PNG / JPG / WEBP，64×64 到 6000×6000 像素。可拖拽到下方虚线框内。</p>
          <div class="form-grid">
            <div class="field"><label for="maskNameInput">蒙版名称</label><input class="input" id="maskNameInput" type="text" placeholder="例如：手柄贴标准版 / 灯牌模板 A" maxlength="40"></div>
            <div class="field"><label for="maskPolaritySelect">设计区域识别</label>
              <select class="select" id="maskPolaritySelect">
                <option value="auto">自动识别（推荐）</option>
                <option value="white_editable">白色 = 设计区域，黑色不可改</option>
                <option value="black_editable">黑色 = 设计区域，白色不可改</option>
                <option value="opaque_editable">非透明 = 设计区域，透明不可改</option>
              </select>
            </div>
          </div>
          <div class="dropzone" id="maskDropzone">
            <input type="file" id="maskFileInput" accept="image/png,image/jpeg,image/webp" hidden>
            <div class="dz-inner">
              <div class="dz-icon">📥</div>
              <div class="dz-title" id="dzTitle">把蒙版图片拖到这里，或点击选择文件</div>
              <div class="dz-hint">最大 15MB · 支持 PNG / JPG / WEBP</div>
            </div>
          </div>
          <div class="inline-actions"><button class="btn btn-primary" id="uploadMaskBtn" type="button">上传到蒙版库</button></div>
          <div class="notice" id="maskNotice"></div>
        </div></div>

        <div class="card"><div class="card-body">
          <div class="row-between" style="align-items:center;margin-bottom:14px">
            <div><h3 class="card-title">蒙版库</h3><p class="card-sub" style="margin:0">共 <b id="maskCount">0</b> 个蒙版，每个只有你能看到。</p></div>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
              <input class="input" id="maskSearchInput" type="search" placeholder="🔍 搜索蒙版名称" style="padding:9px 14px;width:200px">
              <select class="select" id="maskSortSelect" style="padding:9px 14px;width:auto">
                <option value="updated_desc">最近更新</option>
                <option value="created_desc">最近创建</option>
                <option value="name_asc">名称 A→Z</option>
              </select>
            </div>
          </div>
          <div id="maskGrid" class="mask-grid"></div>
          <div id="maskPagination" class="pagination" style="margin-top:18px"></div>
        </div></div>
      </section>

      <!-- 子页：自定义风格 -->
      <section class="subpage" id="sub-styles">
        <div class="page-head"><h1>自定义风格</h1><p>系统内置 12 套商业风格。如果都不满足你的需求，在这里添加你自己的风格描述，下次创建订单时就能直接选用。</p></div>
        <div class="card" style="margin-bottom:18px"><div class="card-body">
          <h3 class="card-title">添加新风格</h3>
          <p class="card-sub">用一段话描述你想要的整体视觉感觉，越具体越好。系统会把它作为风格指导给到 AI。</p>
          <div class="form-grid">
            <div class="field"><label for="styleNameInput">风格名称</label><input class="input" id="styleNameInput" type="text" placeholder="例如：赛博朋克霓虹" maxlength="30"></div>
            <div class="field"><label for="styleDescInput">简短说明</label><input class="input" id="styleDescInput" type="text" placeholder="一句话描述适用场景，例如：科幻 / 夜景 / 霓虹" maxlength="60"></div>
            <div class="field field-full"><label for="stylePromptInput">风格描述（详细）</label><textarea class="textarea" id="stylePromptInput" rows="4" placeholder="详细描述这个风格的视觉特征：用色、构图、装饰元素、整体氛围……" maxlength="600"></textarea></div>
          </div>
          <div class="inline-actions"><button class="btn btn-primary" id="addStyleBtn" type="button">添加到我的风格库</button></div>
          <div class="notice" id="styleNotice"></div>
        </div></div>
        <div class="card"><div class="card-body">
          <h3 class="card-title">系统内置风格</h3>
          <p class="card-sub">这些风格直接可用，无需配置。</p>
          <div id="builtinStyleGrid" class="style-grid" style="margin-top:14px"></div>
        </div></div>
        <div class="card" style="margin-top:18px"><div class="card-body">
          <h3 class="card-title">我的自定义风格 <span class="chip" style="margin-left:8px"><span id="customStyleCount">0</span> / 30</span></h3>
          <p class="card-sub">最多保存 30 个，点击删除按钮可以释放槽位。</p>
          <div id="customStyleList" class="list" style="margin-top:14px"></div>
        </div></div>
      </section>

      <!-- 子页：订单中心 -->
      <section class="subpage" id="sub-orders">
        <div class="page-head"><h1>订单中心</h1><p>实时查看每个订单的进度、运行时长、预估完成时间。完成后可单张下载或整包带走。</p></div>
        <div id="orderList" class="list"></div>
      </section>

      <!-- 子页：额度中心 -->
      <section class="subpage" id="sub-credits">
        <div class="page-head"><h1>额度中心</h1><p>额度是你在 <?= h(APP_NAME) ?> 上的通用结算单位，每张稿件消耗 <?= CREDIT_PER_IMAGE ?> 额度。</p></div>
        <div class="card" style="margin-bottom:18px"><div class="card-body">
          <div class="row-between" style="align-items:center">
            <div><h3 class="card-title">充值额度</h3><p class="card-sub" style="margin:0">在卡密页购买后回到这里粘贴即可立即到账。</p></div>
            <a class="btn btn-ghost" href="<?= h($purchaseUrl) ?>" target="_blank" rel="noopener">购买卡密 →</a>
          </div>
          <div class="form-grid" style="margin-top:18px">
            <div class="field field-full"><label for="cardCodeInput">输入卡密</label><input class="input" id="cardCodeInput" type="text" placeholder="粘贴你的卡密"></div>
          </div>
          <div class="inline-actions"><button class="btn btn-primary" id="redeemBtn" type="button">立即充值</button></div>
          <div class="notice" id="cardNotice"></div>
        </div></div>
        <div class="card"><div class="card-body">
          <h3 class="card-title">额度变动记录</h3>
          <p class="card-sub">最近 20 条额度收支明细，包括赠送、消耗、退款、充值。</p>
          <div class="list" id="creditLogList" style="margin-top:14px"></div>
        </div></div>
      </section>

    </div>
  </div>
</div>

<!-- ===== 登录注册弹窗 ===== -->
<div class="modal" id="authModal">
  <div class="panel">
    <div class="auth-grid">
      <div class="auth-side">
        <h3><?= h(APP_NAME) ?> · <?= h(APP_TAGLINE) ?></h3>
        <p>注册后立即获得 <?= START_CREDITS ?> 额度，足够你免费跑通整个流程。</p>
        <ul>
          <li>专属蒙版资产空间，私有不外泄</li>
          <li>5 套商业级风格库一键切换</li>
          <li>同时并行 <?= MAX_CONCURRENT_ORDERS ?> 个订单不冲突</li>
          <li>失败自动退还额度，不亏一张</li>
          <li>整单 ZIP 打包，下载即交付</li>
        </ul>
      </div>
      <div class="auth-content">
        <div class="tabs">
          <button class="tab active" data-tab="login" type="button">登录</button>
          <button class="tab" data-tab="register" type="button">免费注册</button>
        </div>
        <div id="loginPane">
          <div class="field"><label>手机号</label><input class="input" id="loginMobileInput" type="text" placeholder="11 位中国大陆手机号"></div>
          <div class="field" style="margin-top:14px"><label>密码</label><input class="input" id="loginPasswordInput" type="password" placeholder="请输入密码"></div>
          <div class="inline-actions"><button class="btn btn-primary" id="loginBtn" type="button" style="width:100%">登录并进入工作台</button></div>
          <div class="notice" id="loginNotice"></div>
        </div>
        <div id="registerPane" class="hidden">
          <div class="form-grid">
            <div class="field"><label>用户名</label><input class="input" id="registerUsernameInput" type="text" placeholder="2-24 位"></div>
            <div class="field"><label>手机号</label><input class="input" id="registerMobileInput" type="text" placeholder="11 位手机号"></div>
            <div class="field"><label>密码</label><input class="input" id="registerPasswordInput" type="password" placeholder="至少 6 位"></div>
            <div class="field"><label>邀请人（可选）</label><input class="input" id="registerInviteInput" type="text" placeholder="选填"></div>
            <div class="field field-full"><label>短信验证码</label>
              <div style="display:flex;gap:10px"><input class="input" id="registerCodeInput" type="text" placeholder="6 位验证码" style="flex:1"><button class="btn btn-ghost" id="sendCodeBtn" type="button" style="white-space:nowrap">发送验证码</button></div>
            </div>
          </div>
          <div class="inline-actions"><button class="btn btn-primary" id="registerBtn" type="button" style="width:100%">注册并领取 <?= START_CREDITS ?> 额度</button></div>
          <div class="notice" id="registerNotice"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
const state = {
  csrfToken: <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  user: null,
  masks: [],
  orders: [],
  templates: [],
  systemStatus: null,
  tokenKey: 'gaoqing_auth_cache',
  currentPage: 'home',
  currentSub: 'dashboard',
  creditPerImage: <?= CREDIT_PER_IMAGE ?>,
  selectedStyleId: 'premium_brand',
  maskSearch: '',
  maskSort: 'updated_desc',
  maskPage: 1,
  maskPerPage: 12,
  selectedMaskFile: null,
};

const $ = s => document.querySelector(s);
const $$ = s => Array.from(document.querySelectorAll(s));

function esc(s){return String(s??'').replace(/[&<>'"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]))}
function notice(el,type,text){if(!el)return;if(!text){el.className='notice';el.textContent='';return}el.className='notice show '+type;el.textContent=text}

async function api(url, opt={}) {
  opt = Object.assign({cache:'no-store'}, opt);
  opt.headers = Object.assign({'X-CSRF-Token': state.csrfToken}, opt.headers || {});
  const r = await fetch(url, opt);
  const t = await r.text();
  let d; try { d = JSON.parse(t); } catch(e) { throw new Error('服务器返回异常'); }
  if (!r.ok || !d.ok) throw new Error(d.message || d.msg || '请求失败');
  return d;
}

function avatar(name){return String(name||'A').trim().slice(0,1).toUpperCase()||'A'}
function statusCls(s){return s==='done'?'done':s==='running'?'running':s==='queued'?'queued':(s||'error')}

function fmtDuration(sec){
  sec = Math.max(0, Math.floor(sec||0));
  if (sec < 60) return sec + ' 秒';
  const m = Math.floor(sec/60), s = sec%60;
  if (m < 60) return m + ' 分' + (s ? ' ' + s + ' 秒' : '');
  const h = Math.floor(m/60), mm = m%60;
  return h + ' 小时' + (mm ? ' ' + mm + ' 分' : '');
}
function fmtFinishTime(secFromNow){
  const t = new Date(Date.now() + (secFromNow||0)*1000);
  const hh = String(t.getHours()).padStart(2,'0');
  const mm = String(t.getMinutes()).padStart(2,'0');
  return hh+':'+mm;
}

function saveToken(t){if(t)localStorage.setItem(state.tokenKey,t)}
function clearToken(){localStorage.removeItem(state.tokenKey)}

/* ---------- 页面切换 ---------- */
function goPage(name){
  state.currentPage = name;
  $$('.page').forEach(p => p.classList.remove('active'));
  if (name === 'home' || name === 'features' || name === 'how' || name === 'pricing' || name === 'contact') {
    $('#page-home').classList.add('active');
    $$('#topNav a').forEach(a => a.classList.toggle('active', a.dataset.go === name));
    if (name !== 'home') {
      const target = $('#'+name);
      if (target) setTimeout(() => window.scrollTo({top: target.offsetTop - 80, behavior:'smooth'}), 60);
    } else {
      window.scrollTo({top:0, behavior:'smooth'});
    }
  } else if (name === 'app') {
    $('#page-app').classList.add('active');
    $$('#topNav a').forEach(a => a.classList.remove('active'));
    window.scrollTo({top:0, behavior:'smooth'});
  }
}
function goSub(name){
  state.currentSub = name;
  $$('.subpage').forEach(p => p.classList.remove('active'));
  const target = $('#sub-' + name);
  if (target) target.classList.add('active');
  $$('.sn-item').forEach(b => b.classList.toggle('active', b.dataset.sub === name));
  goPage('app');
}

/* ---------- 弹窗 ---------- */
function tab(name){
  $$('.tab').forEach(x=>x.classList.toggle('active', x.dataset.tab===name));
  $('#loginPane').classList.toggle('hidden', name!=='login');
  $('#registerPane').classList.toggle('hidden', name!=='register');
}
function openAuth(name='login'){tab(name);$('#authModal').classList.add('show')}
function closeAuth(){$('#authModal').classList.remove('show')}

/* ---------- 立即试用 ---------- */
function tryNow(){
  if (state.user) goSub('create');
  else openAuth('register');
}

/* ---------- 渲染 ---------- */
function getAllStyles(){
  const sys = (state.templates || []).map(t => Object.assign({}, t, {custom:false}));
  const cs = (state.user && state.user.custom_styles) ? state.user.custom_styles.map(t => Object.assign({}, t, {custom:true})) : [];
  return [...sys, ...cs];
}

function renderTemplates(){
  // 风格库选择网格 (创建订单页)
  renderStyleGrid();
  // 系统内置风格预览 (风格库页)
  const grid = $('#builtinStyleGrid');
  if (grid) {
    grid.innerHTML = (state.templates || []).map(t => `
      <div class="style-card">
        <span class="style-tag">系统</span>
        <div class="style-name">${esc(t.name)}</div>
        <div class="style-desc">${esc(t.desc)}</div>
      </div>
    `).join('');
  }
  // 我的自定义风格列表
  renderCustomStyles();
}

function renderStyleGrid(){
  const grid = $('#styleGrid');
  if (!grid) return;
  const all = getAllStyles();
  if (!all.length) { grid.innerHTML = '<div class="empty" style="grid-column:1/-1">暂无风格</div>'; return; }
  // 如果当前选中的不存在了，回退第一个
  if (!all.find(s => s.id === state.selectedStyleId)) state.selectedStyleId = all[0].id;
  $('#templateSelect').value = state.selectedStyleId;
  grid.innerHTML = all.map(s => `
    <div class="style-card ${s.custom ? 'custom' : ''} ${s.id === state.selectedStyleId ? 'selected' : ''}" data-style-id="${esc(s.id)}">
      <span class="style-tag">${s.custom ? '自定义' : '系统'}</span>
      <div class="style-name">${esc(s.name)}</div>
      <div class="style-desc">${esc(s.desc || '')}</div>
    </div>
  `).join('');
}

function renderCustomStyles(){
  const list = $('#customStyleList');
  if (!list) return;
  const cs = (state.user && state.user.custom_styles) || [];
  $('#customStyleCount').textContent = String(cs.length);
  if (!cs.length) {
    list.innerHTML = '<div class="empty"><div class="empty-icon">🎨</div><h4>还没有自定义风格</h4><div>用上面的表单添加你的第一个，下次创建订单时就能直接选用。</div></div>';
    return;
  }
  list.innerHTML = cs.map(s => `
    <div class="custom-style-item">
      <div style="min-width:0;flex:1">
        <h5>${esc(s.name)} <span class="chip primary" style="font-size:11px">自定义</span></h5>
        <div class="desc-line">${esc(s.desc || '')}</div>
        <div class="prompt-line">${esc(s.prompt || '')}</div>
      </div>
      <button class="btn btn-danger btn-sm" data-delete-style="${esc(s.id)}" type="button">删除</button>
    </div>
  `).join('');
}

function renderCreditLogs(){
  const logs = (state.user && state.user.credit_logs) || [];
  $('#creditLogList').innerHTML = logs.length ? logs.map(i=>`
    <div class="credit-log"><div class="credit-inner">
      <div class="row-between">
        <div><div style="font-weight:800;font-size:14px">${esc(i.note||i.type||'额度变动')}</div><div class="hint" style="margin-top:4px;font-size:12.5px">${esc(i.time||'')}</div></div>
        <div style="font-weight:900;font-size:18px;color:${(parseInt(i.delta,10)||0)>=0?'var(--green)':'var(--red)'}">${(parseInt(i.delta,10)||0)>=0?'+':''}${esc(i.delta)}</div>
      </div>
      <div class="hint" style="margin-top:8px;font-size:12.5px">当前余额：${esc(i.balance)} 额度</div>
    </div></div>
  `).join('') : '<div class="empty"><div class="empty-icon">📭</div><h4>还没有额度变动</h4><div>等你创建第一笔订单后，这里会出现明细。</div></div>';
}

function getFilteredMasks(){
  const all = state.masks || [];
  const q = (state.maskSearch || '').toLowerCase().trim();
  let arr = q ? all.filter(m => (m.name || '').toLowerCase().includes(q)) : [...all];
  switch (state.maskSort) {
    case 'name_asc': arr.sort((a,b) => (a.name||'').localeCompare(b.name||'', 'zh-CN')); break;
    case 'created_desc': arr.sort((a,b) => (b.created_at||'').localeCompare(a.created_at||'')); break;
    case 'updated_desc':
    default: arr.sort((a,b) => (b.updated_at||'').localeCompare(a.updated_at||'')); break;
  }
  return arr;
}

function renderMasks(){
  // 创建订单页的下拉
  const sel = $('#orderMaskSelect');
  const a = state.masks || [];
  sel.innerHTML = a.length
    ? a.map(m=>`<option value="${esc(m.mask_id)}">${esc(m.name)}（${m.width}×${m.height}）</option>`).join('')
    : '<option value="">请先在"我的蒙版"上传</option>';

  // 蒙版库 grid + 分页
  const filtered = getFilteredMasks();
  $('#maskCount').textContent = String((state.masks || []).length);
  const total = filtered.length;
  const perPage = state.maskPerPage;
  const totalPages = Math.max(1, Math.ceil(total / perPage));
  if (state.maskPage > totalPages) state.maskPage = totalPages;
  if (state.maskPage < 1) state.maskPage = 1;
  const start = (state.maskPage - 1) * perPage;
  const pageItems = filtered.slice(start, start + perPage);

  const grid = $('#maskGrid');
  if (!total) {
    grid.innerHTML = state.maskSearch
      ? `<div class="empty" style="grid-column:1/-1"><div class="empty-icon">🔍</div><h4>没有找到匹配的蒙版</h4><div>试试换个关键词，或<a href="javascript:;" id="clearMaskSearch" style="color:var(--primary)">清除搜索</a>。</div></div>`
      : `<div class="empty" style="grid-column:1/-1"><div class="empty-icon">🧩</div><h4>还没有上传蒙版</h4><div>把固定模板拖到上方虚线框，或点击选择文件。</div></div>`;
    $('#maskPagination').innerHTML = '';
    return;
  }
  grid.innerHTML = pageItems.map((m, i) => `
    <div class="mask-mini" style="animation-delay:${i*30}ms">
      <div class="mask-mini-img"><img src="${m.preview_url}" alt="${esc(m.name)}"></div>
      <div class="mask-mini-body">
        <div class="mask-mini-name" title="${esc(m.name)}">${esc(m.name)}</div>
        <div class="mask-mini-meta"><span>${m.width} × ${m.height}</span><span>${esc((m.updated_at||'').slice(5,16).replace('T',' '))}</span></div>
        <div class="mask-mini-actions">
          <button class="btn btn-light" data-rename="${esc(m.mask_id)}" type="button">重命名</button>
          <button class="btn btn-danger" data-delete="${esc(m.mask_id)}" type="button">删除</button>
        </div>
      </div>
    </div>
  `).join('');
  renderMaskPagination(totalPages, total);
}

function renderMaskPagination(totalPages, totalItems){
  const wrap = $('#maskPagination');
  if (totalPages <= 1) { wrap.innerHTML = `<span class="page-info">共 ${totalItems} 个</span>`; return; }
  const cur = state.maskPage;
  let pages = [];
  // 当总页数较少时全部显示，否则使用窗口
  if (totalPages <= 7) {
    for (let i = 1; i <= totalPages; i++) pages.push(i);
  } else {
    pages.push(1);
    if (cur > 3) pages.push('...');
    for (let i = Math.max(2, cur-1); i <= Math.min(totalPages-1, cur+1); i++) pages.push(i);
    if (cur < totalPages-2) pages.push('...');
    pages.push(totalPages);
  }
  let html = `<button class="page-btn" data-page="prev" ${cur<=1?'disabled':''} type="button">‹</button>`;
  pages.forEach(p => {
    if (p === '...') html += `<span class="page-info">…</span>`;
    else html += `<button class="page-btn ${p===cur?'active':''}" data-page="${p}" type="button">${p}</button>`;
  });
  html += `<button class="page-btn" data-page="next" ${cur>=totalPages?'disabled':''} type="button">›</button>`;
  html += `<span class="page-info">共 ${totalItems} 个，第 ${cur} / ${totalPages} 页</span>`;
  wrap.innerHTML = html;
}
function renderOrders(){
  const a = state.orders || [];
  const active = a.filter(o => o.status === 'queued' || o.status === 'running').length;
  const badge = $('#sbOrderBadge');
  if (active > 0) { badge.textContent = active; badge.classList.remove('hidden'); }
  else badge.classList.add('hidden');

  $('#orderList').innerHTML = a.length ? a.map(o=>{
    const elapsed = parseInt(o.elapsed_seconds || 0, 10);
    const remaining = parseInt(o.remaining_seconds || 0, 10);
    const isRunning = o.status === 'running';
    const isQueued = o.status === 'queued';
    const isDone = o.status === 'done';
    return `
    <div class="order-card" data-order-id="${esc(o.order_id)}"><div class="order-inner">
      <div class="row-between">
        <div style="min-width:0;flex:1">
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <div style="font-size:18px;font-weight:900">${esc(o.theme)}</div>
            <span class="status-badge ${statusCls(o.status)}"><span class="dot-mini"></span>${esc(o.status_text)}</span>
            ${isRunning ? `<span class="runtime-pill"><span class="dot-mini"></span>运行 ${esc(fmtDuration(elapsed))}</span>` : ''}
            ${isDone && elapsed > 0 ? `<span class="chip">⏱️ 总耗时 ${esc(fmtDuration(elapsed))}</span>` : ''}
          </div>
          <div class="chips">
            <span class="chip">📋 ${esc(o.order_id).slice(0,18)}…</span>
            <span class="chip">🧩 ${esc(o.mask_name)}</span>
            <span class="chip">🎨 ${esc(o.template_name)}</span>
            <span class="chip">📦 ${esc(o.design_count)} 张</span>
            ${isQueued && o.queue_position ? `<span class="chip primary">排队第 ${esc(o.queue_position)} 位</span>` : ''}
            ${(isRunning || isQueued) && remaining ? `<span class="chip primary">⏳ 还需 ${esc(fmtDuration(remaining))}</span>` : ''}
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          ${o.zip_url ? `<a class="btn btn-primary btn-sm" href="${o.zip_url}">下载整包 ZIP</a>` : ''}
          ${isQueued ? `<button class="btn btn-light btn-sm" data-cancel="${esc(o.order_id)}" type="button">取消订单</button>` : ''}
        </div>
      </div>
      <div class="progress"><span style="width:${Math.max(0,Math.min(100,parseInt(o.progress||0,10)))}%"></span></div>
      <div class="progress-text"><span>${esc(o.current_step||'')}</span><span>${esc(o.progress)}%</span></div>
      ${o.error_message ? `<div class="notice show err" style="margin-top:12px">${esc(o.error_message)}</div>` : ''}
      <div class="result-grid">
        ${(o.final_sets||[]).map(s=>`
          <div class="result-item">
            <h4>${esc(s.title||'')}</h4>
            <div class="imgbox">${s.image_url ? `<img src="${s.image_url}" alt="${esc(s.title||'')}">` : '<div class="hint" style="font-size:12px">生成中…</div>'}</div>
            <div class="inline-actions" style="margin-top:10px">${s.image_download ? `<a class="btn btn-light btn-sm" href="${s.image_download}">下载这张</a>` : ''}</div>
          </div>
        `).join('')}
      </div>
    </div></div>`;
  }).join('') : '<div class="empty"><div class="empty-icon">📦</div><h4>还没有订单</h4><div>去"创建订单"页提交你的第一笔生成任务吧。</div><div class="inline-actions" style="justify-content:center;margin-top:14px"><button class="btn btn-primary btn-sm" data-sub="create" type="button">立即创建</button></div></div>';
}

function renderSystem(){
  const s = state.systemStatus || {};
  const successRate = s.success_rate != null ? s.success_rate : 99;
  const running = s.running_orders || 0;
  const queued = s.queued_orders || 0;
  const maxC = s.max_concurrent || <?= MAX_CONCURRENT_ORDERS ?>;
  const avg = s.avg_seconds_per_image || <?= ESTIMATED_SECONDS_PER_IMAGE ?>;
  const level = s.level || 'excellent';
  const levelText = s.level_text || '运行流畅';

  // 工作台首页
  $('#sysHealth').className = 'health health-' + level;
  $('#sysHealthText').textContent = levelText;
  $('#sysSuccess').textContent = successRate + '%';
  $('#sysRunning').textContent = running;
  $('#sysQueued').textContent = queued;
  $('#sysAvg').textContent = fmtDuration(avg) + ' / 张';
  const load = Math.min(100, Math.round((running + queued) / Math.max(1, maxC * 2) * 100));
  $('#sysGauge').style.width = load + '%';
  $('#sysLoadText').textContent = load + '%';

  // hero
  $('#heroOrders').textContent = running;
  $('#heroSuccess').textContent = successRate + '%';
  $('#heroAvg').textContent = fmtDuration(avg);
  $('#vsSuccess').textContent = successRate + '%';
  $('#vsAvg').textContent = fmtDuration(avg);
  $('#vsRunning').textContent = running;
  $('#vsQueued').textContent = queued;

  // 预估表
  recomputeEstimate();
}

function estimateSecondsFor(count){
  const s = state.systemStatus || {};
  const perImage = Math.max(40, s.avg_seconds_per_image || <?= ESTIMATED_SECONDS_PER_IMAGE ?>);
  const own = count * perImage;
  const running = s.running_orders || 0;
  const queued = s.queued_orders || 0;
  const maxC = Math.max(1, s.max_concurrent || <?= MAX_CONCURRENT_ORDERS ?>);
  const effective = running + queued;
  let waitBefore = 0;
  if (effective > maxC) {
    waitBefore = Math.ceil((effective - maxC + 1) / maxC) * perImage * 2;
  }
  return own + waitBefore;
}
function recomputeEstimate(){
  // 工作台预估
  const c = parseInt($('#estCountSelect').value || '3', 10);
  $('#estCost').textContent = c * state.creditPerImage;
  const sec = estimateSecondsFor(c);
  $('#estTotal').textContent = '约 ' + fmtDuration(sec);
  $('#estFinish').textContent = fmtFinishTime(sec) + ' 左右完成';
  // 创建订单页预估
  const c2 = parseInt($('#countInput').value || '3', 10);
  const sec2 = estimateSecondsFor(c2);
  $('#createEst').textContent = '约 ' + fmtDuration(sec2);
}

function renderUser(){
  const u = state.user;
  const on = !!u;
  $('#topLoginBtn').classList.toggle('hidden', on);
  $('#topConsoleBtn').classList.toggle('hidden', !on);
  $('#topLogoutBtn').classList.toggle('hidden', !on);

  if (!on) {
    if (state.currentPage === 'app') goPage('home');
    return;
  }
  $('#sbAvatar').textContent = avatar(u.username);
  $('#sbUsername').textContent = u.username || '-';
  $('#sbCredits').textContent = String(u.credits || 0);
  $('#kpiCredits').textContent = String(u.credits || 0);
  $('#kpiOrders').textContent = String(u.total_orders || 0);
  $('#kpiGenerated').textContent = String(u.total_generated || 0);
  $('#kpiSpent').textContent = String(u.total_spent || 0);
  renderCreditLogs();
  renderMasks();
  renderOrders();
}

function updateCount(){
  const n = parseInt($('#countInput').value || '1', 10) || 1;
  $('#countLabel').textContent = n + ' 张';
  $('#costLabel').textContent = String(n * state.creditPerImage);
  recomputeEstimate();
}

/* ---------- 接口调用 ---------- */
async function bootstrap(){
  const d = await api('?action=bootstrap');
  state.csrfToken = d.csrf_token || state.csrfToken;
  state.templates = (d.config && d.config.templates) || [];
  state.user = d.user || null;
  state.masks = d.masks || [];
  state.orders = d.orders || [];
  state.systemStatus = d.system_status || null;
  renderTemplates();
  renderUser();
  renderSystem();
}
async function restore(){
  const token = localStorage.getItem(state.tokenKey) || '';
  if (!token) return false;
  try {
    await api('?action=restore_login', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams({token})});
    return true;
  } catch(e) { clearToken(); return false; }
}
async function init(){
  try {
    await bootstrap();
    if (!state.user) { const ok = await restore(); if (ok) await bootstrap(); }
  } catch(e) { console.error(e); }
}
async function login(){
  notice($('#loginNotice'),'','');
  try {
    const d = await api('?action=login', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({mobile: $('#loginMobileInput').value.trim(), password: $('#loginPasswordInput').value})
    });
    if (d.token) saveToken(d.token);
    closeAuth();
    await bootstrap();
    goSub('dashboard');
  } catch(e) { notice($('#loginNotice'),'err', e.message || '登录失败'); }
}
async function sendCode(){
  notice($('#registerNotice'),'','');
  try {
    await api('?action=send_mobile_code', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({mobile: $('#registerMobileInput').value.trim(), purpose:'register'})
    });
    notice($('#registerNotice'),'ok','验证码已发送，请注意查收');
  } catch(e) { notice($('#registerNotice'),'err', e.message || '发送失败'); }
}
async function register(){
  notice($('#registerNotice'),'','');
  try {
    const d = await api('?action=register', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({
        username: $('#registerUsernameInput').value.trim(),
        mobile: $('#registerMobileInput').value.trim(),
        password: $('#registerPasswordInput').value,
        sms_code: $('#registerCodeInput').value.trim(),
        inviter_username: $('#registerInviteInput').value.trim()
      })
    });
    if (d.token) saveToken(d.token);
    closeAuth();
    await bootstrap();
    goSub('dashboard');
  } catch(e) { notice($('#registerNotice'),'err', e.message || '注册失败'); }
}
async function logout(){
  try { await api('?action=logout', {method:'POST'}); } catch(e) {}
  clearToken();
  state.user = null; state.masks = []; state.orders = [];
  renderUser();
  goPage('home');
}
async function uploadMask(){
  notice($('#maskNotice'),'','');
  const file = state.selectedMaskFile || ($('#maskFileInput').files && $('#maskFileInput').files[0]);
  if (!file) { notice($('#maskNotice'),'err','请先选择或拖入蒙版图片'); return; }
  if (!$('#maskNameInput').value.trim()) { notice($('#maskNotice'),'err','请输入蒙版名称'); return; }
  try {
    const fd = new FormData();
    fd.append('mask_name', $('#maskNameInput').value.trim());
    fd.append('polarity', $('#maskPolaritySelect').value || 'auto');
    fd.append('mask_file', file);
    const d = await api('?action=upload_mask', {method:'POST', body: fd});
    notice($('#maskNotice'),'ok', d.message || '上传成功');
    $('#maskNameInput').value = '';
    $('#maskFileInput').value = '';
    state.selectedMaskFile = null;
    const dz = $('#maskDropzone');
    dz.classList.remove('has-file');
    $('#dzTitle').textContent = '把蒙版图片拖到这里，或点击选择文件';
    await bootstrap();
  } catch(e) { notice($('#maskNotice'),'err', e.message || '上传失败'); }
}
async function createOrder(){
  notice($('#orderNotice'),'','');
  if (!$('#orderMaskSelect').value) { notice($('#orderNotice'),'err','请先到"我的蒙版"上传一张蒙版'); return; }
  if (!$('#themeInput').value.trim()) { notice($('#orderNotice'),'err','请输入主题描述'); return; }
  if (!state.selectedStyleId) { notice($('#orderNotice'),'err','请先选择一个风格'); return; }
  try {
    const d = await api('?action=create_order', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({
        mask_id: $('#orderMaskSelect').value,
        template_id: state.selectedStyleId,
        theme: $('#themeInput').value.trim(),
        design_count: String(parseInt($('#countInput').value || '1', 10) || 1)
      })
    });
    const wait = d.estimated_wait_seconds || 0;
    notice($('#orderNotice'),'ok', (d.message || '订单已创建') + (wait ? '，预计 ' + fmtDuration(wait) + ' 内完成' : ''));
    $('#themeInput').value = '';
    await bootstrap();
    queueTick();
    setTimeout(() => goSub('orders'), 600);
  } catch(e) { notice($('#orderNotice'),'err', e.message || '创建订单失败'); }
}
async function queueTick(){
  try { await api('?action=queue_tick'); } catch(e) {}
}
async function redeem(){
  notice($('#cardNotice'),'','');
  if (!$('#cardCodeInput').value.trim()) { notice($('#cardNotice'),'err','请输入卡密'); return; }
  try {
    const d = await api('?action=redeem_card', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({card_code: $('#cardCodeInput').value.trim()})
    });
    notice($('#cardNotice'),'ok', d.message || '充值成功');
    $('#cardCodeInput').value = '';
    await bootstrap();
  } catch(e) { notice($('#cardNotice'),'err', e.message || '充值失败'); }
}

async function addCustomStyle(){
  notice($('#styleNotice'),'','');
  const name = $('#styleNameInput').value.trim();
  const desc = $('#styleDescInput').value.trim();
  const prompt = $('#stylePromptInput').value.trim();
  if (!name) { notice($('#styleNotice'),'err','请输入风格名称'); return; }
  if (!prompt) { notice($('#styleNotice'),'err','请输入风格描述'); return; }
  try {
    const d = await api('?action=create_custom_style', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({name, desc, prompt})
    });
    if (d.user) state.user = d.user;
    notice($('#styleNotice'),'ok', d.message || '已添加');
    $('#styleNameInput').value = '';
    $('#styleDescInput').value = '';
    $('#stylePromptInput').value = '';
    renderTemplates();
  } catch(e) { notice($('#styleNotice'),'err', e.message || '添加失败'); }
}

/* ---------- 事件绑定 ---------- */
document.addEventListener('click', e => {
  // 风格卡片选中
  const styleCard = e.target.closest('[data-style-id]');
  if (styleCard) {
    state.selectedStyleId = styleCard.dataset.styleId;
    $('#templateSelect').value = state.selectedStyleId;
    $$('#styleGrid .style-card').forEach(c => c.classList.toggle('selected', c.dataset.styleId === state.selectedStyleId));
    return;
  }
  // 删除自定义风格
  const ds = e.target.closest('[data-delete-style]');
  if (ds && window.confirm('确认删除这个自定义风格吗？')) {
    api('?action=delete_custom_style', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({style_id: ds.dataset.deleteStyle})
    }).then(d => { if (d.user) state.user = d.user; renderTemplates(); }).catch(err => notice($('#styleNotice'),'err', err.message || '删除失败'));
    return;
  }
  // 清除搜索
  if (e.target.id === 'clearMaskSearch') {
    e.preventDefault();
    state.maskSearch = '';
    state.maskPage = 1;
    $('#maskSearchInput').value = '';
    renderMasks();
    return;
  }
  // 分页
  const pageBtn = e.target.closest('[data-page]');
  if (pageBtn) {
    const v = pageBtn.dataset.page;
    const filtered = getFilteredMasks();
    const totalPages = Math.max(1, Math.ceil(filtered.length / state.maskPerPage));
    if (v === 'prev') state.maskPage = Math.max(1, state.maskPage - 1);
    else if (v === 'next') state.maskPage = Math.min(totalPages, state.maskPage + 1);
    else state.maskPage = parseInt(v, 10) || 1;
    renderMasks();
    return;
  }

  const goEl = e.target.closest('[data-go]');
  if (goEl) { e.preventDefault(); goPage(goEl.dataset.go); return; }
  const subEl = e.target.closest('[data-sub]');
  if (subEl) {
    e.preventDefault();
    if (!state.user) { openAuth('login'); return; }
    goSub(subEl.dataset.sub);
    return;
  }
  const tabEl = e.target.closest('[data-tab]');
  if (tabEl) { tab(tabEl.dataset.tab); return; }
  const r = e.target.closest('[data-rename]');
  if (r) {
    const id = r.dataset.rename;
    const cur = (state.masks || []).find(x => x.mask_id === id);
    const n = window.prompt('请输入新的蒙版名称', cur ? cur.name : '');
    if (n) {
      api('?action=rename_mask', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({mask_id: id, mask_name: n.trim()})
      }).then(() => bootstrap()).catch(err => notice($('#maskNotice'),'err', err.message || '重命名失败'));
    }
    return;
  }
  const d = e.target.closest('[data-delete]');
  if (d && window.confirm('确认删除这个蒙版吗？删除后不可恢复。')) {
    api('?action=delete_mask', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({mask_id: d.dataset.delete})
    }).then(() => bootstrap()).catch(err => notice($('#maskNotice'),'err', err.message || '删除失败'));
    return;
  }
  const c = e.target.closest('[data-cancel]');
  if (c && window.confirm('确认取消这个排队中的订单吗？已扣的额度会自动退回。')) {
    api('?action=cancel_order', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({order_id: c.dataset.cancel})
    }).then(() => bootstrap()).catch(err => alert(err.message || '取消失败'));
  }
});

/* ---------- 拖拽上传 ---------- */
function setSelectedFile(file){
  if (!file) return;
  if (!/^image\//.test(file.type)) { notice($('#maskNotice'),'err','请选择图片文件'); return; }
  state.selectedMaskFile = file;
  const dz = $('#maskDropzone');
  dz.classList.add('has-file');
  $('#dzTitle').textContent = '已选择：' + file.name + '（' + (file.size/1024).toFixed(0) + ' KB）— 点击或拖入可更换';
  // 自动用文件名补全名称（如果用户没填）
  if (!$('#maskNameInput').value.trim()) {
    const base = file.name.replace(/\.[^.]+$/, '');
    $('#maskNameInput').value = base.slice(0, 40);
  }
}
const dz = $('#maskDropzone');
if (dz) {
  dz.addEventListener('click', () => $('#maskFileInput').click());
  dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('dragover'); });
  dz.addEventListener('dragleave', () => dz.classList.remove('dragover'));
  dz.addEventListener('drop', e => {
    e.preventDefault();
    dz.classList.remove('dragover');
    const file = e.dataTransfer.files && e.dataTransfer.files[0];
    if (file) setSelectedFile(file);
  });
}
const mfi = $('#maskFileInput');
if (mfi) mfi.addEventListener('change', () => { const f = mfi.files && mfi.files[0]; if (f) setSelectedFile(f); });

/* ---------- 蒙版搜索/排序 ---------- */
const msi = $('#maskSearchInput');
if (msi) msi.addEventListener('input', () => { state.maskSearch = msi.value; state.maskPage = 1; renderMasks(); });
const mss = $('#maskSortSelect');
if (mss) mss.addEventListener('change', () => { state.maskSort = mss.value; state.maskPage = 1; renderMasks(); });

$('#topLoginBtn').addEventListener('click', () => openAuth('login'));
$('#topConsoleBtn').addEventListener('click', () => goSub('dashboard'));
$('#topLogoutBtn').addEventListener('click', logout);
$('#heroTryBtn').addEventListener('click', tryNow);
$('#ctaTryBtn').addEventListener('click', tryNow);
$('#authModal').addEventListener('click', e => { if (e.target === $('#authModal')) closeAuth(); });
$('#loginBtn').addEventListener('click', login);
$('#sendCodeBtn').addEventListener('click', sendCode);
$('#registerBtn').addEventListener('click', register);
$('#uploadMaskBtn').addEventListener('click', uploadMask);
$('#createOrderBtn').addEventListener('click', createOrder);
$('#redeemBtn').addEventListener('click', redeem);
$('#addStyleBtn').addEventListener('click', addCustomStyle);
$('#countInput').addEventListener('input', updateCount);
$('#estCountSelect').addEventListener('change', recomputeEstimate);

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAuth(); });

updateCount();
init().then(() => {
  // 实时刷新订单运行时长（每秒推进，前端本地累加更顺滑）
  setInterval(() => {
    if (!state.user) return;
    let needRender = false;
    state.orders.forEach(o => {
      if (o.status === 'running' && o.elapsed_seconds != null) {
        o.elapsed_seconds = (parseInt(o.elapsed_seconds, 10) || 0) + 1;
        if (o.remaining_seconds > 0) o.remaining_seconds = Math.max(0, o.remaining_seconds - 1);
        needRender = true;
      }
    });
    if (needRender) renderOrders();
  }, 1000);
  // 触发后台 worker
  setInterval(() => { queueTick(); }, 4000);
  // 拉取最新数据
  setInterval(() => { bootstrap().catch(() => {}); }, 3500);
});
</script>
</body>
</html>
<?php
    exit;
}
initRuntime();
cleanupExpiredOrdersIfNeeded();
$action = trim((string)($_GET['action'] ?? ''));
switch ($action) {
    case 'bootstrap': handleBootstrap(); break;
    case 'send_mobile_code': handleSendMobileCode(); break;
    case 'register': handleRegister(); break;
    case 'login': handleLogin(); break;
    case 'restore_login': handleRestoreLogin(); break;
    case 'logout': handleLogout(); break;
    case 'bind_mobile': handleBindMobile(); break;
    case 'upload_mask': handleUploadMask(); break;
    case 'rename_mask': handleRenameMask(); break;
    case 'delete_mask': handleDeleteMask(); break;
    case 'create_order': handleCreateOrder(); break;
    case 'order_status': handleOrderStatus(); break;
    case 'list_orders': handleListOrders(); break;
    case 'cancel_order': handleCancelOrder(); break;
    case 'redeem_card': handleRedeemCard(); break;
    case 'queue_tick': handleQueueTick(); break;
    case 'create_custom_style': handleCreateCustomStyle(); break;
    case 'delete_custom_style': handleDeleteCustomStyle(); break;
    case 'mask_file': handleMaskFile(); break;
    case 'order_file': handleOrderFile(); break;
    default: renderPage();
}