const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const root = process.env.PROJECT_ROOT || path.resolve(__dirname, '..');
const php = process.env.PHP_BINARY || (process.platform === 'win32'
  ? 'C:\\Users\\Lee\\Documents\\Codex\\2026-07-10\\php-8-3-postgresql\\tools\\php\\php.exe'
  : 'php');
const ini = path.join(root, 'storage', 'runtime', 'php.ini');
const port = process.env.SMOKE_PORT || '8081';
const args = [...(fs.existsSync(ini) ? ['-c', ini] : []), '-S', `127.0.0.1:${port}`, path.join(root, 'public', 'router.php')];
const server = spawn(php, args, { cwd: root, stdio: 'ignore', windowsHide: true });
const base = `http://127.0.0.1:${port}/brochure`;
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const incomingCookies = headers => (headers.getSetCookie?.() || []).map(value => value.split(';')[0]);
const mergeCookies = (old, fresh) => {
  const jar = new Map(old.map(cookie => cookie.split(/=(.*)/s).slice(0, 2)));
  for (const cookie of fresh) {
    const [name, value] = cookie.split(/=(.*)/s);
    jar.set(name, value);
  }
  return [...jar].map(([name, value]) => `${name}=${value}`);
};
const assert = (condition, message) => { if (!condition) throw new Error(message); console.log(`[通过] ${message}`); };

async function main() {
  for (let i = 0; i < 30; i++) {
    try { if ((await (await fetch(`${base}/health`)).json()).status === 'ok') break; } catch (_) {}
    await sleep(250);
  }
  let publicJar = [];
  const front = await fetch(`${base}/`);
  publicJar = mergeCookies(publicJar, incomingCookies(front.headers));
  const frontHtml = await front.text();
  assert(front.status === 200 && frontHtml.includes('catalog-card'), '客户首页可访问并显示图册');
  assert(frontHtml.includes('data-favorite-id') && frontHtml.includes('data-favorites-filter'), '客户首页提供浏览器收藏与收藏筛选');
  assert(frontHtml.includes('data-share-url'), '每本图册提供分享按钮');
  const direct = await fetch(`${base}/catalog/1`);
  assert(direct.status === 200 && (await direct.text()).includes('data-auto-reader-url'), '分享链接可直达指定图册');
  const css = await fetch(`${base}/assets/app.css`);
  assert(css.status === 200 && css.headers.get('content-type').startsWith('text/css'), '子目录样式资源以正确 MIME 返回');
  const js = await fetch(`${base}/assets/app.js`);
  assert(js.status === 200 && js.headers.get('content-type').startsWith('application/javascript'), '子目录脚本资源以正确 MIME 返回');
  const traversal = await fetch(`http://127.0.0.1:${port}/brochure/%2e%2e%2f.env`);
  const traversalBody = await traversal.text();
  assert(!traversalBody.includes('DB_PASSWORD=') && !traversalBody.includes('ADMIN_PASSWORD_HASH='), '路由器拒绝读取 public 目录之外的文件');
  assert(front.headers.get('x-content-type-options') === 'nosniff' && front.headers.has('content-security-policy'), '动态页面发送基础安全响应头');
  const reader = await fetch(`${base}/reader/2`, { headers: { cookie: publicJar.join('; ') } });
  assert(reader.status === 200 && (await reader.text()).includes('data-image-reader'), 'goootu 使用站内图片阅读器');
  let view = await fetch(`${base}/view/1`, { method: 'POST', headers: { cookie: publicJar.join('; ') } });
  publicJar = mergeCookies(publicJar, incomingCookies(view.headers));
  assert((await view.json()).counted === true, '首次浏览计入热度');
  view = await fetch(`${base}/view/1`, { method: 'POST', headers: { cookie: publicJar.join('; ') } });
  assert((await view.json()).counted === false, '同设备当天不重复计数');

  const anonymous = await fetch(`${base}/admin`, { redirect: 'manual' });
  assert(anonymous.status === 302 && anonymous.headers.get('location').endsWith('/admin/login'), '未登录访问后台会被拦截');

  let login = await fetch(`${base}/admin/login`);
  let adminJar = incomingCookies(login.headers);
  const loginHtml = await login.text();
  const csrf = loginHtml.match(/name="_csrf" value="([^"]+)"/)[1];
  let password = process.env.TEST_ADMIN_PASSWORD || '';
  if (!password) {
    const credentials = fs.readFileSync(path.join(root, 'storage', 'runtime', 'local-login.txt'), 'utf8');
    password = credentials.trim().split(/\r?\n/).pop().substring(3);
  }
  const loginResponse = await fetch(`${base}/admin/login`, {
    method: 'POST', redirect: 'manual',
    headers: { cookie: adminJar.join('; '), 'content-type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ _csrf: csrf, username: 'admin', password }),
  });
  adminJar = mergeCookies(adminJar, incomingCookies(loginResponse.headers));
  const admin = await fetch(`${base}/admin`, { headers: { cookie: adminJar.join('; ') } });
  assert(admin.status === 200 && (await admin.text()).includes('admin-section'), '管理员可以登录后台');
  const dataPage = await fetch(`${base}/admin/data`, { headers: { cookie: adminJar.join('; ') } });
  assert(dataPage.status === 200 && (await dataPage.text()).includes('导入并恢复'), '后台数据管理页面可访问');
  const exported = await fetch(`${base}/admin/data/export`, {
    method: 'POST', headers: { cookie: adminJar.join('; '), 'content-type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ _csrf: csrf }),
  });
  const exportedData = Buffer.from(await exported.arrayBuffer());
  assert(exported.status === 200 && exported.headers.get('content-type').startsWith('application/zip') && exportedData.subarray(0, 2).toString() === 'PK', '后台可导出含本地图片的 ZIP 备份');
  const catalogEdit = await fetch(`${base}/admin/catalogs/1/edit`, { headers: { cookie: adminJar.join('; ') } });
  const catalogEditHtml = await catalogEdit.text();
  assert(catalogEdit.status === 200 && catalogEditHtml.includes('name="reader_mode"') && catalogEditHtml.includes('本地高清页面'), '图册编辑页可选择原网页或本地图片');

  const create = await fetch(`${base}/admin/categories/new`, {
    method: 'POST', redirect: 'manual',
    headers: { cookie: adminJar.join('; '), 'content-type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ _csrf: csrf, name: 'QA temporary category', slug: 'qa-temporary', sort_order: '999', is_active: '1' }),
  });
  assert(create.status === 302, '后台可以新增分类');
  const adminAfterCreate = await fetch(`${base}/admin`, { headers: { cookie: adminJar.join('; ') } });
  const adminHtml = await adminAfterCreate.text();
  const idMatch = adminHtml.match(/<tr><td>QA temporary category<\/td>[\s\S]*?admin\/categories\/(\d+)\/edit/);
  assert(Boolean(idMatch), '新增分类出现在后台列表');
  const remove = await fetch(`${base}/admin/categories/${idMatch[1]}/delete`, {
    method: 'POST', redirect: 'manual',
    headers: { cookie: adminJar.join('; '), 'content-type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ _csrf: csrf }),
  });
  assert(remove.status === 302, '后台可以删除空分类');
  const badCsrf = await fetch(`${base}/admin/categories/new`, {
    method: 'POST', redirect: 'manual',
    headers: { cookie: adminJar.join('; '), 'content-type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ _csrf: 'invalid', name: '不应保存', slug: 'invalid-csrf' }),
  });
  assert(badCsrf.status === 419, '后台写操作拒绝无效 CSRF');
}

main().catch(error => { console.error(`[失败] ${error.message}`); process.exitCode = 1; }).finally(() => server.kill());
