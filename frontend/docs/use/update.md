# 更新升级

## 1. 备份并暂停服务

[备份数据库、配置和自定义文件](../deploy/backup)，保存 `.env`、原 `APP_KEY` 和 `config/v2board.php`。核对仓库、分支和本地修改：

```sh
git remote -v
git branch --show-current
git status --short
php artisan down
```

暂停队列守护和调度，避免升级时处理业务。服务器通常跟踪 `https://github.com/hajidishu/shuv2b.git` 的 `release`；首次换分支按[原版 v2b](../deploy/migrate)或[xiaov2b](../deploy/from-xiaov2b)教程操作。

## 2. 更新

```sh
bash update.sh
```

脚本拉取当前分支，只允许快进更新，安装生产依赖并执行 `v2board:update`、缓存刷新及队列终止。遇到本地修改、分叉或命令失败会停止，不再硬重置或删除 Composer 锁文件，也不自动更换 Web 运行模式。

服务器需预先安装 Composer，或已有 `composer.phar`。Passkey 版本要求 PHP 8.0+；首次更新会将新增的固定版本 WebAuthn 依赖补入旧锁文件，并创建两张 Passkey 表。原用户与订阅数据保留，功能默认关闭，配置方法见 [Passkey 登录](../features/passkey)。结构更新报错时先处理再上线。

邮箱找回修复会创建 `v2_user_email_identity` 精确身份索引，不修改已有账号邮箱。升级须暂停 Web、队列和调度中的账号写入；拉取新版代码后可先运行 `php artisan email-identity:install --check` 检查规范化冲突，确认后运行 `php artisan v2board:update`。冲突会按用户 ID 报错并中止，核对账号归属后再处理，不要直接合并账号。无效历史邮箱会报告用户 ID，需管理员核实后改正。国际化域名的 IDNA 转换与现有邮件投递一致。

升级前发出的验证码全部失效，用户需重新获取。找回发码必须传 `isforget=1`；省略或传 `0` 只用于注册，注册验证码不能找回密码。第三方前端需明确区分这两种用途，原有邮箱、验证码和密码参数不变。

## 3. 恢复服务

恢复 Horizon 守护和调度，重载 PHP-FPM；使用 Webman 时手动重启。然后执行：

```sh
php artisan horizon:status
php artisan up
```

验证用户、订阅、节点和订单，并核对在途收款。`horizon:terminate` 依赖守护服务重新拉起进程。

## 源码与运行分支

`master` 保存源码；管理员 CI 在本地模拟检查通过后，把后端与编译资源推送到 `release`。`release.json` 记录源提交，服务器不需要 Node.js 或前端源码。工作流失败时不会发布新的运行版本，文档站独立发布。

## 常见问题

**拉取失败？** 先检查 origin 是否为 shuv2b、分支是否正确、本地修改及分叉；不要用硬重置跳过问题。

**数据库更新失败？** 保持维护模式，根据报错修复后重新运行 `bash update.sh`。已存在的字段和索引会跳过，其他 SQL 错误会中止；历史统计的重复数据需先整理，更新不会再自动清空统计表来建立唯一索引。

**更新没有变化？** 核对 `release.json`、资源 manifest、Web / Horizon 进程和 CDN 缓存。

**如何降级？** 恢复配套代码、数据库、配置和密钥，核对备份后的收款。先处理[在途付款尝试](../features/payment-ledger)，不要直接用旧库覆盖新订单。
