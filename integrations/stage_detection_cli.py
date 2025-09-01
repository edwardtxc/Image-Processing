#!/usr/bin/env python3
"""
Stage Detection CLI Tool
Processes stage detection requests and returns results in JSON format
"""

import argparse
import json
import os
import sys
import sqlite3
import time
from datetime import datetime
import cv2
import numpy as np

# Ensure we can import project modules
sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

def connect_database(db_path="data/app.sqlite"):
    """Connect to SQLite database"""
    try:
        # Ensure database directory exists
        db_dir = os.path.dirname(db_path)
        if db_dir and not os.path.exists(db_dir):
            os.makedirs(db_dir, exist_ok=True)
        
        conn = sqlite3.connect(db_path, timeout=10.0)
        conn.row_factory = sqlite3.Row
        
        # Test the connection
        cursor = conn.cursor()
        cursor.execute("SELECT 1")
        cursor.fetchone()
        
        return conn
    except Exception as e:
        print(f"Database connection error: {e}", file=sys.stderr)
        return None

def get_next_queued_graduate(conn):
    """Get the next graduate from the queue"""
    try:
        cursor = conn.cursor()
        cursor.execute("""
            SELECT id, full_name, student_id, program 
            FROM graduates 
            WHERE queued_at IS NOT NULL AND announced_at IS NULL 
            ORDER BY queued_at ASC 
            LIMIT 1
        """)
        result = cursor.fetchone()
        return dict(result) if result else None
    except Exception as e:
        print(f"Database query error: {e}", file=sys.stderr)
        return None

def announce_graduate(conn, graduate_id):
    """Mark a graduate as announced"""
    try:
        cursor = conn.cursor()
        cursor.execute("""
            UPDATE graduates 
            SET announced_at = datetime('now') 
            WHERE id = ?
        """, (graduate_id,))
        
        cursor.execute("""
            UPDATE current_announcement 
            SET graduate_id = ?, updated_at = datetime('now') 
            WHERE id = 1
        """, (graduate_id,))
        
        conn.commit()
        return True
    except Exception as e:
        print(f"Announcement error: {e}", file=sys.stderr)
        conn.rollback()
        return False

def detect_people_in_frame(frame):
    """Detect people in the frame using HOG detector"""
    try:
        # Convert to grayscale for better detection
        gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
        
        # Create HOG detector
        person_detector = cv2.HOGDescriptor()
        person_detector.setSVMDetector(cv2.HOGDescriptor_getDefaultPeopleDetector())
        
        # Detect people
        boxes, weights = person_detector.detectMultiScale(
            gray, 
            winStride=(8, 8),
            padding=(4, 4),
            scale=1.05
        )
        
        people = []
        for i, (x, y, w, h) in enumerate(boxes):
            if i < len(weights) and weights[i] > 0.3:  # Confidence threshold
                center_x = x + w // 2
                center_y = y + h // 2
                people.append({
                    'x': center_x,
                    'y': center_y,
                    'width': w,
                    'height': h,
                    'confidence': float(weights[i])
                })
        
        return people
    except Exception as e:
        print(f"Person detection error: {e}", file=sys.stderr)
        return []

def analyze_movement_pattern(people, frame_width=960):
    """Analyze movement patterns and determine sequencing"""
    if not people:
        return None
    
    try:
        # Find the person closest to center
        center_x = frame_width // 2
        closest_person = min(people, key=lambda p: abs(p['x'] - center_x))
        
        # Determine zone
        if closest_person['x'] < frame_width // 3:
            zone = "left"
        elif closest_person['x'] > 2 * frame_width // 3:
            zone = "right"
        else:
            zone = "center"
        
        return {
            'zone': zone,
            'person_count': len(people),
            'closest_person': closest_person
        }
    except Exception as e:
        print(f"Movement analysis error: {e}", file=sys.stderr)
        return None

def process_stage_detection(image_path, db_path="data/app.sqlite"):
    """Main function to process stage detection"""
    result = {
        'success': False,
        'message': '',
        'timestamp': datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
        'detection': {
            'people_detected': 0,
            'movement_zone': None,
            'ready_for_announcement': False,
            'graduate_announced': False
        }
    }
    
    try:
        # Check if image exists
        if not os.path.exists(image_path):
            result['message'] = f"Image not found: {image_path}"
            return result
        
        # Read the image
        frame = cv2.imread(image_path)
        if frame is None:
            result['message'] = 'Failed to read image'
            return result
        
        # Detect people
        people = detect_people_in_frame(frame)
        result['detection']['people_detected'] = len(people)
        
        # Analyze movement
        movement = analyze_movement_pattern(people, frame.shape[1])
        if movement:
            result['detection']['movement_zone'] = movement['zone']
            
            # Check if ready for announcement (person in center zone)
            if movement['zone'] == 'center' and len(people) > 0:
                result['detection']['ready_for_announcement'] = True
        
        # Connect to database
        conn = connect_database(db_path)
        if not conn:
            result['message'] = 'Failed to connect to database'
            return result
        
        # Check if we should announce a graduate
        if result['detection']['ready_for_announcement']:
            next_graduate = get_next_queued_graduate(conn)
            if next_graduate:
                if announce_graduate(conn, next_graduate['id']):
                    result['detection']['graduate_announced'] = True
                    result['detection']['announced_graduate'] = next_graduate
                    result['message'] = f"Graduate announced: {next_graduate['full_name']}"
                else:
                    result['message'] = 'Failed to announce graduate'
            else:
                result['message'] = 'No graduates in queue to announce'
        else:
            result['message'] = 'Stage detection completed'
        
        conn.close()
        result['success'] = True
        
    except Exception as e:
        result['message'] = f'Error processing stage detection: {str(e)}'
        print(f"Stage detection error: {e}", file=sys.stderr)
    
    return result

def main():
    """Main CLI function"""
    parser = argparse.ArgumentParser(description='Stage Detection CLI Tool')
    parser.add_argument('--image_path', required=True, help='Path to captured image')
    parser.add_argument('--db_path', default='data/app.sqlite', help='Database path')
    parser.add_argument('--output_format', default='json', help='Output format (json)')
    parser.add_argument('--debug', action='store_true', help='Enable debug output')
    
    args = parser.parse_args()
    
    if args.debug:
        print(f"DEBUG: Processing image: {args.image_path}", file=sys.stderr)
        print(f"DEBUG: Database path: {args.db_path}", file=sys.stderr)
    
    # Process the detection
    result = process_stage_detection(args.image_path, args.db_path)
    
    # Output result
    if args.output_format == 'json':
        print(json.dumps(result, indent=2))
    else:
        print(f"Success: {result['success']}")
        print(f"Message: {result['message']}")
        print(f"People detected: {result['detection']['people_detected']}")
        print(f"Zone: {result['detection']['movement_zone']}")
        print(f"Ready for announcement: {result['detection']['ready_for_announcement']}")
    
    # Return appropriate exit code
    return 0 if result['success'] else 1

if __name__ == "__main__":
    sys.exit(main())
