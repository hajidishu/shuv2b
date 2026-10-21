# 备份与恢复

## 备份内容

| 内容 | 用途 |
| --- | --- |
| 全部数据库表 | 保留用户、订单、审计、排查和支付台账 |
| `.env`、原 `APP_KEY`、`config/v2board.php` | 恢复连接、加密数据和站点设置 |
| 自定义主题、邮件、订阅模板及上传文件 | 避免丢失仓库外的内容 |
| 配套代码与构建资源、Web / 队列 / 调度配置 | 恢复相同运行版本 |

备份放在 Web 根目录之外。数据库示例：

```sh
mysqldump -u backup_user -p --single-transaction --routines --triggers v2board > /secure-backups/v2board.sql
```

先创建备份目录并配置账号权限；MariaDB 可用 `mariadb-dump`。`--single-transaction` 适用于事务表，结构变更或非事务表需另行保证一致性。

## 恢复步骤

1. 在隔离或维护环境准备配套代码及依赖。
2. 导入数据库，恢复配置、原 `APP_KEY` 和自定义文件，检查权限。
3. 升级恢复时按[原版 v2b](./migrate)或[xiaov2b](./from-xiaov2b)教程补齐结构。
4. 启动 Web、Horizon 和调度器，核对用户、订阅与财务数据后开放访问。

## 常见问题

**恢复旧备份会漏收款吗？** 可能遗漏备份后的支付记录，需与商户账单对账。支付台账属于财务数据，应随数据库保留。
