# Контекст проекта Motor-Force (Bitrix) — для продолжения на другом ПК

Этот файл фиксирует текущее состояние работ и ключевые решения, чтобы можно было быстро продолжить разработку на другом компьютере.

## Как поднять проект

В проекте используется Docker.

```bash
cd /Users/andrey/cursor_projects/bitrix/www
docker compose up -d
```

Контейнеры (из `www/docker-compose.yml`):
- `bitrix_nginx`
- `bitrix_php`
- `bitrix_mysql`

## Главная цель

Скопировать сайт `motor-force.ru` в Bitrix “1 в 1” (верстка/стили/функциональность), включая главную страницу с секциями (слайдер, поиск, магазин, форма, контакты, соцсети, рассылка) и страницу постов/новостей.

## Важные кастомные файлы (что уже сделано)

### 1) Шапка/подвал и общая раскладка

- `www/bitrix/templates/eshop_bootstrap_v4/header.php`
  - фиксированная шапка (Motor-Force-like)
  - жёлтый titlebar/хлебные включаются/выключаются через page properties
  - подключает:
    - `mf-header.css`, `mf-header.js`
    - `mf-footer.css`
    - `mf-mainpage.css`, `mf-mainpage.js` только на главной
    - `mf-posts.css` на `/posts/*`

- `www/bitrix/templates/eshop_bootstrap_v4/footer.php`
  - футер сверстан под оригинал motor-force.ru (без лишних старых блоков)

### 2) Главная страница

- `www/index.php`
  - собраны секции главной по мотивам motor-force.ru:
    - главный слайдер (100vh, автопереключение 5s)
    - поиск по каталогу
    - магазин (категории из `/products/`)
    - “Оставьте заявку” (фон, форма, ajax)
    - “Контакты” (иконки, карта)
    - “Мы в социальных сетях”
    - “Рассылка” (`bitrix:sender.subscribe` кастомный шаблон)
    - “Новости” (слайдер 5 последних)

### 3) Блок “Новости” на главной (слайдер)

- Компонент в `www/index.php`: `bitrix:news.list` с шаблоном `mf_main_posts_slider`
- Шаблон компонента:
  - `www/bitrix/templates/eshop_bootstrap_v4/components/bitrix/news.list/mf_main_posts_slider/template.php`
  - **теперь вывод текста новости как HTML**:
    - если `PREVIEW_TEXT_TYPE=html` → показываем превью как HTML
    - иначе берём `DETAIL_TEXT` (HTML) и показываем первый `<p>...</p>`

### 4) Раздел новостей: переезд `/news/` → `/posts/`

Сделан новый раздел `/posts/` с версткой под `https://motor-force.ru/posts`:

- `www/posts/index.php`
  - список новостей **по году** и “за всё время”
  - URL-шаблоны (как на оригинале):
    - `/posts/year/2025/1`
    - `/posts/year/all/1` (пагинация по 10)
  - справа календарь лет + ссылка “За все время”
  - увеличены отступы между новостями
  - убран внутренний `<header class="posts__header ...">` (дублировал заголовок)

- `www/posts/detail.php`
  - детальная новость (вёрстка под оригинал detail)

- `www/posts/rss/index.php`
  - RSS лента для `/posts/rss/` (генерируется вручную через API iblock)

- `www/posts/.section.php`

Маршрутизация:
- `www/urlrewrite.php`
  - `/posts/year/(all|YYYY)/(page)` → `/posts/index.php`
  - `/posts/{code}/` → `/posts/detail.php`

Редиректы:
- `www/news/index.php`
  - 301 `/news/*` → `/posts/*`

Ссылки обновлены:
- `www/.top.menu.php` и `www/.bottom.menu.php`: “Новости” → `/posts/`
- `www/index.php`: ссылки “Новости” и RSS → `/posts/`, `DETAIL_URL` → `/posts/#ELEMENT_CODE#/`

Стили страницы постов:
- `www/bitrix/templates/eshop_bootstrap_v4/mf-posts.css`
  - сетка “левая колонка новости / правая колонка календарь”
  - gap между колонками без паддингов (учтено, чтобы правая колонка не переносилась)
  - пагинация и календарь лет стилизованы под оригинал

## Хлебные крошки (Bitrix `.bx-breadcrumb`)

Хлебные крошки выводятся в `header.php` компонентом `bitrix:breadcrumb` шаблон `universal`.
Его дефолтные стили находятся в:
- `www/bitrix/components/bitrix/breadcrumb/templates/universal/style.css`

Переопределение стилей сделано в:
- `www/bitrix/templates/eshop_bootstrap_v4/mf-header.css`

Цели стилей:
- строго в ширине контейнера (без переполнения из-за `.row`)
- в одну линию (nowrap + ellipsis)
- вертикальное выравнивание текста в ссылке (inline-flex + line-height)

Если визуально “не меняется”, обычно причина — кеш CSS. Нужно очистить кеш Bitrix или принудительно обновить ассеты в браузере.

## Импорт новостей с motor-force.ru в Bitrix (152 шт.)

Скрипт:
- `www/mf_import_posts.php`

Что делает:
- парсит все страницы `https://motor-force.ru/posts/year/all/N` по пагинации
- вытаскивает:
  - дату (из `<time datetime>` или текста)
  - заголовок
  - текст: пытается взять тело детальной новости (`post-item__body`), иначе берёт “brief” из списка
- **удаляет** текущие элементы инфоблока `IBLOCK_ID=1`
- добавляет новые элементы в iblock:
  - `ACTIVE_FROM` = дата
  - `NAME` = заголовок
  - `DETAIL_TEXT` = HTML (если есть)
  - `PREVIEW_TEXT` = текстовый краткий (fallback)

Важно: запускать надо **внутри Docker контейнера `bitrix_php`**, потому что в `.settings.php` БД хост `mysql:3306` (доступен в docker-сети).

Dry-run:
```bash
docker exec bitrix_php php /var/www/html/mf_import_posts.php --dry-run
```

Импорт с удалением/загрузкой:
```bash
docker exec bitrix_php php /var/www/html/mf_import_posts.php --apply
```

## Что было важно по требованиям верстки

- “1 в 1” с `motor-force.ru`
- заголовки блоков по центру, контент внутри блоков чаще слева
- полноширинные секции через `.mf-breakout`
- слайдер главной строго `100vh`
- RSS и ссылки перенесены на `/posts/`

## Частые места правок

- Стили главной: `www/bitrix/templates/eshop_bootstrap_v4/mf-mainpage.css`
- JS главной: `www/bitrix/templates/eshop_bootstrap_v4/mf-mainpage.js`
- Стили шапки/крошек: `www/bitrix/templates/eshop_bootstrap_v4/mf-header.css`
- Стили постов: `www/bitrix/templates/eshop_bootstrap_v4/mf-posts.css`

## Дальше (если продолжать улучшение “1 в 1”)

- Доточить визуал `/posts/` под оригинал (типографика, отступы, поведение пагинации/календаря)
- Доточить детальную страницу `/posts/{code}/` (если нужны дополнительные блоки/ширины/шрифты как в оригинале)
- Если нужно: сделать импорт идемпотентным (обновлять/добавлять без полного удаления).

