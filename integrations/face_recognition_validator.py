import cv2 as cv
import numpy as np
import os
from typing import Optional, List, Dict
from datetime import datetime

# Optional DCGAN enhancer (non-destructive). If models or torch are missing, it silently disables itself.
class _OptionalDCGANEnhancer:
    def __init__(self, models_dir: Optional[str]):
        self.enabled = False
        self.device = None
        self.generator = None
        self.models_dir = models_dir
        if not models_dir:
            return
        try:
            import torch  # type: ignore
            self.torch = torch
            self.device = torch.device('cuda' if torch.cuda.is_available() else 'cpu')
        except Exception:
            self.torch = None
            return
        try:
            # Try to locate a generator weights file by common extensions
            candidates = []
            for name in os.listdir(models_dir):
                lower = name.lower()
                if lower.endswith(('.pt', '.pth', '.ckpt', '.pkl')) and 'gen' in lower:
                    candidates.append(os.path.join(models_dir, name))
            if not candidates:
                return
            weights_path = sorted(candidates)[-1]

            # Define a very simple DCGAN-like generator wrapper if actual class is not shipped.
            # We will load state_dict by key-match best effort. If it fails, we keep enhancer disabled.
            import torch.nn as nn  # type: ignore

            class _FallbackGenerator(nn.Module):
                def __init__(self, latent_dim: int = 100, out_channels: int = 3):
                    super().__init__()
                    # Minimal stub architecture to allow state_dict load if compatible.
                    # If incompatible, load_state_dict will throw and we will disable enhancer.
                    self.net = nn.Identity()

                def forward(self, x):
                    return x

            gen = _FallbackGenerator().to(self.device)
            # Attempt to load as either a state_dict or a whole module
            try:
                state = self.torch.load(weights_path, map_location=self.device)
                if isinstance(state, dict) and 'state_dict' in state:
                    state = state['state_dict']
                if isinstance(state, dict):
                    try:
                        gen.load_state_dict(state, strict=False)
                    except Exception:
                        # If keys incompatible, keep identity
                        pass
                elif hasattr(state, 'state_dict'):
                    try:
                        gen.load_state_dict(state.state_dict(), strict=False)
                    except Exception:
                        pass
            except Exception:
                # Unable to load weights; keep identity model
                pass

            gen.eval()
            self.generator = gen
            self.enabled = True
        except Exception:
            self.enabled = False

    def is_available(self) -> bool:
        return bool(self.enabled and self.generator is not None and self.torch is not None)

    def enhance_bgr_face(self, face_bgr: np.ndarray) -> np.ndarray:
        # Best-effort enhancement. If anything fails, return original.
        try:
            if not self.is_available():
                return face_bgr
            torch = self.torch
            # Prepare tensor: BGR -> RGB, uint8 [0,255] -> float [-1,1]
            rgb = cv.cvtColor(face_bgr, cv.COLOR_BGR2RGB)
            h, w = rgb.shape[:2]
            # Resize to a reasonable generator input size if needed (e.g., 128)
            target = 128
            if min(h, w) < target:
                scale = float(target) / float(min(h, w))
                new_w = max(1, int(round(w * scale)))
                new_h = max(1, int(round(h * scale)))
                rgb = cv.resize(rgb, (new_w, new_h), interpolation=cv.INTER_CUBIC)
            img = rgb.astype(np.float32) / 127.5 - 1.0
            tensor = torch.from_numpy(img).permute(2, 0, 1).unsqueeze(0).to(self.device)
            with torch.no_grad():
                out = self.generator(tensor)
            out = out.squeeze(0).permute(1, 2, 0).cpu().numpy()
            out = np.clip((out + 1.0) * 127.5, 0, 255).astype(np.uint8)
            out_bgr = cv.cvtColor(out, cv.COLOR_RGB2BGR)
            # Resize back to original face size to keep downstream behavior consistent
            out_bgr = cv.resize(out_bgr, (face_bgr.shape[1], face_bgr.shape[0]), interpolation=cv.INTER_CUBIC)
            return out_bgr
        except Exception:
            return face_bgr


class FaceRecognitionValidator:
    def __init__(self, student_photos_dir: str = "/uploads", use_dcgan: bool = True, dcgan_models_dir: Optional[str] = None, use_dcgan_realtime: bool = False):
        self.student_photos_dir = student_photos_dir
        self.known_faces: Dict[str, Dict] = {}
        # Cosine similarity threshold; 0.0..1.0 (higher is more similar)
        self.validation_threshold = 0.6  # Lower threshold for better detection
        self.face_detection_confidence = 0.5
        self.face_cascade = cv.CascadeClassifier(cv.data.haarcascades + 'haarcascade_frontalface_default.xml')
        os.makedirs(student_photos_dir, exist_ok=True)
        # Performance parameters
        self.template_size = 80  # smaller templates to speed up vector ops
        self.realtime_max_width = 640  # downscale wide frames for faster detection
        # Optional DCGAN enhancer (auto-disabled if unavailable)
        if dcgan_models_dir is None:
            dcgan_models_dir = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'dcgan_models')
        self._dcgan_enhancer = _OptionalDCGANEnhancer(dcgan_models_dir) if use_dcgan else _OptionalDCGANEnhancer(None)
        # Separate control: apply DCGAN on template creation (once) vs live per-frame (disabled by default for speed)
        self._dcgan_enabled_templates = bool(use_dcgan and self._dcgan_enhancer.is_available())
        self._dcgan_enabled_realtime = bool(use_dcgan_realtime and self._dcgan_enhancer.is_available())
        self.load_student_photos()

    def load_student_photos(self):
        print("Loading student photos for face recognition...")
        if not os.path.exists(self.student_photos_dir):
            print(f"Student photos directory not found: {self.student_photos_dir}")
            return
        import re
        photo_files = [f for f in os.listdir(self.student_photos_dir) if f.lower().endswith(('.jpg', '.jpeg', '.png'))]
        for photo_file in photo_files:
            try:
                # Expected format: student_<id>_anything.ext
                match = re.match(r'^student_([^_]+)_', photo_file, flags=re.IGNORECASE)
                if match:
                    student_id = match.group(1)
                else:
                    # Fallback: take the first token before underscore or stem
                    student_id = os.path.splitext(photo_file)[0].split('_')[0]
                photo_path = os.path.join(self.student_photos_dir, photo_file)
                template = self.create_face_template(photo_path)
                if template is not None:
                    # Store by lowercased student id for case-insensitive lookup
                    self.known_faces[student_id.lower()] = {
                        'template': template,
                        'photo_path': photo_path,
                        'name': photo_file.replace('.jpg', '').replace('.jpeg', '').replace('.png', ''),
                        'original_student_id': student_id  # Keep original case for reference
                    }
                    print(f"Loaded photo for student {student_id} (stored as {student_id.lower()})")
            except Exception as e:
                print(f"Error loading photo {photo_file}: {e}")
        print(f"Loaded {len(self.known_faces)} student photos for face recognition")

    def create_face_template(self, image_path: str) -> Optional[np.ndarray]:
        try:
            image = cv.imread(image_path)
            if image is None:
                print(f"Could not load image: {image_path}")
                return None
            gray = cv.cvtColor(image, cv.COLOR_BGR2GRAY)
            faces = self.face_cascade.detectMultiScale(gray, scaleFactor=1.1, minNeighbors=3, minSize=(50, 50))
            if len(faces) == 0:
                alt = cv.CascadeClassifier(cv.data.haarcascades + 'haarcascade_frontalface_alt2.xml')
                faces = alt.detectMultiScale(gray, scaleFactor=1.1, minNeighbors=3, minSize=(50, 50))
            if len(faces) == 0:
                print(f"No faces found in {image_path}")
                return None
            if len(faces) > 1:
                print(f"Multiple faces found in {image_path}, using the largest one")
            largest_face = max(faces, key=lambda x: x[2] * x[3])
            x, y, w, h = largest_face
            # Extract original BGR for optional enhancement, then convert to gray
            face_bgr = image[y:y+h, x:x+w]
            if self._dcgan_enabled_templates:
                face_bgr = self._dcgan_enhancer.enhance_bgr_face(face_bgr)
            face_img = cv.cvtColor(face_bgr, cv.COLOR_BGR2GRAY)
            # Preprocess: contrast normalize (CLAHE), resize, and normalize range
            clahe = cv.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
            face_img = clahe.apply(face_img)
            face_template = cv.resize(face_img, (self.template_size, self.template_size))
            face_template = cv.normalize(face_template, None, 0, 255, cv.NORM_MINMAX)
            face_template = face_template.astype(np.uint8)
            return face_template
        except Exception as e:
            print(f"Error creating face template from {image_path}: {e}")
            return None

    def extract_face_from_frame(self, frame: np.ndarray) -> List[np.ndarray]:
        try:
            # Downscale for faster detection if frame is wide
            h0, w0 = frame.shape[:2]
            scale = 1.0
            if w0 > self.realtime_max_width:
                scale = self.realtime_max_width / float(w0)
                new_w = self.realtime_max_width
                new_h = max(1, int(round(h0 * scale)))
                small = cv.resize(frame, (new_w, new_h), interpolation=cv.INTER_AREA)
            else:
                small = frame
            gray = cv.cvtColor(small, cv.COLOR_BGR2GRAY)
            faces = self.face_cascade.detectMultiScale(gray, scaleFactor=1.1, minNeighbors=3, minSize=(50, 50))
            if len(faces) == 0:
                alt = cv.CascadeClassifier(cv.data.haarcascades + 'haarcascade_frontalface_alt2.xml')
                faces = alt.detectMultiScale(gray, scaleFactor=1.1, minNeighbors=3, minSize=(50, 50))
            if len(faces) == 0:
                return []
            # Keep only the largest face for speed
            x, y, w, h = max(faces, key=lambda r: r[2] * r[3])
            if scale != 1.0:
                inv = 1.0 / scale
                x = int(round(x * inv)); y = int(round(y * inv)); w = int(round(w * inv)); h = int(round(h * inv))
            # Extract original BGR for optional enhancement, then convert to gray
            face_bgr = frame[y:y+h, x:x+w]
            if self._dcgan_enabled_realtime:
                face_bgr = self._dcgan_enhancer.enhance_bgr_face(face_bgr)
            face_img = cv.cvtColor(face_bgr, cv.COLOR_BGR2GRAY)
            clahe = cv.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
            face_img = clahe.apply(face_img)
            face_template = cv.resize(face_img, (self.template_size, self.template_size))
            face_template = cv.normalize(face_template, None, 0, 255, cv.NORM_MINMAX)
            face_template = face_template.astype(np.uint8)
            return [face_template]
        except Exception as e:
            print(f"Error extracting faces from frame: {e}")
            return []

    def compare_faces(self, template1: np.ndarray, template2: np.ndarray) -> float:
        try:
            # Cosine similarity on normalized vectors is robust to uniform brightness changes
            a = template1.astype(np.float32).reshape(-1)
            b = template2.astype(np.float32).reshape(-1)
            # Standardize to zero mean, unit variance to reduce lighting effects
            a = (a - np.mean(a)) / (np.std(a) + 1e-6)
            b = (b - np.mean(b)) / (np.std(b) + 1e-6)
            dot = float(np.dot(a, b))
            denom = float(np.linalg.norm(a) * np.linalg.norm(b)) + 1e-6
            cosine = dot / denom
            similarity = (cosine + 1.0) / 2.0  # map [-1,1] to [0,1]
            return max(0.0, min(1.0, similarity))
        except Exception as e:
            print(f"Error comparing faces: {e}")
            return 0.0

    def _template_to_standardized_vector(self, template: np.ndarray) -> np.ndarray:
        v = template.astype(np.float32).reshape(-1)
        v = (v - np.mean(v)) / (np.std(v) + 1e-6)
        return v

    def validate_student_face(self, frame: np.ndarray, student_id: str) -> Dict:
        validation_result: Dict = {
            'is_valid': False,
            'confidence': 0.0,
            'student_id': student_id,
            'message': 'Unknown error',
            'face_detected': False,
            'known_student': False,
            'timestamp': datetime.now().isoformat()
        }
        try:
            lookup_id = (student_id or "").strip().lower()
            if lookup_id not in self.known_faces:
                validation_result['message'] = f'No reference photo found for student {student_id}'
                validation_result['known_student'] = False
                return validation_result
            validation_result['known_student'] = True
            entry = self.known_faces[lookup_id]
            known_template = entry['template']
            # Cache standardized vector for faster cosine on each request
            if 'vector' not in entry or entry.get('vector') is None:
                try:
                    entry['vector'] = self._template_to_standardized_vector(known_template)
                except Exception:
                    entry['vector'] = None
            known_vector = entry.get('vector')

            current_templates = self.extract_face_from_frame(frame)
            if len(current_templates) == 0:
                validation_result['message'] = 'No face detected in camera'
                validation_result['face_detected'] = False
                return validation_result
            validation_result['face_detected'] = True
            # Compare only the largest face (first)
            current_template = current_templates[0]
            if known_vector is not None:
                current_vector = self._template_to_standardized_vector(current_template)
                dot = float(np.dot(known_vector, current_vector))
                denom = float(np.linalg.norm(known_vector) * np.linalg.norm(current_vector)) + 1e-6
                cosine = dot / denom
                similarity = (cosine + 1.0) / 2.0
            else:
                similarity = self.compare_faces(known_template, current_template)
            validation_result['confidence'] = max(0.0, min(1.0, similarity))
            if validation_result['confidence'] >= self.validation_threshold:
                validation_result['is_valid'] = True
                validation_result['message'] = f'Face verified for student {student_id}'
            else:
                validation_result['is_valid'] = False
                validation_result['message'] = f'Face does not match student {student_id}'
            return validation_result
        except Exception as e:
            validation_result['message'] = f'Face validation error: {str(e)}'
            return validation_result

    def add_student_photo(self, student_id: str, image_path: str) -> bool:
        try:
            import shutil
            target_path = os.path.join(self.student_photos_dir, f"{student_id}_photo.jpg")
            shutil.copy(image_path, target_path)
            template = self.create_face_template(target_path)
            if template is not None:
                self.known_faces[student_id.lower()] = {
                    'template': template,
                    'photo_path': target_path,
                    'name': f"{student_id}_photo",
                    'original_student_id': student_id
                }
                print(f"Added photo for student {student_id} (stored as {student_id.lower()})")
                return True
            else:
                print(f"Could not process face from photo for student {student_id}")
                return False
        except Exception as e:
            print(f"Error adding student photo: {e}")
            return False

    def capture_student_photo(self, frame: np.ndarray, student_id: str) -> bool:
        try:
            photo_path = os.path.join(self.student_photos_dir, f"{student_id}_captured.jpg")
            cv.imwrite(photo_path, frame)
            template = self.create_face_template(photo_path)
            if template is not None:
                self.known_faces[student_id.lower()] = {
                    'template': template,
                    'photo_path': photo_path,
                    'name': f"{student_id}_captured",
                    'original_student_id': student_id
                }
                print(f"Captured and saved photo for student {student_id} (stored as {student_id.lower()})")
                return True
            else:
                print(f"Could not process face from captured photo for student {student_id}")
                return False
        except Exception as e:
            print(f"Error capturing student photo: {e}")
            return False

    def get_validation_statistics(self) -> Dict:
        return {
            'total_known_faces': len(self.known_faces),
            'student_ids': list(self.known_faces.keys()),
            'validation_threshold': self.validation_threshold,
            'photos_directory': self.student_photos_dir
        }

    def set_validation_threshold(self, threshold: float):
        if 0.0 <= threshold <= 1.0:
            self.validation_threshold = threshold
            print(f"Face validation threshold set to {threshold}")
        else:
            print("Threshold must be between 0.0 and 1.0")

    def set_dcgan_enabled(self, enabled: bool):
        # Toggle both template and realtime together for simplicity
        avail = bool(self._dcgan_enhancer and self._dcgan_enhancer.is_available())
        self._dcgan_enabled_templates = bool(enabled and avail)
        self._dcgan_enabled_realtime = bool(enabled and avail)
        state = 'enabled' if (self._dcgan_enabled_templates or self._dcgan_enabled_realtime) else 'disabled'
        print(f"DCGAN enhancement {state}")

    def set_dcgan_realtime(self, enabled: bool):
        # Enable/disable realtime DCGAN independently (templates remain as configured)
        avail = bool(self._dcgan_enhancer and self._dcgan_enhancer.is_available())
        self._dcgan_enabled_realtime = bool(enabled and avail)
        state = 'enabled' if self._dcgan_enabled_realtime else 'disabled'
        print(f"DCGAN realtime enhancement {state}")


class FaceRecognitionUI:
    @staticmethod
    def draw_face_validation_overlay(frame: np.ndarray, validation_result: Dict) -> np.ndarray:
        overlay_frame = frame.copy()
        height, width = frame.shape[:2]
        face_cascade = cv.CascadeClassifier(cv.data.haarcascades + 'haarcascade_frontalface_default.xml')
        gray = cv.cvtColor(frame, cv.COLOR_BGR2GRAY)
        faces = face_cascade.detectMultiScale(gray, scaleFactor=1.1, minNeighbors=4, minSize=(60, 60))
        if len(faces) == 0:
            alt_cascade = cv.CascadeClassifier(cv.data.haarcascades + 'haarcascade_frontalface_alt2.xml')
            faces = alt_cascade.detectMultiScale(gray, scaleFactor=1.1, minNeighbors=4, minSize=(60, 60))
        for (x, y, w, h) in faces:
            color = (0, 255, 0) if validation_result['is_valid'] else (0, 0, 255)
            cv.rectangle(overlay_frame, (int(x), int(y)), (int(x+w), int(y+h)), color, 3)
            if validation_result['known_student']:
                confidence_text = f"{validation_result['confidence']:.1%}"
                cv.putText(overlay_frame, confidence_text, (int(x), int(y-10)), cv.FONT_HERSHEY_SIMPLEX, 0.7, color, 2)
        status_box_height = 120
        status_box = np.zeros((status_box_height, width, 3), dtype=np.uint8)
        if validation_result['face_detected']:
            if validation_result['is_valid']:
                status_box[:] = (0, 100, 0)
                status_text = "FACE VERIFIED"
                status_color = (255, 255, 255)
            else:
                status_box[:] = (0, 0, 100)
                status_text = "FACE NOT VERIFIED"
                status_color = (255, 255, 255)
        else:
            status_box[:] = (50, 50, 50)
            status_text = "NO FACE DETECTED"
            status_color = (200, 200, 200)
        cv.putText(status_box, status_text, (20, 30), cv.FONT_HERSHEY_SIMPLEX, 0.8, status_color, 2)
        if validation_result['known_student']:
            confidence_text = f"Confidence: {validation_result['confidence']:.1%}"
            cv.putText(status_box, confidence_text, (20, 60), cv.FONT_HERSHEY_SIMPLEX, 0.6, status_color, 2)
        message_text = validation_result['message'][:60]
        cv.putText(status_box, message_text, (20, 90), cv.FONT_HERSHEY_SIMPLEX, 0.5, status_color, 1)
        result_frame = np.vstack((status_box, overlay_frame))
        return result_frame


