/* Motor-Force premium redesign — GSAP animations.
   Подключается ПОСЛЕ загрузки gsap + ScrollTrigger.
   Все эффекты безопасные: если GSAP не загрузился, страница работает без анимаций.
*/
(function () {
	"use strict";

	function ready(fn) {
		if (document.readyState !== "loading") fn();
		else document.addEventListener("DOMContentLoaded", fn);
	}

	function hasGsap() {
		return typeof window.gsap !== "undefined";
	}

	function reduceMotion() {
		try { return window.matchMedia("(prefers-reduced-motion: reduce)").matches; }
		catch (_) { return false; }
	}

	ready(function () {
		if (!hasGsap() || reduceMotion()) {
			// graceful no-op: visuals already styled via CSS
			return;
		}

		var gsap = window.gsap;
		var ST = window.ScrollTrigger;
		if (ST && typeof gsap.registerPlugin === "function") gsap.registerPlugin(ST);

		// ---------- 1. Header reveal on load ----------
		gsap.from(".mf-header .mf-top", {
			y: -16, opacity: 0, duration: .6, ease: "power2.out"
		});
		gsap.from(".mf-header .mf-main > .container > .mf-main-inner > *", {
			y: -10, opacity: 0, duration: .6, stagger: .06, ease: "power2.out", delay: .1
		});

		// ---------- 2. Title bar reveal ----------
		var $title = document.querySelector(".mf-titlebar");
		if ($title) {
			gsap.from(".mf-pagetitle", {
				y: 32, opacity: 0, duration: .9, ease: "power3.out"
			});
		}

		// ---------- 3. Homepage slider — kenburns + content reveal ----------
		var slides = document.querySelectorAll(".main-page-slider .slider__item");
		slides.forEach(function (slide) {
			var bg = slide.querySelector(".slider-item__background");
			if (bg) {
				gsap.fromTo(bg,
					{ scale: 1.12 },
					{ scale: 1.0, duration: 14, ease: "power1.out", repeat: -1, yoyo: true }
				);
			}
		});

		gsap.from(".main-page-slider .slick-active .slider__user-content > *", {
			y: 30, opacity: 0, duration: 1, stagger: .08, ease: "power3.out", delay: .3
		});

		// ---------- 4. Reveal on scroll: cards / sections ----------
		if (ST) {
			var revealSelectors = [
				".widget-block",
				".widget__content_filled",
				".product-item",
				".bx_catalog_item",
				".product-item-container",
				".product-card",
				".search-result-card",
				".post-item",
				".blog-post-item",
				".bx-newslist-item",
				".mf-cat-grid > *",
				".mf-contacts-page .contact",
				".contacts__list .contact",
				".bx-soa-section",
				".basket-item",
				".bx-personal-account-list",
				".mf-faq-page .faq-item",
				".faq__item",
				".bx_login",
				".bx-auth"
			];

			document.querySelectorAll(revealSelectors.join(",")).forEach(function (el, idx) {
				gsap.from(el, {
					scrollTrigger: { trigger: el, start: "top 88%", toggleActions: "play none none none" },
					y: 28, opacity: 0, duration: .7, ease: "power2.out", delay: Math.min(idx * 0.04, 0.3)
				});
			});

			// title section parallax
			ST.create({
				trigger: ".mf-titlebar",
				start: "top top",
				end: "+=400",
				scrub: true,
				onUpdate: function (self) {
					var p = self.progress;
					gsap.to(".mf-titlebar", { backgroundPosition: "0 " + (p * 60) + "px", duration: 0 });
				}
			});

			// footer brand tag pulse on appearance
			var $footerBrand = document.querySelector(".mf-footer-brand");
			if ($footerBrand) {
				gsap.from($footerBrand, {
					scrollTrigger: { trigger: ".mf-footer", start: "top 90%" },
					y: 12, opacity: 0, duration: .6, ease: "power2.out"
				});
			}
		}

		// ---------- 5. Hover micro-interactions on product cards (extra) ----------
		var cardSelectors = ".bx_catalog_item, .product-item-container, .product-card, .post-item, .mf-card";
		document.querySelectorAll(cardSelectors).forEach(function (el) {
			el.addEventListener("mouseenter", function () {
				gsap.to(el, { y: -6, duration: .35, ease: "power2.out" });
			});
			el.addEventListener("mouseleave", function () {
				gsap.to(el, { y: 0, duration: .4, ease: "power2.out" });
			});
		});

		// ---------- 6. Buttons: subtle press feedback ----------
		document.querySelectorAll(".btn, .btn-primary, .add2basket, .mrb-btn-item, .bx-soa-button-set .btn").forEach(function (b) {
			b.addEventListener("mousedown", function () {
				gsap.to(b, { scale: 0.97, duration: .12, ease: "power2.out" });
			});
			b.addEventListener("mouseup", function () {
				gsap.to(b, { scale: 1.0, duration: .25, ease: "back.out(2)" });
			});
			b.addEventListener("mouseleave", function () {
				gsap.to(b, { scale: 1.0, duration: .2 });
			});
		});

		// ---------- 7. Category tiles — yellow glow follows pointer ----------
		document.querySelectorAll(".mf-cat-grid > *, .bx-catalog-section-list > *").forEach(function (tile) {
			tile.addEventListener("mousemove", function (e) {
				var r = tile.getBoundingClientRect();
				var x = ((e.clientX - r.left) / r.width) * 100;
				var y = ((e.clientY - r.top) / r.height) * 100;
				tile.style.background =
					"radial-gradient(360px 200px at " + x + "% " + y + "%, rgba(255,212,0,0.12), transparent 70%)," +
					"linear-gradient(180deg, var(--mf-navy-600), var(--mf-navy-700))";
			});
			tile.addEventListener("mouseleave", function () {
				tile.style.background = "";
			});
		});

		// ---------- 8. Smooth in-page anchor scroll ----------
		document.querySelectorAll('a[href^="#"]:not([href="#"])').forEach(function (a) {
			a.addEventListener("click", function (e) {
				var id = this.getAttribute("href");
				if (id.length < 2) return;
				var target = document.querySelector(id);
				if (!target) return;
				e.preventDefault();
				var top = target.getBoundingClientRect().top + window.pageYOffset - 80;
				window.scrollTo({ top: top, behavior: "smooth" });
			});
		});
	});
})();
