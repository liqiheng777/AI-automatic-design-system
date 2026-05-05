<?php
/**
 * GaoQing 配置加载器 / Config Loader
 *
 * 该文件负责：
 *   1. 加载 _airate_runtime/system/app_config.json 中的运行时配置
 *   2. 把配置项注册为全局常量（兼容原代码使用 const 的方式）
 *   3. 提供给后台管理面板读 / 写配置的辅助函数
 *
 * 安全提示：
 *   - 本文件本身不包含任何密钥；密钥都保存在 _airate_runtime/system/app_config.json
 *   - 该 JSON 文件已经在 .gitignore 中忽略，不会被提交到仓库
 *   - 第一次部署后请立即进入后台 /admin.php 完成配置
 */

declare(strict_types=1);

// ============================================================
// 路径常量（这些不变，所以单独定义）
// ============================================================
if (!defined('GAOQING_RUNTIME_DIR')) {
    define('GAOQING_RUNTIME_DIR', '_airate_runtime');
}
if (!defined('GAOQING_BASE_DIR')) {
    define('GAOQING_BASE_DIR', __DIR__);
}
if (!defined('GAOQING_CONFIG_FILE')) {
    define(
        'GAOQING_CONFIG_FILE',
        GAOQING_BASE_DIR . DIRECTORY_SEPARATOR . GAOQING_RUNTIME_DIR
        . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'app_config.json'
    );
}

/**
 * 默认配置。如果 app_config.json 缺失或某项未填，使用这里的默认值。
 * 部署者可以直接编辑后台界面进行修改。
 */
function gaoqing_default_config(): array
{
    return [
        // —— 品牌信息 ——
        'brand' => [
            'app_name' => 'GaoQing',
            'company_name' => 'Your Company',
            'app_tagline' => 'AI 设计稿生成平台',
            'app_slogan' => '上传模板，输入主题，自动产出商业级设计稿',
            'contact_email' => 'admin@example.com',
        ],

        // —— 业务参数 ——
        'business' => [
            'start_credits' => 20,                // 注册赠送额度
            'credit_per_image' => 5,              // 每张图消耗
            'max_concurrent_orders' => 3,         // 最大并发订单数
            'estimated_seconds_per_image' => 95,  // 单张预估秒数（统计兜底值）
            'card_purchase_url' => '',            // 充值卡密购买链接（留空则隐藏入口）
            'order_retention_seconds' => 86400,   // 订单保留秒数
        ],

        // —— 系统硬限制（一般不用改） ——
        'limits' => [
            'max_theme_length' => 120,
            'max_mask_name_length' => 40,
            'max_username_length' => 24,
            'max_password_length' => 32,
            'min_password_length' => 6,
            'max_mask_upload_size' => 15 * 1024 * 1024,
            'max_design_set_count' => 5,
            'min_design_set_count' => 1,
            'max_mask_width' => 6000,
            'max_mask_height' => 6000,
        ],

        // —— 短信平台（注册 / 绑定手机使用） ——
        // 默认配置兼容 tosms.cn，如使用其它平台请自行修改 sendSmsPlatform 函数
        'sms' => [
            'enabled' => false,                                       // 关闭则进入"开发模式"，验证码直接显示
            'api_url' => 'https://www.tosms.cn/Api/SendSms.ashx',
            'username' => '',
            'password_md5' => '',                                     // 平台要求的 32 位 MD5
            'sign_name' => '',                                        // 短信签名
            'code_expire_seconds' => 300,
            'send_cooldown_seconds' => 60,
        ],

        // —— OpenAI 兼容的 GPT 接口（用于生成设计创意） ——
        'gpt' => [
            'api_url' => 'https://api.openai.com/v1/chat/completions',
            'api_key' => '',
            'model' => 'gpt-4o',
            'temperature' => 0.45,
            'max_tokens' => 2600,
            'retry_times' => 3,
            'connect_timeout' => 20,
            'timeout' => 180,
        ],

        // —— 绘图模型（默认对接 GRSAI Nano-Banana，可换成其它兼容接口） ——
        'image_gen' => [
            'draw_url' => 'https://grsai.dakka.com.cn/v1/draw/nano-banana',
            'result_url' => 'https://grsai.dakka.com.cn/v1/draw/result',
            'api_key' => '',
            'model' => 'nano-banana-pro',
            'aspect_ratio' => 'auto',
            'image_size' => '4K',
            'shut_progress' => false,
            'poll_interval_us' => 1500000,
            'max_polls' => 120,
            'connect_timeout' => 20,
            'timeout' => 1800,
            'step_max_retries' => 3,
            'step_retry_delay_us' => 2000000,
        ],

        // —— 后台账号（部署后请立即修改） ——
        'admin' => [
            'username' => 'admin',
            // 默认密码：admin123（只要登录过一次后台并修改了密码就会替换为新 hash）
            'password_hash' => '$2y$10$OSqGLkI5nodcHTy61hBfl.YSkOspJYZXpZvmJi/nN9A4ZxYVOG8aq',
        ],
    ];
}

/**
 * 深度合并两个配置数组：用户值覆盖默认值，未填的项使用默认。
 */
function gaoqing_merge_config(array $defaults, array $user): array
{
    foreach ($user as $key => $value) {
        if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key])) {
            $defaults[$key] = gaoqing_merge_config($defaults[$key], $value);
        } else {
            $defaults[$key] = $value;
        }
    }
    return $defaults;
}

/**
 * 加载配置：从 JSON 文件读取，并合并默认值。
 * 失败时返回默认值，保证程序至少能渲染首页。
 */
function gaoqing_load_config(bool $forceReload = false): array
{
    static $cache = null;
    if ($cache !== null && !$forceReload) {
        return $cache;
    }

    $defaults = gaoqing_default_config();
    if (!is_file(GAOQING_CONFIG_FILE)) {
        $cache = $defaults;
        return $cache;
    }
    $raw = @file_get_contents(GAOQING_CONFIG_FILE);
    if (!is_string($raw) || trim($raw) === '') {
        $cache = $defaults;
        return $cache;
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        $cache = $defaults;
        return $cache;
    }
    $cache = gaoqing_merge_config($defaults, $json);
    return $cache;
}

/**
 * 保存配置到 JSON 文件（原子写入）。
 * 仅供后台管理面板调用。
 */
function gaoqing_save_config(array $config): void
{
    $dir = dirname(GAOQING_CONFIG_FILE);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('无法创建配置目录：' . $dir);
    }
    $tmp = GAOQING_CONFIG_FILE . '.tmp_' . bin2hex(random_bytes(3));
    $payload = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($payload === false) {
        throw new RuntimeException('配置 JSON 编码失败');
    }
    if (@file_put_contents($tmp, $payload) === false) {
        throw new RuntimeException('写入配置文件失败');
    }
    if (!@rename($tmp, GAOQING_CONFIG_FILE)) {
        @unlink($tmp);
        throw new RuntimeException('保存配置文件失败');
    }
}

/**
 * 取一个深层配置值，如 gaoqing_config_get('gpt.api_key')。
 */
function gaoqing_config_get(string $path, mixed $default = null): mixed
{
    $cfg = gaoqing_load_config();
    foreach (explode('.', $path) as $seg) {
        if (!is_array($cfg) || !array_key_exists($seg, $cfg)) {
            return $default;
        }
        $cfg = $cfg[$seg];
    }
    return $cfg;
}

/**
 * 把配置写入全局常量。
 * 这样原代码里的 const APP_NAME 等常量就直接可用，不必改动业务代码。
 *
 * 这个函数被设计成幂等的：多次调用同一进程内只会 define 一次。
 */
function gaoqing_apply_config_constants(): void
{
    $cfg = gaoqing_load_config();

    $defs = [
        // —— 品牌 ——
        'APP_NAME' => $cfg['brand']['app_name'],
        'COMPANY_NAME' => $cfg['brand']['company_name'],
        'APP_TAGLINE' => $cfg['brand']['app_tagline'],
        'APP_SLOGAN' => $cfg['brand']['app_slogan'],
        'CONTACT_EMAIL' => $cfg['brand']['contact_email'],

        // —— 路径与会话 ——
        'RUNTIME_DIR' => GAOQING_RUNTIME_DIR,
        'AUTH_COOKIE_NAME' => 'gaoqing_auth',
        'AUTH_COOKIE_DAYS' => 30,

        // —— 业务参数 ——
        'START_CREDITS' => (int)$cfg['business']['start_credits'],
        'CREDIT_PER_IMAGE' => (int)$cfg['business']['credit_per_image'],
        'ORDER_RETENTION_SECONDS' => (int)$cfg['business']['order_retention_seconds'],
        'CLEANUP_MIN_INTERVAL' => 600,
        'CARD_PURCHASE_URL' => (string)$cfg['business']['card_purchase_url'],
        'ORDER_WORKER_MAX_PER_LOOP' => 1,
        'MAX_CONCURRENT_ORDERS' => (int)$cfg['business']['max_concurrent_orders'],
        'MAX_CONCURRENT_IMAGE_GEN' => (int)$cfg['business']['max_concurrent_orders'],
        'STATS_RECENT_WINDOW' => 50,
        'ESTIMATED_SECONDS_PER_IMAGE' => (int)$cfg['business']['estimated_seconds_per_image'],

        // —— 限制 ——
        'MAX_THEME_LENGTH' => (int)$cfg['limits']['max_theme_length'],
        'MAX_MASK_NAME_LENGTH' => (int)$cfg['limits']['max_mask_name_length'],
        'MAX_USERNAME_LENGTH' => (int)$cfg['limits']['max_username_length'],
        'MAX_PASSWORD_LENGTH' => (int)$cfg['limits']['max_password_length'],
        'MIN_PASSWORD_LENGTH' => (int)$cfg['limits']['min_password_length'],
        'MAX_MASK_UPLOAD_SIZE' => (int)$cfg['limits']['max_mask_upload_size'],
        'MAX_DESIGN_SET_COUNT' => (int)$cfg['limits']['max_design_set_count'],
        'MIN_DESIGN_SET_COUNT' => (int)$cfg['limits']['min_design_set_count'],
        'MAX_MASK_WIDTH' => (int)$cfg['limits']['max_mask_width'],
        'MAX_MASK_HEIGHT' => (int)$cfg['limits']['max_mask_height'],
        'MASK_THRESHOLD' => 128,

        // —— 短信 ——
        'SMS_ENABLED' => (bool)$cfg['sms']['enabled'],
        'SMS_API_URL' => (string)$cfg['sms']['api_url'],
        'SMS_USERNAME' => (string)$cfg['sms']['username'],
        'SMS_PASSWORD_MD5' => (string)$cfg['sms']['password_md5'],
        'SMS_SIGN_NAME' => (string)$cfg['sms']['sign_name'],
        'SMS_CODE_EXPIRE_SECONDS' => (int)$cfg['sms']['code_expire_seconds'],
        'SMS_SEND_COOLDOWN_SECONDS' => (int)$cfg['sms']['send_cooldown_seconds'],

        // —— GPT ——
        'GPT_API_URL' => (string)$cfg['gpt']['api_url'],
        'GPT_API_KEY' => (string)$cfg['gpt']['api_key'],
        'GPT_MODEL' => (string)$cfg['gpt']['model'],
        'GPT_RETRY_TIMES' => (int)$cfg['gpt']['retry_times'],
        'GPT_CONNECT_TIMEOUT' => (int)$cfg['gpt']['connect_timeout'],
        'GPT_TIMEOUT' => (int)$cfg['gpt']['timeout'],
        'GPT_TEMPERATURE' => (float)$cfg['gpt']['temperature'],
        'GPT_MAX_TOKENS' => (int)$cfg['gpt']['max_tokens'],

        // —— 绘图模型 ——
        'BENANA_DRAW_URL' => (string)$cfg['image_gen']['draw_url'],
        'BENANA_RESULT_URL' => (string)$cfg['image_gen']['result_url'],
        'BENANA_API_KEY' => (string)$cfg['image_gen']['api_key'],
        'BENANA_MODEL' => (string)$cfg['image_gen']['model'],
        'BENANA_ASPECT_RATIO' => (string)$cfg['image_gen']['aspect_ratio'],
        'BENANA_IMAGE_SIZE' => (string)$cfg['image_gen']['image_size'],
        'BENANA_SHUT_PROGRESS' => (bool)$cfg['image_gen']['shut_progress'],
        'BENANA_POLL_INTERVAL_US' => (int)$cfg['image_gen']['poll_interval_us'],
        'BENANA_MAX_POLLS' => (int)$cfg['image_gen']['max_polls'],
        'BENANA_CONNECT_TIMEOUT' => (int)$cfg['image_gen']['connect_timeout'],
        'BENANA_TIMEOUT' => (int)$cfg['image_gen']['timeout'],
        'STEP_MAX_RETRIES' => (int)$cfg['image_gen']['step_max_retries'],
        'STEP_RETRY_DELAY_US' => (int)$cfg['image_gen']['step_retry_delay_us'],
    ];

    foreach ($defs as $name => $value) {
        if (!defined($name)) {
            define($name, $value);
        }
    }
}

/**
 * 检查关键配置（API Key 等）是否完整。
 * 用于在前端给管理员发出"请先到后台配置"的提示。
 */
function gaoqing_config_status(): array
{
    $cfg = gaoqing_load_config();
    return [
        'gpt_ready' => trim((string)$cfg['gpt']['api_key']) !== '',
        'image_gen_ready' => trim((string)$cfg['image_gen']['api_key']) !== '',
        'sms_ready' => !empty($cfg['sms']['enabled'])
            && trim((string)$cfg['sms']['username']) !== ''
            && trim((string)$cfg['sms']['password_md5']) !== '',
        'brand_ready' => trim((string)$cfg['brand']['app_name']) !== ''
            && $cfg['brand']['app_name'] !== 'GaoQing',
    ];
}
