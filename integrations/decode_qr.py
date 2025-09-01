#!/usr/bin/env python3
import sys
import os
import json
import re

try:
    import cv2 as cv
except Exception as e:
    print(json.dumps({"ok": False, "error": f"OpenCV not available: {e}"}))
    sys.exit(1)

# Optional fallback: pyzbar (often used in existing Python QR scanners)
try:
    from pyzbar.pyzbar import decode as zbar_decode
    _HAS_PYZBAR = True
except Exception:
    _HAS_PYZBAR = False

def extract_student_id(qr_text: str):
    # First, try to parse as structured format: student_id|name|program
    if '|' in qr_text:
        parts = qr_text.split('|')
        if len(parts) >= 1:
            return parts[0].strip()
    
    # Prefer 6-12 digit numeric id
    m = re.search(r"\b(\d{6,12})\b", qr_text)
    if m:
        return m.group(1)
    # Fallback labeled id
    m = re.search(r"(?:student_id|id|sid)[:=\s]+([A-Za-z0-9_-]{4,})", qr_text, re.I)
    if m:
        return m.group(1)
    # Additional patterns for different QR formats
    # Look for any alphanumeric sequence that might be a student ID
    m = re.search(r"\b([A-Za-z0-9]{6,12})\b", qr_text)
    if m:
        return m.group(1)
    return None


def main():
    if len(sys.argv) < 2:
        print(json.dumps({"ok": False, "error": "Usage: decode_qr.py <image_path>"}))
        return
    image_path = sys.argv[1]
    image = cv.imread(image_path)
    if image is None:
        print(json.dumps({"ok": False, "error": f"Cannot read image: {image_path}"}))
        return
    # Preprocess at multiple scales and rotations
    def try_decode(img):
        # OpenCV detector
        detector = cv.QRCodeDetector()
        data, points, _ = detector.detectAndDecode(img)
        if points is not None and data:
            return data
        # pyzbar fallback
        if _HAS_PYZBAR:
            try:
                gray = cv.cvtColor(img, cv.COLOR_BGR2GRAY)
            except Exception:
                gray = img
            results = zbar_decode(gray)
            if results:
                return results[0].data.decode('utf-8', errors='ignore')
        return None

    def enhance(img):
        try:
            gray = cv.cvtColor(img, cv.COLOR_BGR2GRAY)
        except Exception:
            gray = img
        try:
            clahe = cv.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
            gray = clahe.apply(gray)
        except Exception:
            pass
        try:
            gray = cv.bilateralFilter(gray, 7, 75, 75)
        except Exception:
            pass
        try:
            thr = cv.adaptiveThreshold(gray, 255, cv.ADAPTIVE_THRESH_GAUSSIAN_C, cv.THRESH_BINARY, 31, 2)
            return cv.cvtColor(thr, cv.COLOR_GRAY2BGR)
        except Exception:
            return cv.cvtColor(gray, cv.COLOR_GRAY2BGR) if len(gray.shape) == 2 else gray

    rotations = [0, 90, 180, 270]
    scales = [1.0, 1.3, 0.8]
    candidates = []
    for rot in rotations:
        if rot == 0:
            img_rot = image
        else:
            if rot == 90:
                img_rot = cv.rotate(image, cv.ROTATE_90_CLOCKWISE)
            elif rot == 180:
                img_rot = cv.rotate(image, cv.ROTATE_180)
            else:
                img_rot = cv.rotate(image, cv.ROTATE_90_COUNTERCLOCKWISE)
        for s in scales:
            try:
                if s != 1.0:
                    h, w = img_rot.shape[:2]
                    img_s = cv.resize(img_rot, (int(w * s), int(h * s)), interpolation=cv.INTER_CUBIC if s > 1 else cv.INTER_AREA)
                else:
                    img_s = img_rot
            except Exception:
                img_s = img_rot
            for variant in (img_s, enhance(img_s)):
                data = try_decode(variant)
                if data:
                    candidates.append(data)
                    # Return first successful to keep latency low
                    sid = extract_student_id(data)
                    print(json.dumps({"ok": True, "qr_text": data, "student_id": sid}))
                    return
    print(json.dumps({"ok": True, "qr_text": None, "student_id": None}))

if __name__ == '__main__':
    main()


