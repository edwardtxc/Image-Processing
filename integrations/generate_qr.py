#!/usr/bin/env python3
import argparse
import os
import sys
from typing import Optional

try:
    import qrcode
except Exception as e:
    print("ERROR: qrcode module not available:", e, file=sys.stderr)
    sys.exit(2)

def ensure_parent(path: str):
    parent = os.path.dirname(os.path.abspath(path))
    if parent and not os.path.isdir(parent):
        os.makedirs(parent, exist_ok=True)

try:
    from PIL import Image, ImageDraw, ImageFont
except Exception:
    Image = None  # Optional enhancement only

def draw_labelled(img, name: str, student_id: str) -> 'Image.Image':
    if Image is None:
        return img
    # Create canvas with label area
    qr_w, qr_h = img.size
    pad = 12
    label_h = 80
    canvas = Image.new('RGB', (qr_w + pad * 2, qr_h + pad * 3 + label_h), 'white')
    canvas.paste(img, (pad, pad))
    draw = ImageDraw.Draw(canvas)
    try:
        font_title = ImageFont.truetype('arial.ttf', 18)
        font_small = ImageFont.truetype('arial.ttf', 14)
    except Exception:
        font_title = ImageFont.load_default()
        font_small = ImageFont.load_default()
    text_y = qr_h + pad * 2
    draw.text((pad, text_y), name, fill='black', font=font_title)
    draw.text((pad, text_y + 26), f"ID: {student_id}", fill='gray', font=font_small)
    return canvas

def overlay_photo(img: 'Image.Image', photo_path: Optional[str]) -> 'Image.Image':
    if Image is None or not photo_path or not os.path.isfile(photo_path):
        return img
    qr_w, qr_h = img.size
    try:
        photo = Image.open(photo_path).convert('RGB')
        # Place photo at bottom-right corner, 25% of QR size
        target_w = qr_w // 4
        ratio = target_w / photo.width
        target_h = int(photo.height * ratio)
        photo = photo.resize((target_w, target_h))
        img.paste(photo, (qr_w - target_w - 8, qr_h - target_h - 8))
    except Exception:
        pass
    return img

def main():
    parser = argparse.ArgumentParser(description='Generate a QR image for given data')
    parser.add_argument('--data', required=True, help='QR data string')
    parser.add_argument('--out', required=True, help='Output PNG path')
    parser.add_argument('--size', type=int, default=200, help='QR box size in pixels (approx)')
    # Keep simple/plain QR by default; advanced options intentionally omitted
    args = parser.parse_args()

    qr = qrcode.QRCode(version=1, error_correction=qrcode.constants.ERROR_CORRECT_M, box_size=10, border=2)
    qr.add_data(args.data)
    qr.make(fit=True)
    img = qr.make_image(fill_color='black', back_color='white').convert('RGB')
    ensure_parent(args.out)
    img.save(args.out)
    print(args.out)

if __name__ == '__main__':
    main()


