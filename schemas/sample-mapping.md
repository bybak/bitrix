# Sample Mapping

This document maps two researched source pages into the proposed database model.

## Sample 1: RE Motors / ARI PartStream

Source path:

```text
https://remotors.fi/eng/partfinder?aribrand=KTM
brand: KTM
year: 2025
model/source node: 125 SX CHASSIS - 2025
assembly: AIR FILTER
```

Final browser URL after opening assembly:

```text
https://remotors.fi/eng/partfinder?aribrand=KTM#/KTM/125_SX_CHASSIS_-_2025/AIR_FILTER/7ead2eca-13fc-4fe9-bf0f-a462482059b5/125_SX_CHASSIS_-_2025_AIR_FILTER/y
```

### Source Nodes

#### Brand Node

```json
{
  "source": "remotors_ari",
  "node_type": "brand",
  "title": "KTM",
  "arib": "KTM",
  "external_id": "KTM"
}
```

#### Year Node

```json
{
  "source": "remotors_ari",
  "node_type": "year",
  "title": "2025",
  "arib": "KTM",
  "aria": "FjrcdAkxl-KsENAdRROGDw2",
  "external_id": "KTM:FjrcdAkxl-KsENAdRROGDw2"
}
```

#### Model / Source Variant Node

```json
{
  "source": "remotors_ari",
  "node_type": "model_node",
  "title": "125 SX CHASSIS - 2025",
  "arib": "KTM",
  "aria": "GZDDNRKn6O8LUxsJsTksvA2",
  "external_id": "KTM:GZDDNRKn6O8LUxsJsTksvA2"
}
```

Suggested canonical mapping:

```json
{
  "vehicle_type": "motorcycle",
  "brand": "KTM",
  "model_family": "125 SX",
  "vehicle_variant": {
    "year_from": 2025,
    "year_to": 2025,
    "market_name": "125 SX",
    "source_designation": "125 SX CHASSIS - 2025",
    "variant_section": "chassis"
  }
}
```

#### Assembly Node

```json
{
  "source": "remotors_ari",
  "node_type": "assembly",
  "title": "AIR FILTER",
  "arib": "KTM",
  "aria": "DV9TqbDDXS-lVhCqylBvuA2",
  "slug": "/KTM/125_SX_CHASSIS_-_2025/AIR_FILTER/7ead2eca-13fc-4fe9-bf0f-a462482059b5/125_SX_CHASSIS_-_2025_AIR_FILTER",
  "source_url": "https://remotors.fi/eng/partfinder?aribrand=KTM#/KTM/125_SX_CHASSIS_-_2025/AIR_FILTER/7ead2eca-13fc-4fe9-bf0f-a462482059b5/125_SX_CHASSIS_-_2025_AIR_FILTER/y",
  "external_id": "KTM:DV9TqbDDXS-lVhCqylBvuA2"
}
```

### Diagram

Observed thumbnail for assembly card:

```text
https://cdn.datamanager.arinet.com/image/KTM/e37a9b39-6477-4184-bb74-79f7b813cf5c/Small
```

The final diagram image should be captured from `#ariPartImage` during the part pass. Store:

```json
{
  "original_url": "https://cdn.datamanager.arinet.com/image/KTM/e37a9b39-6477-4184-bb74-79f7b813cf5c/Small",
  "source_image_id": "e37a9b39-6477-4184-bb74-79f7b813cf5c",
  "local_path": "storage/oem-diagrams/remotors_ari/KTM/{source_node_id}/{checksum}.png"
}
```

### Hotspot Example

Observed ARI hotspot style:

```html
<div
  class="ariHotSpot"
  style="position: absolute; top: 20px; left: 161px; width: 14px; height: 13px;"
  coords="...">
</div>
```

Mapped record:

```json
{
  "shape": "rect",
  "raw_coords": "position:absolute;top:20px;left:161px;width:14px;height:13px",
  "x": 161,
  "y": 20,
  "width": 14,
  "height": 13
}
```

### Part Rows

Observed visible rows:

| Ref | OEM | Name | Source price |
| --- | --- | --- | --- |
| 1 | `A46006001000EB` | Lower section of the air filter | `€ 45,97` |
| 2 | `A46006004000EB` | Air filter panel | `€ 33,27` |
| 3 | `A46006003000EB` | Air filter cover | `€ 33,27` |
| 16 | `A46006015000` | Air filter | `€ 23,05` |

Mapped `oem_parts` example:

```json
{
  "manufacturer": "KTM",
  "part_number": "A46006001000EB",
  "normalized_part_number": "A46006001000EB",
  "name": "Lower section of the air filter"
}
```

Mapped `oem_assembly_parts` example:

```json
{
  "ref": "1",
  "quantity": null,
  "row_kind": "original",
  "part_number": "A46006001000EB",
  "source_row_id": "KTM:DV9TqbDDXS-lVhCqylBvuA2:ref:1"
}
```

ARI visible rows did not expose quantity in the sampled text, so quantity remains nullable until deeper tooltip/API parsing confirms it.

## Sample 2: Megazip

Source path:

```text
vehicle type: motorcycle
brand: Yamaha
model family: FZ10/ MTN1000
variant: MTN1000 MT-10, 2016, Europe 050, color B
assembly: Картер двигателя
```

Final URL:

```text
https://www.megazip.ru/zapchasti-dlya-motocyklov/yamaha/fz10-mtn1000-31042/fz10-mtn1000-46593/mtn1000-839279/karter-dvigatelya-15735609
```

### Source Nodes

#### Vehicle Type Node

```json
{
  "source": "megazip",
  "node_type": "vehicle_type",
  "title": "Мотоцикл",
  "url_path": "/zapchasti-dlya-motocyklov",
  "external_id": "megazip:/zapchasti-dlya-motocyklov"
}
```

#### Brand Node

```json
{
  "source": "megazip",
  "node_type": "brand",
  "title": "Yamaha",
  "url_path": "/zapchasti-dlya-motocyklov/yamaha",
  "external_id": "megazip:/zapchasti-dlya-motocyklov/yamaha"
}
```

#### Model Family Node

```json
{
  "source": "megazip",
  "node_type": "model_family",
  "title": "FZ10/ MTN1000",
  "url_path": "/zapchasti-dlya-motocyklov/yamaha/fz10-mtn1000-31042/fz10-mtn1000-46593",
  "external_id": "megazip:model-family:46593"
}
```

Suggested canonical mapping:

```json
{
  "vehicle_type": "motorcycle",
  "brand": "Yamaha",
  "model_family": "MT-10",
  "aliases": ["FZ10/ MTN1000", "MTN1000", "FZ-10"]
}
```

#### Variant Node

```json
{
  "source": "megazip",
  "node_type": "variant",
  "title": "MTN1000 MT-10 B Европа (050) 2016",
  "url_path": "/zapchasti-dlya-motocyklov/yamaha/fz10-mtn1000-31042/fz10-mtn1000-46593/mtn1000-839279",
  "external_id": "megazip:variant:839279"
}
```

Mapped `oem_vehicle_variants`:

```json
{
  "year_from": 2016,
  "year_to": 2016,
  "model_code": "B671",
  "region": "Европа",
  "region_code": "050",
  "color_code": "B",
  "color_name": "DEEP PURPLISH BLUE METALLIC C",
  "engine_cc": 1000,
  "market_name": "MT-10",
  "source_designation": "MTN1000"
}
```

#### Assembly Node

```json
{
  "source": "megazip",
  "node_type": "assembly",
  "title": "Картер двигателя",
  "url_path": "/zapchasti-dlya-motocyklov/yamaha/fz10-mtn1000-31042/fz10-mtn1000-46593/mtn1000-839279/karter-dvigatelya-15735609",
  "external_id": "megazip:assembly:15735609"
}
```

### Diagram

Observed image:

```text
https://storage.megazip.ru/catalog/M/173/173df35b7f0dc148836d577fa29adaca.png
```

Observed dimensions:

```text
560 x 773
```

Mapped record:

```json
{
  "original_url": "https://storage.megazip.ru/catalog/M/173/173df35b7f0dc148836d577fa29adaca.png",
  "source_image_id": "173df35b7f0dc148836d577fa29adaca",
  "width": 560,
  "height": 773,
  "local_path": "storage/oem-diagrams/megazip/Yamaha/{source_node_id}/{checksum}.png"
}
```

### Hotspot Example

Observed image map:

```html
<area shape="rect" coords="186,292,204,310" data-items-list-id="365690767">
```

Mapped record:

```json
{
  "shape": "rect",
  "raw_coords": "186,292,204,310",
  "x": 186,
  "y": 292,
  "width": 18,
  "height": 18,
  "source_items_list_id": "365690767"
}
```

### Part Row Example

Observed row JSON:

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
  "request_allowed": false
}
```

Mapped `oem_parts`:

```json
{
  "manufacturer": "Yamaha",
  "part_number": "B67-15100-09-00",
  "normalized_part_number": "B67151000900",
  "name": "Картер"
}
```

Mapped `oem_assembly_parts`:

```json
{
  "ref": "1",
  "quantity": 1,
  "row_kind": "original",
  "source_row_id": "10536561",
  "source_items_list_id": "365690767"
}
```

### Replacement Example

Observed replacement row:

```json
{
  "name": "Картер",
  "number": "B67-15100-08-00",
  "original_item_id": "10536561",
  "item_id": "10553272",
  "itemslist_id": "365690767",
  "quantity": "1",
  "ref": "1"
}
```

Mapped `oem_part_relations`:

```json
{
  "source_part_number": "B67-15100-09-00",
  "target_part_number": "B67-15100-08-00",
  "relation_type": "replacement",
  "source_row_id": "10536561:10553272"
}
```

### Source Price Snapshot

Observed source offers can be preserved for diagnostics:

```json
{
  "price_id": "4670197948",
  "price": 270270,
  "base_price": 270270,
  "currency": "RUB",
  "shipper": "Склад Японии",
  "min_qty": 1,
  "handling": "3...7 раб. дн."
}
```

This data must not be used as our selling price. Our frontend should use `oem_part_offers` populated from Bitrix/internal pricing.

## Validation Checklist

The pilot import is valid when both samples can produce:

- canonical vehicle type
- canonical brand
- model family candidate
- vehicle variant
- source nodes with source ids/URLs
- assembly
- local diagram image record
- hotspots with coordinates
- parts with normalized OEM numbers
- assembly-part rows with ref and quantity where available
- replacement relation where available
- empty or linked Bitrix product reference
