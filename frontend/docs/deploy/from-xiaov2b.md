# 从 xiaov2b 迁移

适用于 `wyx2685/v2board`。在原站目录操作，先备份数据库、`.env`、`config/v2board.php` 和自定义文件，保留原 `APP_KEY`，暂停队列守护与计划任务。目标为 shuv2b 的 `release` 运行分支，不含前端源码。

## 1. 切换仓库并更新

```sh
php artisan down
git remote set-url origin https://github.com/hajidishu/shuv2b.git
git fetch origin
git checkout release
bash update.sh
```

更新脚本会安装依赖、升级结构并补齐审计、人工排查和支付台账表，不需要重新导入用户。新版脚本遇到本地修改或分叉会停止，先备份并处理差异。

## 2. 刷新配置和队列

确认 `.env` 中 Redis 连接正确，并使用：

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

使缓存与常驻进程加载目标版本的配置。迁移过程中不要清空生产 Redis 或批量重置用户 token。

## 3. 保存主题并恢复运行

进入后台「主题配置 → 选择原主题 → 主题设置 → 保存」。默认主题选择 `default`。

恢复队列守护与计划任务，重载 PHP-FPM；使用 Webman 时重启 Webman。然后执行：

```sh
php artisan horizon:status
php artisan up
```

检查用户、订阅、节点和订单，并确认 `subscription_audit` 队列有消费者。原运行目录不变，无需修改 Web 根目录或重新创建套餐。原站 PHP 8.3 可先保留，迁移后先验证业务；不要同时盲目升级 PHP。

## 常见问题

### 数据库和订阅链接需要重建吗？

不需要。原用户、套餐、订单、余额及订阅 token 沿用，更新时补齐新增结构即可。审计不会补录旧站未保存的请求历史。

### 拉取代码失败怎么办？

先检查目标仓库和本地提交。切换 `release` 时若有冲突，先保存改动；更新采用 `--ff-only`，分叉时需先处理版本差异。使用带编译资源的部署版本；源码版先[构建管理员前端](../features/admin#构建与部署)。

### 旧站改过支付回跳，还要复制补丁吗？

不需要。使用[回跳配置](../features/payment-return-url)填写前端地址和路径：原版为 `/#/order/{trade_no}`，EZ Theme 等主题可用 `/#/payment?trade_no={trade_no}`。不要用旧支付 PHP 文件覆盖新代码。

### 旧付款链接、登录或客服工具会受影响吗？

保留原支付渠道标识、密钥和通知地址，并核对在途收款。改变 Redis 配置可能要求用户重新登录；Staff API 已停用，依赖它的客服工具需要适配，管理员 API 继续使用。

### 更新失败怎么回滚？

先停止新业务并处理在途收款，再恢复配套的旧代码、数据库和配置。已经产生新订单时，不要直接用旧备份覆盖当前库；未完成的新付款尝试也不能直接交给旧代码处理。
