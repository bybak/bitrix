## Playwright сравнение 1в1 (скрин + HTML + diff)

Зачем: автоматизировать “1в1” — сравнивать то, что сделано локально, с оригиналом `motor-force.ru` по конкретному селектору (моб/десктоп).

### Быстрый старт

Собрать образ (один раз):

```bash
tools/playwright/run.sh build
```

Сравнить селектор на мобиле (пример для нижней панели):

```bash
tools/playwright/run.sh compare \
  --a "https://motor-force.ru/contacts" \
  --b "http://host.docker.internal/contacts/" \
  --selector "#bx_eshop_wrap > header > div.mf-nav" \
  --out "/out/mf-nav-mobile" \
  --viewport 390x844
```

Результаты:
- `tools/playwright/out/<name>/a.png` + `a.html` — оригинал
- `tools/playwright/out/<name>/b.png` + `b.html` — локально
- `tools/playwright/out/<name>/diff.png` — diff картинка

