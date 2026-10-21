# 管理员前端

源码位于 `frontend/admin`，使用 React、TypeScript、Ant Design 和 Vite，覆盖原后台管理功能，增加节点批量编辑、用户金额筛选、订阅审计和人工排查。

## 构建与部署

需要 Node.js 22.12+，在仓库根目录执行：

```sh
cd frontend/admin
npm ci
npm run build
npm run publish:assets
```

将完整的 `public/assets/admin-v2` 发布到后端，服务器无需 Node.js。然后在后端根目录执行，并重载 Web 进程：

```sh
php artisan config:clear
php artisan config:cache
php artisan view:clear
```

管理员入口由安全路径配置决定，当前页面不依赖旧编译包。

## 常见问题

**配置未保存？** 自动保存项需等待「已保存」后再离开。

**批量操作范围是什么？** 按确认提示区分勾选记录与全部筛选结果。
