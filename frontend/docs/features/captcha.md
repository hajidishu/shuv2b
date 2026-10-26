# 人机验证

在「系统配置 → 安全 → 人机验证方式」选择 **关闭**、**Google reCAPTCHA** 或 **Cloudflare Turnstile**。验证用于注册和发送邮件验证码，不改变原有密码登录、Passkey 登录流程。

## 使用 Turnstile

1. 在 [Cloudflare Turnstile](https://dash.cloudflare.com/?to=/:account/turnstile) 创建组件，选择托管模式，并添加所有用户前端的域名。前后端分离时填写用户访问的前端域名。
2. 在后台选择 **Cloudflare Turnstile**，填写对应的 **Site Key** 和 **Secret Key**，等待保存成功。Secret Key 仅保存在后端，不要填入用户前端配置。
3. 更新支持此功能的用户前端。本次适配的 EZ Theme 两种登录布局均会跟随后台选择，无需再手动指定验证码类型。

后端必须能够通过 HTTPS 访问 `https://challenges.cloudflare.com/turnstile/v0/siteverify`；浏览器必须能加载 `https://challenges.cloudflare.com/turnstile/v0/api.js`。使用反向代理或加密 API 中间件时，保留验证 token，并按部署说明配置受信代理。

Turnstile token 有效期为 5 分钟且只能使用一次。发送邮件验证码和最终注册分别获取 token；提交失败后需重新验证。[Cloudflare 验证说明](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/)

## 升级与切换

- 无需修改数据库。没有配置新选项的旧站点会沿用原 `recaptcha_enable` 和 Google 密钥。
- 切换验证方式不会删除另一家的密钥，切回时可继续使用。
- 老用户前端需要更新后才能支持 Turnstile；只更新后端不能完成接入。
