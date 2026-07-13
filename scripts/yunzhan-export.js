const fs = require('fs');
const path = require('path');

function loadPlaywright() {
  try { return require('playwright'); } catch (_) {}
  for (const root of (process.env.NODE_PATH || '').split(path.delimiter).filter(Boolean)) {
    try { return require(path.join(root, 'playwright')); } catch (_) {}
  }
  throw new Error('Playwright is not available.');
}

const sourceUrl = process.argv[2];
const output = process.argv[3];
const requested = Number(process.argv[4] || 0);
const executablePath = process.argv[5] || process.env.BROWSER_EXECUTABLE || undefined;
const batchSize = 1;

async function openBook(browser) {
  const context = await browser.newContext({ viewport: { width: 1000, height: 1000 }, deviceScaleFactor: 1 });
  const page = await context.newPage();
  await page.goto(sourceUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForFunction(() => Array.isArray(window.fliphtml5_pages) && window.fliphtml5_pages.length > 0, null, { timeout: 60000 });
  await page.mouse.click(500, 500);
  await page.waitForTimeout(1000);
  await page.evaluate(() => {
    for (const node of document.querySelectorAll('body *')) {
      if ((node.textContent || '').trim() !== '点击查看全屏') continue;
      let target = node;
      while (target.parentElement && target.parentElement !== document.body) {
        const parent = target.parentElement;
        if ((parent.textContent || '').trim() !== '点击查看全屏' || parent.getBoundingClientRect().width > 1000) break;
        target = parent;
      }
      target.style.setProperty('display', 'none', 'important');
    }
  });
  return { context, page, total: await page.evaluate(() => window.fliphtml5_pages.length) };
}

async function capturePage(page, number) {
  await page.evaluate(pageNumber => { if (typeof window.gotoPageFun === 'function') window.gotoPageFun(pageNumber); }, number);
  await page.waitForFunction(pageNumber => [...document.querySelectorAll(`#page${pageNumber}`)].some(container => {
    const rect = container.getBoundingClientRect();
    return rect.width >= 300 && rect.height >= 300 && (
      [...container.querySelectorAll('img')].some(image => image.src.includes('/files/large/') && image.complete && image.naturalWidth > 300 && image.naturalHeight > 300) ||
      [...container.querySelectorAll('canvas')].some(canvas => canvas.width > 300 && canvas.height > 300)
    );
  }), number, { timeout: 20000, polling: 100 });
  const dimensions = await page.evaluate(pageNumber => {
    document.querySelector('#lezhai-export-page')?.remove();
    const candidates = [...document.querySelectorAll(`#page${pageNumber}`)].filter(container => {
      const rect = container.getBoundingClientRect();
      return rect.width >= 300 && rect.height >= 300;
    });
    const container = candidates.sort((a, b) => {
      const first = a.getBoundingClientRect(); const second = b.getBoundingClientRect();
      return second.width * second.height - first.width * first.height;
    })[0];
    if (!container) return null;
    const rect = container.getBoundingClientRect();
    const image = [...container.querySelectorAll('img')].find(node => node.src.includes('/files/large/') && node.complete && node.naturalWidth > 300 && node.naturalHeight > 300);
    if (image) {
      const scale = Math.max(1, 1000 / image.naturalWidth, 1000 / image.naturalHeight);
      const output = image.cloneNode(); output.id = 'lezhai-export-page'; output.width = Math.ceil(image.naturalWidth * scale); output.height = Math.ceil(image.naturalHeight * scale);
      output.style.cssText = `position:fixed;left:0;top:0;width:${output.width}px;height:${output.height}px;z-index:2147483647`;
      document.body.append(output); return { width: output.width, height: output.height };
    }
    const scale = Math.max(1, 1000 / rect.width, 1000 / rect.height);
    const width = Math.ceil(rect.width * scale);
    const height = Math.ceil(rect.height * scale);
    const output = document.createElement('canvas'); output.id = 'lezhai-export-page'; output.width = width; output.height = height;
    output.style.cssText = `position:fixed;left:0;top:0;width:${width}px;height:${height}px;z-index:2147483647;background:#fff`;
    const drawing = output.getContext('2d'); drawing.fillStyle = '#fff'; drawing.fillRect(0, 0, width, height);
    drawing.scale(width / rect.width, height / rect.height);
    for (const canvas of container.querySelectorAll('canvas')) {
      const box = canvas.getBoundingClientRect();
      if (box.width < 1 || box.height < 1 || getComputedStyle(canvas).visibility === 'hidden') continue;
      drawing.globalAlpha = Number.parseFloat(getComputedStyle(canvas).opacity || '1');
      drawing.drawImage(canvas, box.x - rect.x, box.y - rect.y, box.width, box.height);
    }
    drawing.globalAlpha = 1; document.body.append(output); return { width, height };
  }, number);
  if (!dimensions) throw new Error(`Page ${number} could not be decoded.`);
  await page.waitForFunction(() => { const node = document.querySelector('#lezhai-export-page'); return node && (node.tagName !== 'IMG' || node.complete); });
  await page.locator('#lezhai-export-page').screenshot({ path: path.join(output, `${String(number).padStart(4, '0')}.png`), type: 'png', animations: 'disabled' });
  await page.locator('#lezhai-export-page').evaluate(node => node.remove());
}

(async () => {
  if (!sourceUrl || !output) throw new Error('The catalog URL and output directory are required.');
  fs.mkdirSync(output, { recursive: true });
  const { chromium } = loadPlaywright();
  const launchOptions = { headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--disable-extensions', '--disable-background-networking', '--disable-software-rasterizer', '--renderer-process-limit=1', '--no-zygote', '--single-process', '--js-flags=--max-old-space-size=64'], ...(executablePath ? { executablePath } : {}) };
  let number = 1; let total = 0; let count = 0; let failures = 0;
  while (count === 0 || number <= count) {
    const browser = await chromium.launch(launchOptions);
    try {
      const book = await openBook(browser); total = book.total; count = requested > 0 ? Math.min(requested, total) : total;
      const batchEnd = Math.min(count, number + batchSize - 1);
      while (number <= batchEnd) {
        const target = path.join(output, `${String(number).padStart(4, '0')}.png`);
        if (!fs.existsSync(target)) await capturePage(book.page, number);
        process.stdout.write(`PAGE ${number}/${count}\n`); number += 1;
      }
      failures = 0; await book.context.close();
    } catch (error) {
      failures += 1;
      if (failures > 2) throw error;
      process.stderr.write(`Retrying page ${number}: ${error.message}\n`);
    } finally {
      await browser.close().catch(() => {});
    }
  }
  process.stdout.write(JSON.stringify({ total, exported: count }));
})().catch(error => { process.stderr.write(error.stack || String(error)); process.exit(1); });
