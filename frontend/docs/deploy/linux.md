# Linux 手动部署

适用于 Nginx + PHP-FPM。先准备[运行环境](../ENV)；宝塔 / aaPanel 用户见[面板部署](./aapanel)。以下路径、域名和 PHP 版本按实际环境替换。

## 1. 准备代码与数据库

```sh
export V2BOARD_REPOSITORY='https://github.com/hajidishu/shuv2b.git'
git clone --branch release --single-branch "$V2BOARD_REPOSITORY" /var/www/v2board
cd /var/www/v2board
```

创建 `utf8mb4` 数据库及应用账号，授予读写、建表和修改结构权限。`release` 已带管理员编译资源；`master` 为完整源码。

## 2. 安装面板

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
composer check-platform-reqs --no-dev
php artisan v2board:install
```

按提示填写数据库和管理员邮箱，保存输出的入口与凭据。安装器创建 `.env`、`APP_KEY` 和数据库结构。**已有站点不要重新安装**，应按[原版 v2b](./migrate)或[xiaov2b](./from-xiaov2b)教程迁移。

## 3. 准备管理端资源

部署包需包含完整的 `public/assets/admin-v2`。尚未构建的源码版先按[管理员前端说明](../features/admin#构建与部署)编译，再一并发布。

## 4. 配置 Nginx

Web 根目录指向 `public`，配置 HTTPS。以下为 HTTP 入口示例，PHP-FPM socket 按实际路径填写：

```nginx
server {
    listen 80;
    server_name panel.example.com;
    root /var/www/v2board/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ \.php$ { return 404; }
    location ~ /\. { deny all; }
}
```

PHP 运行账号需能写 `storage`、`bootstrap/cache`、`config/v2board.php`；安装时还需创建 `.env`。按账号设置权限，不要全目录 `777`。

## 5. 配置队列与调度

填写 `.env` 中的数据库和 Redis 连接，并设置：

```dotenv
APP_ENV=production
APP_DEBUG=false
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
```

```sh
php artisan config:cache
```

按[队列指南](./queues)守护 Horizon，并每分钟执行调度器，处理订单、发信及定时任务。

## 6. 检查运行

登录后台，修改初始密码、设置站点网址；检查队列监控，再用测试用户验证套餐、订阅、节点、邮件与支付。独立用户前端需设置[支付回跳地址](../features/payment-return-url)。
