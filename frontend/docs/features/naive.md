# Naive 节点

在「节点管理 → 添加节点」选择 **Naive**，或创建 **V2node** 后将协议设为 **Naive**。权限组、倍率、路由、排序和批量操作与其他节点一致。

## 配置

- **监听网络**：TCP 使用 HTTP/2，UDP 使用 QUIC，TCP + UDP 同时监听。双网络节点的订阅默认使用 HTTP/2。
- **TLS**：必须开启，支持 HTTP／DNS 申请或已有受信任证书。证书文件路径填写节点机器上的路径。
- **SNI**：留空时使用节点连接地址。
- **QUIC 拥塞控制**：服务端可选 `bbr`、`cubic`、`reno`，默认 `bbr`。

Naive 不使用 Reality、WebSocket、gRPC、AnyTLS 填充方案或跳过证书验证选项。配置依据：[sing-box 入站](https://sing-box.sagernet.org/zh/configuration/inbound/naive/)、[出站](https://sing-box.sagernet.org/zh/configuration/outbound/naive/)。

## 节点 API

节点程序需要自行实现 Naive 协议适配。本项目提供面板和 API，不包含节点程序。

| 用途 | 独立 Naive 节点 | V2node Naive |
| --- | --- | --- |
| 配置 | `/api/v1/server/UniProxy/config?node_type=naive&node_id=ID&token=TOKEN` | `/api/v2/server/config?node_id=ID&token=TOKEN` |
| 用户 | `/api/v1/server/UniProxy/user?node_type=naive&node_id=ID&token=TOKEN` | 同一接口，`node_type=v2node` |
| 流量上报 | POST `/api/v1/server/UniProxy/push` | 同一接口，`node_type=v2node` |
| 在线设备 | `alivelist`／`alive`，参数与用户接口相同 | 同一接口，`node_type=v2node` |

流量与在线设备请求格式沿用现有 UniProxy 接口。配置返回监听网络、端口、TLS 设置及 `quic_congestion_control`。V2node 同时保留原有 `congestion_control` 字段。

用户返回原有的 `id`、`uuid` 和限速／设备限制字段，并为 Naive 增加 `username`、`password`：用户名是用户 ID 字符串，密码是 UUID，与订阅一致，不使用邮箱。API 的 `tls_settings` 使用面板原有的证书字段，节点程序负责转换为内核配置；`network` 为空表示同时监听 TCP 和 UDP。

## 订阅与更新

支持 Sing-box 1.13+ 的 Naive 出站和通用／v2rayN 的 `naive+https://`、`naive+quic://` 分享链接。旧版 Sing-box 订阅不下发 Naive；其他客户端需要自行确认支持情况。

升级执行 `php artisan v2board:update`，会补齐 `v2_server_naive` 表并保留原节点及用户数据。V2node 复用现有字段。仅补齐该表也可执行 `php artisan naive:install`，允许重复执行。
