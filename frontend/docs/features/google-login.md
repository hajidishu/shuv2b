# Google 登录

功能默认关闭。每个部署者使用自己的 Google Cloud 项目、Web Client ID 和域名；发行包不包含开发者的 OAuth 配置。此功能需要用户前端支持，仅更新后端不会为第三方主题自动添加登录入口。

## 配置

1. 在 [Google Cloud](https://console.cloud.google.com/auth/clients) 创建类型为 **Web application** 的 OAuth 客户端，并配置应用名称、隐私政策等品牌信息。
2. 在客户端的 **Authorized JavaScript origins** 中逐个添加用户前端来源，如 `https://panel.example.com`。包含非默认端口，不填写路径或 `/#/login`。这里登记用户访问的前端域名。
3. 更新面板并执行 `bash update.sh`，自动创建第三方身份表。使用源码版本时，需要先构建管理员前端。
4. 在「系统配置 → 安全」填写 **Google Client ID** 和 **Google 允许来源**。两边的前端来源保持一致，填写完成后启用 **Google 登录**。
5. 按需打开 **允许 Google 注册**。关闭后，已绑定的用户仍可登录。全站关闭注册、邀请码、邮箱限制、人机验证和注册 IP 限制仍由后端执行。

本方案使用 Google Identity Services 的 JavaScript 回调和 ID Token 验证，不需要 Client Secret。生产域名使用 HTTPS；本机开发可使用 localhost。

## 用户流程

- **新用户**：Google 授权后设置站点密码，填写必要的邀请码和人机验证，完成注册后即可使用 Google 或邮箱＋站点密码登录。第三方邮箱类型的 Google 账号还需邮件验证码确认邮箱当前归属。
- **邮箱已注册**：先使用原密码或 Passkey 登录本站，再在个人中心绑定。系统不会仅凭相同邮箱自动合并账号。
- **已登录用户**：在个人中心重新验证密码或 Passkey，选择 Google 账号并确认绑定。Google 邮箱可以与本站邮箱不同，本站邮箱、密码、余额和订阅保持原样。
- **解绑**：需重新验证身份，撤销该 Google 身份的登录及派生会话。密码登录仍可用。
- **密码找回**：仍使用本站邮箱。安全恢复会解除 Google 绑定、撤销 Passkey 及旧会话，恢复账号后可重新绑定。

站点密码与 Google 密码独立。Google 邮箱变化不会自动修改本站登录邮箱。

## 分离部署和加密 API

Google SDK 由浏览器从 Google 官方地址加载；登录凭据通过前端现有请求层提交给加密 API 中间件，再由后端校验。裸后端可以继续仅监听内网，无需开放 Google 回调接口。

中间件保留请求体、`Origin`、`Authorization` 和 JSON 内容类型。一次登录或绑定操作固定使用同一个 API 传输入口，失败后重新开始，不把同一凭据自动提交到其他入口。

浏览器需能访问 `https://accounts.google.com/gsi/client`。后端需能获取 Google 签名公钥；公钥会按有效期缓存。使用 CSP 或 COOP 时，按 [Google 接入说明](https://developers.google.com/identity/gsi/web/guides/get-google-api-clientid)配置允许来源及弹窗策略。

## 常见问题

**是否影响现有用户？** 原用户表、密码、订阅 Token、UUID、订单和已有 Passkey 保留。更新新增 `v2_social_identity` 表，功能保持关闭，直到部署者自行配置并启用。

**为什么同邮箱还要验证本站账号？** 邮箱相同不等于有权绑定已有账号。确认原账号的控制权可防止误关联余额、订单或订阅。

**Google 服务不可达怎么办？** 原邮箱密码和 Passkey 登录继续使用。Google 登录失败时不会降级为未经验证的登录。

**上线是否需要 Google 审核？** 按 Google 控制台提示完成应用发布及必要的品牌验证。开发者提供代码，每个部署者负责自己的 Google 项目和域名配置。
