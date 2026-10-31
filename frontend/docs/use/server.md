# 节点服务端

节点服务端负责拉取配置和用户，并上报流量、在线信息。选择兼容当前节点协议和面板 API 的版本；基础分支 README 提供修改版 V2bX 与 v2node 入口。

## 接入步骤

1. 在面板创建节点，记下类型和 ID。
2. 在服务端填写面板 API 地址、通信密钥、节点类型和 ID。通信密钥不是用户订阅 token。
3. 确认拉取与上报请求未被 WAF 或错误反代阻断。
4. 用测试用户验证鉴权、限速、设备限制和流量归属。

## 面板 API

| 用途 | 接口 |
| --- | --- |
| 独立节点配置 | GET `/api/v1/server/UniProxy/config` |
| V2node 配置 | GET `/api/v2/server/config` |
| 用户 | GET `/api/v1/server/UniProxy/user` |
| 流量上报 | POST `/api/v1/server/UniProxy/push` |
| 在线设备 | 沿用 UniProxy 的 `alivelist`／`alive` 接口 |

请求携带 `node_id` 和通信密钥 `token`；UniProxy 还需 `node_type`，独立节点填对应类型（如 `naive`、`mieru`），V2node 填 `v2node`。

Naive 和 Mieru 的用户接口增加 `username`（用户 ID 字符串）和 `password`（UUID），与订阅一致。Mieru 节点程序将这两个字段映射到 `users[].name` 和 `users[].password`。

- **Naive 配置**：`network` 为空表示同时监听 TCP 和 UDP；`tls_settings` 沿用面板证书字段，`quic_congestion_control` 为服务端 QUIC 拥塞控制。V2node 同时保留 `congestion_control`。
- **Mieru 配置**：`port_bindings` 使用 `port`／`portRange` 和 `protocol`；范围监听时 `server_port` 为起点，完整范围在 `network_settings.server_port_range`。`network_settings.traffic_pattern` 用于客户端，顶层 `traffic_pattern` 或 `network_settings.server_traffic_pattern` 用于服务端，节点程序负责解码 Base64 内容。

## 升级

`php artisan v2board:update` 会自动补齐 Naive 和 Mieru 节点表，保留已有节点与用户数据；V2node 复用现有字段。需要单独补齐时可执行 `php artisan naive:install` 或 `php artisan mieru:install`，允许重复执行。

## 常见问题

**能看到排查节点但无法连接？** [专用节点](../features/leak-probe)按当前轮次名单授权，无需匹配用户套餐组。检查成员资格、节点端是否已拉取新名单及代理日志。
