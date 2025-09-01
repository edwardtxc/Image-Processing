#!/usr/bin/env python3
import argparse
import json
import os
import sys
from datetime import datetime

import cv2 as cv
import numpy as np

# Ensure we can import project modules if needed in future
sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from face_recognition_validator import FaceRecognitionValidator  # type: ignore
import contextlib
import io


def detect_faces_with_boxes(image: np.ndarray):
    gray = cv.cvtColor(image, cv.COLOR_BGR2GRAY)
    cascade = cv.CascadeClassifier(cv.data.haarcascades + 'haarcascade_frontalface_default.xml')
    faces = cascade.detectMultiScale(gray, scaleFactor=1.1, minNeighbors=4, minSize=(60, 60))
    if len(faces) == 0:
        alt = cv.CascadeClassifier(cv.data.haarcascades + 'haarcascade_frontalface_alt2.xml')
        faces = alt.detectMultiScale(gray, scaleFactor=1.1, minNeighbors=4, minSize=(60, 60))
    boxes = []
    for (x, y, w, h) in faces:
        boxes.append({"x": int(x), "y": int(y), "w": int(w), "h": int(h)})
    return boxes


def main():
    parser = argparse.ArgumentParser(description='Face Identification CLI Tool (1:N)')
    parser.add_argument('--image_path', required=True, help='Path to captured image')
    parser.add_argument('--photos_dir', required=True, help='Directory containing student photos')
    parser.add_argument('--allowed_ids_path', default='', help='Optional path to text file with allowed student_ids (one per line)')
    parser.add_argument('--threshold', type=float, default=0.75, help='Confidence threshold for acceptance')
    parser.add_argument('--min_margin', type=float, default=0.08, help='Required margin over 2nd-best match')
    parser.add_argument('--output_format', default='json')
    parser.add_argument('--debug', action='store_true', help='Enable debug output')
    args = parser.parse_args()

    result = {
        'success': False,
        'message': '',
        'timestamp': datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
        'identification': {
            'best_student_id': None,
            'best_confidence': 0.0,
            'face_detected': False,
            'box': None,
        }
    }

    try:
        if not os.path.exists(args.image_path):
            result['message'] = f"Image not found: {args.image_path}"
            print(json.dumps(result))
            return 1

        frame = cv.imread(args.image_path)
        if frame is None:
            result['message'] = 'Failed to read image'
            print(json.dumps(result))
            return 1

        # Suppress validator prints to keep stdout JSON-only
        with contextlib.redirect_stdout(io.StringIO()):
            validator = FaceRecognitionValidator(student_photos_dir=args.photos_dir)
            # Ensure standardized vectors exist for fast comparison
            for sid, entry in validator.known_faces.items():
                if 'template' in entry and entry.get('vector') is None:
                    try:
                        entry['vector'] = validator._template_to_standardized_vector(entry['template'])
                    except Exception:
                        entry['vector'] = None

        if args.debug:
            print(f"DEBUG: Loaded {len(validator.known_faces)} known faces", file=sys.stderr)
            for sid in validator.known_faces.keys():
                print(f"DEBUG: Known face: {sid}", file=sys.stderr)

        # Extract current face template
        current_templates = validator.extract_face_from_frame(frame)
        if len(current_templates) == 0:
            result['message'] = 'No face detected'
            print(json.dumps(result))
            return 0

        result['identification']['face_detected'] = True

        # For UI feedback choose the largest detected box
        boxes = detect_faces_with_boxes(frame)
        if boxes:
            best_box = max(boxes, key=lambda b: b['w'] * b['h'])
            result['identification']['box'] = best_box

        current_template = current_templates[0]
        current_vec = validator._template_to_standardized_vector(current_template)

        allowed_ids = None
        if args.allowed_ids_path and os.path.exists(args.allowed_ids_path):
            with open(args.allowed_ids_path, 'r', encoding='utf-8') as f:
                allowed_ids = set([line.strip().lower() for line in f if line.strip()])
            if args.debug:
                print(f"DEBUG: Allowed IDs: {list(allowed_ids)}", file=sys.stderr)

        best_sid = None
        best_score = 0.0
        second_best = 0.0
        # Iterate known faces and compute cosine similarity
        candidate_items = list(validator.known_faces.items())
        for sid, entry in candidate_items:
            if allowed_ids is not None and sid.lower() not in allowed_ids:
                continue
            vec = entry.get('vector')
            if vec is None:
                template = entry.get('template')
                if template is None:
                    continue
                try:
                    vec = validator._template_to_standardized_vector(template)
                    entry['vector'] = vec
                except Exception:
                    continue
            dot = float(np.dot(vec, current_vec))
            denom = float(np.linalg.norm(vec) * np.linalg.norm(current_vec)) + 1e-6
            cosine = dot / denom
            similarity = (cosine + 1.0) / 2.0
            if args.debug:
                print(f"DEBUG: {sid} similarity: {similarity:.3f}", file=sys.stderr)
            if similarity > best_score:
                second_best = best_score
                best_score = similarity
                best_sid = sid
            elif similarity > second_best:
                second_best = similarity

        # Optional LBPH recognizer (if available via opencv-contrib)
        lbph_sid = None
        lbph_distance = None
        lbph_similarity = None
        lbph_supported = False
        try:
            # Only attempt if module present and there are candidates
            if len(candidate_items) > 0 and hasattr(cv, 'face') and hasattr(cv.face, 'LBPHFaceRecognizer_create'):
                lbph_supported = True
                recognizer = cv.face.LBPHFaceRecognizer_create(radius=2, neighbors=8, grid_x=8, grid_y=8)
                train_images = []
                train_labels = []
                sid_to_label = {}
                label_to_sid = {}
                next_label = 1
                for sid, entry in candidate_items:
                    if allowed_ids is not None and sid.lower() not in allowed_ids:
                        continue
                    templ = entry.get('template')
                    if templ is None:
                        continue
                    # LBPH expects 8-bit single-channel images
                    train_images.append(templ)
                    sid_to_label[sid] = next_label
                    label_to_sid[next_label] = sid
                    train_labels.append(next_label)
                    next_label += 1
                if len(train_images) >= 1:
                    recognizer.train(train_images, np.array(train_labels))
                    label, distance = recognizer.predict(current_template)
                    lbph_distance = float(distance)
                    lbph_sid = label_to_sid.get(label)
                    # Map distance to a similarity in [0,1]. Lower distance -> higher similarity.
                    # Use a soft mapping; distances ~40-70 are common. Tune denominator as needed.
                    lbph_similarity = 1.0 / (1.0 + (lbph_distance / 70.0))
        except Exception:
            lbph_supported = False

        # Combine cosine and LBPH if both point to same SID
        combined_score = best_score
        method = 'cosine_only'
        if lbph_supported and lbph_sid is not None and best_sid is not None and lbph_sid == best_sid and lbph_similarity is not None:
            combined_score = 0.6 * best_score + 0.4 * float(lbph_similarity)
            method = 'hybrid_cosine_lbph'
        elif lbph_supported and lbph_sid is not None and lbph_similarity is not None and (best_sid is None or lbph_similarity > best_score + 0.05):
            # If cosine is weak but LBPH is confident, consider LBPH result
            best_sid = lbph_sid
            combined_score = float(lbph_similarity)
            method = 'lbph_only'

        result['identification']['best_student_id'] = best_sid
        result['identification']['best_confidence'] = float(combined_score)
        result['stats'] = {
            'known_faces': int(len(validator.known_faces)),
            'allowed_filter': bool(allowed_ids is not None),
            'allowed_count': int(len(allowed_ids) if allowed_ids is not None else 0),
            'cosine_best': float(best_score),
            'second_best': float(second_best),
            'lbph_supported': bool(lbph_supported),
            'lbph_sid': lbph_sid,
            'lbph_distance': lbph_distance,
            'lbph_similarity': lbph_similarity,
            'method': method
        }
        # Margin is based on cosine ranking; helps avoid close impostors
        margin = max(0.0, best_score - second_best)
        result['identification']['margin'] = float(margin)
        
        # Special case: if margin is 0 but confidence is very high, it might be identical photos
        # In this case, we should still accept the match
        margin_ok = margin >= args.min_margin or (margin == 0.0 and combined_score >= 0.95)
        
        if best_sid is not None and combined_score >= args.threshold and margin_ok:
            result['success'] = True
            result['message'] = f'Identified {best_sid} (method {method}) conf {combined_score:.3f}'
        else:
            result['success'] = False
            if best_sid is None:
                result['message'] = 'No matching student found'
            else:
                result['message'] = f'Below threshold or margin: {best_sid} (conf {combined_score:.3f}, margin {margin:.3f}, method {method})'

        print(json.dumps(result))
        return 0
    except Exception as e:
        result['message'] = f'Error: {str(e)}'
        print(json.dumps(result))
        return 1


if __name__ == '__main__':
    sys.exit(main())


