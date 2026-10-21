# shuv2b 文档站

使用 VitePress 维护当前分支的部署和使用说明。需要 Node.js 22+，在完整源码仓库执行：

```sh
cd frontend/docs
npm ci
npm run dev
```

`npm run build` 生成 `.vitepress/dist`；`npm run preview` 在 `http://127.0.0.1:4173` 预览。子路径发布前设置 `DOCS_BASE=/docs/`。

导航配置位于 `.vitepress/config.mts`。四份分支功能说明直接包含仓库根目录 `docs` 的 Markdown，构建时保留完整仓库目录。正文来源与适配范围见 `guide/upstream.md`。
