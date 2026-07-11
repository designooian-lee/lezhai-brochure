const fs = require('fs');
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
  const output = process.argv[3];
  const requested = Number(process.argv[4] || 0);
  const executablePath = process.argv[5] || undefined;
  if (!url || !output) throw new Error('The catalog URL and output directory are required.');

  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ headless: true, ...(executablePath ? { executablePath } : {}) });
  try {
    const context = await browser.newContext({ viewport: { width: 1800, height: 2400 }, deviceScaleFactor: 1 });
    const page = await context.newPage();
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForFunction(() => Array.isArray(window.fliphtml5_pages) && window.fliphtml5_pages.length > 0, null, { timeout: 60000 });
    const total = await page.evaluate(() => window.fliphtml5_pages.length);
    const count = requested > 0 ? Math.min(requested, total) : total;

    // The current Yunzhan player creates its page controller only after the
    // first reader interaction. A neutral center click initializes it.
    await page.mouse.click(900, 1200);
    await page.waitForTimeout(1200);

    await page.evaluate(() => {
      for (const node of document.querySelectorAll('body *')) {
        if ((node.textContent || '').trim() !== '点击查看全屏') continue;
        let target = node;
        while (target.parentElement && target.parentElement !== document.body) {
          const parent = target.parentElement;
          if ((parent.textContent || '').trim() !== '点击查看全屏') break;
          if (parent.getBoundingClientRect().width > 1000) break;
          target = parent;
        }
        target.style.setProperty('display', 'none', 'important');
      }
    });

    for (let number = 1; number <= count; number++) {
      await page.evaluate(pageNumber => {
        if (typeof window.gotoPageFun === 'function') window.gotoPageFun(pageNumber);
      }, number);
      const deadline = Date.now() + 20000;
      let captured = null;
      while (!captured && Date.now() < deadline) {
        captured = await page.evaluate(pageNumber => {
          const candidates = [...document.querySelectorAll(`#page${pageNumber}`)].filter(container => {
            const rect = container.getBoundingClientRect();
            if (rect.width < 300 || rect.height < 300) return false;
            return [...container.querySelectorAll('canvas')].some(canvas =>
              canvas.width > 300 && canvas.height > 300 && canvas.toDataURL('image/png').length > 10000
            );
          });
          if (!candidates.length) return null;
          const container = candidates.sort((a, b) => {
            const first = a.getBoundingClientRect();
            const second = b.getBoundingClientRect();
            return (second.width * second.height) - (first.width * first.height);
          })[0];
          const rect = container.getBoundingClientRect();
          const output = document.createElement('canvas');
          output.width = Math.round(rect.width);
          output.height = Math.round(rect.height);
          const context = output.getContext('2d');
          context.fillStyle = '#ffffff';
          context.fillRect(0, 0, output.width, output.height);
          for (const canvas of container.querySelectorAll('canvas')) {
            const box = canvas.getBoundingClientRect();
            if (box.width < 1 || box.height < 1 || getComputedStyle(canvas).visibility === 'hidden') continue;
            context.globalAlpha = Number.parseFloat(getComputedStyle(canvas).opacity || '1');
            context.drawImage(canvas, box.x - rect.x, box.y - rect.y, box.width, box.height);
          }
          context.globalAlpha = 1;
          const data = output.toDataURL('image/png').split(',')[1] || '';
          return data.length > 10000 ? { data, width: output.width, height: output.height } : null;
        }, number);
        if (!captured) await page.waitForTimeout(50);
      }
      if (!captured) throw new Error(`Page ${number} could not be decoded within 20 seconds.`);
      fs.writeFileSync(path.join(output, `${String(number).padStart(4, '0')}.png`), Buffer.from(captured.data, 'base64'));
      process.stdout.write(`PAGE ${number}/${count}\n`);
    }
    process.stdout.write(JSON.stringify({ total, exported: count }));
  } finally {
    await browser.close();
  }
})().catch(error => {
  process.stderr.write(error.stack || String(error));
  process.exit(1);
});
