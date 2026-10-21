# Telegram

## 配置步骤

1. 在 BotFather 创建机器人，将 token 填入「系统配置 → Telegram」。
2. 「Webhook 域名」填写 `https://bot.example.com` 这样的 HTTPS 域名，不带路径，并将回调请求转发到面板后端。
3. 保存后点击「一键设置」。系统会追加 `/api/v1/guest/telegram/webhook` 及校验参数；更换域名后需再次点击。
4. 按需开启通知、设置群组或频道，确认 `send_telegram` 队列运行，再测试绑定和查询。

| 指令 | 用途 |
| --- | --- |
| `/bind` 加订阅链接 | 绑定账号 |
| `/unbind` | 解除绑定 |
| `/traffic` | 查询流量 |
| `/getlatesturl` | 获取站点地址 |

## 常见问题

**CDN 能用吗？** 可以。Telegram 访问的地址需提供有效 HTTPS，并将回调转发到后端，关闭该路径的人机挑战。仅部署静态前端的域名不能接收回调。

**机器人没响应？** 检查已注册 Webhook、token、群组权限、回调是否被拦截，以及出站网络和队列。

**升级后原回调会改变吗？** 不会自动改动；「一键设置」只使用保存的 Webhook 域名，不再使用当前后台域名。
