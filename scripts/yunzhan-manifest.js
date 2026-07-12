const path = require('path');

function loadPlaywright() {
  try { return require('playwright'); } catch (_) {}
  for (const root of (process.env.NODE_PATH || '').split(path.delimiter).filter(Boolean)) {
    try { return require(path.join(root, 'playwright')); } catch (_) {}
  }
  throw new Error('Playwright is not available.');
}

(async () => {
  const { chromium } = loadPlaywright();
  const url = process.argv[2];
  const executablePath = process.argv[3] || process.env.BROWSER_EXECUTABLE || undefined;
  if (!url) throw new Error('The catalog URL is required.');

  const browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'], ...(executablePath ? { executablePath } : {}) });
  try {
    const page = await browser.newPage();
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForFunction(() => Array.isArray(window.fliphtml5_pages) && window.fliphtml5_pages.length > 0, null, { timeout: 60000 });
    const pages = await page.evaluate(() => {
      const root = location.href.split('/mobile/')[0];
      return window.fliphtml5_pages.map((item, index) => {
        let file = Array.isArray(item.n) ? item.n[0] : '';
        if (!file) return '';
        if (/\.zip(?:\?|$)/i.test(file)) return `browser-render://${index + 1}`;
        file = file.replace(/x-oss-process=image\/resize,[^&]+/, 'x-oss-process=image/sharpen,100');
        return `${root}/files/large/${file}`;
      }).filter(Boolean);
    });
    process.stdout.write(JSON.stringify({ pages }));
  } finally {
    await browser.close();
  }
})().catch(error => {
  process.stderr.write(error.stack || String(error));
  process.exit(1);
});
