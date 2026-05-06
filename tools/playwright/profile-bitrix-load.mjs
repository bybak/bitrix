/**
 * Профиль загрузки сайта: навигация, TTFB HTML, тяжёлые ресурсы.
 * Запуск: node profile-bitrix-load.mjs [baseUrl]
 */
import { chromium } from "playwright";

const base = process.argv[2] || "http://new-motor-force.ru";

const paths = [
  "/",
  "/contacts/",
  "/search/",
];

function ms(n) {
  return n == null || !Number.isFinite(n) ? "—" : `${Math.round(n)}ms`;
}

async function profilePath(browser, path) {
  const url = new URL(path, base).href;
  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    userAgent:
      "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/120 Safari/537.36",
  });
  const page = await context.newPage();

  /** @type {{ url: string, status: number, resourceType: string, dns: number, connect: number, ssl: number, ttfb: number, download: number, total: number }[]} */
  const rows = [];

  page.on("requestfinished", async (request) => {
    try {
      const t = await request.timing();
      const resp = await request.response();
      const status = resp?.status() ?? 0;
      const total = t.responseEnd;
      rows.push({
        url: request.url().slice(0, 120),
        status,
        resourceType: request.resourceType(),
        dns: t.domainLookupEnd - t.domainLookupStart,
        connect: t.connectEnd - t.connectStart,
        ssl: t.connectEnd - t.secureConnectionStart > 0 ? t.connectEnd - t.secureConnectionStart : 0,
        ttfb: t.responseStart - t.requestStart,
        download: t.responseEnd - t.responseStart,
        total,
      });
    } catch {
      /* ignore */
    }
  });

  const t0 = Date.now();
  const nav = await page.goto(url, {
    waitUntil: "load",
    timeout: 120000,
  });
  const loadMs = Date.now() - t0;

  const navEntry = await page.evaluate(() => {
    const n = performance.getEntriesByType("navigation")[0];
    if (!n) return null;
    return {
      domContentLoaded: n.domContentLoadedEventEnd,
      loadEventEnd: n.loadEventEnd,
      responseStart: n.responseStart,
      transferSize: n.transferSize,
      encodedBodySize: n.encodedBodySize,
      redirectCount: n.redirectCount,
    };
  });

  // Дождаться «затихания» сети (часто долго на Битрикс из-за фоновых запросов)
  const tIdle0 = Date.now();
  try {
    await page.waitForLoadState("networkidle", { timeout: 45000 });
  } catch {
    /* timeout — зафиксируем */
  }
  const networkIdleExtra = Date.now() - tIdle0;

  await context.close();

  rows.sort((a, b) => b.total - a.total);

  return {
    url,
    httpStatus: nav?.status() ?? 0,
    loadMs,
    networkIdleExtraMs: networkIdleExtra,
    navEntry,
    topResources: rows.slice(0, 25),
    resourceCount: rows.length,
  };
}

const browser = await chromium.launch({ headless: true });

console.log("Base:", base);
console.log("—".repeat(80));

for (const p of paths) {
  const r = await profilePath(browser, p);
  console.log("\n##", r.url);
  console.log("HTTP:", r.httpStatus, "| load (waitUntil=load):", ms(r.loadMs));
  console.log(
    "networkidle wait extra:",
    ms(r.networkIdleExtraMs),
    r.networkIdleExtraMs >= 45000 ? "(timeout 45s, не затихло)" : ""
  );
  if (r.navEntry) {
    console.log(
      "Navigation timing: TTFB (responseStart):",
      ms(r.navEntry.responseStart),
      "| domContentLoaded:",
      ms(r.navEntry.domContentLoaded),
      "| loadEventEnd:",
      ms(r.navEntry.loadEventEnd),
      "| redirects:",
      r.navEntry.redirectCount
    );
  }
  console.log("Ресурсов:", r.resourceCount);
  console.log("\nТоп по полному времени запроса (resource timing):");
  for (const row of r.topResources) {
    console.log(
      `  ${ms(row.total).padStart(7)}  ${row.resourceType.padEnd(8)}  ${row.status}  ttfb:${ms(row.ttfb)}  ${row.url}`
    );
  }
}

await browser.close();
console.log("\nГотово.");
