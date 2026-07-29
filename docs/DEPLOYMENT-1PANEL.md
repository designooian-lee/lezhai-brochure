# 1Panel / VPS 部署与回滚

## 部署门禁

生产部署只允许使用已经在本地通过 Astro 检查、PHP 测试、HTTP 冒烟测试和人工页面检查的 Git 提交或容器镜像。禁止直接在 VPS 修改源码。

## 首次配置

1. 在 1Panel PostgreSQL 中创建独立数据库和最小权限用户，数据库容器与应用都加入 `1panel-network`。
2. 从 `.env.example` 创建 `/opt/apps/lezhai-brochure/.env`；填写实际 `DB_CONTAINER`、`DB_HOST`、数据库凭据、强随机 `APP_SECRET` 和 `ADMIN_PASSWORD_HASH`，文件权限设为 `600`，不得提交。
3. 将 `lezhai.life` 反向代理到主机 `127.0.0.1:4327`，传递 `Host`、`X-Forwarded-For` 和 `X-Forwarded-Proto`，由 1Panel 管理 HTTPS。
4. 将 `www.lezhai.life` 永久重定向到 `https://lezhai.life`。
5. 保留 `public/uploads`、`storage/downloads`、`storage/local-pages` 对应的持久卷。

容器启动会先执行 `php scripts/migrate.php`，成功后才启动 PHP-FPM 和 Nginx。`/health` 同时检查 PHP 路由与数据库连接。

## 每次发布

1. 记录当前 Git 提交和镜像标签。
2. 自动发布脚本使用 `DB_CONTAINER` 和数据库环境变量执行 PostgreSQL 备份。
3. GitHub Actions 执行完整验收，将同一提交构建成不可变镜像。
4. GitHub Actions 通过 SSH 上传镜像并启动新容器，容器启动时执行迁移。
5. 依次检查 `/health`、`/`、`/articles`、`/admin/login`、`/brochure`、`/brochure/tutorials`、`/brochure/articles`。
6. 确认日志无持续错误后再结束维护窗口。

## 回滚

1. 停止新容器并切回上一镜像标签。
2. 若迁移只新增表或字段，旧版本通常可直接运行；若未来出现破坏性迁移，按该版本迁移说明恢复 PostgreSQL 备份。
3. 恢复上传目录/持久卷快照。
4. 再次检查 `/health` 和所有关键页面，并记录回滚原因。

推送或合并到 `main` 会触发完整 CI 和生产发布。也可使用 `workflow_dispatch` 手动重发同一版本。
