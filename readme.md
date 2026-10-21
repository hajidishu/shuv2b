# shuv2b

地鼠自用的 V2Board 分支，基于 [xiaov2b（wyx2685/v2board）](https://github.com/wyx2685/v2board)，保留 V2Board 的使用方式，增加管理与审计功能。

[文档](https://hajidishu.github.io/shuv2b/) · [安装](https://hajidishu.github.io/shuv2b/deploy/linux) · [从 xiaov2b 迁移](https://hajidishu.github.io/shuv2b/deploy/from-xiaov2b) · [从原版 v2b 迁移](https://hajidishu.github.io/shuv2b/deploy/migrate) · [问题反馈](https://github.com/hajidishu/shuv2b/issues)

## 主要改动

- 重写可构建的管理员前端，沿用原后台风格和图标。
- 八类节点编辑与批量操作，用户余额、佣金余额等条件筛选。
- 订阅审计：邮箱、IP、UA、HTTP 详情、排行及自动清理。
- 人工内鬼排查：固定分组、人工推进、可复用专用节点，不自动封禁用户。
- 支付尝试与收款台账、独立前端回跳设置、认证与会话修复。
- Telegram Webhook 域名单独配置。

项目对外名称为 **shuv2b**；PHP 命令、配置键、数据库表、目录及代码标识继续使用 **v2board**，无需改名。

## 安装与更新

- `master`：完整源码、测试与文档。
- `release`：CI 验证并导出的后端与管理员编译资源，不含前端源码和依赖。服务器建议跟踪此分支。

新站获取运行版本：

```sh
git clone --branch release --single-branch https://github.com/hajidishu/shuv2b.git /var/www/v2board
cd /var/www/v2board
```

继续按[安装教程](https://hajidishu.github.io/shuv2b/deploy/linux)配置数据库、PHP、Redis、队列和定时任务。已有站点请使用上方对应迁移教程，保留原数据库、`.env`、`APP_KEY`、`config/v2board.php` 及自定义文件，**不要重新执行安装命令**。

后续更新在站点目录运行 `bash update.sh`。脚本保留当前分支，遇到本地修改或分叉停止；更新后按[更新教程](https://hajidishu.github.io/shuv2b/use/update)重载服务并检查订单与队列。

## 环境与兼容

- PHP：现有 xiaov2b 站点迁移时可先保留 PHP 8.3 并验证。测试环境使用 PHP 8.4.26 + PHP-FPM；不代表全面适配 PHP 8.4 或 Webman。
- MySQL / MariaDB、Redis、Composer；Horizon 需要 Linux CLI 的 `pcntl`、`posix`。
- 节点服务端参考 [V2bX 修改版](https://github.com/wyx2685/V2bX)和 [v2node](https://github.com/wyx2685/v2node)，按所用协议与版本验证。

前端构建见 [管理员 README](https://github.com/hajidishu/shuv2b/blob/master/frontend/admin/README.md)，文档构建见 [文档 README](https://github.com/hajidishu/shuv2b/blob/master/frontend/docs/README.md)。部署构建产物无需 Node.js。

## 反馈与贡献

提交问题请提供版本提交、PHP 版本、部署方式、复现步骤和脱敏日志。提交 AI 编写的代码前，请先确认自己理解其用途及运行方式，并说明验证结果。

## 致谢与许可

基于 V2Board 及 xiaov2b，感谢原作者与贡献者。沿用 [MIT License](LICENSE)。
