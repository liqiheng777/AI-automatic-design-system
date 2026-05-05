# 配置项详解

GaoQing 的所有运行时配置都保存在 `_airate_runtime/system/app_config.json` 这一个 JSON 文件里。
**默认情况下你不需要直接编辑这个文件**，到后台「系统配置」页面用表单修改即可。

本文档把每个字段的含义、默认值、推荐值、影响范围都列出来，供进阶用户参考。

## 目录

- [配置文件位置](#配置文件位置)
- [配置项总览](#配置项总览)
- [brand 品牌信息](#brand-品牌信息)
- [business 业务参数](#business-业务参数)
- [limits 系统限制](#limits-系统限制)
- [sms 短信平台](#sms-短信平台)
- [gpt GPT 接口](#gpt-gpt-接口)
- [image_gen 绘图模型](#image_gen-绘图模型)
- [admin 管理员账号](#admin-管理员账号)
- [手动编辑配置](#手动编辑配置)
- [配置生效机制](#配置生效机制)

---

## 配置文件位置

- 路径：`项目根目录/_airate_runtime/system/app_config.json`
- 格式：UTF-8、JSON、由 PHP `json_encode(..., JSON_PRETTY_PRINT)` 写出
- 写入方式：原子写入（先写 `.tmp_xxx` 再 `rename`）
- **该文件包含所有 API Key 与管理员密码哈希，绝对不能提交到 Git，不能让外部访问**

如果文件不存在，加载时会回退到 `config.php` 中的 `gaoqing_default_config()` 默认值。

---

## 配置项总览

```json
{
  "brand":     { "app_name": "...", "company_name": "...", ... },
  "business":  { "start_credits": 20, "credit_per_image": 5, ... },
  "limits":    { "max_theme_length": 120, ... },
  "sms":       { "enabled": false, "api_url": "...", ... },
  "gpt":       { "api_url": "...", "api_key": "", "model": "gpt-4o", ... },
  "image_gen": { "draw_url": "...", "api_key": "", ... },
  "admin":     { "username": "admin", "password_hash": "$2y$..." }
}
```

下面分组逐项说明。

---

## brand 品牌信息

控制前台展示的品牌名、标语、邮箱。

| 字段 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `app_name` | string | `GaoQing` | 应用名，显示在顶部 logo、标题栏、首页 hero、邮件主题里 |
| `company_name` | string | `Your Company` | 公司全称，显示在页脚和联系页 |
| `app_tagline` | string | `AI 设计稿生成平台` | 副标题，显示在 logo 下方 |
| `app_slogan` | string | `上传模板，输入主题，自动产出商业级设计稿` | 主标语，显示在首页 hero 的描述里 |
| `contact_email` | string | `admin@example.com` | 联系邮箱，显示在「联系我们」并被 `mailto:` 链接 |

**建议**：上线前把这五个字段全部改成你自己的，不然首页一眼就能看出是 demo。

---

## business 业务参数

控制额度、并发、订单生命周期。

| 字段 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `start_credits` | int | `20` | 新用户注册赠送的额度。`20` 默认够用户免费试 4 张稿（每张 5 额度） |
| `credit_per_image` | int | `5` | 每张设计稿消耗的额度。订单创建时按 `张数 × 5` 预扣，失败的部分自动退还 |
| `max_concurrent_orders` | int | `3` | 同一时间最多有几个订单在跑。受 GPT/绘图 API 平台的并发上限制约。3 是个保守值，硬件好可以提到 6-8 |
| `estimated_seconds_per_image` | int | `95` | 单张稿的预估耗时（秒）。仅用于前端显示「预计还需 X 秒」，不影响实际执行。当系统统计到真实数据后会自动用真实值 |
| `card_purchase_url` | string | `` | 卡密购买链接。**留空时前台「购买卡密」按钮不渲染**，需要充值时直接对接你的发卡平台链接 |
| `order_retention_seconds` | int | `86400` | 订单文件保留时间（秒）。默认 24 小时，过期后自动清理订单目录释放磁盘 |

**说明**：

- 改动 `start_credits` / `credit_per_image` 只影响新订单和新用户，已有用户的余额和已扣额度不变
- 提高 `max_concurrent_orders` 之前先确认你的 GPT/绘图平台账号支持对应的 RPS

---

## limits 系统限制

硬限制类参数，一般不需要改。改之前确认你了解后果。

| 字段 | 默认值 | 说明 |
| --- | --- | --- |
| `max_theme_length` | `120` | 主题词最大长度（字符）。超过会拒绝创建订单 |
| `max_mask_name_length` | `40` | 蒙版名称最大长度 |
| `max_username_length` | `24` | 用户名最大长度 |
| `max_password_length` | `32` | 密码最大长度。密码用 bcrypt 哈希，过长会被截断 |
| `min_password_length` | `6` | 密码最小长度。改太小会让账号容易被破，改太大用户会嫌烦 |
| `max_mask_upload_size` | `15728640` | 蒙版上传最大字节数（默认 15MB）。注意要同时调整 PHP 的 `upload_max_filesize` 和 `post_max_size` |
| `max_design_set_count` | `5` | 单订单最多生成几张稿。默认 5 是产品页宣传的数量 |
| `min_design_set_count` | `1` | 单订单最少生成几张稿。一般保持 1 |
| `max_mask_width` | `6000` | 蒙版图最大宽度（像素）。超过会拒绝上传 |
| `max_mask_height` | `6000` | 蒙版图最大高度（像素） |

---

## sms 短信平台

控制注册、绑定手机时的短信验证码发送。

| 字段 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `enabled` | bool | `false` | **开关**。`false` 时进入开发模式：验证码不发送，直接显示在响应消息里并写入 `dev_sms.log`。生产环境必须改为 `true` |
| `api_url` | string | `https://www.tosms.cn/Api/SendSms.ashx` | 短信平台 API URL。默认是 tosms.cn 兼容协议 |
| `username` | string | `` | 短信平台账号 |
| `password_md5` | string | `` | 平台密码的 32 位 MD5 哈希。在 tosms.cn 后台可以看到 |
| `sign_name` | string | `` | 短信签名，例如 `【你的公司】`。会自动包在验证码内容前面 |
| `code_expire_seconds` | int | `300` | 验证码有效期（秒）。默认 5 分钟 |
| `send_cooldown_seconds` | int | `60` | 同一手机号两次发送的冷却时间（秒）。防止刷短信 |

### 切换其它短信平台

代码中负责发送的函数是 `index.php` 中的 `sendSmsPlatform()`。该函数严格按照 tosms.cn 的协议构造 POST 参数：

```php
$postData = [
    '_type' => '1',
    'username' => SMS_USERNAME,
    'Password' => SMS_PASSWORD_MD5,
    'Phones' => $mobile,
    'Content' => $content,
];
```

如果你用的是阿里云、腾讯云、Twilio 等其它平台，需要修改这个函数。改的时候**保留它的返回值结构**：

```php
return ['ok' => true|false, 'msg' => '...', 'raw' => ...];
```

---

## gpt GPT 接口

控制设计创意（design prompt）的生成。

| 字段 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `api_url` | string | `https://api.openai.com/v1/chat/completions` | OpenAI 兼容的 Chat Completions URL |
| `api_key` | string | `` | API Key，OpenAI 形如 `sk-...` |
| `model` | string | `gpt-4o` | 模型名。可换 `gpt-4o-mini`（更便宜）、`gpt-4-turbo`、或者其它兼容平台的模型名 |
| `temperature` | float | `0.45` | 采样温度。0.4-0.6 适合"既要创意又要遵循规则"的场景 |
| `max_tokens` | int | `2600` | 最大输出 token 数。设计创意的 prompt 比较长，建议 ≥ 2000 |
| `retry_times` | int | `3` | 单次创意生成失败后的重试次数 |
| `connect_timeout` | int | `20` | TCP 连接超时（秒） |
| `timeout` | int | `180` | 整体请求超时（秒）。GPT-4 偶尔会很慢 |

### 切换其它 GPT 平台

只要平台兼容 OpenAI Chat Completions 格式（POST messages 数组、返回 `choices[0].message.content`），换 URL 和 Key 即可。已知兼容的：

- Azure OpenAI（URL 不同，需要再调整代码）
- OpenRouter
- 国内的"中转"平台（自行评估稳定性）
- DeepSeek、Moonshot、智谱清言等（多数兼容 OpenAI 协议）

完全不兼容的平台（如 Anthropic Claude 原生 API），需要改 `callGptRaw()` 函数。

---

## image_gen 绘图模型

控制实际出图。当前默认对接 **GRSAI Nano-Banana** 平台。

| 字段 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `draw_url` | string | `https://grsai.dakka.com.cn/v1/draw/nano-banana` | 提交绘图任务的 URL |
| `result_url` | string | `https://grsai.dakka.com.cn/v1/draw/result` | 查询任务结果的 URL |
| `api_key` | string | `` | 平台 API Key |
| `model` | string | `nano-banana-pro` | 模型名 |
| `aspect_ratio` | string | `auto` | 画面比例。`auto` 会按蒙版尺寸自适应。可改成 `1:1`、`3:4`、`16:9` 等 |
| `image_size` | string | `4K` | 输出尺寸档位。Nano-Banana 支持 `1K` / `2K` / `4K`。`4K` 最清晰但也最慢/最贵 |
| `shut_progress` | bool | `false` | 是否关闭进度回报。一般保持 `false` |
| `poll_interval_us` | int | `1500000` | 轮询任务结果的间隔（微秒）。默认 1.5 秒 |
| `max_polls` | int | `120` | 最大轮询次数。`120 × 1.5s = 180s` 后超时 |
| `connect_timeout` | int | `20` | TCP 连接超时（秒） |
| `timeout` | int | `1800` | 整体请求超时（秒）。绘图很慢，给 30 分钟富余 |
| `step_max_retries` | int | `3` | 每个子步骤（创意/出图/裁切）的最大自动重试次数 |
| `step_retry_delay_us` | int | `2000000` | 重试间隔（微秒）。默认 2 秒 |

### 切换其它绘图平台

`index.php` 里有两个关键函数：

- `benanaSubmitTask($prompt, $referenceUrls)`：提交任务，返回 task ID
- `benanaPollTask($taskId, $onProgress)`：轮询结果，返回 `['image_url' => ..., 'content' => ...]`

如果你接的是同步出图的平台（如 SD WebUI、ComfyUI、DALL-E），可以把这两个函数合并成一个直接返回图片 URL 或 base64。注意保留 `generateBenanaImageToPng()` 中的「moderation 重试」逻辑——它会在第一次失败时换一个更"温和"的 prompt 再试一次，避免命中内容审核。

---

## admin 管理员账号

后台管理员凭证。

| 字段 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `username` | string | `admin` | 后台用户名。可以在后台「修改管理员账号 / 密码」面板里改 |
| `password_hash` | string | bcrypt of `admin123` | 后台密码的 bcrypt 哈希。**永远不要在配置文件里写明文密码** |

### 重置密码（救急）

如果忘了密码，可以手动改 `app_config.json`。先生成新哈希：

```bash
php -r "echo password_hash('your_new_password', PASSWORD_DEFAULT) . PHP_EOL;"
```

把输出的 `$2y$10$....` 整段填到 `admin.password_hash` 字段，保存即可。

或者直接把 `app_config.json` 删掉，密码会回到 `admin / admin123`，但**所有其它配置（API Key 等）也会丢**，要重新填。

---

## 手动编辑配置

虽然推荐用后台界面改，但有时手动编辑更快（比如批量部署、自动化运维）。

`_airate_runtime/system/app_config.json` 一份完整示例（已脱敏）：

```json
{
  "brand": {
    "app_name": "稿擎",
    "company_name": "上海某科技有限公司",
    "app_tagline": "生产级设计稿生成平台",
    "app_slogan": "上传模板，输入主题，自动产出商业级设计稿",
    "contact_email": "hi@yoursite.com"
  },
  "business": {
    "start_credits": 20,
    "credit_per_image": 5,
    "max_concurrent_orders": 3,
    "estimated_seconds_per_image": 95,
    "card_purchase_url": "https://buy.example.com/topup",
    "order_retention_seconds": 86400
  },
  "limits": {
    "max_theme_length": 120,
    "max_mask_name_length": 40,
    "max_username_length": 24,
    "max_password_length": 32,
    "min_password_length": 6,
    "max_mask_upload_size": 15728640,
    "max_design_set_count": 5,
    "min_design_set_count": 1,
    "max_mask_width": 6000,
    "max_mask_height": 6000
  },
  "sms": {
    "enabled": true,
    "api_url": "https://www.tosms.cn/Api/SendSms.ashx",
    "username": "your_sms_account",
    "password_md5": "REPLACE_WITH_YOUR_32_CHAR_MD5_HASH",
    "sign_name": "你的公司",
    "code_expire_seconds": 300,
    "send_cooldown_seconds": 60
  },
  "gpt": {
    "api_url": "https://api.openai.com/v1/chat/completions",
    "api_key": "sk-PLACEHOLDER_REPLACE_ME",
    "model": "gpt-4o",
    "temperature": 0.45,
    "max_tokens": 2600,
    "retry_times": 3,
    "connect_timeout": 20,
    "timeout": 180
  },
  "image_gen": {
    "draw_url": "https://grsai.dakka.com.cn/v1/draw/nano-banana",
    "result_url": "https://grsai.dakka.com.cn/v1/draw/result",
    "api_key": "sk-PLACEHOLDER_REPLACE_ME",
    "model": "nano-banana-pro",
    "aspect_ratio": "auto",
    "image_size": "4K",
    "shut_progress": false,
    "poll_interval_us": 1500000,
    "max_polls": 120,
    "connect_timeout": 20,
    "timeout": 1800,
    "step_max_retries": 3,
    "step_retry_delay_us": 2000000
  },
  "admin": {
    "username": "admin",
    "password_hash": "$2y$10$用password_hash生成的哈希"
  }
}
```

---

## 配置生效机制

不同字段的生效时机略有不同：

| 类型 | 生效时机 |
| --- | --- |
| 品牌信息（HTML 渲染时使用） | 用户**下次刷新页面**时生效 |
| 业务参数（创建订单时读取） | 下次**创建订单**时生效 |
| API Key（订单处理时读取） | 下一个**进入处理**的订单生效（已经在跑的订单不会切换 Key） |
| SMS 配置（发短信时读取） | 下一次发送验证码时生效 |
| 管理员密码 | 下次登录时生效 |

如果某项改了不生效，做下面三步：

1. 强制刷新浏览器（Ctrl + F5）
2. PHP 有 OPcache 时执行 `sudo systemctl reload php-fpm`（或重启 Web 服务）
3. 检查 `app_config.json` 文件确实被写入（看修改时间）

---

如果你想新增配置项（比如自定义某个业务参数），步骤是：

1. 在 `config.php` 的 `gaoqing_default_config()` 里加入默认值
2. 在 `gaoqing_apply_config_constants()` 里把它注册成常量（如果业务代码用常量访问）
3. 在 `admin.php` 的「系统配置」表单里加一个 `<input data-cfg="your.path">`
4. 业务代码里直接用 `gaoqing_config_get('your.path')` 读取

完成。
