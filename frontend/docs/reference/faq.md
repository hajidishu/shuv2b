# 常见问题

## 数据库能否沿用

可以保留原数据，但要执行结构升级。按来源选择[原版 v2b](../deploy/migrate)或[xiaov2b](../deploy/from-xiaov2b)教程。

## 已付款但仍在开通中

检查通知验签、[收款台账](../features/payment-ledger)及 `order_handle` 队列。回跳页面不负责确认收款。

## 支付后跳错页面

按用户主题设置[前端地址和路径模板](../features/payment-return-url)，重新生成付款链接。

## 修改配置未生效

执行 `php artisan config:cache`，重载 PHP-FPM / Webman，并用 `php artisan horizon:terminate` 让守护服务重启消费者。

## 订阅审计没有历史请求

启用前不补录；无效 token、提前拦截和 CDN 未回源的请求不记录。详见[订阅审计](../features/subscription-audit)。

## 旧 token 仍脱敏

已脱敏值无法还原，可在详情复制当前订阅链接。用户重置令牌后，当前链接与历史请求不同。

## 多个 IP 是否说明共享

不能单凭 IP 判断。网络切换、代理、自动更新及 NAT 都可能影响记录，拉取 IP 也不同于节点连接 IP。

## 排查节点为什么不按套餐组授权

[专用节点](../features/leak-probe)按当前轮次名单授权，结束后可复用；普通节点仍按套餐组。

## Staff API 返回 404

未完成的 Staff 路由已停用，管理员 API 正常。外部客服工具需适配。

## 文档站是否连接数据库

不会。文档为静态页面，搜索使用本地索引，构建与发布见[文档发布](./documentation)。
