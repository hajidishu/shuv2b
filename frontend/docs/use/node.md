# 节点

## 添加与编辑

1. 在「节点管理」选择类型：Shadowsocks、VMess、Trojan、TUIC、Hysteria、VLESS、AnyTLS、Naive、Mieru 或 V2node。
2. 填写连接地址、端口、权限组及该协议的传输、TLS 等配置。V2node 还需选择实际协议。
3. 在节点服务器安装兼容的[服务端](./server)，配置面板地址、通信密钥、节点类型与 ID。
4. 用测试用户验证订阅、连接和流量上报。

批量编辑时勾选节点，仅修改需要同步的字段；协议专用参数需分别处理。

## 端口、父子节点与权限组

- **连接端口**：客户端使用的入口。**服务端口**：节点程序监听端口，中转时两者可以不同。
- **父子节点**：多个入口关联同一服务实例；修改后核对服务端拉取的 ID 和流量归属。
- **权限组**：普通节点需与用户套餐匹配。[人工排查专用节点](../features/leak-probe)按任务名单单独授权。

路由在「路由管理」创建后应用到节点，需服务端支持对应匹配规则与动作。

## 协议兼容性

- **Naive**：必须使用受信任 TLS；TCP 对应 HTTP/2，UDP 对应 QUIC，也可同时监听。支持 Sing-box 1.13+ 和 `naive+https://`／`naive+quic://` 链接。
- **Mieru**：支持 TCP／UDP 和端口范围，不使用 TLS。支持 Mihomo／Clash.Meta 和 `mierus://` 链接。MTU 默认 1400；Mihomo 无自定义 MTU 选项，自定义 MTU 的 UDP 节点仅下发原生链接。

V2node 可选择这两种协议，节点程序须自行适配[面板 API](./server#面板-api)。

## 指定订阅格式

在已有订阅链接后添加 `&flag=...`：

| 客户端 | 识别值 |
| --- | --- |
| Clash / Meta / Mihomo | `clash` / `clash.meta` / `mihomo` |
| Verge / Nyanpasu | `verge` / `nyanpasu` |
| Shadowrocket | `shadowrocket` |
| Surge / Stash / Surfboard | `surge` / `stash` / `surfboard` |
| Loon | `loon` |
| V2rayN / V2rayNG | `v2rayn` / `v2rayng` |
| Passwall / SSRPlus | `passwall` / `ssrplus` |
| SagerNet / Shadowsocks | `sagernet` / `shadowsocks` |
| sing-box | 按客户端 UA 与版本识别 |

客户端还需支持订阅中的协议及参数。

## 常见问题

**用户看不到节点？** 检查套餐和节点权限组、显示状态，以及用户是否封禁、过期或流量用尽。

**节点状态异常？** 检查通信密钥、节点 ID、服务端日志、拉取与上报时间。
