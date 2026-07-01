def diagram_point_layout(point, image_width, image_height):
    """Return CSS percentages for a point/rect relative to real image size.

    Expected point keys:
      x, y, width, height

    Formula:
      left = x / image_width * 100
      top = y / image_height * 100
      width = width / image_width * 100
      height = height / image_height * 100
    """
    image_w = float(image_width)
    image_h = float(image_height)
    x = float(point.get("x") or 0)
    y = float(point.get("y") or 0)
    width = float(point.get("width") or 0)
    height = float(point.get("height") or 0)

    return {
        "left": x / image_w * 100.0,
        "top": y / image_h * 100.0,
        "width": width / image_w * 100.0,
        "height": height / image_h * 100.0,
    }
