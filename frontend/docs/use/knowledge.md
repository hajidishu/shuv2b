# 知识库

在「知识库管理」新增文章，填写标题、分类、语言和正文，设置显示状态与排序。编辑器支持 Markdown / HTML 及预览；最终效果以用户主题为准。

## 正文变量

后端按当前登录用户替换变量：

| 变量 | 内容 |
| --- | --- |
| `{{siteName}}` | 站点名称 |
| `{{subscribeUrl}}` | 符合当前链接模式的订阅 URL |
| `{{urlEncodeSubscribeUrl}}` | URL 编码的订阅 URL |
| `{{safeBase64SubscribeUrl}}` | URL-safe Base64 订阅 URL |
| `{{subscribeToken}}` | 基础订阅 token，不是临时凭据 |

指定客户端格式可使用 `{{subscribeUrl}}&flag=v2rayng`。一次性或限时模式请使用生成的 URL。

## 限制有效用户可见

用以下标记包住受限内容；没有有效订阅的用户不会收到这部分正文：

```html
<!--access start-->
需要有效订阅才能查看的内容。
<!--access end-->
```

发布后用对应用户账号检查权限和显示效果。

## 常见问题

**`jump()`、`copy()` 或一键导入不生效？** 这些交互需要用户主题支持，按主题说明配置。
