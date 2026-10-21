# 进阶与前后端分离

## 用户前端独立部署

1. 将主题构建产物部署到静态服务器，按主题说明填写后端 API 地址。
2. 配置后端 CORS，允许前端来源、请求头和预检请求；反代保留 `/api` 路径。
3. 验证登录凭据、HTTPS 和 Cookie 设置，配置[支付回跳地址](../features/payment-return-url)。

管理员前端默认依赖 Laravel 入口注入站点设置、安全路径，并使用同源 API。独立部署时需补齐这些初始化信息和认证配置，不能只复制 `dist/index.html`。

## 自定义订阅模板

以 Clash 为例，复制默认模板后修改规则与分组：

```sh
cp resources/rules/default.clash.yaml resources/rules/custom.clash.yaml
```

检查 YAML 格式，将自定义文件纳入备份。其他适配器按实际读取的模板路径处理。

## 扩展客户端适配

协议输出位于 `app/Protocols`，入口为 `app/Http/Controllers/V1/Client/ClientController.php`。新增适配器需验证识别码、UA、协议字段和实际客户端导入。

使用反向代理时，在 `TrustProxies` 或 Nginx real_ip 中只信任实际代理，确保 IP、协议和生成的地址正确。

## 常见问题

**登录后立即退出？** 检查浏览器请求是否携带凭据、CORS 和 Cookie 属性是否匹配主题；不同域名本身不一定是原因。
