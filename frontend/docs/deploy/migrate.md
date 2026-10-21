# 从原版v2b迁移

适用于 `v2board/v2board`。在原站目录操作，先备份数据库、`.env`、`config/v2board.php` 和自定义文件，保留原 `APP_KEY`，暂停队列守护与计划任务。目标为 shuv2b 的 `release` 运行分支，不含前端源码。

## 1. 切换仓库并更新

```sh
php artisan down
git remote set-url origin https://github.com/hajidishu/shuv2b.git
git fetch origin
git checkout release
bash update.sh
```

`update.sh` 会安装依赖并调用 `v2board:update`，补齐数据库结构，所以不需要再手动导入 SQL。新版脚本遇到本地修改或分叉会停止，先备份并处理差异。

## 2. 配置 Redis 并刷新缓存

确认 Redis 连接正确，在 `.env` 中设置以下两项；没有的项补上：

```dotenv
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
```

```sh
php artisan config:clear
php artisan config:cache
php artisan view:clear
php artisan horizon:terminate
```

当前分支使用 Redis 缓存和队列，刷新缓存并重启进程后才会使用新配置。

## 3. 保存主题并恢复运行

进入后台「主题配置 → 选择 default → 主题设置 → 保存」。使用自定义主题时，选择兼容的原主题并保存。

恢复队列守护与计划任务，重载 PHP-FPM；使用 Webman 时重启 Webman。然后执行：

```sh
php artisan horizon:status
php artisan up
```

检查原用户订阅、节点及订单是否正常。主题重新保存用于更新配置，常驻进程重启用于加载新代码。原站 PHP 8.3 可先保留；PHP 8.4 仅在指定 PHP-FPM 测试环境验证，未全面验证 Webman。

## 常见问题

### 原数据库要重新导入吗？

不用重建数据库，但原版各版本结构不同，应先在数据库副本验证。更新脚本会执行基础结构升级并安装新增功能表；不要执行 `v2board:install` 或整份导入安装 SQL。

### 拉取代码失败怎么办？

先检查目标仓库和本地修改。切换 `release` 时若有文件冲突，先保存改动；更新采用 `--ff-only`，不要硬重置丢弃修改。目标地址应包含本 fork 的代码，不能填成 `wyx2685/v2board` 后期待获得本 fork 的功能。

### 新后台打不开怎么办？

部署版本需包含完整的 `public/assets/admin-v2`。使用源码仓库时，先按[管理员前端说明](../features/admin#构建与部署)编译，再刷新视图与浏览器缓存。

### 支付回跳或节点连接不正常怎么办？

分离前端按[回跳配置](../features/payment-return-url)设置前端地址和路径；原节点服务端需确认兼容当前 API。保留原支付渠道标识、密钥和通知地址，对照商户记录核对在途收款。

### 迁移失败怎么回滚？

先停止新业务并处理在途收款，再恢复配套的旧代码、数据库和配置。已经产生新订单时，不能直接用旧备份覆盖当前库。新付款尝试未处理完时，也不能直接切回旧代码。
