# 环境要求

## 面板运行环境

| 组件 | 要求 / 已验证环境 |
| --- | --- |
| 系统 | 支持 CLI `pcntl` 的 Linux；完整测试环境为 Debian 13 |
| PHP | Composer 声明 `^7.3.0 \|\| ^8.0`；现有 PHP 8.3 迁移可先保留并验证。指定 PHP 8.4.26 + PHP-FPM 环境回归通过，不代表全面支持 8.4 / Webman |
| 数据库 | MySQL 驱动；完整测试环境为 MariaDB 11.8 |
| Redis | 缓存、会话及 Horizon 队列，设置 `CACHE_DRIVER=redis` |
| Web | Nginx + PHP-FPM 等，根目录指向 `public` |
| Composer | 安装 PHP 依赖，有锁文件时按锁文件安装 |

CLI 与 Web 均需数据库、Redis 及所需扩展。检查 PDO MySQL、Redis、mbstring、OpenSSL、fileinfo、cURL、XML 和 CLI 的 pcntl / posix：

```sh
php -v
php -m
composer check-platform-reqs --no-dev
```

## 前端构建环境

| 工程 | 环境 | 部署产物 |
| --- | --- | --- |
| 管理员前端 | Node.js 22.12+、npm | `public/assets/admin-v2` |
| 文档站 | Node.js 22+、npm | `frontend/docs/.vitepress/dist` |

部署构建产物的服务器无需 Node.js。文档站无需 PHP、Redis 或数据库。环境就绪后按[Linux](./deploy/linux)或[面板](./deploy/aapanel)教程安装。

## 常见问题

**需要多少内存？** 取决于队列进程数、审计量和数据库负载，应观察实际占用调整。

**PHP 8.4 有弃用提示？** Laravel 等旧依赖仍有提示。关闭错误展示与日志轮转不等于完成兼容修复；当前没有完整的多版本验证矩阵。`webman.php` 所用 AdapterMan 还需满足其实际安装版本的 PHP 要求。
