#!/usr/bin/env python3
"""
Stage Detection System
Real-time object detection for graduation ceremony stage sequencing
"""

import cv2
import numpy as np
import sqlite3
import json
import time
import threading
from datetime import datetime
import os
import sys

class StageDetector:
    def __init__(self, db_path="data/app.sqlite"):
        self.db_path = db_path
        self.cap = None
        self.is_running = False
        self.detection_thread = None
        
        # Detection zones
        self.left_zone = (0, 0, 320, 480)      # Left side of frame
        self.center_zone = (320, 0, 320, 480)  # Center of frame  
        self.right_zone = (640, 0, 320, 480)   # Right side of frame
        
        # Person detection
        self.person_detector = cv2.HOGDescriptor()
        self.person_detector.setSVMDetector(cv2.HOGDescriptor_getDefaultPeopleDetector())
        
        # Movement tracking
        self.person_positions = []
        self.sequence_count = 0
        self.last_announcement = None
        
        # Load pre-trained model for better detection
        self.load_custom_model()
    
    def load_custom_model(self):
        """Load a pre-trained model for better person detection"""
        try:
            # Try to use YOLO if available, otherwise fall back to HOG
            self.use_yolo = False
            # You can add YOLO model loading here if needed
        except:
            self.use_yolo = False
    
    def connect_database(self):
        """Connect to SQLite database"""
        try:
            # Close existing connection if any
            if hasattr(self, 'conn'):
                self.conn.close()
            
            # Ensure database directory exists
            import os
            db_dir = os.path.dirname(self.db_path)
            if db_dir and not os.path.exists(db_dir):
                os.makedirs(db_dir, exist_ok=True)
            
            self.conn = sqlite3.connect(self.db_path, timeout=10.0)
            self.conn.row_factory = sqlite3.Row
            
            # Test the connection
            cursor = self.conn.cursor()
            cursor.execute("SELECT 1")
            cursor.fetchone()
            
            print(f"Database connected successfully: {self.db_path}")
            return True
        except Exception as e:
            print(f"Database connection error: {e}")
            return False
    
    def get_next_queued_graduate(self):
        """Get the next graduate from the queue"""
        try:
            cursor = self.conn.cursor()
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
            print(f"Database query error: {e}")
            return None
    
    def announce_graduate(self, graduate_id):
        """Mark a graduate as announced"""
        try:
            cursor = self.conn.cursor()
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
            
            self.conn.commit()
            self.last_announcement = datetime.now()
            print(f"Announced graduate ID: {graduate_id}")
            
            # Notify the display system about the new announcement
            self.notify_display_system(graduate_id)
            
            return True
        except Exception as e:
            print(f"Announcement error: {e}")
            self.conn.rollback()
            return False
    
    def notify_display_system(self, graduate_id):
        """Notify the display system about a new graduation announcement"""
        try:
            import requests
            import json
            
            # Get graduate details for the notification
            cursor = self.conn.cursor()
            cursor.execute("""
                SELECT id, full_name, student_id, program 
                FROM graduates 
                WHERE id = ?
            """, (graduate_id,))
            
            graduate = cursor.fetchone()
            if graduate:
                # Prepare notification data
                notification_data = {
                    'action': 'announce_graduation',
                    'graduate_id': graduate_id
                }
                
                # Send notification to the API
                try:
                    # Try to send HTTP notification
                    response = requests.post(
                        'http://localhost/api/notify_graduation.php',
                        data=notification_data,
                        timeout=5
                    )
                    
                    if response.status_code == 200:
                        result = response.json()
                        if result.get('success'):
                            print(f"Display notification sent successfully for graduate {graduate['full_name']}")
                        else:
                            print(f"Display notification failed: {result.get('message')}")
                    else:
                        print(f"Display notification HTTP error: {response.status_code}")
                        
                except requests.exceptions.RequestException as e:
                    print(f"Display notification request failed: {e}")
                    # Fallback: try to trigger notification through file system
                    self.trigger_file_notification(graduate_id)
                    
        except ImportError:
            # requests module not available, use file system notification
            print("requests module not available, using file system notification")
            self.trigger_file_notification(graduate_id)
        except Exception as e:
            print(f"Display notification error: {e}")
            # Fallback: try to trigger notification through file system
            self.trigger_file_notification(graduate_id)
    
    def trigger_file_notification(self, graduate_id):
        """Fallback notification method using file system"""
        try:
            # Create a notification file that the PHP system can detect
            notification_dir = os.path.join(os.path.dirname(self.db_path), 'notifications')
            os.makedirs(notification_dir, exist_ok=True)
            
            notification_file = os.path.join(notification_dir, f'graduation_{graduate_id}_{int(time.time())}.json')
            
            notification_data = {
                'type': 'graduation_announced',
                'graduate_id': graduate_id,
                'timestamp': datetime.now().isoformat(),
                'action': 'announce_graduation'
            }
            
            with open(notification_file, 'w') as f:
                json.dump(notification_data, f)
            
            print(f"File notification created: {notification_file}")
            
        except Exception as e:
            print(f"File notification error: {e}")
    
    def detect_people(self, frame):
        """Detect people in the frame using HOG detector"""
        # Convert to grayscale for better detection
        gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
        
        # Detect people
        boxes, weights = self.person_detector.detectMultiScale(
            gray, 
            winStride=(8, 8),
            padding=(4, 4),
            scale=1.05
        )
        
        people = []
        for (x, y, w, h) in boxes:
            if weights[0] > 0.3:  # Confidence threshold
                center_x = x + w // 2
                center_y = y + h // 2
                people.append({
                    'x': center_x,
                    'y': center_y,
                    'width': w,
                    'height': h,
                    'confidence': weights[0]
                })
        
        return people
    
    def analyze_movement(self, people):
        """Analyze movement patterns and determine sequencing"""
        if not people:
            return None
        
        # Find the person closest to center
        center_x = 480  # Center of 960x480 frame
        closest_person = min(people, key=lambda p: abs(p['x'] - center_x))
        
        # Determine zone
        if closest_person['x'] < 320:
            zone = "left"
        elif closest_person['x'] > 640:
            zone = "right"
        else:
            zone = "center"
        
        # Track movement pattern
        self.person_positions.append({
            'zone': zone,
            'timestamp': time.time(),
            'person': closest_person
        })
        
        # Keep only recent positions (last 5 seconds)
        current_time = time.time()
        self.person_positions = [p for p in self.person_positions 
                               if current_time - p['timestamp'] < 5.0]
        
        # Analyze sequence pattern
        if len(self.person_positions) >= 3:
            zones = [p['zone'] for p in self.person_positions]
            
            # Check for left -> center -> right pattern
            if "left" in zones and "center" in zones and "right" in zones:
                # Simple pattern detection - could be enhanced
                return "sequence_complete"
            
            # Check for center presence (ready for announcement)
            if zone == "center" and len([p for p in self.person_positions if p['zone'] == "center"]) >= 2:
                return "ready_for_announcement"
        
        return None
    
    def draw_detection_zones(self, frame):
        """Draw detection zones on the frame"""
        # Left zone
        cv2.rectangle(frame, (self.left_zone[0], self.left_zone[1]), 
                     (self.left_zone[0] + self.left_zone[2], self.left_zone[1] + self.left_zone[3]), 
                     (0, 255, 0), 2)
        cv2.putText(frame, "LEFT", (10, 30), cv2.FONT_HERSHEY_SIMPLEX, 1, (0, 255, 0), 2)
        
        # Center zone
        cv2.rectangle(frame, (self.center_zone[0], self.center_zone[1]), 
                     (self.center_zone[0] + self.center_zone[2], self.center_zone[1] + self.center_zone[3]), 
                     (255, 0, 0), 2)
        cv2.putText(frame, "CENTER", (330, 30), cv2.FONT_HERSHEY_SIMPLEX, 1, (255, 0, 0), 2)
        
        # Right zone
        cv2.rectangle(frame, (self.right_zone[0], self.right_zone[1]), 
                     (self.right_zone[0] + self.right_zone[2], self.right_zone[1] + self.right_zone[3]), 
                     (0, 0, 255), 2)
        cv2.putText(frame, "RIGHT", (650, 30), cv2.FONT_HERSHEY_SIMPLEX, 1, (0, 0, 255), 2)
    
    def draw_people(self, frame, people):
        """Draw detected people on the frame"""
        for person in people:
            x, y, w, h = person['x'] - person['width']//2, person['y'] - person['height']//2, person['width'], person['height']
            cv2.rectangle(frame, (x, y), (x + w, y + h), (0, 255, 255), 2)
            cv2.putText(frame, f"Person ({person['confidence']:.2f})", 
                       (x, y - 10), cv2.FONT_HERSHEY_SIMPLEX, 0.5, (0, 255, 255), 1)
    
    def draw_status(self, frame, status, next_graduate):
        """Draw status information on the frame"""
        # Status text
        cv2.putText(frame, f"Status: {status}", (10, 450), cv2.FONT_HERSHEY_SIMPLEX, 0.7, (255, 255, 255), 2)
        
        # Next graduate info
        if next_graduate:
            cv2.putText(frame, f"Next: {next_graduate['full_name']}", (10, 470), 
                       cv2.FONT_HERSHEY_SIMPLEX, 0.6, (255, 255, 255), 1)
    
    def process_frame(self, frame):
        """Process a single frame for detection and analysis"""
        # Detect people
        people = self.detect_people(frame)
        
        # Analyze movement
        movement_result = self.analyze_movement(people)
        
        # Get next graduate from queue
        next_graduate = self.get_next_queued_graduate()
        
        # Handle sequencing logic
        if movement_result == "ready_for_announcement" and next_graduate:
            if (not self.last_announcement or 
                (datetime.now() - self.last_announcement).seconds > 3):
                self.announce_graduate(next_graduate['id'])
        
        # Draw visual elements
        self.draw_detection_zones(frame)
        self.draw_people(frame, people)
        self.draw_status(frame, movement_result or "detecting", next_graduate)
        
        return frame, people, movement_result
    
    def start_camera(self, camera_index=0):
        """Start the camera capture"""
        try:
            # Release existing camera if any
            if self.cap:
                self.cap.release()
            
            self.cap = cv2.VideoCapture(camera_index)
            if not self.cap.isOpened():
                print(f"Error: Could not open camera {camera_index}")
                return False
            
            # Set camera properties with error handling
            self.cap.set(cv2.CAP_PROP_FRAME_WIDTH, 960)
            self.cap.set(cv2.CAP_PROP_FRAME_HEIGHT, 480)
            self.cap.set(cv2.CAP_PROP_FPS, 30)
            
            # Test if camera actually works by reading a frame
            ret, test_frame = self.cap.read()
            if not ret or test_frame is None:
                print("Error: Camera opened but cannot read frames")
                self.cap.release()
                return False
            
            print(f"Camera {camera_index} started successfully")
            return True
            
        except Exception as e:
            print(f"Error starting camera: {e}")
            if self.cap:
                self.cap.release()
            return False
    
    def run_detection(self):
        """Main detection loop"""
        # Retry database connection with backoff
        max_db_retries = 5
        db_retry_count = 0
        while db_retry_count < max_db_retries:
            if self.connect_database():
                break
            db_retry_count += 1
            print(f"Database connection failed, retrying... ({db_retry_count}/{max_db_retries})")
            time.sleep(2)
        
        if db_retry_count >= max_db_retries:
            print("Failed to connect to database after multiple attempts")
            return
        
        # Retry camera connection with backoff
        max_camera_retries = 5
        camera_retry_count = 0
        camera_available = False
        
        while camera_retry_count < max_camera_retries:
            if self.start_camera():
                camera_available = True
                break
            camera_retry_count += 1
            print(f"Camera connection failed, retrying... ({camera_retry_count}/{max_camera_retries})")
            time.sleep(2)
        
        if not camera_available:
            print("Failed to start camera after multiple attempts")
            print("Running in simulation mode (no camera)")
            self.cap = None  # Set to None to indicate no camera
        
        self.is_running = True
        print("Stage detection system started successfully.")
        
        # Track consecutive frame failures
        consecutive_failures = 0
        max_consecutive_failures = 10
        
        try:
            while self.is_running:
                if self.cap is None:
                    # Simulation mode - no camera available
                    print("Running in simulation mode - checking for manual announcements...")
                    
                    # Check for manual announcement trigger (simulate detection)
                    next_graduate = self.get_next_queued_graduate()
                    if next_graduate:
                        # In simulation mode, we can manually trigger announcements
                        # This allows testing the system without a camera
                        print(f"Simulation: Next graduate ready: {next_graduate['full_name']}")
                    
                    time.sleep(5)  # Check every 5 seconds in simulation mode
                    continue
                
                # Normal camera mode
                ret, frame = self.cap.read()
                if not ret:
                    consecutive_failures += 1
                    print(f"Failed to grab frame ({consecutive_failures}/{max_consecutive_failures})")
                    
                    if consecutive_failures >= max_consecutive_failures:
                        print("Too many consecutive frame failures, attempting camera restart...")
                        self.cap.release()
                        time.sleep(1)
                        if self.start_camera():
                            consecutive_failures = 0
                            print("Camera restarted successfully")
                        else:
                            print("Failed to restart camera, switching to simulation mode...")
                            self.cap = None
                            continue
                    else:
                        time.sleep(0.1)  # Short delay before retry
                        continue
                
                # Reset failure counter on successful frame
                consecutive_failures = 0
                
                # Process frame
                processed_frame, people, movement_result = self.process_frame(frame)
                
                # Only display frame if we have a display (not running in background)
                try:
                    # Check if we're running in a headless environment
                    import os
                    if 'DISPLAY' in os.environ or os.name == 'nt':
                        cv2.imshow('Stage Detection System', processed_frame)
                        # Handle key presses only if window is available
                        key = cv2.waitKey(1) & 0xFF
                        if key == ord('q'):
                            break
                        elif key == ord('a'):  # Manual announcement trigger
                            next_graduate = self.get_next_queued_graduate()
                            if next_graduate:
                                self.announce_graduate(next_graduate['id'])
                except cv2.error:
                    # Running in headless mode, skip display
                    pass
                
                # Small delay to prevent excessive CPU usage
                time.sleep(0.03)
                
        except KeyboardInterrupt:
            print("Detection stopped by user")
        except Exception as e:
            print(f"Unexpected error in detection loop: {e}")
        finally:
            self.cleanup()
    
    def cleanup(self):
        """Clean up resources"""
        self.is_running = False
        if self.cap:
            self.cap.release()
        cv2.destroyAllWindows()
        if hasattr(self, 'conn'):
            self.conn.close()
    
    def start(self):
        """Start the detection system in a separate thread"""
        if self.detection_thread and self.detection_thread.is_alive():
            return False
        
        self.detection_thread = threading.Thread(target=self.run_detection)
        self.detection_thread.daemon = True
        self.detection_thread.start()
        return True
    
    def stop(self):
        """Stop the detection system"""
        self.is_running = False
        if self.detection_thread:
            self.detection_thread.join(timeout=2.0)

def main():
    """Main function to run the stage detection system"""
    detector = StageDetector()
    
    try:
        detector.start()
        # Keep main thread alive
        while detector.is_running:
            time.sleep(1)
    except KeyboardInterrupt:
        print("Shutting down...")
    finally:
        detector.stop()

if __name__ == "__main__":
    main() 
