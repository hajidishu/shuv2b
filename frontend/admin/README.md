# V2Board 管理后台

适配当前分支的独立 React、TypeScript、Ant Design 管理后台，保留原后台功能，支持八类节点编辑与批量操作、用户余额和佣金筛选。

## 编译

需要 Node.js 22.12+，在仓库根目录运行：

```sh
cd frontend/admin
npm ci
npm run build
npm run publish:assets
```

正式资源位于 `public/assets/admin-v2/`。服务器只需部署后端和编译产物，无需 Node.js 或前端源码。

## 安装

部署本分支代码和正式资源后，在后端根目录执行：

```sh
php artisan config:clear
php artisan config:cache
php artisan view:clear
```

重启 PHP-FPM 或常驻 Web 服务，访问原管理员后台地址即可。
