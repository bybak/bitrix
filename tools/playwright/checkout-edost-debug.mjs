import { chromium } from '@playwright/test';

const BASE = process.env.MF_BASE || 'http://127.0.0.1';

(async () => {
	const browser = await chromium.launch();
	const ctx = await browser.newContext({
		viewport: { width: 414, height: 900 },
		userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
	});
	const page = await ctx.newPage();
	const errors = [];
	page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
	page.on('console', (msg) => {
		if (msg.type() === 'error')
			errors.push('console: ' + msg.text());
	});

	await page.goto(`${BASE}/products/brp113004/`, { waitUntil: 'domcontentloaded' });
	await page.waitForTimeout(2000);
	await page.locator('text=В корзину').first().click({ timeout: 10000 });
	await page.waitForTimeout(2000);

	await page.goto(`${BASE}/personal/order/make/`, { waitUntil: 'domcontentloaded' });
	await page.waitForTimeout(5000);

	console.log('url after order/make', page.url());

	if (!page.url().includes('order/make'))
	{
		console.log('Not on checkout, abort');
		await browser.close();
		process.exit(1);
	}

	const boot = await page.evaluate(() => ({
		hasBX: !!window.BX,
		hasSaleOrderAjax: !!(window.BX && BX.saleOrderAjax),
		hasMfEdost: !!(window.BX?.saleOrderAjax?.__mfEdost),
		hasFindAnchor: !!(window.BX?.saleOrderAjax?.__mfBuyerAddress?.findDeliveryLocationAnchor),
		mfCheckout: window.BX?.Sale?.OrderAjaxComponent?.result?.MF_CHECKOUT || null,
		mfBoot: window.MF_CHECKOUT_BOOT || null,
		scriptSrc: Array.from(document.querySelectorAll('script[src*="script.js"]')).map(s => s.src).filter(u => u.includes('sale.order.ajax')),
	}));

	console.log('boot', JSON.stringify(boot, null, 2));

	const next = page.locator('#bx-soa-basket .btn-primary').first();
	console.log('basket next count', await next.count());
	if (await next.count())
	{
		await next.click();
		await page.waitForTimeout(3000);
	}

	let state = await page.evaluate(() => {
		const del = document.querySelector('#bx-soa-delivery');
		const content = del?.querySelector('.bx-soa-section-content');
		return {
			activeSectionId: window.BX?.Sale?.OrderAjaxComponent?.activeSectionId,
			deliverySelected: del?.classList.contains('bx-selected'),
			contentDisplay: content ? getComputedStyle(content).display : null,
			hasLocCol: !!del?.querySelector('.bx_soa_location .col'),
			edostBox: !!document.querySelector('#mf-edost-box'),
			nominatim: !!document.querySelector('#mf-nominatim-wrap'),
			mfEdostStyle: !!document.getElementById('mf-edost-style'),
			text: content?.innerText?.slice(0, 600) || '',
			contentChildIds: content ? Array.from(content.children).slice(0, 8).map(n => n.id || n.className?.toString()?.slice(0, 40)) : [],
		};
	});
	console.log('after basket next', JSON.stringify(state, null, 2));

	await page.evaluate(() => {
		try { BX.Sale?.OrderAjaxComponent?.mfTriggerEdostDeliveryInit?.(0); } catch (e) {}
		try { BX.saleOrderAjax?.__mfEdost?.onEnterDelivery?.(true); } catch (e) {}
	});
	await page.waitForTimeout(1500);

	state = await page.evaluate(() => {
		const del = document.querySelector('#bx-soa-delivery');
		const content = del?.querySelector('.bx-soa-section-content');
		return {
			edostBox: !!document.querySelector('#mf-edost-box'),
			nominatim: !!document.querySelector('#mf-nominatim-wrap'),
			text: content?.innerText?.slice(0, 800) || '',
		};
	});
	console.log('after force init', JSON.stringify(state, null, 2));

	if (errors.length)
		console.log('errors sample', errors.slice(0, 8));

	await page.screenshot({ path: 'tools/playwright/out/checkout-edost-mobile.png', fullPage: true });
	await browser.close();
})();
