const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const root = process.env.PROJECT_ROOT || path.resolve(__dirname, '..');
const php = process.env.PHP_BINARY || (process.platform === 'win32'
  ? 'C:\\Users\\Lee\\Documents\\Codex\\2026-07-10\\php-8-3-postgresql\\tools\\php\\php.exe'
  : 'php');
const ini = path.join(root, 'storage', 'runtime', 'php.ini');
const useExistingServer = process.env.USE_EXISTING_SERVER === '1';
const port = process.env.SMOKE_PORT || (useExistingServer ? '8080' : '8081');
const args = [...(fs.existsSync(ini) ? ['-c', ini] : []), '-S', `127.0.0.1:${port}`, path.join(root, 'public', 'router.php')];
const server = useExistingServer ? null : spawn(php, args, { cwd: root, stdio: ['ignore','inherit','inherit'], windowsHide: true });
const base = `http://127.0.0.1:${port}/brochure`;
const siteRoot = `http://127.0.0.1:${port}`;
const adminBase = `${siteRoot}/admin`;
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
  const health = await fetch(`${siteRoot}/health`);
  assert(health.status === 200 && (await health.json()).status === 'ok', '统一健康检查可访问');
  const website = await fetch(`${siteRoot}/`);
  const websiteHtml = await website.text();
  assert(website.status === 200 && websiteHtml.includes('ARTICLES') && websiteHtml.includes('13530067877') && websiteHtml.includes('广东省惠州市仲恺区香樟小镇 D10 铺'), '官网首页提供文章页尾与固定联系资料');
  const footerHtml = websiteHtml.slice(websiteHtml.indexOf('<footer'));
  assert(footerHtml.indexOf('提交预约需求') < footerHtml.indexOf('广东省惠州市仲恺区香樟小镇 D10 铺'), '预约需求入口位于页脚定位信息之前');
  const articleList = await fetch(`${siteRoot}/articles`);
  assert(articleList.status === 200 && (await articleList.text()).includes('LEZHAI JOURNAL'), '官网文章列表可访问');
  let publicJar = [];
  const front = await fetch(`${base}/`);
  publicJar = mergeCookies(publicJar, incomingCookies(front.headers));
  const frontHtml = await front.text();
  assert(front.status === 200 && frontHtml.includes('catalog-card'), '客户首页可访问并显示图册');
  assert(frontHtml.includes('© 2026 乐宅.Life'), '图册公开页面显示固定版权');
  assert(frontHtml.includes('data-favorite-id') && frontHtml.includes('data-favorites-filter'), '客户首页提供浏览器收藏与收藏筛选');
  assert(frontHtml.includes('data-share-url'), '每本图册提供分享按钮');
  const direct = await fetch(`${base}/catalog/1`);
  assert(direct.status === 200 && (await direct.text()).includes('data-auto-reader-url'), '分享链接可直达指定图册');
  const css = await fetch(`${base}/assets/app.css`);
  const cssText = await css.text();
  assert(css.status === 200 && css.headers.get('content-type').startsWith('text/css'), '子目录样式资源以正确 MIME 返回');
  assert(cssText.includes('.status{white-space:nowrap}') && cssText.includes('height:460px') && cssText.includes('.category-tabs a{display:flex}'), '后台状态、固定高度和移动分类样式已加载');
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

  const legacyAdmin = await fetch(`${base}/admin`, { redirect: 'manual' });
  assert(legacyAdmin.status === 301 && legacyAdmin.headers.get('location') === '/admin', '旧后台地址永久重定向到根后台');
  const anonymous = await fetch(adminBase, { redirect: 'manual' });
  assert(anonymous.status === 302 && anonymous.headers.get('location').endsWith('/admin/login'), '未登录访问后台会被拦截');

  let login = await fetch(`${adminBase}/login`);
  let adminJar = incomingCookies(login.headers);
  const loginHtml = await login.text();
  const csrf = loginHtml.match(/name="_csrf" value="([^"]+)"/)[1];
  let password = process.env.TEST_ADMIN_PASSWORD || '';
  if (!password) {
    const credentials = fs.readFileSync(path.join(root, 'storage', 'runtime', 'local-login.txt'), 'utf8');
    password = credentials.trim().split(/\r?\n/).pop().substring(3);
  }
  const loginResponse = await fetch(`${adminBase}/login`, {
    method: 'POST', redirect: 'manual',
    headers: { cookie: adminJar.join('; '), 'content-type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ _csrf: csrf, username: 'admin', password }),
  });
  adminJar = mergeCookies(adminJar, incomingCookies(loginResponse.headers));
  const admin = await fetch(adminBase, { headers: { cookie: adminJar.join('; ') } });
  const adminLandingHtml = await admin.text();
  assert(admin.status === 200 && adminLandingHtml.includes('官网文章') && adminLandingHtml.includes('查看官网') && adminLandingHtml.indexOf('官网文章') < adminLandingHtml.indexOf('分类'), '管理员可以登录，文章管理在前且提供官网入口');
  assert(adminLandingHtml.includes('© 2026 乐宅.Life') && (adminLandingHtml.match(/admin-fixed-section/g)||[]).length===2, '后台显示固定版权且分类、教程使用固定高度容器');
  const imageData = new FormData(); imageData.append('_csrf', csrf); imageData.append('image', new Blob([Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64')], { type: 'image/png' }), 'pasted.png');
  const imageUpload = await fetch(`${adminBase}/articles/image`, { method: 'POST', headers: { cookie: adminJar.join('; ') }, body: imageData });
  const imageResult = await imageUpload.json();
  assert(imageUpload.status === 200 && /^\/uploads\/articles\/body-[a-f0-9]+\.webp$/.test(imageResult.url), `粘贴图片上传后转换为本站 WebP（${imageUpload.status} ${JSON.stringify(imageResult)}）`);
  const draftCreate = await fetch(`${adminBase}/articles/new`, { method: 'POST', redirect: 'manual', headers: { cookie: adminJar.join('; '), 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ _csrf: csrf, title: 'QA 草稿预览', slug: '', excerpt: '草稿', body_html: `<p>正文</p><img src="${imageResult.url}" alt="测试">`, status: 'draft' }) });
  const draftId = draftCreate.headers.get('location').match(/articles\/(\d+)\/edit/)[1];
  const draftEdit = await fetch(`${adminBase}/articles/${draftId}/edit`, { headers: { cookie: adminJar.join('; ') } }); const draftEditHtml = await draftEdit.text();
  assert(draftCreate.status === 302 && draftEditHtml.includes(`value="article-${draftId}"`), '空网址标识自动生成稳定 article-ID');
  const anonymousPreview = await fetch(`${adminBase}/articles/${draftId}/preview?surface=website`, { redirect: 'manual' });
  assert(anonymousPreview.status === 302, '未登录用户不能预览草稿');
  const websitePreview = await fetch(`${adminBase}/articles/${draftId}/preview?surface=website`, { headers: { cookie: adminJar.join('; ') } }); const websitePreviewHtml = await websitePreview.text();
  assert(websitePreview.status === 200 && websitePreviewHtml.includes('noindex,nofollow') && !websitePreviewHtml.includes('website-article-cover'), '官网草稿预览可访问且详情正文不显示封面');
  const brochurePreview = await fetch(`${adminBase}/articles/${draftId}/preview?surface=brochure`, { headers: { cookie: adminJar.join('; ') } });
  assert(brochurePreview.status === 200 && (await brochurePreview.text()).includes('QA 草稿预览'), '图册版本草稿预览可访问');
  const articleCreate = await fetch(`${adminBase}/articles/new`, {
    method: 'POST', redirect: 'manual',
    headers: { cookie: adminJar.join('; '), 'content-type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ _csrf: csrf, title: 'QA 测试文章', slug: 'qa-article', excerpt: '用于自动验收', body_html: '<h2>安全正文</h2><p>本地验收内容</p><script>alert(1)</script>', seo_keywords: '门窗,安装', seo_title: 'QA SEO 标题', meta_description: 'QA SEO 描述', status: 'published' }),
  });
  assert(articleCreate.status === 302, '后台可创建并发布文章');
  const publishedId = articleCreate.headers.get('location').match(/articles\/(\d+)\/edit/)[1];
  const publicArticle = await fetch(`${siteRoot}/articles/qa-article`);
  const publicArticleHtml = await publicArticle.text();
  assert(publicArticle.status === 200 && publicArticleHtml.includes('QA SEO 标题') && publicArticleHtml.includes('name="keywords" content="门窗,安装"') && !publicArticleHtml.includes('alert(1)'), '官网文章输出 SEO 并清理危险 HTML');
  assert(publicArticleHtml.includes('热门文章') && publicArticleHtml.includes('article-hot-viewport'), '官网文章右侧显示月度热门文章滚动区');
  const brochureArticle = await fetch(`${base}/articles/qa-article`);
  assert(brochureArticle.status === 200 && (await brochureArticle.text()).includes('https://lezhai.life/articles/qa-article'), '图册文章 canonical 指向官网版本');
  const sitemap = await fetch(`${siteRoot}/sitemap.xml`);
  assert(sitemap.status === 200 && (await sitemap.text()).includes('/articles/qa-article'), '站点地图包含已发布文章');
  for (const articleId of [draftId, publishedId]) { const articleRemove = await fetch(`${adminBase}/articles/${articleId}/delete`, { method: 'POST', redirect: 'manual', headers: { cookie: adminJar.join('; '), 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ _csrf: csrf }) }); assert(articleRemove.status === 302, `后台可删除测试文章 ${articleId}`); }
  const dataPage = await fetch(`${adminBase}/data`, { headers: { cookie: adminJar.join('; ') } });
  assert(dataPage.status === 200 && (await dataPage.text()).includes('导入并恢复'), '后台数据管理页面可访问');
  const exported = await fetch(`${adminBase}/data/export`, {
    method: 'POST', headers: { cookie: adminJar.join('; '), 'content-type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ _csrf: csrf }),
  });
  const exportedData = Buffer.from(await exported.arrayBuffer());
  assert(exported.status === 200 && exported.headers.get('content-type').startsWith('application/zip') && exportedData.subarray(0, 2).toString() === 'PK', '后台可导出含本地图片的 ZIP 备份');
  const catalogEdit = await fetch(`${adminBase}/catalogs/1/edit`, { headers: { cookie: adminJar.join('; ') } });
  const catalogEditHtml = await catalogEdit.text();
  assert(catalogEdit.status === 200 && catalogEditHtml.includes('name="reader_mode"') && catalogEditHtml.includes('本地高清页面'), '图册编辑页可选择原网页或本地图片');

  const create = await fetch(`${adminBase}/categories/new`, {
    method: 'POST', redirect: 'manual',
    headers: { cookie: adminJar.join('; '), 'content-type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ _csrf: csrf, name: 'QA temporary category', slug: 'qa-temporary', sort_order: '999', is_active: '1' }),
  });
  assert(create.status === 302, '后台可以新增分类');
  const adminAfterCreate = await fetch(adminBase, { headers: { cookie: adminJar.join('; ') } });
  const adminHtml = await adminAfterCreate.text();
  const idMatch = adminHtml.match(/<tr><td>QA temporary category<\/td>[\s\S]*?admin\/categories\/(\d+)\/edit/);
  assert(Boolean(idMatch), '新增分类出现在后台列表');
  const remove = await fetch(`${adminBase}/categories/${idMatch[1]}/delete`, {
    method: 'POST', redirect: 'manual',
    headers: { cookie: adminJar.join('; '), 'content-type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ _csrf: csrf }),
  });
  assert(remove.status === 302, '后台可以删除空分类');
  const badCsrf = await fetch(`${adminBase}/categories/new`, {
    method: 'POST', redirect: 'manual',
    headers: { cookie: adminJar.join('; '), 'content-type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ _csrf: 'invalid', name: '不应保存', slug: 'invalid-csrf' }),
  });
  assert(badCsrf.status === 419, '后台写操作拒绝无效 CSRF');
}

main().catch(error => { console.error(`[失败] ${error.message}`, error.cause || ''); process.exitCode = 1; }).finally(() => server?.kill());
