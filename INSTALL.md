# 部署指南

本文档详细说明如何在不同环境下部署 GaoQing。

## 目录

- [环境要求](#环境要求)
- [获取代码](#获取代码)
- [Apache 部署](#apache-部署)
- [Nginx 部署](#nginx-部署)
- [宝塔 / aaPanel 一键部署](#宝塔--aapanel-一键部署)
- [Docker 部署](#docker-部署)
- [本地开发](#本地开发)
- [首次配置](#首次配置)
- [生产环境加固](#生产环境加固)
- [升级与备份](#升级与备份)
- [问题排查](#问题排查)

---

## 环境要求

### 服务器

- Linux / Windows / macOS 任意一种（推荐 Ubuntu 22.04 / Debian 12 / CentOS 9 / Rocky Linux）
- 至少 2 GB 内存（GD 处理大图比较吃内存，且 PHP 默认配置上限是 1024M）
- 至少 10 GB 磁盘（订单文件会累积，但 24 小时后自动清理）
- 公网 IP / 域名 / SSL 证书（推荐用 Let's Encrypt）

### 软件

- **PHP ≥ 8.1**（必须，代码用了 `match`、`never` 等 8.0+ 特性）
- **PHP 扩展**：
  - `gd`（必须，用于图像处理）
  - `curl`（必须，用于调用 AI API）
  - `json`、`fileinfo`、`mbstring`、`zip`（一般默认就启用）
  - 推荐：`opcache`（性能）、`apcu`（可选）
- **Web 服务器**：Apache 2.4+ 或 Nginx 1.20+
- **PHP-FPM**（如果用 Nginx）

### 检查环境

把下面这段保存为 `check.php` 放到项目根目录访问，可以快速验证：

```php
<?php
echo 'PHP: ' . PHP_VERSION . "\n";
$ext = ['gd','curl','json','fileinfo','mbstring','zip'];
foreach ($ext as $e) {
    echo "$e: " . (extension_loaded($e) ? 'OK' : 'MISSING') . "\n";
}
echo 'memory_limit: ' . ini_get('memory_limit') . "\n";
echo 'post_max_size: ' . ini_get('post_max_size') . "\n";
echo 'upload_max_filesize: ' . ini_get('upload_max_filesize') . "\n";
echo 'max_execution_time: ' . ini_get('max_execution_time') . "\n";
echo 'writable: ' . (is_writable(__DIR__) ? 'OK' : 'FAIL') . "\n";
```

期望全部 `OK`，且：
- `memory_limit` ≥ 512M（推荐 1024M）
- `post_max_size` ≥ 20M
- `upload_max_filesize` ≥ 20M
- `max_execution_time` 在 PHP-FPM 下不重要，因为代码里用了 `set_time_limit(0)`

测完记得删掉 `check.php`。

---

## 获取代码

### 方式 1：Git Clone（推荐）

```bash
cd /var/www
git clone https://github.com/yourname/gaoqing.git
cd gaoqing
```

### 方式 2：下载 ZIP

从 Release 页下载 `.zip`，上传到服务器解压。

### 方式 3：直接上传

用 FTP / SCP / 宝塔文件管理器，把项目目录上传到服务器即可。**只需要这三个 PHP 文件 + 配套文档**：

```
index.php
admin.php
config.php
README.md  (可选)
LICENSE    (可选)
.gitignore (推荐)
```

---

## Apache 部署

### 1. 准备 vhost

新建一个 VirtualHost（以 Ubuntu 24.04 + Apache 2.4 + PHP 8.3 为例）：

```bash
sudo nano /etc/apache2/sites-available/gaoqing.conf
```

写入：

```apache
<VirtualHost *:80>
    ServerName gaoqing.example.com
    DocumentRoot /var/www/gaoqing

    <Directory /var/www/gaoqing>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # 关键：禁止外部访问 _airate_runtime 目录
    <DirectoryMatch "/_airate_runtime">
        Require all denied
    </DirectoryMatch>

    # 上传大小（蒙版可能 15MB）
    <IfModule mod_php.c>
        php_value upload_max_filesize 20M
        php_value post_max_size 20M
        php_value memory_limit 1024M
    </IfModule>

    ErrorLog ${APACHE_LOG_DIR}/gaoqing-error.log
    CustomLog ${APACHE_LOG_DIR}/gaoqing-access.log combined
</VirtualHost>
```

### 2. 启用站点

```bash
sudo a2ensite gaoqing.conf
sudo a2dissite 000-default.conf    # 可选，停用默认站点
sudo systemctl reload apache2
```

### 3. 设置目录权限

```bash
sudo chown -R www-data:www-data /var/www/gaoqing
sudo chmod -R 755 /var/www/gaoqing
sudo mkdir -p /var/www/gaoqing/_airate_runtime
sudo chmod 775 /var/www/gaoqing/_airate_runtime
```

### 4. 配置 HTTPS（推荐）

用 Certbot 一键申请 Let's Encrypt 证书：

```bash
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d gaoqing.example.com
```

它会自动改写你的 vhost 文件，加入 443 端口和强制 HTTPS 跳转。

---

## Nginx 部署

### 1. 安装 Nginx + PHP-FPM

```bash
sudo apt install -y nginx php8.3-fpm php8.3-gd php8.3-curl php8.3-mbstring php8.3-zip php8.3-cli
```

### 2. 准备 Nginx 配置

```bash
sudo nano /etc/nginx/sites-available/gaoqing
```

写入：

```nginx
server {
    listen 80;
    server_name gaoqing.example.com;

    root /var/www/gaoqing;
    index index.php;

    client_max_body_size 20M;

    # 关键：禁止外部访问运行时目录（含 API Key、用户数据）
    location ~ ^/_airate_runtime/ {
        deny all;
        return 404;
    }

    # 禁止访问隐藏文件（.gitignore、.git 等）
    location ~ /\. {
        deny all;
        return 404;
    }

    # 静态资源直出
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|woff2?)$ {
        try_files $uri =404;
        expires 1d;
        access_log off;
    }

    # 默认走 index.php / admin.php
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP 处理
    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;

        # 关键：等待 AI 接口的长时间响应
        fastcgi_read_timeout 1800;
        fastcgi_send_timeout 1800;
    }

    error_log /var/log/nginx/gaoqing-error.log;
    access_log /var/log/nginx/gaoqing-access.log;
}
```

### 3. 启用 + 重载

```bash
sudo ln -s /etc/nginx/sites-available/gaoqing /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### 4. PHP-FPM 调参（重要）

`/etc/php/8.3/fpm/php.ini` 调整：

```ini
memory_limit = 1024M
upload_max_filesize = 20M
post_max_size = 20M
max_execution_time = 1800
```

`/etc/php/8.3/fpm/pool.d/www.conf` 调整池子大小（按服务器内存定）：

```ini
pm = dynamic
pm.max_children = 20         ; 4 核 8G 大约配 20-30
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 8
```

```bash
sudo systemctl restart php8.3-fpm
```

### 5. HTTPS

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d gaoqing.example.com
```

---

## 宝塔 / aaPanel 一键部署

如果你用宝塔面板（推荐新手），步骤更简单：

1. **「网站」→「添加站点」**
   - 域名：`gaoqing.example.com`
   - 根目录：`/www/wwwroot/gaoqing`
   - PHP 版本：选 **8.1 或更高**
   - 数据库：**不需要**
2. **上传代码**：把 `index.php` / `admin.php` / `config.php` 这三个文件上传到 `/www/wwwroot/gaoqing/`
3. **「PHP 设置」→「安装扩展」**：确保 `gd`、`curl`、`zip`、`fileinfo` 都打了勾
4. **「PHP 设置」→「配置修改」**：把 `upload_max_filesize` 和 `post_max_size` 都改到 20M，`memory_limit` 改到 1024M
5. **「设置」→「禁止访问目录」**：加一条 `/_airate_runtime`，避免目录被外部访问
6. **「SSL」**：用宝塔的 Let's Encrypt 一键申请证书 + 强制 HTTPS

---

## Docker 部署

写一个最简的 `Dockerfile`：

```dockerfile
FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
        libgd-dev libwebp-dev libjpeg-dev libpng-dev libfreetype6-dev \
        libzip-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) gd zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# 上传 / 内存调参
RUN echo "upload_max_filesize=20M\npost_max_size=20M\nmemory_limit=1024M" \
    > /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html
COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html \
    && mkdir -p /var/www/html/_airate_runtime \
    && chmod -R 775 /var/www/html/_airate_runtime

# 禁止外部访问 runtime 目录
RUN echo '<Directory "/var/www/html/_airate_runtime">\n  Require all denied\n</Directory>' \
    > /etc/apache2/conf-enabled/gaoqing-secure.conf

EXPOSE 80
CMD ["apache2-foreground"]
```

构建并运行：

```bash
docker build -t gaoqing .
docker run -d --name gaoqing \
  -p 8080:80 \
  -v gaoqing-data:/var/www/html/_airate_runtime \
  --restart unless-stopped \
  gaoqing
```

`gaoqing-data` 这个 volume 保存了所有运行时数据（API Key、用户、订单、蒙版），**升级容器时务必保留**。

或者用 `docker-compose.yml`（更推荐）：

```yaml
services:
  gaoqing:
    build: .
    ports:
      - "8080:80"
    volumes:
      - ./_airate_runtime:/var/www/html/_airate_runtime
    restart: unless-stopped
```

---

## 本地开发

PHP 内置服务器最方便：

```bash
cd gaoqing
php -S 127.0.0.1:8000
```

然后访问 `http://127.0.0.1:8000/` 看用户端，`http://127.0.0.1:8000/admin.php` 进后台。

⚠️ PHP 内置服务器是**单线程**的，并发能力很差，绝对不能在生产环境用。

---

## 首次配置

部署完毕后，浏览器访问 `https://你的域名/admin.php`：

### 第一步：登录

- 用户名：`admin`
- 密码：`admin123`

### 第二步：进入「系统配置」

填入下面这些（不需要的字段可以留空）：

#### 必填

- **GPT 接口**
  - API URL：`https://api.openai.com/v1/chat/completions`（如果用 OpenAI）
  - API Key：`sk-...`
  - 模型名：`gpt-4o`、`gpt-4o-mini`、`gpt-4-turbo` 等
- **绘图模型**
  - API Key：`sk-...`
  - 其它字段保持默认（指向 GRSAI Nano-Banana）即可

#### 强烈建议改

- **品牌信息**：默认值是占位的 `GaoQing` / `Your Company`，看起来很 demo。
- **管理员密码**：默认 `admin / admin123` 是公开的，必须改。

#### 可选

- **短信平台**：如果不打算让用户用手机注册，关闭即可（开发模式直接显示验证码）。
- **业务参数**：注册赠送、每张消耗、并发上限可按需调整。
- **卡密购买链接**：如果接了发卡平台，把链接填进去；不接就留空。

### 第三步：保存配置 + 测试连通性

- 点 **「保存配置」**
- 点 **「测试 GPT 连通性」**，看到「GPT 接口连通正常」说明 Key 没问题
- 点 **「检查绘图 Key」** 确认 Key 已设置（具体连通性需要发起订单测试）

### 第四步：修改管理员凭证

「修改管理员账号 / 密码」面板里：

- 当前密码：`admin123`
- 新用户名：你想改的（可留空保持 admin）
- 新密码：≥ 8 位

提交后会被强制退出，用新凭证重新登录。

---

## 生产环境加固

### 1. 关闭错误显示

编辑 `index.php` 和 `admin.php`，把开头的：

```php
ini_set('display_errors', '1');
error_reporting(E_ALL);
```

改成：

```php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_airate_runtime/system/php-error.log');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
```

### 2. 启用 OPcache

`/etc/php/8.3/fpm/conf.d/10-opcache.ini`：

```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=10000
opcache.validate_timestamps=0   ; 生产环境关闭，部署时手动 reload PHP-FPM
```

### 3. 限制后台访问

可以给 `admin.php` 加 IP 白名单，Nginx：

```nginx
location = /admin.php {
    allow 1.2.3.4;
    deny all;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

或者把 admin.php 改名为不容易被猜到的名字（如 `admin-9f2x.php`），但更建议用 IP 白名单 + HTTP Basic Auth 双保险。

### 4. 备份策略

最重要的备份对象是：

```
_airate_runtime/system/app_config.json   (含 API Key)
_airate_runtime/users/                   (用户数据)
_airate_runtime/cards/card_keys.json     (卡密)
_airate_runtime/masks/                   (用户蒙版资产，可能很大)
```

cron 每天打包一次：

```bash
0 3 * * * cd /var/www/gaoqing && tar -czf /backup/gaoqing-$(date +\%F).tar.gz _airate_runtime
```

订单目录 `_airate_runtime/orders/` 是临时数据（24 小时清理），可以不备份。

---

## 升级与备份

升级流程（前提是 `_airate_runtime` 不在 git 仓库里）：

```bash
cd /var/www/gaoqing
sudo cp -a _airate_runtime /backup/_airate_runtime.$(date +%s)   # 先备份
sudo -u www-data git pull                                         # 拉最新代码
sudo systemctl reload php8.3-fpm                                  # 让 OPcache 重新加载
```

如果你修改过代码（比如改了风格库文案），用 `git stash` + `git stash pop` 把修改保留。

---

## 问题排查

### 访问首页 500 报错

- 看 `/var/log/apache2/gaoqing-error.log` 或 `/var/log/nginx/gaoqing-error.log`
- 9 成是 PHP 版本太低或缺扩展。先把 `display_errors` 临时打开，把错误读出来看看
- `_airate_runtime/` 目录没有写权限：`chown -R www-data:www-data _airate_runtime`

### 注册时验证码发不出去

- 配置短信平台后没有正确的密钥
- 关闭短信即可进入开发模式，验证码会显示在页面消息里

### GPT 调用失败

- 后台「系统配置」→「测试 GPT 连通性」看错误信息
- 常见：API Key 没钱、IP 被墙、模型名拼错

### 生成图片超时

- 绘图 API 慢，PHP-FPM 的 `fastcgi_read_timeout` 必须 ≥ 1800 秒
- 如果用 Cloudflare 之类的 CDN，需要单独配置长连接超时

### 上传蒙版失败

- 文件超过 15MB（代码里 `MAX_MASK_UPLOAD_SIZE`）
- PHP `upload_max_filesize` 太小

### 订单一直排队不动

- 队列推进依赖前端定时调用 `?action=queue_tick`，浏览器关掉就停了
- 解决方法：加一个 cron 任务每分钟跑一次：

```bash
* * * * * curl -s http://127.0.0.1/?action=queue_tick > /dev/null
```

---

如果还有问题，欢迎到 GitHub Issues 提问。
