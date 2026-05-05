<?php

declare(strict_types=1);

session_start();

ini_set('display_errors', '1');
error_reporting(E_ALL);
ini_set('memory_limit', '1024M');
set_time_limit(0);

date_default_timezone_set('Asia/Shanghai');

// ============================================================
// 加载配置（与 index.php 共享同一份 config.php）
// ============================================================
require_once __DIR__ . '/config.php';
gaoqing_apply_config_constants();

const ADMIN_RUNTIME_DIR = '_airate_runtime';
const ADMIN_SESSION_KEY = 'gaoqing_admin_ok';
const ADMIN_CSRF_KEY = 'gaoqing_admin_csrf';
const ADMIN_PAGE_SIZE = 20;
// MASK_THRESHOLD 已经由 config.php 定义；此处不再重复

// 可配置的应用名（用于后台页面标题）
if (!defined('ADMIN_APP_NAME')) {
    define('ADMIN_APP_NAME', (defined('APP_NAME') ? APP_NAME : 'GaoQing') . ' 后台管理');
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nowIso(): string
{
    return date('c');
}

function nowTs(): int
{
    return time();
}

function runtimePath(string ...$parts): string
{
    $path = __DIR__ . DIRECTORY_SEPARATOR . ADMIN_RUNTIME_DIR;
    foreach ($parts as $part) {
        $path .= DIRECTORY_SEPARATOR . $part;
    }
    return $path;
}

function ensureDir(string $dir): string
{
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('无法创建目录：' . $dir);
    }
    return $dir;
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

function atomicWriteJson(string $path, array $data): void
{
    ensureDir(dirname($path));
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

function withFileLock(string $lockPath, callable $callback)
{
    ensureDir(dirname($lockPath));
    $fp = fopen($lockPath, 'c+');
    if ($fp === false) {
        throw new RuntimeException('无法创建锁文件：' . basename($lockPath));
    }
    try {
        if (!flock($fp, LOCK_EX)) {
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

function withUserLock(string $userId, callable $callback)
{
    return withFileLock(userLockPath($userId), static function () use ($callback) {
        return $callback();
    });
}

function getCsrfToken(): string
{
    if (empty($_SESSION[ADMIN_CSRF_KEY]) || !is_string($_SESSION[ADMIN_CSRF_KEY])) {
        $_SESSION[ADMIN_CSRF_KEY] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION[ADMIN_CSRF_KEY];
}

function requireCsrf(): void
{
    $header = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $posted = trim((string)($_POST['csrf_token'] ?? ''));
    $token = $header !== '' ? $header : $posted;
    if ($token === '' || !hash_equals(getCsrfToken(), $token)) {
        jsonResponse(['ok' => false, 'message' => '请求已失效，请刷新后重试'], 419);
    }
}

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function isAdminLoggedIn(): bool
{
    return !empty($_SESSION[ADMIN_SESSION_KEY]);
}

function requireAdmin(): void
{
    if (!isAdminLoggedIn()) {
        jsonResponse(['ok' => false, 'message' => '请先登录后台'], 401);
    }
}

function randomId(string $prefix = 'ID'): string
{
    return $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
}

function normalizeCardCode(string $code): string
{
    return strtoupper(str_replace([' ', '-', '_'], '', trim($code)));
}

function listAllUsers(): array
{
    $dir = runtimePath('users');
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
        $row = readJsonFile($dir . DIRECTORY_SEPARATOR . $item, []);
        if (($row['user_id'] ?? '') === '') {
            continue;
        }
        $rows[] = $row;
    }
    usort($rows, static fn(array $a, array $b): int => (int)($b['updated_ts'] ?? 0) <=> (int)($a['updated_ts'] ?? 0));
    return $rows;
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
        $meta = readJsonFile($dir . DIRECTORY_SEPARATOR . $item . DIRECTORY_SEPARATOR . 'meta.json', []);
        if (($meta['order_id'] ?? '') === '') {
            continue;
        }
        $rows[] = $meta;
    }
    usort($rows, static fn(array $a, array $b): int => (int)($b['updated_ts'] ?? 0) <=> (int)($a['updated_ts'] ?? 0));
    return $rows;
}

function listAllMasks(): array
{
    $base = runtimePath('masks');
    if (!is_dir($base)) {
        return [];
    }
    $users = scandir($base);
    if ($users === false) {
        return [];
    }
    $rows = [];
    foreach ($users as $userId) {
        if ($userId === '.' || $userId === '..') {
            continue;
        }
        $dir = $base . DIRECTORY_SEPARATOR . $userId;
        if (!is_dir($dir)) {
            continue;
        }
        $items = scandir($dir);
        if ($items === false) {
            continue;
        }
        foreach ($items as $item) {
            if (!str_ends_with($item, '.json')) {
                continue;
            }
            $meta = readJsonFile($dir . DIRECTORY_SEPARATOR . $item, []);
            if (($meta['mask_id'] ?? '') === '') {
                continue;
            }
            $rows[] = $meta;
        }
    }
    usort($rows, static fn(array $a, array $b): int => (int)($b['updated_ts'] ?? 0) <=> (int)($a['updated_ts'] ?? 0));
    return $rows;
}

function adminUserPublic(array $user): array
{
    return [
        'user_id' => (string)($user['user_id'] ?? ''),
        'username' => (string)($user['username'] ?? ''),
        'mobile' => (string)($user['mobile'] ?? ''),
        'status' => (string)($user['status'] ?? 'active'),
        'credits' => (int)($user['credits'] ?? 0),
        'total_spent' => (int)($user['total_spent'] ?? 0),
        'total_generated' => (int)($user['total_generated'] ?? 0),
        'total_orders' => (int)($user['total_orders'] ?? 0),
        'created_at' => (string)($user['created_at'] ?? ''),
        'updated_at' => (string)($user['updated_at'] ?? ''),
        'last_login_at' => $user['last_login_at'] ?? null,
        'last_login_ip' => $user['last_login_ip'] ?? null,
        'inviter_username' => (string)($user['inviter_username'] ?? ''),
        'remember_token_hash' => trim((string)($user['remember_token_hash'] ?? '')) !== '',
        'credit_logs' => array_slice(is_array($user['credit_logs'] ?? null) ? $user['credit_logs'] : [], 0, 100),
        'custom_style_count' => count(is_array($user['custom_styles'] ?? null) ? $user['custom_styles'] : []),
    ];
}

function adminMaskPublic(array $meta): array
{
    return [
        'mask_id' => (string)($meta['mask_id'] ?? ''),
        'user_id' => (string)($meta['user_id'] ?? ''),
        'name' => (string)($meta['name'] ?? ''),
        'width' => (int)($meta['width'] ?? 0),
        'height' => (int)($meta['height'] ?? 0),
        'polarity' => (string)($meta['polarity'] ?? ''),
        'resolved_polarity' => (string)($meta['resolved_polarity'] ?? ''),
        'created_at' => (string)($meta['created_at'] ?? ''),
        'updated_at' => (string)($meta['updated_at'] ?? ''),
        'preview_url' => '?action=mask_preview&user_id=' . rawurlencode((string)($meta['user_id'] ?? '')) . '&mask_id=' . rawurlencode((string)($meta['mask_id'] ?? '')),
    ];
}

function adminOrderPublic(array $meta): array
{
    $files = is_array($meta['files'] ?? null) ? $meta['files'] : [];
    $sets = [];
    $count = max(1, min(5, (int)($meta['design_count'] ?? 1)));
    for ($i = 1; $i <= $count; $i++) {
        $key = 'set' . $i . '_masked';
        $sets[] = [
            'title' => '第 ' . $i . ' 张设计稿',
            'image_url' => !empty($files[$key]) ? '?action=order_file&order_id=' . rawurlencode((string)$meta['order_id']) . '&key=' . rawurlencode($key) : '',
        ];
    }
    return [
        'order_id' => (string)($meta['order_id'] ?? ''),
        'user_id' => (string)($meta['user_id'] ?? ''),
        'username' => (string)($meta['username'] ?? ''),
        'theme' => (string)($meta['theme'] ?? ''),
        'mask_name' => (string)($meta['mask_name'] ?? ''),
        'template_name' => (string)($meta['template_name'] ?? ''),
        'status' => (string)($meta['status'] ?? ''),
        'status_text' => (string)($meta['status_text'] ?? ''),
        'progress' => (int)($meta['progress'] ?? 0),
        'current_step' => (string)($meta['current_step'] ?? ''),
        'error_message' => (string)($meta['error_message'] ?? ''),
        'design_count' => $count,
        'generated_count' => (int)($meta['generated_count'] ?? 0),
        'reserved_credits' => (int)($meta['reserved_credits'] ?? 0),
        'spent_credits' => (int)($meta['spent_credits'] ?? 0),
        'refund_credits' => (int)($meta['refund_credits'] ?? 0),
        'created_at' => (string)($meta['created_at'] ?? ''),
        'started_at' => $meta['started_at'] ?? null,
        'finished_at' => $meta['finished_at'] ?? null,
        'zip_url' => !empty($files['package_zip']) ? '?action=order_file&order_id=' . rawurlencode((string)$meta['order_id']) . '&key=package_zip&download=1' : '',
        'sets' => $sets,
    ];
}

function paginate(array $rows, int $page, int $size = ADMIN_PAGE_SIZE): array
{
    $page = max(1, $page);
    $size = max(1, min(100, $size));
    $total = count($rows);
    $pages = max(1, (int)ceil($total / $size));
    if ($page > $pages) {
        $page = $pages;
    }
    return [
        'items' => array_slice($rows, ($page - 1) * $size, $size),
        'page' => $page,
        'size' => $size,
        'total' => $total,
        'pages' => $pages,
    ];
}

function handleLogin(): never
{
    requireCsrf();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $cfg = gaoqing_load_config();
    $expectedUser = (string)($cfg['admin']['username'] ?? 'admin');
    $passwordHash = (string)($cfg['admin']['password_hash'] ?? '');

    $userOk = hash_equals($expectedUser, $username);
    $passOk = $passwordHash !== '' && password_verify($password, $passwordHash);

    if (!$userOk || !$passOk) {
        // 增加 1 秒的延迟，缓解暴力破解
        usleep(1000000);
        jsonResponse(['ok' => false, 'message' => '账号或密码错误'], 400);
    }
    $_SESSION[ADMIN_SESSION_KEY] = true;
    $_SESSION['admin_login_at'] = nowIso();
    jsonResponse(['ok' => true, 'message' => '登录成功']);
}

function handleLogout(): never
{
    requireCsrf();
    unset($_SESSION[ADMIN_SESSION_KEY]);
    jsonResponse(['ok' => true, 'message' => '已退出']);
}

function handleBootstrap(): never
{
    $payload = [
        'ok' => true,
        'csrf_token' => getCsrfToken(),
        'logged_in' => isAdminLoggedIn(),
    ];
    if (!isAdminLoggedIn()) {
        jsonResponse($payload);
    }

    $users = listAllUsers();
    $orders = listAllOrders();
    $masks = listAllMasks();
    $cards = readJsonFile(cardStorePath(), ['cards' => []]);
    $cardsRows = is_array($cards['cards'] ?? null) ? $cards['cards'] : [];

    $todayStart = strtotime(date('Y-m-d 00:00:00')) ?: 0;
    $todayRechargeCredits = 0;
    $todayRechargeCount = 0;
    $totalRechargeCredits = 0;
    $usedCards = 0;
    $unusedCards = 0;
    $invalidCards = 0;
    foreach ($cardsRows as $card) {
        $credits = (int)($card['credits'] ?? 0);
        if (!empty($card['invalid'])) {
            $invalidCards++;
        }
        if (!empty($card['used'])) {
            $usedCards++;
            $totalRechargeCredits += $credits;
            $usedAt = !empty($card['used_at']) ? (strtotime((string)$card['used_at']) ?: 0) : 0;
            if ($usedAt >= $todayStart) {
                $todayRechargeCredits += $credits;
                $todayRechargeCount++;
            }
        } else {
            $unusedCards++;
        }
    }

    $runningOrders = 0;
    $queuedOrders = 0;
    $doneOrders = 0;
    $errorOrders = 0;
    $todayOrders = 0;
    $todayGenerated = 0;
    $reservedCredits = 0;
    foreach ($orders as $order) {
        $status = (string)($order['status'] ?? '');
        if ($status === 'running') $runningOrders++;
        if ($status === 'queued') $queuedOrders++;
        if ($status === 'done') $doneOrders++;
        if ($status === 'error') $errorOrders++;
        $createdTs = (int)($order['created_ts'] ?? 0);
        if ($createdTs >= $todayStart) {
            $todayOrders++;
            $todayGenerated += (int)($order['generated_count'] ?? 0);
        }
        if (in_array($status, ['queued', 'running'], true)) {
            $reservedCredits += max(0, (int)($order['reserved_credits'] ?? 0) - (int)($order['spent_credits'] ?? 0) - (int)($order['refund_credits'] ?? 0));
        }
    }

    $activeUsers = 0;
    $bannedUsers = 0;
    $totalCredits = 0;
    foreach ($users as $user) {
        if (($user['status'] ?? 'active') === 'banned') {
            $bannedUsers++;
        } else {
            $activeUsers++;
        }
        $totalCredits += (int)($user['credits'] ?? 0);
    }

    $payload['dashboard'] = [
        'user_count' => count($users),
        'active_users' => $activeUsers,
        'banned_users' => $bannedUsers,
        'mask_count' => count($masks),
        'order_count' => count($orders),
        'running_orders' => $runningOrders,
        'queued_orders' => $queuedOrders,
        'done_orders' => $doneOrders,
        'error_orders' => $errorOrders,
        'total_user_credits' => $totalCredits,
        'reserved_credits' => $reservedCredits,
        'total_recharge_credits' => $totalRechargeCredits,
        'today_recharge_credits' => $todayRechargeCredits,
        'today_recharge_count' => $todayRechargeCount,
        'today_orders' => $todayOrders,
        'today_generated' => $todayGenerated,
        'card_total' => count($cardsRows),
        'card_used' => $usedCards,
        'card_unused' => $unusedCards,
        'card_invalid' => $invalidCards,
        'recent_orders' => array_map('adminOrderPublic', array_slice($orders, 0, 8)),
    ];
    jsonResponse($payload);
}

function handleListUsers(): never
{
    requireAdmin();
    $keyword = trim((string)($_GET['keyword'] ?? ''));
    $page = (int)($_GET['page'] ?? 1);
    $rows = listAllUsers();
    if ($keyword !== '') {
        $kw = mb_strtolower($keyword, 'UTF-8');
        $rows = array_values(array_filter($rows, static function (array $u) use ($kw): bool {
            $hay = mb_strtolower(
                (string)($u['user_id'] ?? '') . ' ' . (string)($u['username'] ?? '') . ' ' . (string)($u['mobile'] ?? ''),
                'UTF-8'
            );
            return str_contains($hay, $kw);
        }));
    }
    $pager = paginate($rows, $page);
    $pager['items'] = array_map('adminUserPublic', $pager['items']);
    jsonResponse(['ok' => true, 'result' => $pager]);
}

function handleUserDetail(): never
{
    requireAdmin();
    $userId = trim((string)($_GET['user_id'] ?? ''));
    if ($userId === '') {
        jsonResponse(['ok' => false, 'message' => '缺少 user_id'], 400);
    }
    $user = readUser($userId);
    jsonResponse(['ok' => true, 'user' => adminUserPublic($user)]);
}

function adjustUserCreditsAdmin(string $userId, int $delta, string $type, string $note): array
{
    return withUserLock($userId, static function () use ($userId, $delta, $type, $note): array {
        $user = readUser($userId);
        $current = (int)($user['credits'] ?? 0);
        $next = $current + $delta;
        if ($next < 0) {
            throw new RuntimeException('用户额度不足，无法扣减这么多');
        }
        $user['credits'] = $next;
        $logs = is_array($user['credit_logs'] ?? null) ? $user['credit_logs'] : [];
        array_unshift($logs, [
            'time' => nowIso(),
            'delta' => $delta,
            'type' => $type,
            'note' => $note,
            'balance' => $next,
            'extra' => ['source' => 'admin'],
        ]);
        $user['credit_logs'] = array_slice($logs, 0, 100);
        saveUser($user);
        return $user;
    });
}

function handleUpdateUserStatus(): never
{
    requireAdmin();
    requireCsrf();
    try {
        $userId = trim((string)($_POST['user_id'] ?? ''));
        $status = trim((string)($_POST['status'] ?? 'active'));
        if (!in_array($status, ['active', 'banned'], true)) {
            throw new RuntimeException('状态不合法');
        }
        $user = withUserLock($userId, static function () use ($userId, $status): array {
            $u = readUser($userId);
            $u['status'] = $status;
            if ($status === 'banned') {
                $u['remember_token_hash'] = '';
                unset($_SESSION['user_id']);
                withFileLock(systemLockPath(), static function () use ($userId): void {
                    $indices = loadIndices();
                    foreach ($indices['auth_index'] as $hash => $uid) {
                        if ((string)$uid === $userId) {
                            unset($indices['auth_index'][$hash]);
                        }
                    }
                    saveIndices($indices);
                });
            }
            saveUser($u);
            return $u;
        });
        jsonResponse(['ok' => true, 'message' => $status === 'banned' ? '已封禁用户' : '已解除封禁', 'user' => adminUserPublic($user)]);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

function handleResetUserPassword(): never
{
    requireAdmin();
    requireCsrf();
    try {
        $userId = trim((string)($_POST['user_id'] ?? ''));
        $password = (string)($_POST['new_password'] ?? '');
        if (strlen($password) < 6 || strlen($password) > 32) {
            throw new RuntimeException('新密码长度需在 6-32 位之间');
        }
        $user = withUserLock($userId, static function () use ($userId, $password): array {
            $u = readUser($userId);
            $u['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $u['remember_token_hash'] = '';
            saveUser($u);
            return $u;
        });
        withFileLock(systemLockPath(), static function () use ($userId): void {
            $indices = loadIndices();
            foreach ($indices['auth_index'] as $hash => $uid) {
                if ((string)$uid === $userId) {
                    unset($indices['auth_index'][$hash]);
                }
            }
            saveIndices($indices);
        });
        jsonResponse(['ok' => true, 'message' => '密码已重置，并强制该用户重新登录', 'user' => adminUserPublic($user)]);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

function handleAdjustCredits(): never
{
    requireAdmin();
    requireCsrf();
    try {
        $userId = trim((string)($_POST['user_id'] ?? ''));
        $delta = (int)($_POST['delta'] ?? 0);
        $note = trim((string)($_POST['note'] ?? '后台调整额度'));
        if ($delta === 0) {
            throw new RuntimeException('调整额度不能为 0');
        }
        $user = adjustUserCreditsAdmin($userId, $delta, 'admin_adjust', $note);
        jsonResponse(['ok' => true, 'message' => '额度已调整', 'user' => adminUserPublic($user)]);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

function handleListMasks(): never
{
    requireAdmin();
    $keyword = trim((string)($_GET['keyword'] ?? ''));
    $page = (int)($_GET['page'] ?? 1);
    $rows = listAllMasks();
    if ($keyword !== '') {
        $kw = mb_strtolower($keyword, 'UTF-8');
        $rows = array_values(array_filter($rows, static function (array $m) use ($kw): bool {
            $hay = mb_strtolower((string)($m['mask_id'] ?? '') . ' ' . (string)($m['name'] ?? '') . ' ' . (string)($m['user_id'] ?? ''), 'UTF-8');
            return str_contains($hay, $kw);
        }));
    }
    $pager = paginate($rows, $page);
    $pager['items'] = array_map('adminMaskPublic', $pager['items']);
    jsonResponse(['ok' => true, 'result' => $pager]);
}

function handleListOrders(): never
{
    requireAdmin();
    $keyword = trim((string)($_GET['keyword'] ?? ''));
    $status = trim((string)($_GET['status'] ?? ''));
    $page = (int)($_GET['page'] ?? 1);
    $rows = listAllOrders();
    if ($keyword !== '') {
        $kw = mb_strtolower($keyword, 'UTF-8');
        $rows = array_values(array_filter($rows, static function (array $o) use ($kw): bool {
            $hay = mb_strtolower(
                (string)($o['order_id'] ?? '') . ' ' . (string)($o['theme'] ?? '') . ' ' . (string)($o['username'] ?? '') . ' ' . (string)($o['user_id'] ?? '') . ' ' . (string)($o['mask_name'] ?? ''),
                'UTF-8'
            );
            return str_contains($hay, $kw);
        }));
    }
    if ($status !== '') {
        $rows = array_values(array_filter($rows, static fn(array $o): bool => (string)($o['status'] ?? '') === $status));
    }
    $pager = paginate($rows, $page);
    $pager['items'] = array_map('adminOrderPublic', $pager['items']);
    jsonResponse(['ok' => true, 'result' => $pager]);
}

function handleListCards(): never
{
    requireAdmin();
    $keyword = trim((string)($_GET['keyword'] ?? ''));
    $page = (int)($_GET['page'] ?? 1);
    $store = readJsonFile(cardStorePath(), ['cards' => []]);
    $rows = is_array($store['cards'] ?? null) ? $store['cards'] : [];
    usort($rows, static function (array $a, array $b): int {
        $at = !empty($a['created_at']) ? (strtotime((string)$a['created_at']) ?: 0) : 0;
        $bt = !empty($b['created_at']) ? (strtotime((string)$b['created_at']) ?: 0) : 0;
        return $bt <=> $at;
    });
    if ($keyword !== '') {
        $kw = mb_strtolower($keyword, 'UTF-8');
        $rows = array_values(array_filter($rows, static function (array $c) use ($kw): bool {
            $hay = mb_strtolower(
                (string)($c['code'] ?? '') . ' ' . (string)($c['used_by'] ?? '') . ' ' . (string)($c['batch_id'] ?? ''),
                'UTF-8'
            );
            return str_contains($hay, $kw);
        }));
    }
    $pager = paginate($rows, $page, 30);
    jsonResponse(['ok' => true, 'result' => $pager]);
}

function generateCardCode(): string
{
    return strtoupper(bin2hex(random_bytes(4)) . bin2hex(random_bytes(4)) . bin2hex(random_bytes(2)));
}

function handleGenerateCards(): never
{
    requireAdmin();
    requireCsrf();
    try {
        $credits = (int)($_POST['credits'] ?? 0);
        $count = (int)($_POST['count'] ?? 1);
        $prefix = strtoupper(trim((string)($_POST['prefix'] ?? '')));
        if ($credits <= 0) {
            throw new RuntimeException('请输入正确的额度');
        }
        if ($count < 1 || $count > 500) {
            throw new RuntimeException('一次最多生成 500 个卡密');
        }
        $batchId = 'BATCH_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
        $created = withFileLock(systemLockPath(), static function () use ($credits, $count, $prefix, $batchId): array {
            $store = readJsonFile(cardStorePath(), ['cards' => [], 'updated_at' => nowIso(), 'updated_ts' => nowTs()]);
            $cards = is_array($store['cards'] ?? null) ? $store['cards'] : [];
            $exists = [];
            foreach ($cards as $card) {
                $exists[normalizeCardCode((string)($card['code'] ?? ''))] = true;
            }
            $newCodes = [];
            for ($i = 0; $i < $count; $i++) {
                do {
                    $code = ($prefix !== '' ? $prefix : '') . generateCardCode();
                    $code = normalizeCardCode($code);
                } while (isset($exists[$code]));
                $exists[$code] = true;
                $row = [
                    'code' => $code,
                    'credits' => $credits,
                    'used' => false,
                    'invalid' => false,
                    'used_by' => '',
                    'used_at' => null,
                    'created_at' => nowIso(),
                    'batch_id' => $batchId,
                ];
                $cards[] = $row;
                $newCodes[] = $row;
            }
            $store['cards'] = $cards;
            $store['updated_at'] = nowIso();
            $store['updated_ts'] = nowTs();
            atomicWriteJson(cardStorePath(), $store);
            return $newCodes;
        });
        jsonResponse(['ok' => true, 'message' => '卡密生成成功', 'batch_id' => $batchId, 'cards' => $created]);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

function handleInvalidateCard(): never
{
    requireAdmin();
    requireCsrf();
    try {
        $code = normalizeCardCode((string)($_POST['code'] ?? ''));
        if ($code === '') {
            throw new RuntimeException('缺少卡密');
        }
        withFileLock(systemLockPath(), static function () use ($code): void {
            $store = readJsonFile(cardStorePath(), ['cards' => []]);
            $cards = is_array($store['cards'] ?? null) ? $store['cards'] : [];
            $found = false;
            foreach ($cards as &$card) {
                if (normalizeCardCode((string)($card['code'] ?? '')) !== $code) {
                    continue;
                }
                $found = true;
                if (!empty($card['used'])) {
                    throw new RuntimeException('已使用的卡密不能作废');
                }
                $card['invalid'] = true;
                break;
            }
            unset($card);
            if (!$found) {
                throw new RuntimeException('卡密不存在');
            }
            $store['cards'] = $cards;
            $store['updated_at'] = nowIso();
            $store['updated_ts'] = nowTs();
            atomicWriteJson(cardStorePath(), $store);
        });
        jsonResponse(['ok' => true, 'message' => '卡密已作废']);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

function handleDeleteCard(): never
{
    requireAdmin();
    requireCsrf();
    try {
        $code = normalizeCardCode((string)($_POST['code'] ?? ''));
        if ($code === '') {
            throw new RuntimeException('缺少卡密');
        }
        withFileLock(systemLockPath(), static function () use ($code): void {
            $store = readJsonFile(cardStorePath(), ['cards' => []]);
            $cards = is_array($store['cards'] ?? null) ? $store['cards'] : [];
            $before = count($cards);
            $cards = array_values(array_filter($cards, static fn(array $c): bool => normalizeCardCode((string)($c['code'] ?? '')) !== $code));
            if (count($cards) === $before) {
                throw new RuntimeException('卡密不存在');
            }
            $store['cards'] = $cards;
            $store['updated_at'] = nowIso();
            $store['updated_ts'] = nowTs();
            atomicWriteJson(cardStorePath(), $store);
        });
        jsonResponse(['ok' => true, 'message' => '卡密已删除']);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

function handleMaskPreview(): never
{
    requireAdmin();
    $userId = trim((string)($_GET['user_id'] ?? ''));
    $maskId = trim((string)($_GET['mask_id'] ?? ''));
    $path = maskImagePath($userId, $maskId);
    if (!is_file($path)) {
        http_response_code(404);
        exit('not found');
    }
    header('Content-Type: image/png');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    readfile($path);
    exit;
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

function handleOrderFile(): never
{
    requireAdmin();
    $orderId = trim((string)($_GET['order_id'] ?? ''));
    $key = trim((string)($_GET['key'] ?? ''));
    $download = ((string)($_GET['download'] ?? '') === '1');
    $meta = readJsonFile(orderMetaPath($orderId), []);
    if (($meta['order_id'] ?? '') !== $orderId) {
        http_response_code(404);
        exit('not found');
    }
    $files = is_array($meta['files'] ?? null) ? $meta['files'] : [];
    $filename = trim((string)($files[$key] ?? ''));
    if ($filename === '') {
        http_response_code(404);
        exit('not found');
    }
    $path = orderFilePath($orderId, $filename);
    if (!is_file($path)) {
        http_response_code(404);
        exit('not found');
    }
    header('Content-Type: ' . detectMimeByExtension($filename));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    if ($download) {
        $safe = rawurlencode($filename);
        header("Content-Disposition: attachment; filename*=UTF-8''{$safe}");
    }
    readfile($path);
    exit;
}

function handleGetConfig(): never
{
    requireAdmin();
    $cfg = gaoqing_load_config(true);
    // 出于安全考虑，不把已有的密码哈希原样回显到前端
    if (isset($cfg['admin']['password_hash'])) {
        $cfg['admin']['password_hash'] = $cfg['admin']['password_hash'] !== '' ? '__SET__' : '';
    }
    jsonResponse(['ok' => true, 'config' => $cfg]);
}

/**
 * 从前端 POST 中读取嵌套字段，比如 form 里 cfg[gpt][api_key] 可以直接收到
 */
function readPostedConfig(): array
{
    if (!isset($_POST['cfg']) || !is_array($_POST['cfg'])) {
        return [];
    }
    return $_POST['cfg'];
}

function handleSaveConfig(): never
{
    requireAdmin();
    requireCsrf();
    try {
        $posted = readPostedConfig();
        if (empty($posted)) {
            throw new RuntimeException('未提交任何配置');
        }
        $current = gaoqing_load_config(true);

        // 合法性整理：把字符串 'true'/'false' 转换为 bool
        $coerce = static function (mixed $v): mixed {
            if ($v === 'true') return true;
            if ($v === 'false') return false;
            if ($v === '') return '';
            return $v;
        };
        array_walk_recursive($posted, static function (&$v) use ($coerce): void {
            $v = $coerce($v);
        });

        // 数值字段自动转为 int / float
        $intFields = [
            'business.start_credits', 'business.credit_per_image',
            'business.max_concurrent_orders', 'business.estimated_seconds_per_image',
            'business.order_retention_seconds',
            'limits.max_theme_length', 'limits.max_mask_name_length',
            'limits.max_username_length', 'limits.max_password_length',
            'limits.min_password_length', 'limits.max_mask_upload_size',
            'limits.max_design_set_count', 'limits.min_design_set_count',
            'limits.max_mask_width', 'limits.max_mask_height',
            'sms.code_expire_seconds', 'sms.send_cooldown_seconds',
            'gpt.max_tokens', 'gpt.retry_times', 'gpt.connect_timeout', 'gpt.timeout',
            'image_gen.poll_interval_us', 'image_gen.max_polls',
            'image_gen.connect_timeout', 'image_gen.timeout',
            'image_gen.step_max_retries', 'image_gen.step_retry_delay_us',
        ];
        $floatFields = ['gpt.temperature'];
        $boolFields = ['sms.enabled', 'image_gen.shut_progress'];

        $apply = static function (array &$cfg, string $path, callable $caster): void {
            $segs = explode('.', $path);
            $ref = &$cfg;
            foreach ($segs as $seg) {
                if (!is_array($ref) || !array_key_exists($seg, $ref)) {
                    return;
                }
                $ref = &$ref[$seg];
            }
            $ref = $caster($ref);
        };

        $merged = gaoqing_merge_config($current, $posted);
        foreach ($intFields as $f) {
            $apply($merged, $f, static fn($v) => (int)$v);
        }
        foreach ($floatFields as $f) {
            $apply($merged, $f, static fn($v) => (float)$v);
        }
        foreach ($boolFields as $f) {
            $apply($merged, $f, static fn($v) => (bool)$v);
        }

        // 不允许通过 cfg.admin.password_hash 直接覆盖密码（必须走 change_admin_password 接口）
        if (isset($merged['admin']['password_hash'])
            && $merged['admin']['password_hash'] === '__SET__') {
            $merged['admin']['password_hash'] = $current['admin']['password_hash'] ?? '';
        }
        if (empty($merged['admin']['username'])) {
            $merged['admin']['username'] = 'admin';
        }
        if (empty($merged['admin']['password_hash'])) {
            $merged['admin']['password_hash'] = $current['admin']['password_hash'] ?? '';
        }

        gaoqing_save_config($merged);
        jsonResponse(['ok' => true, 'message' => '配置已保存。部分项可能需要刷新页面后生效。']);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

function handleChangeAdminPassword(): never
{
    requireAdmin();
    requireCsrf();
    try {
        $oldPassword = (string)($_POST['old_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $newUsername = trim((string)($_POST['new_username'] ?? ''));
        if (strlen($newPassword) < 8 || strlen($newPassword) > 64) {
            throw new RuntimeException('新密码长度需在 8-64 位之间');
        }
        $cfg = gaoqing_load_config(true);
        $hash = (string)($cfg['admin']['password_hash'] ?? '');
        if ($hash === '' || !password_verify($oldPassword, $hash)) {
            usleep(1000000);
            throw new RuntimeException('当前密码不正确');
        }
        if ($newUsername !== '') {
            if (!preg_match('/^[A-Za-z0-9_.\-]{2,32}$/', $newUsername)) {
                throw new RuntimeException('用户名只能用 字母 / 数字 / _ . -，长度 2-32');
            }
            $cfg['admin']['username'] = $newUsername;
        }
        $cfg['admin']['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        gaoqing_save_config($cfg);
        // 退出当前登录，强制用新凭证登入
        unset($_SESSION[ADMIN_SESSION_KEY]);
        jsonResponse(['ok' => true, 'message' => '管理员凭证已更新，请用新账号密码重新登录']);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

function handleTestApi(): never
{
    requireAdmin();
    requireCsrf();
    $type = trim((string)($_POST['type'] ?? ''));
    try {
        if ($type === 'gpt') {
            $cfg = gaoqing_load_config();
            $key = trim((string)$cfg['gpt']['api_key']);
            $url = trim((string)$cfg['gpt']['api_url']);
            $model = trim((string)$cfg['gpt']['model']);
            if ($key === '' || $url === '') {
                throw new RuntimeException('GPT API 未配置');
            }
            $body = json_encode([
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => 'ping']],
                'max_tokens' => 10,
            ], JSON_UNESCAPED_UNICODE);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $key,
                ],
                CURLOPT_POSTFIELDS => $body,
            ]);
            $resp = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($errno !== 0) {
                throw new RuntimeException('请求失败：' . $error);
            }
            if ($httpCode < 200 || $httpCode >= 300) {
                throw new RuntimeException('HTTP ' . $httpCode . '：' . substr((string)$resp, 0, 300));
            }
            jsonResponse(['ok' => true, 'message' => 'GPT 接口连通正常']);
        } elseif ($type === 'image_gen') {
            $cfg = gaoqing_load_config();
            $key = trim((string)$cfg['image_gen']['api_key']);
            if ($key === '') {
                throw new RuntimeException('绘图 API Key 未配置');
            }
            jsonResponse(['ok' => true, 'message' => '绘图 API Key 已设置（具体连通性请通过下单测试）']);
        } else {
            throw new RuntimeException('未知的测试类型');
        }
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

function renderPage(): never
{
    $csrf = getCsrfToken();
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(ADMIN_APP_NAME) ?></title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"PingFang SC","Microsoft YaHei",sans-serif;background:#f4f7fb;color:#0f172a}button,input,select,textarea{font:inherit}a{text-decoration:none;color:inherit}.hidden{display:none!important}
:root{--bg:#f4f7fb;--card:#fff;--line:#e6edf5;--primary:#4f46e5;--primary2:#7c3aed;--text:#0f172a;--muted:#64748b;--green:#16a34a;--red:#dc2626;--orange:#d97706;--blue:#2563eb;--radius:18px;--shadow:0 10px 30px rgba(15,23,42,.08)}
.top{position:sticky;top:0;z-index:50;background:rgba(15,23,42,.88);backdrop-filter:blur(12px);color:#fff;border-bottom:1px solid rgba(255,255,255,.08)}.top-inner{height:64px;display:flex;align-items:center;justify-content:space-between;max-width:1440px;margin:0 auto;padding:0 20px}.brand{font-weight:900;letter-spacing:.3px}.top-actions{display:flex;gap:10px;align-items:center}
.btn{border:none;border-radius:12px;padding:10px 16px;cursor:pointer;font-weight:700}.btn-primary{background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff}.btn-light{background:#fff;color:var(--text);border:1px solid var(--line)}.btn-danger{background:#fff;color:var(--red);border:1px solid #fecaca}.btn-sm{padding:7px 11px;border-radius:9px;font-size:12px}
.wrap{max-width:1440px;margin:0 auto;padding:20px}.layout{display:grid;grid-template-columns:240px minmax(0,1fr);gap:20px}.side{background:var(--card);border:1px solid var(--line);border-radius:22px;padding:16px;position:sticky;top:84px;height:fit-content;box-shadow:var(--shadow)}.navbtn{width:100%;text-align:left;border:none;background:transparent;padding:12px 14px;border-radius:12px;font-weight:700;color:#334155;cursor:pointer;margin-bottom:6px}.navbtn.active,.navbtn:hover{background:#eef2ff;color:#4338ca}
.content{min-width:0}.panel{background:var(--card);border:1px solid var(--line);border-radius:22px;padding:20px;box-shadow:var(--shadow)}.subpage{display:none}.subpage.active{display:block}.grid4{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}.stat{padding:18px;border:1px solid var(--line);border-radius:18px;background:#fff}.stat .label{font-size:12px;color:var(--muted);font-weight:700}.stat .value{font-size:28px;font-weight:900;margin-top:8px}
.tablewrap{overflow:auto;border:1px solid var(--line);border-radius:16px}.table{width:100%;border-collapse:collapse;background:#fff}.table th,.table td{padding:12px 14px;border-bottom:1px solid var(--line);font-size:13px;text-align:left;vertical-align:top}.table th{background:#f8fafc;position:sticky;top:0}.toolbar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}.input,.select,.textarea{border:1px solid var(--line);background:#fff;border-radius:12px;padding:10px 12px;outline:none}.textarea{width:100%;min-height:90px;resize:vertical}.badge{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:800}.b-active{background:#dcfce7;color:#166534}.b-banned{background:#fee2e2;color:#991b1b}.b-queued{background:#fef3c7;color:#92400e}.b-running{background:#dbeafe;color:#1d4ed8}.b-done{background:#dcfce7;color:#166534}.b-error{background:#fee2e2;color:#991b1b}.b-cancelled{background:#e2e8f0;color:#475569}.notice{margin:12px 0;padding:12px 14px;border-radius:12px;display:none}.notice.show{display:block}.notice.ok{background:#dcfce7;color:#166534}.notice.err{background:#fee2e2;color:#991b1b}.cards{display:grid;grid-template-columns:1.2fr .8fr;gap:18px}.loginbox{max-width:460px;margin:80px auto;background:#fff;border:1px solid var(--line);border-radius:22px;padding:26px;box-shadow:var(--shadow)}.loginbox h1{margin:0 0 8px;font-size:28px}.muted{color:var(--muted)}.thumb{width:84px;height:84px;object-fit:contain;border:1px solid var(--line);border-radius:10px;background:#fff}.order-set{display:flex;gap:8px;flex-wrap:wrap}.order-set img{width:74px;height:74px;object-fit:contain;border:1px solid var(--line);border-radius:10px;background:#fff}.pager{display:flex;gap:8px;align-items:center;justify-content:flex-end;margin-top:12px}.drawer{position:fixed;right:0;top:0;bottom:0;width:min(560px,100%);background:#fff;border-left:1px solid var(--line);box-shadow:-20px 0 50px rgba(0,0,0,.08);transform:translateX(100%);transition:transform .25s ease;z-index:90;padding:20px;overflow:auto}.drawer.show{transform:translateX(0)}.overlay{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;z-index:80}.overlay.show{display:block}.kv{display:grid;grid-template-columns:120px 1fr;gap:8px;font-size:13px}.kv div{padding:8px 0;border-bottom:1px dashed var(--line)}
@media(max-width:1100px){.layout,.cards,.grid4{grid-template-columns:1fr}.side{position:static}}
</style>
</head>
<body>
<div class="top"><div class="top-inner"><div class="brand">稿擎后台管理系统</div><div class="top-actions"><span class="muted" id="topState">加载中...</span><button class="btn btn-light hidden" id="logoutBtn" type="button">退出登录</button></div></div></div>

<div id="loginView" class="hidden">
  <div class="loginbox">
    <h1>后台登录</h1>
    <p class="muted">默认账号：admin / 默认密码：admin123 — <b>登录后请立即在「系统配置」中修改密码</b></p>
    <div class="notice" id="loginNotice"></div>
    <div style="display:flex;flex-direction:column;gap:12px;margin-top:16px">
      <input class="input" id="loginUsername" placeholder="后台账号" value="admin">
      <input class="input" id="loginPassword" placeholder="后台密码" type="password" value="">
      <button class="btn btn-primary" id="loginBtn" type="button">登录后台</button>
    </div>
  </div>
</div>

<div id="appView" class="hidden">
  <div class="wrap layout">
    <aside class="side">
      <button class="navbtn active" data-sub="dashboard">仪表盘</button>
      <button class="navbtn" data-sub="users">用户表</button>
      <button class="navbtn" data-sub="masks">蒙版管理</button>
      <button class="navbtn" data-sub="orders">订单管理</button>
      <button class="navbtn" data-sub="cards">卡密管理</button>
      <button class="navbtn" data-sub="config">系统配置</button>
    </aside>
    <main class="content">
      <section class="subpage active" id="sub-dashboard">
        <div class="panel">
          <h2 style="margin:0 0 16px">仪表盘</h2>
          <div class="grid4" id="dashStats"></div>
        </div>
        <div class="cards" style="margin-top:18px">
          <div class="panel">
            <h3 style="margin:0 0 12px">近期订单</h3>
            <div class="tablewrap"><table class="table"><thead><tr><th>订单</th><th>用户</th><th>状态</th><th>进度</th><th>下载</th></tr></thead><tbody id="recentOrders"></tbody></table></div>
          </div>
          <div class="panel">
            <h3 style="margin:0 0 12px">充值概览</h3>
            <div class="kv" id="rechargeKv"></div>
          </div>
        </div>
      </section>

      <section class="subpage" id="sub-users">
        <div class="panel">
          <div class="toolbar">
            <input class="input" id="userKeyword" placeholder="搜索 user_id / 用户名 / 手机号">
            <button class="btn btn-primary" id="searchUsersBtn" type="button">搜索</button>
          </div>
          <div class="tablewrap"><table class="table"><thead><tr><th>用户</th><th>手机号</th><th>状态</th><th>额度</th><th>累计</th><th>最后登录</th><th>操作</th></tr></thead><tbody id="userRows"></tbody></table></div>
          <div class="pager" id="userPager"></div>
        </div>
      </section>

      <section class="subpage" id="sub-masks">
        <div class="panel">
          <div class="toolbar">
            <input class="input" id="maskKeyword" placeholder="搜索 mask_id / 用户ID / 名称">
            <button class="btn btn-primary" id="searchMasksBtn" type="button">搜索</button>
          </div>
          <div class="tablewrap"><table class="table"><thead><tr><th>预览</th><th>蒙版</th><th>用户</th><th>尺寸</th><th>极性</th><th>时间</th></tr></thead><tbody id="maskRows"></tbody></table></div>
          <div class="pager" id="maskPager"></div>
        </div>
      </section>

      <section class="subpage" id="sub-orders">
        <div class="panel">
          <div class="toolbar">
            <input class="input" id="orderKeyword" placeholder="搜索订单号 / 主题 / 用户 / 蒙版">
            <select class="select" id="orderStatus"><option value="">全部状态</option><option value="queued">queued</option><option value="running">running</option><option value="done">done</option><option value="error">error</option><option value="cancelled">cancelled</option></select>
            <button class="btn btn-primary" id="searchOrdersBtn" type="button">搜索</button>
          </div>
          <div class="tablewrap"><table class="table"><thead><tr><th>订单</th><th>用户</th><th>状态</th><th>进度</th><th>图稿</th><th>下载</th></tr></thead><tbody id="orderRows"></tbody></table></div>
          <div class="pager" id="orderPager"></div>
        </div>
      </section>

      <section class="subpage" id="sub-cards">
        <div class="panel" style="margin-bottom:18px">
          <h3 style="margin:0 0 12px">生成卡密</h3>
          <div class="toolbar">
            <input class="input" id="cardCredits" type="number" placeholder="每张额度" value="100">
            <input class="input" id="cardCount" type="number" placeholder="生成数量" value="10">
            <input class="input" id="cardPrefix" placeholder="前缀，可选，例如 VIP">
            <button class="btn btn-primary" id="generateCardsBtn" type="button">批量生成</button>
          </div>
          <div class="notice" id="cardGenNotice"></div>
          <textarea class="textarea" id="generatedCardsText" placeholder="新生成的卡密会显示在这里，方便你直接复制"></textarea>
        </div>
        <div class="panel">
          <div class="toolbar">
            <input class="input" id="cardKeyword" placeholder="搜索卡密 / 用户ID / 批次号">
            <button class="btn btn-primary" id="searchCardsBtn" type="button">搜索</button>
          </div>
          <div class="tablewrap"><table class="table"><thead><tr><th>卡密</th><th>额度</th><th>状态</th><th>使用信息</th><th>批次</th><th>操作</th></tr></thead><tbody id="cardRows"></tbody></table></div>
          <div class="pager" id="cardPager"></div>
        </div>
      </section>

      <section class="subpage" id="sub-config">
        <div class="panel" style="margin-bottom:18px">
          <h3 style="margin:0 0 6px">系统配置</h3>
          <p class="muted" style="margin:0 0 18px">所有 API Key、品牌信息、业务参数都从这里修改，会被保存到 <code>_airate_runtime/system/app_config.json</code>。修改后部分项目需刷新生效。</p>
          <div class="notice" id="configNotice"></div>

          <h4 style="margin:18px 0 10px">品牌信息</h4>
          <div class="grid4" style="grid-template-columns:1fr 1fr">
            <div><label class="muted">应用名（APP_NAME）</label><input class="input" data-cfg="brand.app_name"></div>
            <div><label class="muted">应用副标题</label><input class="input" data-cfg="brand.app_tagline"></div>
            <div><label class="muted">公司名称</label><input class="input" data-cfg="brand.company_name"></div>
            <div><label class="muted">联系邮箱</label><input class="input" data-cfg="brand.contact_email"></div>
            <div style="grid-column:1/-1"><label class="muted">主标语</label><input class="input" data-cfg="brand.app_slogan"></div>
          </div>

          <h4 style="margin:18px 0 10px">业务参数</h4>
          <div class="grid4" style="grid-template-columns:1fr 1fr 1fr 1fr">
            <div><label class="muted">注册赠送额度</label><input class="input" type="number" data-cfg="business.start_credits"></div>
            <div><label class="muted">每张图消耗额度</label><input class="input" type="number" data-cfg="business.credit_per_image"></div>
            <div><label class="muted">最大并发订单</label><input class="input" type="number" data-cfg="business.max_concurrent_orders"></div>
            <div><label class="muted">单张预估秒数</label><input class="input" type="number" data-cfg="business.estimated_seconds_per_image"></div>
            <div style="grid-column:1/-1"><label class="muted">卡密购买链接（留空则前台不显示购买按钮）</label><input class="input" data-cfg="business.card_purchase_url" placeholder="https://..."></div>
          </div>

          <h4 style="margin:18px 0 10px">GPT 接口（生成创意 / 设计 prompt）</h4>
          <div class="grid4" style="grid-template-columns:1fr 1fr">
            <div><label class="muted">API URL</label><input class="input" data-cfg="gpt.api_url" placeholder="https://api.openai.com/v1/chat/completions"></div>
            <div><label class="muted">模型名</label><input class="input" data-cfg="gpt.model" placeholder="gpt-4o"></div>
            <div style="grid-column:1/-1"><label class="muted">API Key</label><input class="input" type="password" data-cfg="gpt.api_key" placeholder="sk-..."></div>
            <div><label class="muted">temperature</label><input class="input" type="number" step="0.05" data-cfg="gpt.temperature"></div>
            <div><label class="muted">max_tokens</label><input class="input" type="number" data-cfg="gpt.max_tokens"></div>
          </div>
          <div style="margin-top:8px"><button class="btn btn-light btn-sm" data-test-api="gpt" type="button">测试 GPT 连通性</button></div>

          <h4 style="margin:18px 0 10px">绘图模型（默认对接 Nano-Banana / GRSAI）</h4>
          <div class="grid4" style="grid-template-columns:1fr 1fr">
            <div><label class="muted">提交任务 URL</label><input class="input" data-cfg="image_gen.draw_url"></div>
            <div><label class="muted">查询结果 URL</label><input class="input" data-cfg="image_gen.result_url"></div>
            <div><label class="muted">模型名</label><input class="input" data-cfg="image_gen.model"></div>
            <div><label class="muted">画面比例</label><input class="input" data-cfg="image_gen.aspect_ratio"></div>
            <div><label class="muted">输出尺寸</label><input class="input" data-cfg="image_gen.image_size" placeholder="2K / 4K"></div>
            <div><label class="muted">最大轮询次数</label><input class="input" type="number" data-cfg="image_gen.max_polls"></div>
            <div style="grid-column:1/-1"><label class="muted">API Key</label><input class="input" type="password" data-cfg="image_gen.api_key" placeholder="sk-..."></div>
          </div>
          <div style="margin-top:8px"><button class="btn btn-light btn-sm" data-test-api="image_gen" type="button">检查绘图 Key</button></div>

          <h4 style="margin:18px 0 10px">短信平台（注册 / 绑定手机；未启用时进入开发模式直接显示验证码）</h4>
          <div class="grid4" style="grid-template-columns:1fr 1fr">
            <div><label class="muted">是否启用</label><select class="select" data-cfg="sms.enabled"><option value="false">关闭（开发模式）</option><option value="true">启用</option></select></div>
            <div><label class="muted">短信平台 URL</label><input class="input" data-cfg="sms.api_url"></div>
            <div><label class="muted">用户名</label><input class="input" data-cfg="sms.username"></div>
            <div><label class="muted">密码 MD5（32 位）</label><input class="input" data-cfg="sms.password_md5"></div>
            <div><label class="muted">短信签名</label><input class="input" data-cfg="sms.sign_name"></div>
            <div><label class="muted">验证码有效期（秒）</label><input class="input" type="number" data-cfg="sms.code_expire_seconds"></div>
          </div>

          <div style="margin-top:24px;display:flex;gap:10px">
            <button class="btn btn-primary" id="saveConfigBtn" type="button">保存配置</button>
            <button class="btn btn-light" id="reloadConfigBtn" type="button">放弃并重新加载</button>
          </div>
        </div>

        <div class="panel">
          <h3 style="margin:0 0 6px">修改管理员账号 / 密码</h3>
          <p class="muted" style="margin:0 0 14px">默认账号 admin / admin123，请<b>立即</b>修改。修改后会强制重新登录。</p>
          <div class="notice" id="adminPwdNotice"></div>
          <div class="grid4" style="grid-template-columns:1fr 1fr">
            <div><label class="muted">新用户名（留空表示不变）</label><input class="input" id="adminNewUsername" placeholder="2-32 位 字母 / 数字 / _ . -"></div>
            <div><label class="muted">当前密码</label><input class="input" id="adminOldPwd" type="password"></div>
            <div style="grid-column:1/-1"><label class="muted">新密码（8-64 位）</label><input class="input" id="adminNewPwd" type="password"></div>
          </div>
          <div style="margin-top:14px"><button class="btn btn-primary" id="changeAdminPwdBtn" type="button">提交修改</button></div>
        </div>
      </section>
    </main>
  </div>
</div>

<div class="overlay" id="overlay"></div>
<div class="drawer" id="userDrawer">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:10px"><h3 style="margin:0">用户详情</h3><button class="btn btn-light btn-sm" id="closeDrawerBtn" type="button">关闭</button></div>
  <div id="userDetailBox" style="margin-top:16px"></div>
</div>

<script>
const state = {csrf: <?= json_encode($csrf, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>, loggedIn:false, currentSub:'dashboard', usersPage:1, masksPage:1, ordersPage:1, cardsPage:1};
const $ = s => document.querySelector(s); const $$ = s => Array.from(document.querySelectorAll(s));
function esc(s){return String(s??'').replace(/[&<>'"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]))}
function notice(el,type,msg){el.className='notice'+(msg?' show ':'')+(type?' '+type:'');el.textContent=msg||''}
function badge(status){const map={active:'b-active',banned:'b-banned',queued:'b-queued',running:'b-running',done:'b-done',error:'b-error',cancelled:'b-cancelled'};return `<span class="badge ${map[status]||'b-cancelled'}">${esc(status)}</span>`}
async function api(url,opt={}){opt.headers=Object.assign({'X-CSRF-Token':state.csrf},opt.headers||{});const r=await fetch(url,Object.assign({cache:'no-store'},opt));const t=await r.text();let d;try{d=JSON.parse(t)}catch(e){throw new Error('服务器返回异常')}if(!r.ok||!d.ok)throw new Error(d.message||'请求失败');return d}
function switchSub(name){state.currentSub=name;$$('.navbtn').forEach(b=>b.classList.toggle('active',b.dataset.sub===name));$$('.subpage').forEach(p=>p.classList.toggle('active',p.id==='sub-'+name))}
function renderDashboard(d){const stats=[['用户总数',d.user_count],['活跃用户',d.active_users],['封禁用户',d.banned_users],['蒙版总数',d.mask_count],['订单总数',d.order_count],['运行中订单',d.running_orders],['排队订单',d.queued_orders],['已完成订单',d.done_orders],['失败订单',d.error_orders],['用户总额度',d.total_user_credits],['冻结额度',d.reserved_credits],['总充值额度',d.total_recharge_credits],['今日充值额度',d.today_recharge_credits],['今日充值笔数',d.today_recharge_count],['今日订单数',d.today_orders],['今日生成张数',d.today_generated]];$('#dashStats').innerHTML=stats.map(([k,v])=>`<div class="stat"><div class="label">${esc(k)}</div><div class="value">${esc(v)}</div></div>`).join('');$('#recentOrders').innerHTML=(d.recent_orders||[]).map(o=>`<tr><td><b>${esc(o.order_id)}</b><div class="muted">${esc(o.theme)}</div></td><td>${esc(o.username)}<div class="muted">${esc(o.user_id)}</div></td><td>${badge(o.status)}</td><td>${esc(o.progress)}%<div class="muted">${esc(o.current_step||'')}</div></td><td>${esc(o.generated_count)}/${esc(o.design_count)}</td><td>${o.zip_url?`<a class="btn btn-light btn-sm" href="${o.zip_url}">下载ZIP</a>`:'-'}</td></tr>`).join('')||'<tr><td colspan="6">暂无数据</td></tr>';$('#rechargeKv').innerHTML=`<div>卡密总数</div><div>${esc(d.card_total)}</div><div>已使用</div><div>${esc(d.card_used)}</div><div>未使用</div><div>${esc(d.card_unused)}</div><div>已作废</div><div>${esc(d.card_invalid)}</div><div>今日充值额度</div><div>${esc(d.today_recharge_credits)}</div><div>总充值额度</div><div>${esc(d.total_recharge_credits)}</div>`}
function renderPager(el,page,pages,cb){el.innerHTML=`<button class="btn btn-light btn-sm" ${page<=1?'disabled':''}>上一页</button><span class="muted">第 ${page} / ${pages} 页</span><button class="btn btn-light btn-sm" ${page>=pages?'disabled':''}>下一页</button>`;const btns=el.querySelectorAll('button');btns[0].onclick=()=>page>1&&cb(page-1);btns[1].onclick=()=>page<pages&&cb(page+1)}
async function loadUsers(page=1){state.usersPage=page;const kw=$('#userKeyword').value.trim();const d=await api(`?action=list_users&page=${page}&keyword=${encodeURIComponent(kw)}`);const r=d.result;$('#userRows').innerHTML=r.items.map(u=>`<tr><td><b>${esc(u.username)}</b><div class="muted">${esc(u.user_id)}</div></td><td>${esc(u.mobile||'-')}</td><td>${badge(u.status)}</td><td><b>${esc(u.credits)}</b></td><td>订单 ${esc(u.total_orders)}<br>生成 ${esc(u.total_generated)}<br>消耗 ${esc(u.total_spent)}</td><td>${esc(u.last_login_at||'-')}<div class="muted">${esc(u.last_login_ip||'')}</div></td><td><div style="display:flex;gap:6px;flex-wrap:wrap"><button class="btn btn-light btn-sm" data-view-user="${esc(u.user_id)}">详情</button><button class="btn btn-light btn-sm" data-ban-user="${esc(u.user_id)}" data-status="${u.status==='banned'?'active':'banned'}">${u.status==='banned'?'解封':'封禁'}</button></div></td></tr>`).join('')||'<tr><td colspan="7">暂无用户</td></tr>';renderPager($('#userPager'),r.page,r.pages,loadUsers)}
async function loadMasks(page=1){state.masksPage=page;const kw=$('#maskKeyword').value.trim();const d=await api(`?action=list_masks&page=${page}&keyword=${encodeURIComponent(kw)}`);const r=d.result;$('#maskRows').innerHTML=r.items.map(m=>`<tr><td><img class="thumb" src="${m.preview_url}" alt=""></td><td><b>${esc(m.name)}</b><div class="muted">${esc(m.mask_id)}</div></td><td>${esc(m.user_id)}</td><td>${esc(m.width)} × ${esc(m.height)}</td><td>${esc(m.polarity)}<div class="muted">${esc(m.resolved_polarity)}</div></td><td>${esc(m.created_at)}<div class="muted">更新：${esc(m.updated_at)}</div></td></tr>`).join('')||'<tr><td colspan="6">暂无蒙版</td></tr>';renderPager($('#maskPager'),r.page,r.pages,loadMasks)}
async function loadOrders(page=1){state.ordersPage=page;const kw=$('#orderKeyword').value.trim();const status=$('#orderStatus').value;const d=await api(`?action=list_orders&page=${page}&keyword=${encodeURIComponent(kw)}&status=${encodeURIComponent(status)}`);const r=d.result;$('#orderRows').innerHTML=r.items.map(o=>`<tr><td><b>${esc(o.order_id)}</b><div class="muted">${esc(o.theme)}</div><div class="muted">模板：${esc(o.mask_name)}</div></td><td>${esc(o.username)}<div class="muted">${esc(o.user_id)}</div></td><td>${badge(o.status)}</td><td>${esc(o.progress)}%<div class="muted">${esc(o.current_step||'')}</div>${o.error_message?`<div style="color:#dc2626">${esc(o.error_message)}</div>`:''}</td><td><div class="order-set">${(o.sets||[]).map(s=>s.image_url?`<img src="${s.image_url}">`:'').join('')}</div></td><td>${o.zip_url?`<a class="btn btn-light btn-sm" href="${o.zip_url}">下载ZIP</a>`:'-'}</td></tr>`).join('')||'<tr><td colspan="6">暂无订单</td></tr>';renderPager($('#orderPager'),r.page,r.pages,loadOrders)}
async function loadCards(page=1){state.cardsPage=page;const kw=$('#cardKeyword').value.trim();const d=await api(`?action=list_cards&page=${page}&keyword=${encodeURIComponent(kw)}`);const r=d.result;$('#cardRows').innerHTML=r.items.map(c=>{const st=c.invalid?'已作废':(c.used?'已使用':'可用');return `<tr><td><b>${esc(c.code)}</b></td><td>${esc(c.credits)}</td><td>${c.invalid?'<span class="badge b-error">已作废</span>':(c.used?'<span class="badge b-done">已使用</span>':'<span class="badge b-active">可用</span>')}</td><td>${c.used?`用户：${esc(c.used_by||'')}<div class="muted">${esc(c.used_at||'')}</div>`:'-'}</td><td>${esc(c.batch_id||'-')}<div class="muted">${esc(c.created_at||'')}</div></td><td><div style="display:flex;gap:6px;flex-wrap:wrap">${!c.used&&!c.invalid?`<button class="btn btn-light btn-sm" data-invalid-card="${esc(c.code)}">作废</button>`:''}${!c.used?`<button class="btn btn-danger btn-sm" data-delete-card="${esc(c.code)}">删除</button>`:''}</div></td></tr>`}).join('')||'<tr><td colspan="6">暂无卡密</td></tr>';renderPager($('#cardPager'),r.page,r.pages,loadCards)}
async function bootstrap(){const d=await api('?action=bootstrap');state.csrf=d.csrf_token||state.csrf;state.loggedIn=!!d.logged_in;$('#topState').textContent=state.loggedIn?'已登录后台':'未登录';$('#logoutBtn').classList.toggle('hidden',!state.loggedIn);$('#loginView').classList.toggle('hidden',state.loggedIn);$('#appView').classList.toggle('hidden',!state.loggedIn);if(state.loggedIn&&d.dashboard){renderDashboard(d.dashboard);loadUsers(1);loadMasks(1);loadOrders(1);loadCards(1)}}
async function login(){try{await api('?action=login',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({username:$('#loginUsername').value.trim(),password:$('#loginPassword').value})});notice($('#loginNotice'),'ok','登录成功');bootstrap()}catch(e){notice($('#loginNotice'),'err',e.message)}}
async function logout(){try{await api('?action=logout',{method:'POST'})}catch(e){}bootstrap()}
async function openUserDetail(userId){const d=await api(`?action=user_detail&user_id=${encodeURIComponent(userId)}`);const u=d.user;$('#userDetailBox').innerHTML=`<div class="kv"><div>用户ID</div><div>${esc(u.user_id)}</div><div>用户名</div><div>${esc(u.username)}</div><div>手机号</div><div>${esc(u.mobile||'-')}</div><div>状态</div><div>${badge(u.status)}</div><div>当前额度</div><div>${esc(u.credits)}</div><div>累计订单</div><div>${esc(u.total_orders)}</div><div>累计生成</div><div>${esc(u.total_generated)}</div><div>累计消耗</div><div>${esc(u.total_spent)}</div><div>注册时间</div><div>${esc(u.created_at)}</div><div>最后登录</div><div>${esc(u.last_login_at||'-')}</div><div>登录IP</div><div>${esc(u.last_login_ip||'-')}</div><div>邀请人</div><div>${esc(u.inviter_username||'-')}</div></div><div style="margin-top:18px"><h4>调整额度</h4><div class="toolbar"><input class="input" id="drawerCreditDelta" type="number" placeholder="正数加点，负数减点"><input class="input" id="drawerCreditNote" placeholder="备注，例如：补偿 / 手动扣减"><button class="btn btn-primary" id="drawerAdjustBtn" type="button">提交调整</button></div><h4>修改密码</h4><div class="toolbar"><input class="input" id="drawerNewPassword" placeholder="新密码 6-32 位"><button class="btn btn-primary" id="drawerResetPwdBtn" type="button">重置密码</button></div><div class="toolbar"><button class="btn btn-light" id="drawerToggleStatusBtn" type="button">${u.status==='banned'?'解除封禁':'封禁账号'}</button></div><h4>额度变动详情</h4><div class="tablewrap"><table class="table"><thead><tr><th>时间</th><th>变动</th><th>类型</th><th>备注</th><th>余额</th></tr></thead><tbody>${(u.credit_logs||[]).map(l=>`<tr><td>${esc(l.time||'')}</td><td>${esc(l.delta)}</td><td>${esc(l.type||'')}</td><td>${esc(l.note||'')}</td><td>${esc(l.balance)}</td></tr>`).join('')||'<tr><td colspan="5">暂无记录</td></tr>'}</tbody></table></div></div>`;$('#overlay').classList.add('show');$('#userDrawer').classList.add('show');$('#drawerAdjustBtn').onclick=async()=>{try{await api('?action=adjust_credits',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({user_id:u.user_id,delta:$('#drawerCreditDelta').value,note:$('#drawerCreditNote').value})});await openUserDetail(u.user_id);await loadUsers(state.usersPage);await bootstrap()}catch(e){alert(e.message)}};$('#drawerResetPwdBtn').onclick=async()=>{try{await api('?action=reset_user_password',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({user_id:u.user_id,new_password:$('#drawerNewPassword').value})});alert('密码已重置')}catch(e){alert(e.message)}};$('#drawerToggleStatusBtn').onclick=async()=>{try{await api('?action=update_user_status',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({user_id:u.user_id,status:u.status==='banned'?'active':'banned'})});await openUserDetail(u.user_id);await loadUsers(state.usersPage);await bootstrap()}catch(e){alert(e.message)}}}
function closeDrawer(){$('#overlay').classList.remove('show');$('#userDrawer').classList.remove('show')}

/* ---------- 系统配置：读 / 写 / 测 ---------- */
function setCfgValue(path, value){
  const el = document.querySelector(`[data-cfg="${path}"]`);
  if (!el) return;
  if (typeof value === 'boolean') value = value ? 'true' : 'false';
  el.value = value == null ? '' : String(value);
}
function getCfgValue(path){
  const el = document.querySelector(`[data-cfg="${path}"]`);
  return el ? el.value : '';
}
async function loadConfigForm(){
  try{
    const d = await api('?action=get_config');
    const c = d.config || {};
    const fill = (obj, prefix) => {
      Object.keys(obj || {}).forEach(k => {
        const v = obj[k];
        const path = prefix ? prefix+'.'+k : k;
        if (v && typeof v === 'object' && !Array.isArray(v)) fill(v, path);
        else if (path !== 'admin.password_hash') setCfgValue(path, v);
      });
    };
    fill(c, '');
  } catch(e){ notice($('#configNotice'),'err','加载配置失败：'+e.message); }
}
async function saveConfigForm(){
  notice($('#configNotice'),'','');
  const inputs = document.querySelectorAll('[data-cfg]');
  const fd = new URLSearchParams();
  inputs.forEach(el => {
    const path = el.dataset.cfg;
    fd.append('cfg['+path.split('.').join('][')+']', el.value);
  });
  try{
    const d = await api('?action=save_config', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: fd});
    notice($('#configNotice'),'ok', d.message || '已保存');
  }catch(e){ notice($('#configNotice'),'err', e.message || '保存失败'); }
}
async function testApi(type){
  notice($('#configNotice'),'','正在测试 '+type+'...');
  try{
    const d = await api('?action=test_api', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams({type})});
    notice($('#configNotice'),'ok', d.message || '测试通过');
  }catch(e){ notice($('#configNotice'),'err', e.message || '测试失败'); }
}
async function changeAdminPwd(){
  notice($('#adminPwdNotice'),'','');
  const oldPwd = $('#adminOldPwd').value;
  const newPwd = $('#adminNewPwd').value;
  const newName = $('#adminNewUsername').value.trim();
  if (newPwd.length < 8) { notice($('#adminPwdNotice'),'err','新密码至少 8 位'); return; }
  try{
    const d = await api('?action=change_admin_password', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams({old_password: oldPwd, new_password: newPwd, new_username: newName})});
    notice($('#adminPwdNotice'),'ok', d.message || '已修改，请重新登录');
    setTimeout(()=>{ logout(); }, 1200);
  } catch(e){ notice($('#adminPwdNotice'),'err', e.message || '修改失败'); }
}

document.addEventListener('click',async e=>{const n=e.target.closest('[data-sub]');if(n){switchSub(n.dataset.sub);if(n.dataset.sub==='config')loadConfigForm();return}if(e.target.id==='loginBtn'){login();return}if(e.target.id==='logoutBtn'){logout();return}if(e.target.id==='searchUsersBtn'){loadUsers(1);return}if(e.target.id==='searchMasksBtn'){loadMasks(1);return}if(e.target.id==='searchOrdersBtn'){loadOrders(1);return}if(e.target.id==='searchCardsBtn'){loadCards(1);return}if(e.target.id==='generateCardsBtn'){try{const d=await api('?action=generate_cards',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({credits:$('#cardCredits').value,count:$('#cardCount').value,prefix:$('#cardPrefix').value})});notice($('#cardGenNotice'),'ok',d.message+'，批次：'+d.batch_id);$('#generatedCardsText').value=(d.cards||[]).map(x=>`${x.code} | ${x.credits}额度`).join('\n');await loadCards(1);await bootstrap()}catch(err){notice($('#cardGenNotice'),'err',err.message)}return}if(e.target.id==='closeDrawerBtn'||e.target.id==='overlay'){closeDrawer();return}
if(e.target.id==='saveConfigBtn'){saveConfigForm();return}
if(e.target.id==='reloadConfigBtn'){loadConfigForm();return}
if(e.target.id==='changeAdminPwdBtn'){changeAdminPwd();return}
const ta=e.target.closest('[data-test-api]');if(ta){testApi(ta.dataset.testApi);return}
const vu=e.target.closest('[data-view-user]');if(vu){openUserDetail(vu.dataset.viewUser);return}
const bu=e.target.closest('[data-ban-user]');if(bu){try{await api('?action=update_user_status',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({user_id:bu.dataset.banUser,status:bu.dataset.status})});await loadUsers(state.usersPage);await bootstrap()}catch(err){alert(err.message)}return}
const ic=e.target.closest('[data-invalid-card]');if(ic){if(!confirm('确认作废这个卡密吗？'))return;try{await api('?action=invalidate_card',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({code:ic.dataset.invalidCard})});await loadCards(state.cardsPage);await bootstrap()}catch(err){alert(err.message)}return}
const dc=e.target.closest('[data-delete-card]');if(dc){if(!confirm('确认删除这个卡密吗？'))return;try{await api('?action=delete_card',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({code:dc.dataset.deleteCard})});await loadCards(state.cardsPage);await bootstrap()}catch(err){alert(err.message)}return}
});
bootstrap();
</script>
</body>
</html>
<?php
    exit;
}

$action = trim((string)($_GET['action'] ?? ''));
switch ($action) {
    case 'login': handleLogin(); break;
    case 'logout': handleLogout(); break;
    case 'bootstrap': handleBootstrap(); break;
    case 'list_users': handleListUsers(); break;
    case 'user_detail': handleUserDetail(); break;
    case 'update_user_status': handleUpdateUserStatus(); break;
    case 'reset_user_password': handleResetUserPassword(); break;
    case 'adjust_credits': handleAdjustCredits(); break;
    case 'list_masks': handleListMasks(); break;
    case 'list_orders': handleListOrders(); break;
    case 'list_cards': handleListCards(); break;
    case 'generate_cards': handleGenerateCards(); break;
    case 'invalidate_card': handleInvalidateCard(); break;
    case 'delete_card': handleDeleteCard(); break;
    case 'mask_preview': handleMaskPreview(); break;
    case 'order_file': handleOrderFile(); break;
    case 'get_config': handleGetConfig(); break;
    case 'save_config': handleSaveConfig(); break;
    case 'change_admin_password': handleChangeAdminPassword(); break;
    case 'test_api': handleTestApi(); break;
    default: renderPage();
}
