const assert = require('node:assert/strict');
const { readdir, readFile, stat } = require('node:fs/promises');
const path = require('node:path');

const repoRoot = path.resolve(__dirname, '..');
const distRoot = path.join(repoRoot, 'storage', 'website-dist');

async function filesUnder(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  return (await Promise.all(entries.map(async (entry) => {
    const target = path.join(directory, entry.name);
    return entry.isDirectory() ? filesUnder(target) : [target];
  }))).flat();
}

async function main() {
  const files = await filesUnder(distRoot);
  const css = (await Promise.all(files.filter(file => file.endsWith('.css')).map(file => readFile(file, 'utf8')))).join('\n');
  const fontFiles = files.filter(file => file.endsWith('.woff2'));
  const totalFontBytes = (await Promise.all(fontFiles.map(async file => (await stat(file)).size))).reduce((sum, size) => sum + size, 0);

  assert.ok(fontFiles.some(file => file.includes('NotoSansSC')), 'Noto Sans SC WOFF2 must be self-hosted');
  assert.ok(fontFiles.some(file => file.includes('Inter')), 'Inter WOFF2 must be self-hosted');
  assert.doesNotMatch(css, /\.ttf(?:["')?])/i, 'built CSS must not reference full TTF fonts');
  assert.match(css, /unicode-range:/i, 'Chinese font CSS must define unicode-range subsets');
  assert.ok(totalFontBytes <= 1_500_000, `self-hosted WOFF2 budget exceeded: ${totalFontBytes} bytes`);

  const oversizedCritical = [];
  for (const file of files.filter(file => /\.(?:css|js|woff2)$/i.test(file))) {
    const size = (await stat(file)).size;
    if (size > 650_000) {
      oversizedCritical.push(`${path.relative(distRoot, file)} (${size} bytes)`);
    }
  }
  assert.deepEqual(oversizedCritical, [], `critical asset budget exceeded:\n${oversizedCritical.join('\n')}`);

  const firstScreenCandidates = files.filter(file => /\.(?:css|js)$/i.test(file) || /hero-entry.*\.avif$/i.test(file));
  const firstScreenBytes = totalFontBytes + (await Promise.all(firstScreenCandidates.map(async file => (await stat(file)).size)))
    .reduce((sum, size) => sum + size, 0);
  assert.ok(firstScreenBytes <= 1_000_000, `first-screen critical resource budget exceeded: ${firstScreenBytes} bytes`);

  console.log(`Website asset budgets passed (${totalFontBytes} font bytes; ${firstScreenBytes} first-screen upper bound).`);
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
