# Парсер каталога pilotmoto.ru

Структура и БД — как у `tools/outdoorworld_catalog_scraper`: **SQLite** (возобновление), **`stock_and_price.csv`** (`Бренд;Артикул;Остаток;Цена`), опционально **`eternal_price.csv`**.

Два этапа: **(1)** полный BFS — со [страницы каталога](https://pilotmoto.ru/catalog/) берутся стартовые ссылки, затем с **каждой** страницы категории (листинг с `sort=1&view_type=1&qflt=0`) собираются все новые URL `/catalog/.../`, пока очередь не опустеет; **(2)** по полученному полному списку URL обходятся **все страницы пагинации** (`page_num=2`, `3`, …). Параметр **`qflt=0`** соответствует фильтру «Наличие: все» (не только «в наличии»). Дубликаты карточек в SQLite не плодятся: первичный ключ — `product_url`, при совпадении URL строка обновляется (`ON CONFLICT`).

**Данные с листинга** (без захода в карточку): в `div.block_with_img` — бренд `p.for_list.hidden_tab`, остаток `p.for_list.hidden_mob`, цена `p.price`, наименование `h3.title a`, **артикул** — сегмент пути после `/item/`. Число страниц: **max(`#pagescnt`, максимальный `page_num` в ссылках)** — если в интерфейсе «… 103», а `#pagescnt` меньше, важны ссылки пагинации (раньше учитывалось только поле `#pagescnt`).

Флаг `--max-categories` ограничивает только **число стартовых** ссылок с `/catalog/`; BFS всё равно может расширить список до полного дерева. Для полного пересбора после смены логики удалите `data/state.sqlite3`.

Особенности сайта:

- Листинг: `div.block_with_img`, ссылка на товар `h3.title a` или первый `a[href*="/item/"]`.
- Пагинация: `sort=1&view_type=1&qflt=0&page_num=N` (не Bitrix `PAGEN_*`).
- Доп. обход карточек: по умолчанию **выключен** (`detail_enrichment: false`); при необходимости — флаг **`--with-detail`** (остаток из модалки магазинов при включённом **`stock_modal_sum_enabled`**).
- Валюта — **рубли**. В схеме БД по-прежнему поле `price_kzt`, в нём хранится **числовая цена в ₽**; в `config.yaml` задано **`site_currency: RUB`**, экспорт умножает на **1.0** (без PHP/курса KZT).

## Установка

```bash
cd tools/pilotmoto_catalog_scraper
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
```

## Запуск

```bash
python run.py
```

Полезные флаги: `--max-categories N`, **`--with-detail`** (дополнительно карточки товара), `--skip-detail`, `--max-detail-products N`, `--export-only`, `--both-formats`.

Переменная окружения **`PILOTMOTO_CONFIG`** — путь к альтернативному `config.yaml`.
