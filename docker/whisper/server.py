"""
Minimal speech-to-text service for twocans.

Wraps faster-whisper (CTranslate2) and nothing else. Deliberately not FastAPI or
the reference OpenAI engine: those drag in PyTorch and CUDA libraries this box
will never use, which is the difference between a ~700MB image and an 8GB one.

Protocol is as small as it can be, because twocans owns both ends:

    GET  /health  -> {"ok": true, "model": "base", "ready": true}
    POST /asr     -> body is the raw audio file; replies with plain text

No multipart parsing, no auth: this listens only on the internal compose
network and is never published to the host.
"""

from __future__ import annotations

import json
import logging
import os
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from faster_whisper import WhisperModel

MODEL_NAME = os.environ.get("WHISPER_MODEL", "base")
MODEL_DIR = os.environ.get("WHISPER_MODEL_DIR", "/data/whisper")
LANGUAGE = os.environ.get("WHISPER_LANGUAGE", "en")
# int8 is roughly 3x faster than float32 on CPU with barely any accuracy cost
# on speech this clean; the bottleneck here is CPU, not quality.
COMPUTE_TYPE = os.environ.get("WHISPER_COMPUTE_TYPE", "int8")
THREADS = int(os.environ.get("WHISPER_THREADS", "4"))
MAX_BYTES = 200 * 1024 * 1024

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(message)s", datefmt="%H:%M:%S")
log = logging.getLogger("twocans-whisper")

_model: WhisperModel | None = None
_model_lock = threading.Lock()


def get_model() -> WhisperModel:
    """Load the model once, on first use, and keep it resident."""
    global _model
    with _model_lock:
        if _model is None:
            log.info("loading %s (compute_type=%s, threads=%d)", MODEL_NAME, COMPUTE_TYPE, THREADS)
            _model = WhisperModel(
                MODEL_NAME,
                device="cpu",
                compute_type=COMPUTE_TYPE,
                cpu_threads=THREADS,
                download_root=MODEL_DIR,
            )
            log.info("model ready")
    return _model


def transcribe(path: str) -> str:
    segments, _info = get_model().transcribe(
        path,
        language=LANGUAGE,
        # Phone audio is narrowband and full of gaps. Without VAD, Whisper
        # invents text during silence — "thank you for watching" and friends.
        vad_filter=True,
        vad_parameters={"min_silence_duration_ms": 500},
        beam_size=5,
        condition_on_previous_text=False,
    )
    return "\n".join(s.text.strip() for s in segments if s.text.strip())


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt, *args):          # quieter than the default
        log.info("%s", fmt % args)

    def _send(self, code: int, body: bytes, content_type: str) -> None:
        self.send_response(code)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self) -> None:
        if self.path.split("?")[0] != "/health":
            self._send(404, b"not found", "text/plain; charset=utf-8")
            return
        payload = json.dumps({
            "ok": True,
            "model": MODEL_NAME,
            "ready": _model is not None,
        }).encode()
        self._send(200, payload, "application/json")

    def do_POST(self) -> None:
        if self.path.split("?")[0] != "/asr":
            self._send(404, b"not found", "text/plain; charset=utf-8")
            return

        length = int(self.headers.get("Content-Length") or 0)
        if length <= 0:
            self._send(400, b"empty body", "text/plain; charset=utf-8")
            return
        if length > MAX_BYTES:
            self._send(413, b"audio too large", "text/plain; charset=utf-8")
            return

        audio = self.rfile.read(length)

        # faster-whisper wants a path it can decode, so land it on disk briefly.
        with tempfile.NamedTemporaryFile(suffix=".wav", delete=True) as tmp:
            tmp.write(audio)
            tmp.flush()
            try:
                text = transcribe(tmp.name)
            except Exception as exc:                      # noqa: BLE001
                log.exception("transcription failed")
                self._send(500, str(exc).encode()[:500], "text/plain; charset=utf-8")
                return

        self._send(200, text.encode(), "text/plain; charset=utf-8")


def main() -> None:
    port = int(os.environ.get("PORT", "9000"))
    if os.environ.get("WHISPER_PRELOAD", "1") == "1":
        get_model()                                       # fail fast, not on first call
    log.info("listening on :%d", port)
    ThreadingHTTPServer(("0.0.0.0", port), Handler).serve_forever()


if __name__ == "__main__":
    main()
