import { chromium } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { PNG } from 'pngjs';
import pixelmatch from 'pixelmatch';

function usage() {
  console.log(`Usage:
  node compare.mjs --a <urlA> --b <urlB> --selector <css> --out <dir> [--selectorA <css>] [--selectorB <css>] [--viewport <w>x<h>]

Example:
  node compare.mjs --a https://motor-force.ru/ --b http://nginx/ --selector "#bx_eshop_wrap > header > .mf-nav" --out out/mf-nav --viewport 390x844
`);
}

function parseArgs(argv) {
  const args = {};
  for (let i = 2; i < argv.length; i++) {
    const k = argv[i];
    if (!k.startsWith('--')) continue;
    args[k.slice(2)] = argv[i + 1];
    i++;
  }
  return args;
}

function ensureDir(p) {
  fs.mkdirSync(p, { recursive: true });
}

function parseViewport(v) {
  if (!v) return { width: 390, height: 844 };
  const m = String(v).match(/^(\d+)x(\d+)$/);
  if (!m) return { width: 390, height: 844 };
  return { width: Number(m[1]), height: Number(m[2]) };
}

async function snap(page, url, selector, fileBase) {
  await page.goto(url, { waitUntil: 'domcontentloaded' });
  // Hide common overlays (chat/cookies) that break visual diffs.
  await page.addStyleTag({
    content: `
      #jivo_container,
      [id^="jivo"],
      .jivo-iframe-container,
      iframe[src*="jivo"],
      iframe[title*="Jivo"],
      .cc-window, .cookie, .cookie-consent, .cookies, .cookie-notice,
      [id*="cookie"], [class*="cookie"] {
        display: none !important;
        visibility: hidden !important;
        opacity: 0 !important;
      }
    `,
  });
  await page.waitForTimeout(500); // allow layout settle
  await page.waitForSelector(selector, { state: 'visible', timeout: 15000 });
  const el = await page.locator(selector).first();
  await el.scrollIntoViewIfNeeded();

  const html = await el.evaluate((node) => node.outerHTML);
  fs.writeFileSync(fileBase + '.html', html, 'utf8');

  await el.screenshot({ path: fileBase + '.png' });
}

function diffPng(aPath, bPath, outPath) {
  const img1 = PNG.sync.read(fs.readFileSync(aPath));
  const img2 = PNG.sync.read(fs.readFileSync(bPath));
  const w = Math.max(img1.width, img2.width);
  const h = Math.max(img1.height, img2.height);

  const a = new PNG({ width: w, height: h });
  const b = new PNG({ width: w, height: h });
  PNG.bitblt(img1, a, 0, 0, img1.width, img1.height, 0, 0);
  PNG.bitblt(img2, b, 0, 0, img2.width, img2.height, 0, 0);

  const diff = new PNG({ width: w, height: h });
  const mismatched = pixelmatch(a.data, b.data, diff.data, w, h, { threshold: 0.1 });
  fs.writeFileSync(outPath, PNG.sync.write(diff));
  return mismatched;
}

async function main() {
  const args = parseArgs(process.argv);
  const urlA = args.a;
  const urlB = args.b;
  const selector = args.selector;
  const selectorA = args.selectorA || selector;
  const selectorB = args.selectorB || selector;
  const outDir = args.out;
  const viewport = parseViewport(args.viewport);

  if (!urlA || !urlB || !selector || !outDir) {
    usage();
    process.exit(2);
  }

  ensureDir(outDir);

  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport });
  const page = await ctx.newPage();

  const baseA = path.join(outDir, 'a');
  const baseB = path.join(outDir, 'b');

  await snap(page, urlA, selectorA, baseA);
  await snap(page, urlB, selectorB, baseB);

  await browser.close();

  const mismatched = diffPng(baseA + '.png', baseB + '.png', path.join(outDir, 'diff.png'));
  console.log(`Saved:
  ${baseA}.png/.html
  ${baseB}.png/.html
  ${path.join(outDir, 'diff.png')}
Mismatched pixels: ${mismatched}`);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});

