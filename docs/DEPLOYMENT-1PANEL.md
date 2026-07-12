# 1Panel / VPS 部署与回滚

## 部署门禁

生产部署只允许使用已经在本地通过 Astro 检查、PHP 测试、HTTP 冒烟测试和人工页面检查的 Git 提交或容器镜像。禁止直接在 VPS 修改源码。

## 首次配置

1. 在 1Panel 创建编排，使用仓库根目录的 `docker-compose.yml`。
2. 从 `.env.example` 创建生产环境变量；设置强随机 `APP_SECRET`、`DB_PASSWORD`、`ADMIN_PASSWORD_HASH`，不得提交 `.env`。
3. 将 `www.lezhai.life` 反向代理到应用容器 `8080`，由 1Panel 管理 HTTPS。
4. 保留 PostgreSQL、`public/uploads`、`storage/downloads`、`storage/local-pages` 对应的持久卷。

容器启动会先执行 `php scripts/migrate.php`，成功后才启动 PHP-FPM 和 Nginx。`/health` 同时检查 PHP 路由与数据库连接。

## 每次发布

1. 记录当前 Git 提交和镜像标签。
2. 从后台导出 ZIP，并另外执行 PostgreSQL 备份及上传目录/卷快照。
3. 在本地执行完整验收，将同一提交构建成不可变镜像。
4. 1Panel 拉取指定镜像标签，启动新容器并执行迁移。
5. 依次检查 `/health`、`/`、`/articles`、`/admin/login`、`/brochure`、`/brochure/tutorials`、`/brochure/articles`。
6. 确认日志无持续错误后再结束维护窗口。

## 回滚

1. 停止新容器并切回上一镜像标签。
2. 若迁移只新增表或字段，旧版本通常可直接运行；若未来出现破坏性迁移，按该版本迁移说明恢复 PostgreSQL 备份。
3. 恢复上传目录/持久卷快照。
4. 再次检查 `/health` 和所有关键页面，并记录回滚原因。

CI 只检查与构建，不自动覆盖生产。发布必须由 1Panel 或受控人工操作触发。
