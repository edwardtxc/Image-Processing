import argparse
import json
import os
import sys
from datetime import datetime

import cv2 as cv
import numpy as np

from face_recognition_validator import FaceRecognitionValidator
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
    parser = argparse.ArgumentParser()
    parser.add_argument('--verify', action='store_true')
    parser.add_argument('--student_id', required=True)
    parser.add_argument('--image_path', required=True)
    parser.add_argument('--photos_dir', required=True)
    parser.add_argument('--threshold', type=float, default=0.7)
    parser.add_argument('--output_format', default='json')
    args = parser.parse_args()

    result = {
        "success": False,
        "message": "",
        "timestamp": datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
        "face_validation": {
            "student_id": args.student_id,
            "is_valid": False,
            "confidence": 0.0,
            "face_detected": False,
            "known_student": False,
            "box": None,
        }
    }

    try:
        if not os.path.exists(args.image_path):
            result["message"] = f"Image not found: {args.image_path}"
            print(json.dumps(result))
            return 1

        # Suppress verbose prints from the validator so only JSON is emitted
        with contextlib.redirect_stdout(io.StringIO()):
            validator = FaceRecognitionValidator(student_photos_dir=args.photos_dir)
            validator.set_validation_threshold(args.threshold)

            image = cv.imread(args.image_path)
            if image is None:
                result["message"] = "Failed to read image"
                print(json.dumps(result))
                return 1

            # Run validation to compute confidence
            validation = validator.validate_student_face(image, args.student_id)

        # Find faces and choose the largest box (approximate best)
        boxes = detect_faces_with_boxes(image)
        best_box = None
        if boxes:
            # choose the biggest area as best_box
            best_box = max(boxes, key=lambda b: b['w'] * b['h'])

        result["face_validation"].update({
            "is_valid": bool(validation.get('is_valid')),
            "confidence": float(validation.get('confidence', 0.0)),
            "face_detected": bool(validation.get('face_detected')),
            "known_student": bool(validation.get('known_student')),
            "box": best_box,
        })
        result["success"] = True
        result["message"] = validation.get('message', '')

        print(json.dumps(result))
        return 0
    except Exception as e:
        result["message"] = f"Error: {str(e)}"
        print(json.dumps(result))
        return 1


if __name__ == '__main__':
    sys.exit(main())

#!/usr/bin/env python3
"""
Face Verification CLI Script
This script can be called from PHP to perform face verification
"""

import argparse
import sys
import json
import os
import cv2 as cv
import numpy as np
from typing import Dict, Any

# Add the parent directory to the path to import our modules
sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from services.face.face_verifier import FaceVerifier


def main():
    parser = argparse.ArgumentParser(description='Face Verification CLI Tool')
    parser.add_argument('--verify', action='store_true', help='Perform face verification')
    parser.add_argument('--student_id', type=str, required=True, help='Student ID to verify')
    parser.add_argument('--image_path', type=str, required=True, help='Path to captured image')
    parser.add_argument('--photos_dir', type=str, default='student_photos', help='Directory containing student photos')
    parser.add_argument('--threshold', type=float, default=0.7, help='Verification threshold (0.0-1.0)')
    parser.add_argument('--output_format', type=str, default='json', choices=['json', 'text'], help='Output format')
    
    args = parser.parse_args()
    
    try:
        # Initialize face verifier
        verifier = FaceVerifier(args.photos_dir)
        verifier.set_validation_threshold(args.threshold)
        
        if args.verify:
            # Load the captured image
            if not os.path.exists(args.image_path):
                print_error(f"Image file not found: {args.image_path}")
                sys.exit(1)
            
            # Read the image
            frame = cv.imread(args.image_path)
            if frame is None:
                print_error(f"Could not read image: {args.image_path}")
                sys.exit(1)
            
            # Perform verification
            result = verifier.verify_student_face(frame, args.student_id)
            
            # Output result
            if args.output_format == 'json':
                print(json.dumps(result, indent=2))
            else:
                print_verification_result(result)
            
            # Exit with appropriate code
            sys.exit(0 if result['success'] else 1)
        
        else:
            print_error("No action specified. Use --verify to perform verification.")
            sys.exit(1)
            
    except Exception as e:
        error_result = {
            'success': False,
            'error': str(e),
            'student_id': args.student_id,
            'timestamp': get_timestamp()
        }
        
        if args.output_format == 'json':
            print(json.dumps(error_result, indent=2))
        else:
            print_error(f"Error: {str(e)}")
        
        sys.exit(1)


def print_verification_result(result: Dict[str, Any]):
    """Print verification result in human-readable format"""
    print("=" * 50)
    print("FACE VERIFICATION RESULT")
    print("=" * 50)
    print(f"Student ID: {result['student_id']}")
    print(f"Status: {'✓ VERIFIED' if result['success'] else '✗ NOT VERIFIED'}")
    print(f"Confidence: {result['confidence']:.1%}")
    print(f"Face Detected: {'Yes' if result['face_detected'] else 'No'}")
    print(f"Known Student: {'Yes' if result['known_student'] else 'No'}")
    print(f"Message: {result['message']}")
    print(f"Timestamp: {result['timestamp']}")
    print("=" * 50)


def print_error(message: str):
    """Print error message to stderr"""
    print(f"ERROR: {message}", file=sys.stderr)


def get_timestamp() -> str:
    """Get current timestamp in ISO format"""
    from datetime import datetime
    return datetime.now().isoformat()


if __name__ == "__main__":
    main()


