# Парсер каталога outdoorworld.kz

Сбор **всех товаров** с [outdoorworld.kz/catalog/](https://outdoorworld.kz/catalog/), **кроме** разделов:

- `Техника` → `/catalog/tekhnika/`
- `Услуги` → `/catalog/uslugi/`
- `Сервис` → `/catalog/servis_1/`

Результат: **SQLite** (возобновление) + **CSV**.

## Форматы CSV

1. **`stock_and_price.csv` (по умолчанию)** — `Бренд;Артикул;Остаток;Цена`  
   - **Бренд** и **Артикул** — с плитки каталога (блок `.i_info_block`, пары «Бренд» / «Артикул»), при отсутствии — из `span.i_article_item`. Отдельный обход `/product/.../` **не обязателен** (`detail_enrichment: false` по умолчанию).  
   - **Цена** — в **рублях**: число в KZT с **листинга** (`span.i_price` → `price_kzt`), пересчёт через **`CCurrencyRates::ConvertCurrency(..., 'KZT', 'RUB')`**. При **`--with-detail`** или `detail_enrichment: true` поля можно уточнять с карточки (JSON-LD).  
   - **Остаток** — с плитки: подпись `span.i_quan_text` и класс `*_stack` на `span.i_quan_sl` → число через `stock_text_map` / `stock_stack_map` в `config.yaml`. Опционально атрибут **`data-quan`** у `.i_buy_succes` (флаг `listing_prefer_data_quan`).  
   Путь выхода: `output_csv_alley` (по умолчанию `data/stock_and_price.csv`).

2. **`eternal_price.csv`** (опционально, флаг `--both-formats`) — `Производитель;Артикул;Наименование;Цена`  
   - **Цена** — в **рублях** по тому же правилу: **`price_kzt`** (список или карточка), иначе парсинг **`price_raw`**.  
   Путь: `output_csv` (по умолчанию `data/eternal_price.csv`).

Если курс KZT→RUB из Bitrix недоступен, задайте **`fallback_rub_per_kzt`**, иначе в `stock_and_price` колонка «Цена» будет пустой.

В админке должны быть валюты **KZT** и **RUB** и задан курс между ними; иначе `ConvertCurrency` может не дать ожидаемый множитель.

### Курс из Bitrix при Docker (ошибка `getaddrinfo for mysql`)

В `.settings.php` хост БД часто **`mysql`** — с **Mac** он не резолвится. Варианты:

1. **Рекомендуется:** в `config.yaml` поставить **`bitrix_kzt_use_docker: true`**, запустить контейнеры (`docker compose up -d`). Python вызовет  
   `docker compose exec -T php php /var/www/html/local/php_interface/mf_kzt_to_rub_cli.php …` из каталога с вашим `docker-compose.yml` (обычно корень репозитория, где лежат `www/` и `tools/`).

2. Проверка вручную:  
   `docker compose exec php php /var/www/html/local/php_interface/mf_kzt_to_rub_cli.php 1000000`  
   — в ответ одна строка JSON с `rub_per_kzt`.

3. Локальный `php` на Mac — только если MySQL доступен с хоста (проброс порта `3306`, в настройках хост `127.0.0.1`), и **`bitrix_kzt_use_docker: false`**.

## Требования

- Python **3.10+**
- Доступ в интернет к `outdoorworld.kz`
- Для пересчёта цены в ₽: PHP + рабочая БД Bitrix (как в проде), либо только `fallback_rub_per_kzt`

## Установка

```bash
cd tools/outdoorworld_catalog_scraper
python3 -m venv .venv
source .venv/bin/activate   # Windows: .venv\Scripts\activate
pip install -r requirements.txt
```

`playwright` в `requirements.txt` для опционального расширения (рендер JS); **по умолчанию** используется **httpx** — страницы каталога отдаются статически.

## Запуск

```bash
python run.py
```

- Сначала обход **категорий** (плитка `.i_item` — бренд, артикул, цена KZT, остаток). **Карточки `/product/` не запрашиваются**, если в `config.yaml` не задано `detail_enrichment: true` и не передан **`--with-detail`**.
- Прогресс: tqdm по категориям; при включённом обогащении — второй бар по карточкам.
- По умолчанию пересобирается CSV **остаток+цена** (`data/stock_and_price.csv`).

Дополнительно сохранить **легаси**-CSV:

```bash
python run.py --both-formats
```

Загрузить страницы `/product/` и перезаписать поля с детальной (дороже по запросам):

```bash
python run.py --with-detail
```

Явно **не** ходить в `/product/`:

```bash
python run.py --skip-detail
```

Только выгрузить CSV из уже заполненной БД:

```bash
python run.py --export-only
```

## Возобновление после сбоя

Состояние хранится в **`data/state.sqlite3`** (путь задаётся в `config.yaml`):

- `category_meta` — число страниц и параметр `PAGEN_*` Bitrix.
- `category_pages` — какие страницы категории уже скачаны.
- `products` — товары (по `product_url`), поле **`detail_done`**: после обхода листинга выставляется **1** (данные с витрины достаточны). Если включён этап карточек — при успехе снова **1**.

Повторный запуск **`python run.py`** пропускает уже завершённые **страницы** каталога. При **`--with-detail`** к обходу попадут только товары с `detail_done=0` (если такие остались).

После обновления скрипта (новые поля в карточке) можно перезапустить обогащение для всех товаров:

```sql
UPDATE products SET detail_done=0;
```

Удалите `data/state.sqlite3`, чтобы начать с нуля.

## Настройка (`config.yaml`)

| Параметр | Смысл |
|----------|--------|
| `request_delay_sec` | Пауза между запросами (снижает нагрузку на сайт). |
| `http2` | `false` по умолчанию: HTTP/2 у части сайтов обрывается с `RemoteProtocolError`. |
| `http_max_retries` / `http_retry_delay_sec` | Повтор запроса при временных сетевых ошибках. |
| `max_concurrent_requests` | Одновременные запросы (не ставьте слишком большим). |
| `excluded_path_prefixes` | Префиксы URL категорий, которые не обходятся. |
| `output_csv_alley` | Путь CSV «остаток и цена» (по умолчанию `data/stock_and_price.csv`). |
| `output_csv` | Путь CSV с наименованием (при `--both-formats`, по умолчанию `data/eternal_price.csv`). |
| `stock_text_map` / `stock_stack_map` / `stock_default_instock` | Перевод текстовых остатков в число для `stock_and_price`. |
| `fallback_rub_per_kzt` | Рублей за 1 KZT, если Bitrix недоступен. |
| `bitrix_kzt_use_docker` | `true` — курс через `docker compose exec php` (скрипт `www/bitrix/php_interface/mf_kzt_to_rub_cli.php`). Нужен запущенный Docker и каталог с `docker-compose.yml` (по умолчанию — корень репозитория над `tools/`). |
| `bitrix_docker_compose_dir` | Явный путь к каталогу с `docker-compose.yml`, если не угадан автоматически. |
| `bitrix_php_binary` | Если `bitrix_kzt_use_docker: false`: локальный `php` и доступ к MySQL с хоста. |
| `bitrix_kzt_script` | Явный путь к PHP-скрипту курса. |

## Docker

```bash
docker compose build
docker compose run --rm outdoorworld-scraper
```

Каталог `data/` монтируется в контейнер — БД и CSV сохраняются на хосте. Для курса из Bitrix внутри контейнера нужен доступ к БД и тот же `mf_kzt_to_rub.php`, либо используйте `fallback_rub_per_kzt`.

## Ограничения

- При сетевых сбоях (`RemoteProtocolError` и т.п.) запросы **повторяются**; при необходимости увеличьте `http_max_retries` или уменьшите `max_concurrent_requests`.

- Цена и название в первой фазе берутся **со страницы списка**; «Нет цены» попадает в колонку «Цена» легаси как есть.
- **Точного числового остатка** на outdoorworld.kz в HTML/JSON-LD нет — только уровни; «Остаток» в `stock_and_price` задаётся маппингом и эвристиками (см. выше).
- До обхода карточки артикул дублируется из текста названия по `Артикул:`; после фазы детальной — из таблицы характеристик, если указан.
- Если у товара нет заполненных характеристик на сайте («Характеристики на стадии заполнения»), **Бренд** в CSV может быть пустым; повторный парсинг не исправит это, пока данные не появятся на источнике.
- Уважайте [robots.txt](https://outdoorworld.kz/robots.txt) и нагрузку на чужой сайт: при необходимости увеличьте `request_delay_sec` и уменьшите `max_concurrent_requests`.

## Структура

```
tools/outdoorworld_catalog_scraper/
  config.yaml
  run.py
  mf_kzt_to_rub.php      # KZT→RUB через Bitrix (mf_ep_bitrix_convert_to_rub)
  requirements.txt
  Dockerfile
  docker-compose.yml
  README.md
  outdoorworld_scraper/
    config.py
    db.py
    parse.py
    crawl.py
    export_csv.py
  data/                 # создаётся при запуске; в .gitignore
    state.sqlite3
    stock_and_price.csv
    eternal_price.csv
```
