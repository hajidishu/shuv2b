# 项目介绍

**shuv2b** 基于 `wyx2685/v2board`，新增可构建管理员前端、订阅审计、人工排查、支付台账与会话安全改动。[文档来源](./upstream)。

## 开始使用

| 情况 | 教程 |
| --- | --- |
| 安装新站 | [环境要求](../ENV) → [Linux](../deploy/linux) / [宝塔](../deploy/aapanel)部署 |
| 已有官方原版 v2board/v2board | [从原版v2b迁移](../deploy/migrate) |
| 已有 wyx2685/v2board | [从 xiaov2b 迁移](../deploy/from-xiaov2b) |
| 配置日常业务 | [套餐](../use/subscribe)、[用户](../use/user)、[节点](../use/node)、[支付](../use/payment) |
| 使用新增功能 | [订阅审计](../features/subscription-audit)、[人工排查](../features/leak-probe) |
| 独立部署用户前端 | [前后端分离](../use/advanced)、[支付回跳](../features/payment-return-url) |

## 工程目录

- `frontend/admin`：React、TypeScript、Ant Design、Vite 管理员前端；部署资源为 `public/assets/admin-v2`。
- `frontend/docs`：独立 VitePress 文档站，构建为静态文件。
- 用户主题独立配置，不随管理端或文档站构建。

源码位于 [master](https://github.com/hajidishu/shuv2b)，CI 验证后导出到 [release](https://github.com/hajidishu/shuv2b/tree/release)，服务器无需下载前端源码或安装 Node.js。命令、配置键和数据库表继续使用 `v2board`。
