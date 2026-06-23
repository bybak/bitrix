# Source: Megazip

Entry page:

```text
https://www.megazip.ru/
```

Megazip exposes indexable pages for OEM catalogs. Compared with ARI PartStream, Megazip is easier to crawl because the hierarchy and final part pages are present in HTML.

## First-Scope Categories

| Vehicle type | Example URL |
| --- | --- |
| motorcycle | `https://www.megazip.ru/zapchasti-dlya-motocyklov` |
| ATV | `https://www.megazip.ru/zapchasti-dlya-kvadrocyklov` |
| snowmobile | `https://www.megazip.ru/zapchasti-dlya-snegohodov` |
| PWC / jet ski | `https://www.megazip.ru/zapchasti-dlya-gidrociklov` |
| outboard | `https://www.megazip.ru/zapchasti-dlya-lodochnyh-motorov` |

Megazip also contains generators, stationary engines, and cars. They are out of scope for the first version.

## Observed Hierarchy

Example Yamaha motorcycle path:

```text
/zapchasti-dlya-motocyklov/yamaha
/zapchasti-dlya-motocyklov/yamaha/fz10-mtn1000-31042/fz10-mtn1000-46593
/zapchasti-dlya-motocyklov/yamaha/fz10-mtn1000-31042/fz10-mtn1000-46593/mtn1000-839279
/zapchasti-dlya-motocyklov/yamaha/fz10-mtn1000-31042/fz10-mtn1000-46593/mtn1000-839279/karter-dvigatelya-15735609
```

Meaning:

```text
vehicle type -> brand -> model family -> variant list -> concrete variant -> assembly page
```

## Brand Page

Example:

```text
https://www.megazip.ru/zapchasti-dlya-motocyklov/yamaha
```

The page lists model families and display names, for example:

```text
FZ10/ MTN1000
FZ-10
MT-10
MT-10 SP
```

The model family page can include filters by year, engine volume, and model.

## Variant Page

Example:

```text
https://www.megazip.ru/zapchasti-dlya-motocyklov/yamaha/fz10-mtn1000-31042/fz10-mtn1000-46593
```

Observed variant fields:

| Field | Example |
| --- | --- |
| source designation | `MTN1000` |
| model | `MT-10` |
| year | `2016` |
| model code | `B671` |
| region | `Европа` |
| region code | `050` |
| engine | `1000` |
| color | `DEEP PURPLISH BLUE METALLIC C` |
| color code | `B` |

Concrete variant pages list assemblies.

## Assembly List Page

Example:

```text
https://www.megazip.ru/zapchasti-dlya-motocyklov/yamaha/fz10-mtn1000-31042/fz10-mtn1000-46593/mtn1000-839279
```

Observed assemblies:

- `Головка цилиндра`
- `Цилиндр`
- `Распредвал & Цепь ГРМ`
- `Картер двигателя`
- `Рама`
- `Передний тормоз`
- `Электрооборудование 1`

Assembly URLs are source nodes and must be stored.

## Final Assembly Page

Example:

```text
https://www.megazip.ru/zapchasti-dlya-motocyklov/yamaha/fz10-mtn1000-31042/fz10-mtn1000-46593/mtn1000-839279/karter-dvigatelya-15735609
```

Observed title:

```text
Картер двигателя для Yamaha FZ10/ MTN1000 MT-10
```

Observed variant metadata:

```text
Год 2016
Цвет B
Код модели B671
Регион продаж Европа (050)
Объем двигателя 1000
Вариант окраса DEEP PURPLISH BLUE METALLIC C
Модель MT-10
```

## Diagram Image

Observed image:

```text
https://storage.megazip.ru/catalog/M/173/173df35b7f0dc148836d577fa29adaca.png
```

Observed dimensions:

```text
560 x 773
```

Store original URL, local path, checksum, width, height, and source id if available.

## Hotspots

Megazip uses HTML image maps:

```html
<area shape="rect" coords="186,292,204,310" data-items-list-id="365690767">
```

Fields to store:

- `shape`: `rect`, and possibly other HTML map shapes
- `coords`: raw coordinate string
- normalized numeric coordinates
- `data-items-list-id`: link to the row group in the parts list

One `items_list_id` can appear on multiple hotspots and can have multiple replacement rows.

## Part Rows

Megazip rows contain display table data and row-level JSON in attributes. Observed JSON fields:

```json
{
  "name": "Картер",
  "number": "B67-15100-09-00",
  "original_item_id": "10536561",
  "item_id": "10536561",
  "item_description": "",
  "itemslist_id": "365690767",
  "itemsset_description": "",
  "quantity": "1",
  "ref": "1",
  "manufacturer": "Yamaha",
  "tech_type": "Мотоцикл",
  "variant": "FZ10/ MTN1000",
  "search_url": "/zapchasti-dlya/yamaha/karter-B67151000900",
  "request_allowed": false,
  "original": [
    {
      "price_id": "4670197948",
      "price": 270270,
      "base_price": 270270,
      "shipper": "Склад Японии",
      "min_qty": "1",
      "handling": "3...7 раб. дн."
    }
  ]
}
```

Source price offers should be stored only as optional snapshots. The production selling price must come from our own Bitrix data.

## Replacement Rows

Megazip marks replacement groups with labels like:

```text
замена
замены
```

Rows after such delimiter can share `original_item_id` with a different `item_id`. Store them as `part_relations`:

```text
original -> replacement
```

Do not collapse replacement rows into the original part.

## Parser Notes

Recommended implementation:

1. Crawl category brand pages for model family URLs.
2. Crawl model family pages for concrete variant URLs and metadata.
3. Crawl variant pages for assembly URLs.
4. Crawl assembly pages for diagram image, image-map coordinates, and row JSON.
5. Preserve raw row JSON for audit and parser upgrades.
6. Normalize OEM numbers by removing non-alphanumeric separators but keep original formatting.

## Open Research Items

- Confirm if every final assembly page has row-level JSON attributes or if older pages require table-only parsing.
- Confirm all possible HTML map shapes.
- Confirm availability of high-resolution diagram images.
- Confirm throttling limits and whether sitemap/index pages can reduce crawling load.
