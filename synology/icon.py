#!/usr/bin/env python3
"""
The twocans package icon for DSM, drawn from the brand mark.

DSM shows a package's icon exactly as supplied — no mask, no rounding — and
every native app is a rounded square, so the flat cream square we used to
copy from the PWA icon sat oddly beside them. This draws the two cans from
backend/views/partials/logo.php (same geometry, same colours) onto a rounded
tile with transparent corners, at 4x, and downsamples to the sizes DSM wants:
PACKAGE_ICON.PNG (64) and PACKAGE_ICON_256.PNG (256), plus the package UI's.

    python3 synology/icon.py OUTDIR [--ui DIR --sizes 16,24,32,48,64,72,256]
"""
import argparse
import os

from PIL import Image, ImageChops, ImageDraw, ImageFilter

S = 4096                       # supersampled canvas; everything is downsampled from here
RADIUS = 0.225                 # corner radius as a share of the side, as DSM's own icons use

CORAL, CORAL_PALE = "#FF7A59", "#FFA98F"
TEAL, TEAL_PALE = "#5BC7B8", "#86DDD1"
STRING = "#CDB89B"
CREAM_TOP, CREAM_BOTTOM = "#FBF3E4", "#EFE2CC"

# The mark's own coordinate space (the SVG's 120x60 viewBox) mapped onto the
# tile: 104 units of cans across 80% of the width — DSM's own icons fill their
# tile — sat a touch above the middle, where the eye expects a thing to rest.
K = 0.80 * S / 104
CX, CY = 60.0, 28.7
LIFT = 0.025 * S


def rgba(hex_colour: str, alpha: int = 255):
    h = hex_colour.lstrip("#")
    return (int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16), alpha)


def m(x: float, y: float):
    """A point in the mark's units → canvas pixels."""
    return (S / 2 + (x - CX) * K, S / 2 - LIFT + (y - CY) * K)


def box(x0, y0, x1, y1):
    return [*m(x0, y0), *m(x1, y1)]


def tile() -> Image.Image:
    """The rounded cream tile: vertical wash, soft one-pixel edge, nothing outside."""
    r = int(S * RADIUS)
    mask = Image.new("L", (S, S), 0)
    ImageDraw.Draw(mask).rounded_rectangle([0, 0, S - 1, S - 1], r, fill=255)

    strip = Image.new("RGBA", (1, S))
    top, bottom = rgba(CREAM_TOP), rgba(CREAM_BOTTOM)
    for y in range(S):
        t = y / (S - 1)
        strip.putpixel((0, y), tuple(int(a + (b - a) * t) for a, b in zip(top, bottom)))
    im = strip.resize((S, S))
    im.putalpha(mask)

    # The edge: a touch darker just inside, a touch lighter along the top, so the
    # tile reads as a surface on Package Center's white rather than a stain.
    edge = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    d = ImageDraw.Draw(edge)
    w = S // 256                                    # one pixel at 256
    d.rounded_rectangle([w / 2, w / 2, S - 1 - w / 2, S - 1 - w / 2], r - w // 2, outline=(90, 70, 50, 34), width=w)
    d.rounded_rectangle([w * 1.5, w * 1.5, S - 1 - w * 1.5, S - 1 - w * 1.5], r - w, outline=(255, 255, 255, 110), width=w)
    edge.putalpha(ImageChops.multiply(edge.getchannel("A"), mask))
    return Image.alpha_composite(im, edge)


def can(x: float, pivot_x: float, degrees: float, body: str, lid: str) -> Image.Image:
    """One can — body, ribs, base shade, lid — tilted about its middle, as the SVG does."""
    layer = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    body_box = box(x, 14, x + 28, 48)
    d.rounded_rectangle(body_box, radius=8 * K, fill=rgba(body))

    # Ribs and shade are clipped to the body, so nothing pokes past a rounded corner.
    detail = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    dd = ImageDraw.Draw(detail)
    for y in (22, 30, 38):
        dd.rectangle(box(x, y, x + 28, y + 1.3), fill=(0, 0, 0, 23))         # 9 % dark
        dd.rectangle(box(x, y + 1.3, x + 28, y + 2.1), fill=(255, 255, 255, 46))  # 18 % light
    dd.rounded_rectangle(box(x, 38, x + 28, 48), radius=5 * K, fill=(0, 0, 0, 15))  # 6 % base shade
    body_mask = Image.new("L", (S, S), 0)
    ImageDraw.Draw(body_mask).rounded_rectangle(body_box, radius=8 * K, fill=255)
    detail.putalpha(ImageChops.multiply(detail.getchannel("A"), body_mask))
    layer = Image.alpha_composite(layer, detail)

    ImageDraw.Draw(layer).ellipse(box(x, 14 - 4.6, x + 28, 14 + 4.6), fill=rgba(lid))

    # SVG rotates clockwise for a positive angle; PIL anticlockwise.
    return layer.rotate(-degrees, resample=Image.BICUBIC, center=m(pivot_x, 31))


def shadow_for(c: Image.Image) -> Image.Image:
    """A soft pool under a can, from its own silhouette: grounds it on the tile."""
    a = c.getchannel("A").point(lambda v: v * 0.16)
    sh = Image.new("RGBA", (S, S), (60, 40, 25, 0))
    sh.putalpha(a)
    sh = sh.transform(sh.size, Image.AFFINE, (1, 0, 0, 0, 1, -int(0.012 * S)))  # drop it a little
    return sh.filter(ImageFilter.GaussianBlur(S // 110))


def string() -> Image.Image:
    """The dotted string: 1.5 on, 7 off, round caps, width 3 — as pills along y = 30."""
    layer = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    x = 36.0
    while x + 1.5 <= 84:
        d.rounded_rectangle(box(x - 1.5, 28.5, x + 1.5 + 1.5, 31.5), radius=1.5 * K, fill=rgba(STRING))
        x += 8.5
    return layer


def render() -> Image.Image:
    im = tile()
    left = can(8, 22, -8, CORAL, CORAL_PALE)
    right = can(84, 98, 8, TEAL, TEAL_PALE)
    for part in (shadow_for(left), shadow_for(right), string(), left, right):
        im = Image.alpha_composite(im, part)
    return im


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("out", help="where PACKAGE_ICON.PNG and PACKAGE_ICON_256.PNG go")
    ap.add_argument("--ui", help="also write <size>.png icons for the package UI here")
    ap.add_argument("--sizes", default="16,24,32,48,64,72,256", help="UI sizes, comma-separated")
    ap.add_argument("--master", help="also keep the 1024 master here, for previews")
    args = ap.parse_args()

    full = render()
    os.makedirs(args.out, exist_ok=True)
    full.resize((64, 64), Image.LANCZOS).save(os.path.join(args.out, "PACKAGE_ICON.PNG"), optimize=True)
    full.resize((256, 256), Image.LANCZOS).save(os.path.join(args.out, "PACKAGE_ICON_256.PNG"), optimize=True)
    if args.ui:
        os.makedirs(args.ui, exist_ok=True)
        for size in (int(s) for s in args.sizes.split(",") if s.strip()):
            full.resize((size, size), Image.LANCZOS).save(os.path.join(args.ui, f"{size}.png"), optimize=True)
    if args.master:
        os.makedirs(args.master, exist_ok=True)
        full.resize((1024, 1024), Image.LANCZOS).save(os.path.join(args.master, "icon-1024.png"), optimize=True)
    print("icons written to", args.out)


if __name__ == "__main__":
    main()
