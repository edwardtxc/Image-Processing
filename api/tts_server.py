import threading
import queue
import time
from flask import Flask, request, jsonify
from flask_cors import CORS

# Offline TTS using pyttsx3 (works on Windows)
import pyttsx3


def create_engine_with_malay_voice() -> pyttsx3.Engine:
    engine = pyttsx3.init()
    # Try to select a Malay/Malaysia voice if available
    selected_voice_id = None
    for v in engine.getProperty('voices'):
        name = (getattr(v, 'name', '') or '').lower()
        lang = ''
        # voice.languages can be bytes or list
        langs = getattr(v, 'languages', []) or []
        if isinstance(langs, (list, tuple)) and langs:
            raw = langs[0]
            if isinstance(raw, bytes):
                try:
                    lang = raw.decode('utf-8', errors='ignore').lower()
                except Exception:
                    lang = ''
            else:
                lang = str(raw).lower()
        elif hasattr(v, 'id'):
            lang = str(getattr(v, 'id', '')).lower()

        if 'ms' in lang or 'malay' in name or 'malaysia' in name:
            selected_voice_id = v.id
            break

    if selected_voice_id:
        engine.setProperty('voice', selected_voice_id)
    # Slightly slower and clearer pace for ceremony announcements
    try:
        engine.setProperty('rate', 165)
        engine.setProperty('volume', 1.0)
    except Exception:
        pass
    return engine


class TTSWorker(threading.Thread):
    def __init__(self):
        super().__init__(daemon=True)
        self.engine = create_engine_with_malay_voice()
        self.tasks: "queue.Queue[dict]" = queue.Queue(maxsize=100)
        self._stop_event = threading.Event()

    def run(self):
        while not self._stop_event.is_set():
            try:
                task = self.tasks.get(timeout=0.5)
            except queue.Empty:
                continue

            try:
                text = task.get('text') or ''
                if not text.strip():
                    continue
                # Speak synchronously (queue ensures one at a time)
                self.engine.say(text)
                self.engine.runAndWait()
            except Exception:
                # Keep service alive even if one utterance fails
                try:
                    self.engine.stop()
                except Exception:
                    pass
                # Recreate engine in case it crashed
                try:
                    self.engine = create_engine_with_malay_voice()
                except Exception:
                    time.sleep(0.5)
            finally:
                self.tasks.task_done()

    def enqueue(self, text: str):
        try:
            self.tasks.put_nowait({'text': text})
            return True
        except queue.Full:
            return False

    def stop(self):
        self._stop_event.set()


app = Flask(__name__)
CORS(app)
worker = TTSWorker()
worker.start()


@app.route('/health', methods=['GET'])
def health():
    return jsonify({
        'status': 'ok',
        'queue_size': worker.tasks.qsize()
    })


@app.route('/speak', methods=['POST'])
def speak():
    data = request.get_json(silent=True) or {}
    full_name = (data.get('full_name') or '').strip()
    program = (data.get('program') or '').strip()
    student_id = (data.get('student_id') or '').strip()

    # Compose Malay-style announcement
    # Example: "Sila beri tepukan kepada graduan seterusnya: Ali Bin Abu, Program Sains Komputer, ID Pelajar 2411111. Tahniah!"
    if full_name:
        parts = [
            "Sila beri tepukan kepada graduan seterusnya:",
            full_name,
        ]
        if program:
            parts.append(f"Program {program}")
        if student_id:
            parts.append(f"ID Pelajar {student_id}")
        parts.append("Tahniah!")
        text = ", ".join(parts)
    else:
        text = data.get('text') or ''

    if not text.strip():
        return jsonify({'success': False, 'message': 'Missing text/full_name'}), 400

    ok = worker.enqueue(text)
    if not ok:
        return jsonify({'success': False, 'message': 'Queue full'}), 503
    return jsonify({'success': True})


def main():
    # Allow overriding host/port via environment variables for remote access
    import os
    host = os.environ.get('TTS_HOST', '127.0.0.1')
    try:
        port = int(os.environ.get('TTS_PORT', '5111'))
    except Exception:
        port = 5111
    app.run(host=host, port=port, debug=False, use_reloader=False)


if __name__ == '__main__':
    main()


