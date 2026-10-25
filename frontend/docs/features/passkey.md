# Passkey 登录

Passkey 使用设备解锁或安全密钥登录，不改变用户的订阅 token、UUID、套餐和余额。用户端需要使用支持 Passkey 的前端；本次适配的 EZ Theme 在登录页和个人中心提供入口。

## 开启

1. 按[更新教程](../use/update)更新代码、依赖和数据库，重启 PHP 与队列进程。新增两张 `v2_passkey_*` 表，不改写原用户数据。
2. 在「系统配置 → 安全」中填写固定 **RP ID** 和所有前端的 **允许来源**，然后开启 Passkey。多域名统一使用 Related Origin Requests（ROR），不另设开关。
3. 部署已适配的用户端前端，通过 HTTPS 访问，使用密码登录后在个人中心添加 Passkey。

例如前端包括 `https://panel.example.com`、`https://panel.example.net`：

| 配置 | 值 |
| --- | --- |
| RP ID | 固定为 `panel.example.com`，不含协议、端口和路径 |
| 允许来源 | `https://panel.example.com`、`https://panel.example.net`，非默认端口必须填写 |

前端可以使用不同主域名，但全部使用同一个 RP ID。RP ID 不变，已有密钥无需重绑；更换 RP ID 则需重新绑定，不能只改数据库里的域名。

## 发布关联声明

浏览器会从 `https://RP域名/.well-known/webauthn` 获取公开 JSON 声明。此地址必须经可信 HTTPS 的 **443 端口**直接返回 `200 application/json`，无需登录，不经过加密 API 中间件。后端已提供该路由，内容自动取自同一份允许来源配置。

在「系统配置 → 安全 → ROR 部署」可以查看保存后的 RP ID、声明地址，以及复制或下载声明文件。RP 域名指向本后端时，声明由后端路由提供，无需手动创建目录或文件。

独立静态前端采用文件部署时：在**前端网站根目录**创建 `.well-known` 目录，再把下载的 **`webauthn` 文件（没有扩展名）**放进该目录。

```text
前端网站根目录/
└── .well-known/       ← 目录
    └── webauthn       ← 文件，内容为 JSON，没有扩展名
```

例如网站根目录为 `/var/www/ez`，文件的完整路径就是 `/var/www/ez/.well-known/webauthn`。`webauthn` 不是目录，不需要在它下面再创建文件。浏览器通过 `https://RP域名/.well-known/webauthn` 读取这个文件，服务器须返回 `application/json`；来源列表改变后重新下载并替换此文件。

也可以选择反向代理，无需在静态前端放置声明文件。在 RP 域名的 Nginx HTTPS 虚拟主机中将请求转发到后端，例如：

```nginx
location = /.well-known/webauthn {
    proxy_pass http://127.0.0.1:8082;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto https;
    proxy_set_header X-Forwarded-For $remote_addr;
}
```

按实际后端地址调整，并把这个受控代理加入 `TRUSTED_PROXIES`。该精确路径不能被静态站回退、隐藏文件拦截、登录验证或 WAF 人机验证覆盖。声明仅在配置的 RP 主机通过 HTTPS 提供；API 主机与 RP 主机不同时必须保留 RP 的 Host。

后端 Nginx 若拦截隐藏目录，也需在其虚拟主机加入精确例外，让请求进入 PHP 路由：

```nginx
location = /.well-known/webauthn {
    try_files $uri /index.php?$query_string;
}
```

公开文档仅包含需要 ROR 的关联来源；同 RP 及其子域无需占用额外声明条目。没有关联来源时返回普通允许来源。更新白名单后，后端立即拒绝移除的来源；浏览器对声明可能有短暂缓存。

ROR 仍受浏览器限制：按最多 **5 种注册域标签**规划，并非 5 个网址。例如 `example.com`、`example.net` 都属于 `example` 标签。RP 域名不可达可能使其他域名的 Passkey 失败，应保持密码登录可用。参见 [WebAuthn 规范](https://www.w3.org/TR/webauthn-3/#sctn-related-origins)。

## 使用与撤销

- 每个账号在当前 RP ID 下最多绑定 10 个密钥；添加、重命名和删除都需要重新验证当前密码或已有 Passkey。
- 删除密钥会注销通过该密钥登录的会话及其快速登录派生会话。升级前未记录认证来源的旧会话也会一并注销；新建的密码会话和其他密钥会话保留。管理员从右上角账号菜单进入「Passkey 管理」，也可按邮箱撤销用户的全部密钥和会话。
- 邮箱找回密码、管理员重置密码及命令行重置密码会撤销旧 Passkey。用户主动修改密码保留 Passkey，仍按原逻辑注销登录会话。
- 设备全部丢失时使用密码登录；管理员也可执行 `php artisan v2board:passkey-revoke 用户邮箱`。

## 前后端分离与加密中间件

配置的是**浏览器访问的前端来源**，不是后端或中间件地址。GeneralEncryptionMiddleware 可沿用现有 AES 路由加密、`X-IV` 和 Authorization，无需新增 Cookie 或修改中间件源码；中间件 CORS 白名单也须包含这些前端来源。

Passkey 接口必须保留嵌套 JSON，请求不能转换为表单字符串；options 与 verify 必须使用同一后端。EZ 已处理这两点，失败后需要重新发起验证，不能重放旧验证请求。生产环境仍需通过 HTTPS 访问中间件。

反向代理应保留浏览器的 `Origin` 以及包含端口的公开 `Host`（Nginx 可用 `$http_host`），否则中间件可能错误判断跨端口请求并缺少 CORS 响应头。

后端 `.env` 的 `TRUSTED_PROXIES` 只填写受控代理 IP。本机 Nginx / 中间件可设置 `TRUSTED_PROXIES=127.0.0.1,::1`，然后刷新配置并重启 PHP 和队列；直接部署保持留空。入口代理必须覆盖外部传来的 `X-Forwarded-For`，避免所有用户共用代理 IP 的登录限额，也防止客户端伪造 IP。

## 常见问题

**关联域名不能调用 Passkey？** 检查浏览器 ROR 支持、RP 域名的 HTTPS 443 声明，以及前端来源是否同时在后端和中间件白名单内。不支持时使用密码登录；不会自动换成另一个 RP ID。

**提示安装 ROR 适配？** 正常 `composer install` 会自动应用固定版本的 WebAuthn 来源白名单补丁。若跳过了 Composer scripts，运行 `php library/WebAuthnPatch.php`，再重启 PHP 与队列。依赖文件异常会停止适配，不能跳过签名或来源验证。

**裸内网 IP 的 HTTP 页面能用吗？** 不能。使用已信任证书的 HTTPS 域名；本机开发可使用浏览器认可的 `http://localhost`。测试证书仅供内网测试，正式部署使用可信证书。

**需要升级 PHP 吗？** 新依赖要求 PHP 8.0+ 及 OpenSSL、mbstring；最终安装条件还受已有锁文件约束，执行 `composer check-platform-reqs --no-dev` 检查。不要为此同时盲目升级 PHP。

**关闭功能会删除密钥吗？** 不会，关闭后不能使用 Passkey 接口。重新开启原 RP ID 后可继续使用；需要失效时执行撤销操作。
