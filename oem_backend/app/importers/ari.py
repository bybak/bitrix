from app.importers import writer
from app.importers.progress import ProgressReporter


ARI_SAMPLE_URL = (
    "https://remotors.fi/eng/partfinder?aribrand=KTM"
    "#/KTM/125_SX_CHASSIS_-_2025/AIR_FILTER/"
    "7ead2eca-13fc-4fe9-bf0f-a462482059b5/"
    "125_SX_CHASSIS_-_2025_AIR_FILTER/y"
)


SAMPLE_PARTS = [
    ("1", "A46006001000EB", "Lower section of the air filter", "45.97", 1),
    ("2", "A46006004000EB", "Air filter panel", "33.27", 1),
    ("3", "A46006003000EB", "Air filter cover", "33.27", 1),
    ("4", "A46006005000EB", "Side fairing", "33.27", 1),
    ("5", "A46006001010EB", "Air filter box front part", "16.01", 1),
    ("7", "0025060206", "HH collar screw M6x20 TX30", "2.14", 2),
    ("9", "0017060206", "SCREW F. PLASTIC K60X20AL SW6", "2.14", 5),
    ("10", "77304066150", "Chain slider bushing", "4.28", 2),
    ("11", "79105098000", "exhaust spacer", "6.49", 1),
    ("12", "78006027000", "HOSE CLAMP 45-65MM", "4.07", 1),
    ("13", "A44006026000", "Air boot", "50.73", 1),
    ("14", "A46006017000", "Air filter holding bracket", "5.94", 1),
    ("15", "A42006016000", "Air filter support", "24.09", 1),
    ("16", "A46006015000", "Air filter", "23.05", 1),
    ("19", "47106003160", "quick release rubber grommet", "3.38", 5),
    ("23", "A46004050000", "Splash protection", "18.71", 1),
    ("24", "0081050141", "EJOT PT screw K50x14", "2.14", 2),
    ("26", "79003003000", "SPECIAL SCREW M8X18 TORX45 SS", "3.93", 2),
    ("27", "83008000012", "special screw M6x12x3", "3.24", 1),
]


SAMPLE_HOTSPOTS = [
    {"ref": "24", "x": 161, "y": 20, "width": 14, "height": 13},
    {"ref": "24", "x": 416, "y": 193, "width": 15, "height": 11},
    {"ref": "1", "x": 191, "y": 96, "width": 6, "height": 11},
    {"ref": "4", "x": 370, "y": 157, "width": 9, "height": 11},
    {"ref": "11", "x": 135, "y": 208, "width": 12, "height": 11},
    {"ref": "26", "x": 19, "y": 214, "width": 14, "height": 12},
    {"ref": "26", "x": 287, "y": 394, "width": 14, "height": 14},
    {"ref": "3", "x": 465, "y": 228, "width": 10, "height": 12},
    {"ref": "9", "x": 101, "y": 235, "width": 9, "height": 11},
    {"ref": "9", "x": 42, "y": 383, "width": 10, "height": 12},
    {"ref": "9", "x": 432, "y": 484, "width": 10, "height": 12},
    {"ref": "9", "x": 456, "y": 533, "width": 10, "height": 12},
    {"ref": "9", "x": 52, "y": 300, "width": 10, "height": 13},
    {"ref": "19", "x": 75, "y": 266, "width": 14, "height": 13},
    {"ref": "19", "x": 91, "y": 423, "width": 15, "height": 12},
    {"ref": "19", "x": 163, "y": 446, "width": 14, "height": 11},
    {"ref": "19", "x": 238, "y": 462, "width": 14, "height": 10},
    {"ref": "19", "x": 270, "y": 545, "width": 18, "height": 13},
    {"ref": "27", "x": 365, "y": 284, "width": 16, "height": 13},
    {"ref": "5", "x": 139, "y": 295, "width": 9, "height": 13},
    {"ref": "15", "x": 189, "y": 472, "width": 14, "height": 12},
    {"ref": "13", "x": 135, "y": 492, "width": 14, "height": 12},
    {"ref": "16", "x": 324, "y": 521, "width": 13, "height": 11},
    {"ref": "10", "x": 429, "y": 545, "width": 13, "height": 11},
    {"ref": "10", "x": 405, "y": 494, "width": 15, "height": 12},
    {"ref": "23", "x": 372, "y": 590, "width": 15, "height": 13},
    {"ref": "2", "x": 126, "y": 61, "width": 10, "height": 13},
    {"ref": "7", "x": 363, "y": 77, "width": 10, "height": 13},
    {"ref": "7", "x": 406, "y": 99, "width": 10, "height": 14},
    {"ref": "14", "x": 185, "y": 330, "width": 13, "height": 15},
    {"ref": "12", "x": 94, "y": 646, "width": 14, "height": 14},
]


def import_sample(progress: ProgressReporter | None = None) -> dict:
    if progress is None:
        progress = ProgressReporter(
            total=11 + len(SAMPLE_PARTS) + len(SAMPLE_HOTSPOTS),
            label="remotors_ari_sample",
        )

    progress.set_stage("canonical vehicle", 4)
    brand_id = writer.ensure_brand("KTM")
    progress.advance("brand KTM saved")
    writer.ensure_brand_alias(brand_id, "remotors_ari", "KTM")
    progress.advance("brand alias saved")
    model_id = writer.ensure_model_family("motorcycle", brand_id, "125 SX")
    progress.advance("model family 125 SX saved")
    writer.ensure_model_alias(model_id, "remotors_ari", "125 SX CHASSIS - 2025", reviewed=False)
    progress.advance("model alias saved")

    progress.set_stage("source navigation nodes", 3)
    brand_node_id = writer.ensure_source_node(
        source_code="remotors_ari",
        node_type="brand",
        title="KTM",
        external_id="KTM",
        arib="KTM",
    )
    progress.advance("source brand node saved")
    year_node_id = writer.ensure_source_node(
        source_code="remotors_ari",
        node_type="year",
        title="2025",
        external_id="KTM:FjrcdAkxl-KsENAdRROGDw2",
        parent_id=brand_node_id,
        arib="KTM",
        aria="FjrcdAkxl-KsENAdRROGDw2",
    )
    progress.advance("source year node saved")
    model_node_id = writer.ensure_source_node(
        source_code="remotors_ari",
        node_type="model_node",
        title="125 SX CHASSIS - 2025",
        external_id="KTM:GZDDNRKn6O8LUxsJsTksvA2",
        parent_id=year_node_id,
        arib="KTM",
        aria="GZDDNRKn6O8LUxsJsTksvA2",
    )
    progress.advance("source model node saved")

    progress.set_stage("variant assembly diagram", 4)
    variant_id = writer.ensure_variant(
        model_id,
        year_from=2025,
        year_to=2025,
        market_name="125 SX",
        source_designation="125 SX CHASSIS - 2025",
        variant_section="chassis",
    )
    writer.link_source_node(model_node_id, "vehicle_variant", variant_id)
    progress.advance("vehicle variant saved")

    assembly_node_id = writer.ensure_source_node(
        source_code="remotors_ari",
        node_type="assembly",
        title="AIR FILTER",
        external_id="KTM:DV9TqbDDXS-lVhCqylBvuA2",
        parent_id=model_node_id,
        source_url=ARI_SAMPLE_URL,
        arib="KTM",
        aria="DV9TqbDDXS-lVhCqylBvuA2",
        slug="/KTM/125_SX_CHASSIS_-_2025/AIR_FILTER/7ead2eca-13fc-4fe9-bf0f-a462482059b5/125_SX_CHASSIS_-_2025_AIR_FILTER",
    )
    progress.advance("assembly source node saved")
    assembly_id = writer.ensure_assembly(variant_id, "AIR FILTER", assembly_node_id)
    writer.link_source_node(assembly_node_id, "assembly", assembly_id)
    progress.advance("assembly saved")

    image_url = "https://cdn.datamanager.arinet.com/image/KTM/e37a9b39-6477-4184-bb74-79f7b813cf5c/Max"
    diagram_id = writer.ensure_diagram(
        assembly_id,
        source_node_id=assembly_node_id,
        original_url=image_url,
        source_image_id="e37a9b39-6477-4184-bb74-79f7b813cf5c",
        width=500,
        height=691,
    )
    progress.advance("diagram saved")

    progress.set_stage("parts and source prices", len(SAMPLE_PARTS))
    parts_by_ref: dict[str, int] = {}
    imported_parts = 0
    for ref, number, name, source_price, quantity in SAMPLE_PARTS:
        part_id = writer.ensure_part("KTM", number, name, brand_id)
        assembly_part_id = writer.add_assembly_part(
            assembly_id=assembly_id,
            part_id=part_id,
            ref=ref,
            quantity=quantity,
            row_kind="original",
            source_node_id=assembly_node_id,
            source_row_id=f"KTM:DV9TqbDDXS-lVhCqylBvuA2:ref:{ref}",
            raw_payload={"source_price_eur": source_price, "quantity": quantity},
        )
        writer.add_source_price_snapshot(
            source_code="remotors_ari",
            part_id=part_id,
            assembly_part_id=assembly_part_id,
            source_price_id=f"KTM:DV9TqbDDXS-lVhCqylBvuA2:ref:{ref}:EUR",
            price=source_price,
            currency="EUR",
            min_qty=quantity,
            raw_payload={"ref": ref, "part_number": number, "source_price_eur": source_price, "quantity": quantity},
        )
        parts_by_ref[ref] = assembly_part_id
        imported_parts += 1
        progress.advance(f"part ref={ref} number={number} price={source_price} EUR")

    progress.set_stage("diagram hotspots", len(SAMPLE_HOTSPOTS))
    writer.clear_diagram_hotspots(diagram_id)
    for hotspot in SAMPLE_HOTSPOTS:
        writer.add_hotspot(
            diagram_id=diagram_id,
            assembly_part_id=parts_by_ref.get(hotspot["ref"]),
            shape="rect",
            raw_coords=(
                f"top:{hotspot['y']}px;left:{hotspot['x']}px;"
                f"width:{hotspot['width']}px;height:{hotspot['height']}px"
            ),
            x=hotspot["x"],
            y=hotspot["y"],
            width=hotspot["width"],
            height=hotspot["height"],
            ref=hotspot["ref"],
            raw_payload=hotspot,
        )
        progress.advance(f"hotspot ref={hotspot['ref']} x={hotspot['x']} y={hotspot['y']}")

    result = {
        "source": "remotors_ari",
        "url": ARI_SAMPLE_URL,
        "variant_id": variant_id,
        "assembly_id": assembly_id,
        "diagram_id": diagram_id,
        "parts": imported_parts,
        "hotspots": len(SAMPLE_HOTSPOTS),
    }
    progress.finish("sample import finished")
    return result
