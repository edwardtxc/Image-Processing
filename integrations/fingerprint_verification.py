#!/usr/bin/env python3
"""
Fingerprint Verification System
Compares captured fingerprint images with stored reference fingerprints
"""

import cv2
import numpy as np
import os
import sys
import json
import argparse
from pathlib import Path
from typing import Dict, List, Tuple, Optional
import logging

# Custom JSON encoder to handle numpy types
class NumpyEncoder(json.JSONEncoder):
    def default(self, obj):
        if isinstance(obj, np.integer):
            return int(obj)
        elif isinstance(obj, np.floating):
            return float(obj)
        elif isinstance(obj, np.ndarray):
            return obj.tolist()
        return super(NumpyEncoder, self).default(obj)

# Configure logging - only enable if not called from API
if '--captured' in sys.argv:
    # Called from API, disable logging to avoid JSON interference
    logging.basicConfig(level=logging.ERROR, stream=sys.stderr)
else:
    # Called directly, enable detailed logging
    logging.basicConfig(level=logging.INFO, format='%(levelname)s:%(name)s:%(message)s', stream=sys.stderr)

logger = logging.getLogger(__name__)

class FingerprintVerifier:
    def __init__(self, uploads_dir: str = "uploads"):
        self.uploads_dir = Path(uploads_dir)
        self.min_match_score = 0.25  # Even lower threshold for easier matching
        
    def preprocess_fingerprint(self, image_path: str) -> Optional[np.ndarray]:
        """
        Preprocess fingerprint image for comparison
        """
        try:
            # Read image
            img = cv2.imread(image_path)
            if img is None:
                logger.error(f"Could not read image: {image_path}")
                return None
                
            # Convert to grayscale
            gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
            
            # Apply Gaussian blur to reduce noise
            blurred = cv2.GaussianBlur(gray, (5, 5), 0)
            
            # Apply adaptive thresholding to enhance fingerprint ridges
            thresh = cv2.adaptiveThreshold(
                blurred, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, 
                cv2.THRESH_BINARY, 11, 2
            )
            
            # Apply morphological operations to clean up the image
            kernel = np.ones((3, 3), np.uint8)
            cleaned = cv2.morphologyEx(thresh, cv2.MORPH_CLOSE, kernel)
            cleaned = cv2.morphologyEx(cleaned, cv2.MORPH_OPEN, kernel)
            
            # Resize to standard size for comparison
            resized = cv2.resize(cleaned, (256, 256))
            
            return resized
            
        except Exception as e:
            logger.error(f"Error preprocessing fingerprint: {e}")
            return None
    
    def extract_features(self, image: np.ndarray) -> Dict:
        """
        Extract features from fingerprint image
        """
        try:
            features = {}
            
            # 1. Ridge orientation analysis
            # Apply Gabor filter to detect ridge patterns
            angles = [0, 45, 90, 135]
            gabor_responses = []
            
            for angle in angles:
                kernel = cv2.getGaborKernel((21, 21), 8.0, np.radians(angle), 10.0, 0.5, 0, ktype=cv2.CV_32F)
                response = cv2.filter2D(image, cv2.CV_8UC3, kernel)
                gabor_responses.append(response)
            
            features['gabor_responses'] = gabor_responses
            
            # 2. Minutiae points (simplified)
            # Find corners using Harris corner detection
            corners = cv2.cornerHarris(image.astype(np.float32), 2, 3, 0.04)
            corner_points = np.where(corners > 0.01 * corners.max())
            features['corner_points'] = [(int(x), int(y)) for x, y in zip(corner_points[1], corner_points[0])]
            
            # 3. Ridge density
            # Count ridge pixels vs background
            ridge_pixels = np.sum(image < 128)  # Assuming dark ridges
            total_pixels = image.size
            ridge_density = float(ridge_pixels / total_pixels)
            features['ridge_density'] = ridge_density
            
            # 4. Local Binary Pattern (LBP) for texture analysis
            lbp = self._compute_lbp(image)
            features['lbp_histogram'] = np.histogram(lbp, bins=256, range=(0, 256))[0].tolist()
            
            return features
            
        except Exception as e:
            logger.error(f"Error extracting features: {e}")
            return {}
    
    def _compute_lbp(self, image: np.ndarray) -> np.ndarray:
        """
        Compute Local Binary Pattern
        """
        lbp = np.zeros_like(image)
        for i in range(1, image.shape[0] - 1):
            for j in range(1, image.shape[1] - 1):
                center = image[i, j]
                code = 0
                # 8-neighbor comparison
                neighbors = [
                    image[i-1, j-1], image[i-1, j], image[i-1, j+1],
                    image[i, j+1], image[i+1, j+1], image[i+1, j],
                    image[i+1, j-1], image[i, j-1]
                ]
                for k, neighbor in enumerate(neighbors):
                    if neighbor >= center:
                        code |= (1 << k)
                lbp[i, j] = code
        return lbp
    
    def compare_fingerprints(self, features1: Dict, features2: Dict) -> float:
        """
        Compare two fingerprint feature sets and return similarity score
        """
        try:
            score = 0.0
            comparisons = 0
            weights = {}
            
            # 1. Compare LBP histograms (40% weight)
            if 'lbp_histogram' in features1 and 'lbp_histogram' in features2:
                hist1 = np.array(features1['lbp_histogram'])
                hist2 = np.array(features2['lbp_histogram'])
                
                # Normalize histograms
                hist1 = hist1 / (np.sum(hist1) + 1e-8)
                hist2 = hist2 / (np.sum(hist2) + 1e-8)
                
                # Calculate multiple similarity measures
                # Correlation
                correlation = np.corrcoef(hist1, hist2)[0, 1]
                if not np.isnan(correlation):
                    correlation_score = max(0, correlation)
                else:
                    correlation_score = 0.0
                
                # Cosine similarity
                cosine_sim = np.dot(hist1, hist2) / (np.linalg.norm(hist1) * np.linalg.norm(hist2) + 1e-8)
                cosine_score = max(0, cosine_sim)
                
                # Chi-square distance (inverted to similarity)
                chi_square = np.sum((hist1 - hist2) ** 2 / (hist1 + hist2 + 1e-8))
                chi_square_score = max(0, 1 - chi_square / 100)  # Normalize
                
                # Use the best of the three measures
                lbp_score = max(correlation_score, cosine_score, chi_square_score)
                score += lbp_score * 0.4
                comparisons += 1
                weights['lbp'] = lbp_score
                
                logger.info(f"LBP comparison - Correlation: {correlation_score:.3f}, Cosine: {cosine_score:.3f}, Chi-square: {chi_square_score:.3f}, Best: {lbp_score:.3f}")
            
            # 2. Compare ridge density (30% weight)
            if 'ridge_density' in features1 and 'ridge_density' in features2:
                density1 = features1['ridge_density']
                density2 = features2['ridge_density']
                density_diff = abs(density1 - density2)
                
                # More lenient normalization
                density_similarity = max(0, 1 - density_diff / 0.2)  # Increased tolerance
                score += density_similarity * 0.3
                comparisons += 1
                weights['density'] = density_similarity
                
                logger.info(f"Ridge density comparison - D1: {density1:.3f}, D2: {density2:.3f}, Diff: {density_diff:.3f}, Similarity: {density_similarity:.3f}")
            
            # 3. Compare corner point distributions (30% weight)
            if 'corner_points' in features1 and 'corner_points' in features2:
                points1 = features1['corner_points']
                points2 = features2['corner_points']
                
                if len(points1) > 0 and len(points2) > 0:
                    # Calculate average distance between points
                    distances = []
                    for p1 in points1[:min(10, len(points1))]:  # Limit to first 10 points
                        min_dist = float('inf')
                        for p2 in points2[:min(10, len(points2))]:
                            dist = np.sqrt((p1[0] - p2[0])**2 + (p1[1] - p2[1])**2)
                            min_dist = min(min_dist, dist)
                        distances.append(min_dist)
                    
                    if distances:
                        avg_distance = np.mean(distances)
                        # More lenient normalization
                        point_similarity = max(0, 1 - avg_distance / 100)  # Increased tolerance
                        score += point_similarity * 0.3
                        comparisons += 1
                        weights['points'] = point_similarity
                        
                        logger.info(f"Corner points comparison - Avg distance: {avg_distance:.1f}, Similarity: {point_similarity:.3f}")
                else:
                    # If no corner points found, give a neutral score
                    point_similarity = 0.5
                    score += point_similarity * 0.3
                    comparisons += 1
                    weights['points'] = point_similarity
                    logger.info(f"No corner points found, using neutral score: {point_similarity}")
            
            # Return weighted average score
            if comparisons > 0:
                final_score = float(score / comparisons)
                logger.info(f"Final comparison scores: {str(weights)}")
                logger.info(f"Final weighted score: {final_score:.3f}")
                return final_score
            else:
                logger.warning("No comparisons made, returning 0.0")
                return 0.0
                
        except Exception as e:
            logger.error(f"Error comparing fingerprints: {e}")
            return 0.0
    
    def verify_fingerprint(self, captured_image_path: str, student_id: str) -> Dict:
        """
        Verify captured fingerprint against stored reference
        """
        try:
            # Find reference fingerprint for student
            reference_pattern = f"fingerprint_{student_id}_*.png"
            reference_files = list(self.uploads_dir.glob(reference_pattern))
            
            logger.info(f"Looking for pattern: {reference_pattern}")
            logger.info(f"Found {len(reference_files)} reference files")
            for ref_file in reference_files:
                logger.info(f"Reference file: {ref_file}")
            
            if not reference_files:
                return {
                    'success': False,
                    'message': f'No reference fingerprint found for student {student_id} (pattern: {reference_pattern})',
                    'match_score': 0.0,
                    'is_valid': False
                }
            
            # Preprocess captured image
            captured_processed = self.preprocess_fingerprint(captured_image_path)
            if captured_processed is None:
                return {
                    'success': False,
                    'message': 'Failed to process captured fingerprint',
                    'match_score': 0.0,
                    'is_valid': False
                }
            
            # Extract features from captured image
            captured_features = self.extract_features(captured_processed)
            if not captured_features:
                return {
                    'success': False,
                    'message': 'Failed to extract features from captured fingerprint',
                    'match_score': 0.0,
                    'is_valid': False
                }
            
            # Compare with each reference fingerprint
            best_score = 0.0
            best_reference = None
            
            for reference_file in reference_files:
                # Preprocess reference image
                reference_processed = self.preprocess_fingerprint(str(reference_file))
                if reference_processed is None:
                    continue
                
                # Extract features from reference image
                reference_features = self.extract_features(reference_processed)
                if not reference_features:
                    continue
                
                # Compare fingerprints
                score = self.compare_fingerprints(captured_features, reference_features)
                
                if score > best_score:
                    best_score = score
                    best_reference = str(reference_file)
            
            # Determine if match is valid
            is_valid = best_score >= self.min_match_score
            
            return {
                'success': True,
                'message': 'Fingerprint verification completed',
                'match_score': float(best_score),
                'is_valid': bool(is_valid),
                'best_reference': str(best_reference) if best_reference else None,
                'threshold': float(self.min_match_score)
            }
            
        except Exception as e:
            logger.error(f"Error in fingerprint verification: {e}")
            return {
                'success': False,
                'message': f'Verification error: {str(e)}',
                'match_score': 0.0,
                'is_valid': False
            }

def main():
    parser = argparse.ArgumentParser(description='Fingerprint Verification System')
    parser.add_argument('--captured', required=True, help='Path to captured fingerprint image')
    parser.add_argument('--student-id', required=True, help='Student ID to verify against')
    parser.add_argument('--uploads-dir', default='uploads', help='Directory containing reference fingerprints')
    parser.add_argument('--output', help='Output file for results (JSON)')
    
    args = parser.parse_args()
    
    # Initialize verifier
    verifier = FingerprintVerifier(args.uploads_dir)
    
    # Perform verification
    result = verifier.verify_fingerprint(args.captured, args.student_id)
    
    # Output results
    if args.output:
        with open(args.output, 'w') as f:
            json.dump(result, f, indent=2, cls=NumpyEncoder)
    else:
        print(json.dumps(result, indent=2, cls=NumpyEncoder))
    
    # Exit with appropriate code
    sys.exit(0 if result['success'] else 1)

if __name__ == '__main__':
    main()
