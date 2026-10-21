# aaPanel / 宝塔部署

## 1. 准备环境

安装 Nginx、PHP、MySQL / MariaDB 和 Redis，版本及扩展见[环境要求](../ENV)。CLI 与网站使用一致的 PHP 环境。

Horizon 需要进程控制功能。按需启用 CLI 的 `proc_open`、`putenv`、`pcntl_signal`、`pcntl_alarm` 等函数。

## 2. 创建站点

1. 添加域名和数据库，选择 PHP 版本。
2. 将本 fork 代码放入站点目录，执行[安装步骤](./linux#_2-安装面板)，准备[管理端资源](../features/admin#构建与部署)。
3. 运行目录设为 `/public`，配置 HTTPS 与伪静态：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

沿用面板生成的 PHP 处理配置，确认 `.env`、`.git` 和备份文件不可通过网站访问。

## 3. 配置调度器

在计划任务中添加每分钟执行的 Shell 命令，路径按实际站点替换：

```sh
/www/server/php/83/bin/php /www/wwwroot/panel.example.com/artisan schedule:run
```

## 4. 守护 Horizon

在 Supervisor 插件中创建进程：

| 配置项 | 值 |
| --- | --- |
| 命令 | 站点 PHP CLI 路径加 `artisan horizon` |
| 工作目录 | 项目根目录，不是 `public` |
| 运行账号 | 网站运行账号，如 `www` |
| 进程数量 | 一个主进程 |
| 自动启动 / 重启 | 开启 |

启动后检查后台队列监控，包括 `subscription_audit` 消费者。Redis 配置见[Linux 部署](./linux#_5-配置队列与调度)。

## 常见问题

**500 / 502？** 查看站点、PHP 和 `storage/logs` 日志，检查扩展、数据库、Redis、写权限和 PHP-FPM。生产环境保持 `APP_DEBUG=false`。
