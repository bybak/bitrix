// Run: node tools/playwright/mf-snap-pages.mjs
// Captures full-page screenshots + extracts headings/font/colour issues for review.

import { chromium } from '@playwright/test';
import fs from 'node:fs/promises';
import path from 'node:path';

const BASE = process.env.MF_BASE || 'http://127.0.0.1';
const OUT_DIR = path.resolve('tools/playwright/out/mf-snap');

const ROUTES = [
	{ slug: 'home', url: '/' },
	{ slug: 'faq', url: '/faq/' },
	{ slug: 'delivery', url: '/delivery/' },
	{ slug: 'oplata', url: '/oplata/' },
	{ slug: 'documents', url: '/documents/' },
	{ slug: 'sotrudnichestvo', url: '/sotrudnichestvo/' },
	{ slug: 'remont_motorov', url: '/remont_motorov/' },
	{ slug: 'prokat', url: '/prokat/' },
	{ slug: 'vikup_mototehniki', url: '/vikup_mototehniki/' },
	{ slug: 'contacts', url: '/contacts/' },
	{ slug: 'about', url: '/about/' },
	{ slug: 'oferta', url: '/oferta/' },
	{ slug: 'dogovor-oferti', url: '/dogovor-oferti/' },
	{ slug: 'login', url: '/login/' },
	{ slug: 'register', url: '/login/?register=yes' },
	{ slug: 'search-empty', url: '/search/' },
	{ slug: 'search-result', url: '/search/?q=ремень' },
	{ slug: 'products', url: '/products/' },
	{ slug: 'category-quad', url: '/products/category/zapchasti-dlya-kvadrotsiklov/' },
	{ slug: '404', url: '/__definitely_not_existing__/' },
];

const VIEWPORTS = [
	{ name: 'desktop', width: 1440, height: 900 },
	{ name: 'mobile', width: 414, height: 900 },
];

(async () => {
	await fs.mkdir(OUT_DIR, { recursive: true });
	const browser = await chromium.launch();

	const report = {
		base: BASE,
		generatedAt: new Date().toISOString(),
		results: [],
	};

	for (const vp of VIEWPORTS) {
		const ctx = await browser.newContext({
			viewport: { width: vp.width, height: vp.height },
			userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 13_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 MFcheck',
			deviceScaleFactor: 1,
			reducedMotion: 'reduce',
		});
		const page = await ctx.newPage();
		page.setDefaultTimeout(30000);

		for (const r of ROUTES) {
			const slug = `${r.slug}-${vp.name}`;
			let entry = { route: r.url, slug, viewport: vp.name, status: 'ok' };
			try {
				const resp = await page.goto(`${BASE}${r.url}`, { waitUntil: 'domcontentloaded' });
				entry.httpStatus = resp ? resp.status() : null;
				try { await page.waitForLoadState('networkidle', { timeout: 8000 }); } catch (e) {}
				await page.waitForTimeout(300);
				const file = path.join(OUT_DIR, `${slug}.png`);
				await page.screenshot({ path: file, fullPage: true });
				entry.screenshot = path.relative(process.cwd(), file);

				// audit
				entry.audit = await page.evaluate(() => {
					const out = {
						title: document.title,
						h1Count: document.querySelectorAll('h1').length,
						h1: Array.from(document.querySelectorAll('h1')).slice(0, 3).map(h => (h.textContent || '').trim().slice(0, 80)),
						hasMfRedesign: !!document.querySelector('link[href*="mf-redesign.css"]'),
						hasMfHomeCss: !!document.querySelector('link[href*="mf-home.css"]'),
						hasMfPersonalCss: !!document.querySelector('link[href*="mf-personal.css"]'),
						hasMfAuthCss: !!document.querySelector('link[href*="mf-auth.css"]'),
					};

					// find low-contrast headings (white on white) -- buggy hero/footer
					const issues = [];
					const checkEls = Array.from(document.querySelectorAll('h1, h2, h3, h4, h5, h6, p, span, div'));
					for (const el of checkEls) {
						const r = el.getBoundingClientRect();
						if (r.width < 100 || r.height < 12) continue;
						const text = (el.textContent || '').trim();
						if (!text || text.length < 3) continue;
						const cs = getComputedStyle(el);
						const bg = cs.backgroundColor;
						const color = cs.color;
						// crude same-color check (text == background)
						if (color && bg && color === bg && bg !== 'rgba(0, 0, 0, 0)') {
							issues.push({ kind: 'same-color', tag: el.tagName, text: text.slice(0, 60), color, bg });
							if (issues.length > 30) break;
						}
					}
					out.issuesSample = issues.slice(0, 10);

					// horizontal scroll check
					out.horizontalScroll = document.documentElement.scrollWidth > document.documentElement.clientWidth + 1;
					out.scrollWidth = document.documentElement.scrollWidth;
					out.clientWidth = document.documentElement.clientWidth;

					// detect if double numbering present in lists with counters
					const dblCount = Array.from(document.querySelectorAll('ol.mf-cooperation-steps, ol.mf-repair-list, ol.mf-faq-steps, ol.mf-buyout-list')).filter(ol => {
						const ls = getComputedStyle(ol).listStyleType;
						return ls && ls !== 'none' && ls !== '""' && ls !== 'initial';
					}).map(ol => ol.className.split(' ')[0]);
					out.doubleNumberedLists = dblCount;

					return out;
				});
			} catch (err) {
				entry.status = 'error';
				entry.error = String(err && err.message || err);
			}
			report.results.push(entry);
			console.log(`${vp.name.padEnd(8)} ${r.url.padEnd(48)} ${entry.httpStatus ?? '?'} ${entry.status === 'ok' ? '' : entry.status}`);
		}

		await ctx.close();
	}

	await browser.close();
	await fs.writeFile(path.join(OUT_DIR, 'report.json'), JSON.stringify(report, null, 2), 'utf8');
	console.log(`\nReport: ${path.join(OUT_DIR, 'report.json')}`);
})();
