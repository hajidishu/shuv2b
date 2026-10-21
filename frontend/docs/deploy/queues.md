# 队列与定时任务

Horizon 消费队列；调度器触发订单检查、佣金审核、流量重置和清理。两者都需持续运行。

## 1. 守护 Horizon

将以下内容保存为 `/etc/systemd/system/v2board-horizon.service`，替换路径、PHP 和运行账号：

```ini
[Unit]
Description=V2Board Horizon
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/v2board
ExecStart=/usr/bin/php artisan horizon
Restart=always
RestartSec=3
TimeoutStopSec=60
KillSignal=SIGTERM

[Install]
WantedBy=multi-user.target
```

```sh
sudo systemctl daemon-reload
sudo systemctl enable --now v2board-horizon
sudo systemctl status v2board-horizon --no-pager
```

运行账号需能访问应用、数据库和 Redis。

## 2. 启用调度

在运行账号的 crontab 添加以下任务，并为日志配置轮转。同一站点只配置一份每分钟调度：

```text
* * * * * cd /var/www/v2board && /usr/bin/php artisan schedule:run >> /var/www/v2board/storage/logs/scheduler.log 2>&1
```

## 3. 检查消费者

`config/horizon.php` 中的队列：

| 队列 | 用途 |
| --- | --- |
| `order_handle` | 订单开通 |
| `traffic_fetch`、`stat` | 流量与统计 |
| `send_email`、`send_email_mass` | 邮件 |
| `send_telegram` | Telegram 通知 |
| `subscription_audit` | 订阅审计与排查下发记录 |

`HORIZON_MAX_PROCESSES` 限制业务消费者最大进程数，审计消费者独立配置。

```sh
php artisan horizon:status
php artisan schedule:list
php artisan queue:failed
```

更改代码或配置后执行：

```sh
php artisan config:cache
php artisan horizon:terminate
```

守护服务应自动重启 Horizon。

## 常见问题

**任务一直等待？** 检查对应队列是否有消费者、积压和失败日志，避免未核对收款就批量重试支付任务。

**订阅正常但没有审计？** 订阅返回不依赖审计成功，需单独检查审计入队、消费者及失败任务。
