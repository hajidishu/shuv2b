<img src="https://avatars.githubusercontent.com/u/56885001?s=200&v=4" alt="logo" width="130" height="130" align="right"/>

[![](https://img.shields.io/badge/TgChat-@UnOfficialV2board讨论-blue.svg)](https://t.me/unofficialV2board)

## 关于本分支

本分支fork于 wyx2685/v2board 添加了大量原版没有的功能，同时可能包含某些原版没有的bug，本分支为地鼠个人使用，不保证你用着舒服，有问题请点击屏幕上方的issue

>在提交由ai编写的代码之前请先确保你知道这些代码是干什么的，以及他们是如何运作的

## 本分支支持的后端
 - [修改版V2bX](https://github.com/wyx2685/V2bX)
 - [v2node](https://github.com/wyx2685/v2node)

## 迁移到 shuv2b

适用于原版 V2Board 和 xiaov2b（`wyx2685/v2board`）。先备份数据库、`.env`、`config/v2board.php` 和自定义文件，保留原 `APP_KEY`；确认 PHP 为 8.0+，已安装 Composer、Redis，并暂停队列守护和计划任务。

在原站目录切换到本分支的部署版本（`release` 已包含编译好的后台，无需在服务器构建前端）：

```sh
php artisan down
git remote set-url origin https://github.com/hajidishu/shuv2b.git
git fetch origin
git checkout release
```

确认 `.env` 中 Redis 连接正确，并设置以下两项；没有的项补上：

```dotenv
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
```

执行更新，安装依赖、升级数据库结构并刷新缓存：

```sh
bash update.sh
```

原用户、套餐、订单、余额和订阅 token 沿用，不需要重建数据库；不要运行 `v2board:install` 或导入安装 SQL。遇到本地修改、分叉或升级失败时，先处理报错再恢复服务，不要强制重置文件。

使用内置主题时，在后台「主题配置 → 选择原主题 → 主题设置」重新保存。恢复队列守护和计划任务，重载 PHP-FPM；使用 Webman 时重启 Webman，然后执行：

```sh
php artisan horizon:status
php artisan up
```

检查用户、订阅、节点和订单，并确认 `subscription_audit` 队列有消费者。详细步骤及注意事项：[从原版 v2b 迁移](https://hajidishu.github.io/shuv2b/deploy/migrate) · [从 xiaov2b 迁移](https://hajidishu.github.io/shuv2b/deploy/from-xiaov2b)。

# **V2Board**

- PHP7.3+
- Composer
- MySQL5.5+
- Redis
- Laravel

## Document
[Click](https://hajidishu.github.io/shuv2b/)

## Sponsors
Thanks to the open source project license provided by [Jetbrains](https://www.jetbrains.com/)

## Community
🔔Telegram Group: [@unofficialV2board](https://t.me/unofficialV2board)  

## How to Feedback
Follow the template in the issue to submit your question correctly, and we will have someone follow up with you.
