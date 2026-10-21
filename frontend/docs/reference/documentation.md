# 构建与发布文档站

需要 Node.js 22+ 和 npm，文档工程位于 `frontend/docs`。

## 本地开发与构建

```sh
cd frontend/docs
npm ci
npm run dev
```

发布前构建并预览：

```sh
npm run build
npm run preview
```

预览地址 `http://127.0.0.1:4173`。将 `.vitepress/dist` 部署到静态服务器，无需 Node.js、PHP 或数据库。

## 部署路径

默认从 `/` 发布。部署到 `/docs/` 时，构建和预览使用相同 `DOCS_BASE`，首尾均带 `/`：

::: code-group

```sh [Linux / macOS]
DOCS_BASE=/docs/ npm run build
DOCS_BASE=/docs/ npm run preview
```

```powershell [PowerShell]
$env:DOCS_BASE = '/docs/'
npm run build
npm run preview
```

:::

独立 Nginx 站点示例，`root` 指向构建产物：

```nginx
root /srv/v2board-docs;
index index.html;
location / {
    try_files $uri $uri.html $uri/ =404;
}
```

子路径部署需相应调整挂载规则。

## GitHub Pages

1. 仓库 Pages 来源选择 GitHub Actions。
2. `master` 的文档提交通过 `.github/workflows/docs.yml` 自动构建并发布；拉取请求只检查构建，手动运行也可重新发布。
3. shuv2b 的 base 为 `/shuv2b/`，地址为 [hajidishu.github.io/shuv2b](https://hajidishu.github.io/shuv2b/)。其他仓库按名称推导路径；自定义域名可用变量 `DOCS_BASE` 覆盖。

也可下载 CI 产物部署到其他静态托管。

## 修改内容

页面使用 Markdown，导航在 `.vitepress/config.mts`。四篇功能页引用仓库 `docs` 下同名文件，修改源文件即可；构建需保留这些文件。来源见[文档来源](../guide/upstream)。

## 常见问题

**链接或资源 404？** 核对 base、静态目录与 Nginx 路径解析，重新构建检查死链接，并验证搜索和侧栏。
