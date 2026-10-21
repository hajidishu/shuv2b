# 订阅审计

## 查询记录

在「用户 → 订阅审计」查看拉取时间、邮箱、IP、UA、格式、结果及 HTTP 状态，支持多条件筛选。

点击时间或「详情」查看方法、请求地址、实际 token、查询参数、请求头和耗时，并复制「用户当前订阅链接」。Cookie、Authorization 等凭据仍脱敏，不保存响应正文。

顶部统计覆盖全部筛选结果，可切换 IP / UA / 用户前十名。统计最多缓存 60 秒，「刷新」可立即更新。

## 保留设置

默认 **30 天、1,000,000 条**，先达到哪个限制就按哪个清理，旧记录按拉取时间和 ID 淘汰。调低上限后后台分批清理，删除的数据不可恢复。

过期清理每分钟运行，也可手动执行：

```sh
php artisan subscribe-audit:prune
```

## 安装与升级

正常安装、`php artisan v2board:update` 会自动补齐结构；仅安装本功能时执行：

```sh
php artisan subscribe-audit:install
php artisan config:cache
php artisan horizon:terminate
```

新增 `v2_subscribe_audit`、`v2_subscribe_audit_setting` 表；旧版升级会补充 HTTP 详情字段，保留已有记录和设置。数据库账号需有建表及 ALTER 权限。

确保 Horizon 守护、`subscription_audit` 消费者和每分钟调度正常；Webman 等常驻 Web 进程需重启。

## 常见问题

**为什么没有某次请求？** 仅记录识别到用户并进入订阅流程的请求，含账号不可用和处理异常。无效 token、CDN 未回源、提前拦截及启用前的请求不记录。

**为什么 IP 不对？** 配置实际代理的 Nginx real_ip 或 `TrustProxies`，不要信任任意来源的转发头。多个 IP 本身不能证明共享。

**旧 token 能恢复吗？** 历史已脱敏值不能恢复；可复制当前链接。重置 token 后，历史值和当前链接不同。

**详情不完整？** UA 最多 512 字节，HTTP 详情最多 24,000 字节；旧记录未保存的信息不能补录。

**如何停用或回退？** 设置 `SUBSCRIPTION_AUDIT_ENABLED=false`，刷新配置并重启常驻进程，记录与定时清理一并停用。回退代码前排空审计队列，新增表可保留。
