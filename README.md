# 乐宅.Life 官网、图册与统一后台

单仓库的 PHP 8.3 + PostgreSQL 应用：Astro 负责构建官网静态页面，PHP 提供统一路由、动态文章、图册、教程与管理后台。

## 本地运行

双击根目录的 `一键启动.bat`。首次启动会准备 PostgreSQL、执行可重复迁移、生成随机后台密码、构建官网并完成健康检查。

- 官网：http://127.0.0.1:8080/
- 官网文章：http://127.0.0.1:8080/articles
- 后台登录：http://127.0.0.1:8080/admin/login
- 后台首页：http://127.0.0.1:8080/admin
- 图册平台：http://127.0.0.1:8080/brochure
- 指纹锁教程：http://127.0.0.1:8080/brochure/tutorials
- 图册文章：http://127.0.0.1:8080/brochure/articles
- 健康检查：http://127.0.0.1:8080/health
- 本地登录凭据：`storage/runtime/local-login.txt`

停止服务：运行 `powershell -ExecutionPolicy Bypass -File scripts/stop-local.ps1`。

## 内容管理

后台首页最前方为“官网文章”。文章支持草稿、发布、封面、轻量富文本、SEO 标题和 Meta Description。已发布内容同时出现在官网与图册平台；图册版本 canonical 指向官网版本。官网页尾 `ARTICLES` 自动显示最新 4 篇。

文章网址标识可留空，系统会生成稳定的 `article-{id}`。正文图片支持选择、粘贴和拖拽，自动保存为最长边不超过 1920px 的本地 WebP；封面统一为 1200 × 675px，未上传封面时自动取正文第一张图片。官网页尾显示最新 4 篇文章；官网文章每页 6 篇，图册文章和教程每页 8 篇，图册选款每页 12 本，后台文章每页 10 篇。

数据管理 ZIP 会备份数据库内容、图册封面、教程附件与文章图片。正文 HTML 会在服务端清理，只保留有限的安全标签和本站文章图片。草稿预览使用管理员专用 ID 路由，未登录用户无法访问。

## 开发验证

```powershell
pnpm install --frozen-lockfile
pnpm run check:website
pnpm run build:website
php scripts/migrate.php
php tests/run.php
node tests/http-smoke.js
```

Windows 本机若 8081 端口被防火墙拦截，可先用一键脚本启动 8080，再设置 `USE_EXISTING_SERVER=1` 运行 HTTP 测试。完整部署流程见 `docs/DEPLOYMENT-1PANEL.md`，改造与验收记录见 `docs/IMPLEMENTATION-RECORD.md`。

## 品牌基线

官网沿用正式品牌资产；应用界面使用陶土橙 `#C65D3B`、炭黑 `#252A28`、Noto Sans SC 与 Inter。品牌名称固定写作“乐宅.Life”，英文识别为“LEZHAI”。
