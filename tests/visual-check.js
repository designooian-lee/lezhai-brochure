const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const root = process.env.PROJECT_ROOT || path.resolve(__dirname, '..');
const php = process.env.PHP_BINARY || (process.platform === 'win32' ? 'C:\\Users\\Lee\\Documents\\Codex\\2026-07-10\\php-8-3-postgresql\\tools\\php\\php.exe' : 'php');
const browserPath = process.env.BROWSER_EXECUTABLE || undefined;
const output = path.join(root, 'storage', 'runtime', 'qa');
fs.mkdirSync(output, { recursive: true });
const ini = path.join(root, 'storage', 'runtime', 'php.ini');
const args = [...(fs.existsSync(ini) ? ['-c', ini] : []), '-S', '127.0.0.1:8082', path.join(root, 'public', 'router.php')];
const server = spawn(php, args, { cwd: root, stdio: 'ignore', windowsHide: true });
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const assert = (condition, message) => { if (!condition) throw new Error(message); console.log(`[通过] ${message}`); };

(async () => {
  let browser;
  try {
    const base = 'http://127.0.0.1:8082/brochure';
    for (let i = 0; i < 30; i++) {
      try { if ((await (await fetch(`${base}/health`)).json()).status === 'ok') break; } catch (_) {}
      await sleep(250);
    }
    browser = await chromium.launch({ headless: true, ...(browserPath ? { executablePath: browserPath } : {}) });
    const page = await browser.newPage();
    for (const [name, width, height] of [['360',360,800],['390',390,844],['430',430,932],['768',768,1024],['desktop',1440,1000]]) {
      await page.setViewportSize({ width, height });
      await page.goto(`${base}/`, { waitUntil: 'networkidle', timeout: 30000 });
      const state = await page.evaluate(() => ({
        overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
        cards: document.querySelectorAll('.catalog-grid .catalog-card').length,
        badImages: [...document.images].filter(img => img.complete && img.naturalWidth === 0).length,
      }));
      assert(!state.overflow && state.cards >= 10 && state.badImages === 0, `${width}px 首页无横向溢出、图册封面完整 ${JSON.stringify(state)}`);
      await page.screenshot({ path: path.join(output, `home-${name}.png`), fullPage: true });
    }
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${base}/`, { waitUntil: 'networkidle', timeout: 30000 });
    const firstFavorite = page.locator('.catalog-grid [data-favorite-id]').first();
    const favoriteId = await firstFavorite.getAttribute('data-favorite-id');
    await firstFavorite.click();
    await page.reload({ waitUntil: 'networkidle' });
    assert(await page.locator(`[data-favorite-id="${favoriteId}"]`).first().getAttribute('aria-pressed') === 'true', '收藏保存在当前客户浏览器');
    await page.locator('[data-favorites-filter]').click();
    const favoriteState = await page.evaluate(() => ({
      visible: [...document.querySelectorAll('.catalog-grid .catalog-card')].filter(card => !card.hidden).length,
      count: document.querySelector('[data-favorites-count]')?.textContent,
    }));
    assert(favoriteState.visible === 1 && favoriteState.count === '1', '我的收藏筛选只显示已收藏图册');
    await page.goto(`${base}/catalog/1`, { waitUntil: 'networkidle' });
    await page.waitForSelector('dialog[open]');
    assert(await page.locator('dialog[open]').isVisible(), '分享链接打开后自动进入指定图册');
    await page.setViewportSize({ width: 390, height: 844 });
    for (const [id, type, marker] of [[2,'goootu','[data-image-reader]'],[5,'yunzhan365','iframe.catalog-frame'],[11,'flbook','iframe.catalog-frame']]) {
      await page.goto(`${base}/`, { waitUntil: 'networkidle', timeout: 30000 });
      await page.locator(`[data-reader-url$="/reader/${id}"]`).first().click();
      await page.waitForSelector('dialog[open]', { timeout: 10000 });
      const dialogSize = await page.locator('dialog[open]').evaluate(node => { const box=node.getBoundingClientRect(); return { top:box.top, left:box.left, width:box.width, height:box.height, viewport:[innerWidth,innerHeight], bodyOverflow:getComputedStyle(document.body).overflow }; });
      assert(dialogSize.top === 0 && dialogSize.left === 0 && Math.abs(dialogSize.width-dialogSize.viewport[0]) < 2 && Math.abs(dialogSize.height-dialogSize.viewport[1]) < 2 && dialogSize.bodyOverflow === 'hidden', `${type} 阅读层覆盖完整窗口`);
      await page.waitForSelector(marker, { timeout: 20000 });
      if (type !== 'goootu') {
        const frame = page.locator('iframe.catalog-frame');
        await page.waitForTimeout(3000);
        const embedded = page.frames().find(candidate => candidate.parentFrame() && candidate.url().startsWith('http'));
        assert((await frame.getAttribute('src')).startsWith('http') && Boolean(embedded), `${type} 在站内阅读层加载`);
      } else {
        await page.waitForFunction(() => { const image=document.querySelector('[data-page-image]'); return image && image.complete && image.naturalWidth > 0; }, null, { timeout: 20000 });
        assert(await page.locator('[data-page-image]').isVisible(), 'goootu 自有阅读器首图加载并可见');
      }
      await page.screenshot({ path: path.join(output, `reader-${type}.png`), fullPage: true });
      await page.locator('[data-close-reader]').click();
    }
    const credentials = fs.readFileSync(path.join(root, 'storage', 'runtime', 'local-login.txt'), 'utf8');
    const password = credentials.trim().split(/\r?\n/).pop().substring(3);
    await page.goto(`${base}/admin/login`, { waitUntil: 'networkidle' });
    await page.locator('input[name="username"]').fill('admin');
    await page.locator('input[name="password"]').fill(password);
    await page.locator('button[type="submit"]').click();
    await page.waitForSelector('.admin-section');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.reload({ waitUntil: 'networkidle' });
    const adminState = await page.evaluate(() => ({ overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1, rows: document.querySelectorAll('.catalog-table tbody tr').length }));
    assert(!adminState.overflow && adminState.rows >= 10, '390px 后台无页面级横向溢出并显示全部图册');
    await page.screenshot({ path: path.join(output, 'admin-390.png'), fullPage: true });
    await page.goto(`${base}/admin/data`, { waitUntil: 'networkidle' });
    assert(await page.locator('.data-panel').count() === 2, '手机后台显示数据导入与导出');
    await page.goto(`${base}/admin/catalogs/1/edit`, { waitUntil: 'networkidle' });
    assert(await page.locator('select[name="reader_mode"]').isVisible() && await page.locator('.local-pages-panel').isVisible(), '手机图册编辑页显示阅读方式和本地图片状态');
    console.log(`截图目录：${output}`);
  } finally {
    if (browser) await browser.close();
    server.kill();
  }
})().catch(error => { console.error(`[失败] ${error.message}`); process.exitCode = 1; });
