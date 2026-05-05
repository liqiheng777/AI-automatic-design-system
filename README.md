# GaoQing · 模板化 AI 设计稿生成平台

> 上传一张固定结构的"蒙版"模板，写一句话主题，自动产出一整套商业级二维平面设计稿。
> 适合做电商、周边、IP 衍生、礼品、潮玩贴标、手柄面板、灯牌等**有固定模板**的设计需求。

[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-MIT-green?style=flat-square)](LICENSE)
[![Storage](https://img.shields.io/badge/Storage-File%20Based-orange?style=flat-square)]()
[![Database](https://img.shields.io/badge/Database-None-lightgrey?style=flat-square)]()

---

## 目录

- [它是什么](#它是什么)
- [核心特性](#核心特性)
- [快速开始](#快速开始)
- [工作流程](#工作流程)
- [项目结构](#项目结构)
- [配置说明](#配置说明)
- [部署说明](#部署说明)
- [常见问题](#常见问题)
- [安全注意事项](#安全注意事项)
- [License](#license)

---

## 它是什么

GaoQing 是一个**纯 PHP、无数据库**的 Web 应用，做的事情很简单：

1. 用户上传一张**二值蒙版图**（白色 = 可设计区域，黑色 = 不可改的边界/孔位/外框）
2. 用户从风格库里选一种风格（高级品牌感 / 赛博能量 / 国潮东方 / ……），输入一句主题词
3. 系统调用 GPT 生成设计创意 prompt → 调用绘图 AI 生成图像 → 用 GD 把结果按蒙版裁切
4. 同一订单可一次生成 1–5 张不同方向的方案，用户挑一张满意的下载，整单也可以打包 ZIP 带走

整个项目数据**全部存文件**（JSON + PNG + ZIP），不依赖 MySQL/Redis，开箱即用。

---

## 核心特性

### 用户端

- 🎯 **模板严格不变形**：基于蒙版做像素级裁切，外轮廓、孔位、镂空一像素都不会跑偏
- ⚡ **批量并发出稿**：默认同时 3 个订单并行处理，一次提交可出 1–5 张不同方向的方案
- 🎨 **12 套商业风格库**：高级品牌感、赛博能量、潮玩可爱、暗黑奢感、极简高级、国潮东方、二次元 IP、复古怀旧、街头涂鸦、马卡龙梦幻、工业科技、节庆喜庆
- 🎛 **支持自定义风格**：用户可写一段风格描述保存为自定义风格，最多 30 个
- 📦 **整单 ZIP 打包**：每个完成的订单自动打包成包含设计成品 + 设计思路 JSON + 思路 TXT 的 ZIP
- 🔒 **资产隔离**：每个用户的蒙版、订单、生成结果完全独立，互相不可见
- 📊 **可视化进度**：排队位置、当前步骤、运行时长、预估完成时间实时刷新
- 💰 **额度计费**：注册赠送 20 额度，每张 5 额度，失败自动退款，卡密充值

### 后台

- 👥 用户管理：列表 / 搜索 / 详情 / 封禁解封 / 重置密码
- 💎 额度管理：手动加减、变动记录、备注
- 🧩 蒙版管理：所有用户的蒙版预览
- 📦 订单管理：所有订单的状态 / 进度 / 错误信息 / 成品下载
- 🎟 卡密管理：批量生成、按批次追踪、作废、删除
- ⚙️ **系统配置（在线编辑）**：API Key、品牌信息、业务参数全部从后台界面填写，无需改代码
- 🔐 管理员密码可在后台修改（bcrypt + 限速防爆破）

### 技术亮点

- 全文件存储，零数据库依赖
- 文件锁 (`flock`) 保证并发安全
- 步骤级自动重试（最多 3 次）+ 版权拒绝立即终止
- 异步执行：通过 `fastcgi_finish_request` 在 HTTP 响应后继续处理订单
- 清理任务自动跑：超过 24 小时的订单文件自动清理释放磁盘
- CSRF 保护、密码 bcrypt、SameSite Cookie

---

## 快速开始

### 前置条件

- **PHP ≥ 8.1**（用了 `match` 表达式、`never` 返回类型、`readonly`-style 等）
- 启用扩展：`gd`、`curl`、`json`、`fileinfo`、`mbstring`、`zip`
- 一个支持 PHP 的 Web 服务器：Apache / Nginx + PHP-FPM 都可以
- 一台运行 Linux/macOS/Windows 的服务器（推荐 Linux）

### 三步部署

```bash
# 1. 克隆代码
git clone https://github.com/yourname/gaoqing.git
cd gaoqing

# 2. 给 PHP 进程账号写权限（用于创建 _airate_runtime 目录）
chmod -R 755 .
mkdir -p _airate_runtime
chown -R www-data:www-data _airate_runtime    # Apache / Nginx 默认账号

# 3. 把项目根目录指给 Web 服务器
# Apache: DocumentRoot 指向项目目录
# Nginx:  详见 INSTALL.md
```

然后浏览器打开站点首页即可看到产品页。第一次部署完，**立刻**做下面这件事：

### 首次配置（很重要）

1. 浏览器访问 `https://你的域名/admin.php`
2. 用默认账号登录：
   - **用户名**：`admin`
   - **密码**：`admin123`
3. 进入「**系统配置**」 → 填入：
   - **品牌信息**（应用名 / 公司 / 联系邮箱 / 标语）
   - **GPT API Key**（用 OpenAI 官方就填 `https://api.openai.com/v1/chat/completions` + 你的 sk-key + `gpt-4o`）
   - **绘图 API Key**（默认对接 GRSAI Nano-Banana，可换其他兼容接口）
   - **短信平台**（不需要可不填，会自动进入开发模式直接显示验证码）
4. 进入「**修改管理员账号 / 密码**」 → 改成你自己的强密码（≥8 位）
5. 退出，重新登录确认无误

完成上面 5 步，应用就上线了。

---

## 工作流程

```
┌──────────────┐    ┌──────────────┐    ┌──────────────────┐
│ 用户上传蒙版 │───▶│ 选风格+写主题│───▶│ 提交订单（扣额度）│
└──────────────┘    └──────────────┘    └────────┬─────────┘
                                                  │
                          ┌───────────────────────▼──────────┐
                          │ 队列调度（最多 3 个订单同时跑）  │
                          └───────────────────────┬──────────┘
                                                  │
                                  ┌───────────────▼──────────────┐
                                  │ 每张稿件三步走：             │
                                  │  A. GPT 生成 design prompt   │
                                  │  B. 绘图模型出图             │
                                  │  C. GD 按蒙版裁切            │
                                  │ （任一步失败自动重试 3 次）  │
                                  └───────────────┬──────────────┘
                                                  │
                                  ┌───────────────▼──────────────┐
                                  │ 全部完成 → 打包 ZIP          │
                                  │ 失败     → 退还剩余额度      │
                                  │ 版权命中 → 立即终止+退款     │
                                  └──────────────────────────────┘
```

---

## 项目结构

```
gaoqing/
├── index.php           # 用户端入口（首页 + 工作台 + API）
├── admin.php           # 后台管理（仪表盘 + 用户/订单/蒙版/卡密/系统配置）
├── config.php          # 配置加载器（不含密钥；从 JSON 加载）
├── README.md           # 你正在看的这个
├── INSTALL.md          # 详细部署文档
├── CONFIG.md           # 配置项详解
├── LICENSE             # MIT
├── .gitignore          # 关键：把 _airate_runtime 排除掉
└── _airate_runtime/    # 运行时数据，自动创建，已 gitignore
    ├── system/
    │   ├── app_config.json     # 你的所有配置（含 API Key）
    │   ├── indices.json        # 用户名/手机号/token 索引
    │   ├── system.lock         # 系统级文件锁
    │   ├── queue.lock          # 订单队列锁
    │   ├── cleanup_meta.json   # 清理任务元数据
    │   └── dev_sms.log         # 开发模式下的短信验证码日志
    ├── users/<USR_xxx>.json    # 每个用户一个 JSON 文件
    ├── masks/<USR_xxx>/<MASK_xxx>.{json,png}
    ├── orders/<ORDER_xxx>/
    │   ├── meta.json
    │   ├── order_mask.png
    │   ├── set1_raw.png
    │   ├── set1_masked.png
    │   ├── set1_idea.json
    │   ├── set1_idea.txt
    │   └── design_package.zip
    └── cards/card_keys.json    # 所有卡密
```

---

## 配置说明

所有配置在后台「系统配置」页面的可视化表单中完成。完整字段说明见 **[CONFIG.md](CONFIG.md)**。

简要清单：

| 模块 | 关键字段 | 是否必填 |
| --- | --- | --- |
| 品牌 | 应用名、公司、邮箱、标语 | 建议改 |
| 业务 | 注册赠送额度、每张消耗、最大并发 | 有默认值 |
| **GPT** | API URL、API Key、模型名 | **必填** |
| **绘图** | 提交/查询 URL、API Key、模型名 | **必填** |
| 短信 | 启用开关 + tosms.cn 兼容字段 | 可选（关闭则进入开发模式） |
| 卡密 | 购买跳转链接 | 可选（留空则隐藏入口） |
| 管理员 | 用户名、密码 | 部署后必改 |

---

## 部署说明

详细的 Apache、Nginx、Docker、宝塔/aaPanel 部署指南见 **[INSTALL.md](INSTALL.md)**。

简易速查：

- **Apache**：把项目目录设为 DocumentRoot，确保 mod_rewrite 不必（项目无路由重写需求），允许 PHP 执行
- **Nginx**：把 `index.php` 和 `admin.php` 加到 `try_files`，配合 PHP-FPM，单独保护 `_airate_runtime` 目录不让外部访问

---

## 常见问题

<details>
<summary><b>Q：可以不配短信吗？</b></summary>

可以。`系统配置 → 短信平台 → 是否启用 = 关闭`，注册时验证码会直接显示在响应消息里，并写入 `_airate_runtime/system/dev_sms.log`。**只适合开发/小范围使用**，公开运营请配置真实短信。
</details>

<details>
<summary><b>Q：GPT 模型可以换成别的吗？例如 Claude、DeepSeek？</b></summary>

可以。只要接口兼容 OpenAI Chat Completions 格式（`POST` 一个 messages 数组，返回 `choices[0].message.content`），改 `GPT API URL` + `Model` 即可。如果是完全不同的接口协议，需要改 `index.php` 里的 `callGptRaw()` 函数。
</details>

<details>
<summary><b>Q：绘图模型可以换吗？</b></summary>

绘图模型默认对接 GRSAI 的 Nano-Banana 接口（提交任务 → 轮询结果），如果你接的是 SD WebUI、ComfyUI、DALL-E、即梦等同步接口，需要改 `index.php` 里的 `benanaSubmitTask()` 和 `benanaPollTask()` 函数。
</details>

<details>
<summary><b>Q：用户数据存在文件里，并发会不会冲突？</b></summary>

不会。所有读写都通过 `flock(LOCK_EX)` 加文件锁，且写入用先写临时文件再 `rename` 的原子方式，保证并发安全。但磁盘速度会成为瓶颈，如果用户量大（万级以上），建议改造成 SQLite 或 MySQL。
</details>

<details>
<summary><b>Q：怎么清理旧订单？</b></summary>

订单默认保留 24 小时，过期会自动被清理脚本删除（每 10 分钟检查一次，跟随用户访问触发）。如果想改，调整 `系统配置 → 业务 → order_retention_seconds`。蒙版文件不会自动删，由用户自己在前台删除。
</details>

<details>
<summary><b>Q：忘了管理员密码怎么办？</b></summary>

直接编辑 `_airate_runtime/system/app_config.json`，把 `admin.password_hash` 改成你的 bcrypt 哈希即可。生成新哈希：

```bash
php -r "echo password_hash('your_new_password', PASSWORD_DEFAULT);"
```

或者把整个文件删掉，配置会回到默认状态（admin / admin123），但**所有其它配置也会丢**，要重新填。
</details>

---

## 安全注意事项

🚨 **请认真阅读这段，不然你的 API Key 可能会泄露！**

1. **绝对不要把 `_airate_runtime/` 目录提交到 Git**。`.gitignore` 已经帮你处理好了，不要手贱改它。
2. **部署后立刻改默认管理员密码**。`admin / admin123` 不改的话，任何看过本仓库的人都能登录你的后台。
3. **限制 `_airate_runtime/` 的 Web 访问**。它包含用户上传的蒙版、配置文件等。在 Apache/Nginx 配置中明确禁止外部 HTTP 访问该目录（INSTALL.md 有示例）。
4. **强烈建议启用 HTTPS**。本项目用 Cookie 维持登录态，HTTP 下会被中间人嗅探。
5. **生产环境请关闭错误显示**。`index.php` 顶部 `ini_set('display_errors', '1')` 改成 `'0'`，避免暴露文件路径。
6. **API Key 配额监控**。后台没有限制单个用户的调用频率（除了额度系统），如果 GPT/绘图平台账号被滥用，账单会很贵。建议在 GPT 平台一侧设置 API 用量上限。

---

## License

[MIT License](LICENSE)

本项目所有第三方服务的 Key 和账号需要部署者自行向对应平台申请。

如果觉得这个项目有用，欢迎 Star ⭐ 或者把改进 PR 回来 🫶
