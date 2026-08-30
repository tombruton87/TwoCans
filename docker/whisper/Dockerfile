# Speech-to-text for twocans: faster-whisper (CTranslate2) and nothing else.
#
# The obvious off-the-shelf image is onerahmet/openai-whisper-asr-webservice at
# 8.19GB, because it bundles the OpenAI reference engine — PyTorch plus CUDA
# libraries — alongside faster-whisper and lets you choose at runtime. This box
# has no GPU and only ever uses faster-whisper, so none of that is wanted.
FROM python:3.12-slim

# faster-whisper decodes audio with PyAV, so no ffmpeg binary is needed.
# --no-compile skips .pyc generation, which is dead weight in a container.
#
# Pinned: 1.0.3 imports `requests` without declaring it, which breaks against
# huggingface_hub 1.x since that dropped requests for httpx. 1.2.1 removed the
# import altogether. Pin both so a rebuild months from now behaves the same.
RUN pip install --no-cache-dir --no-compile \
      "faster-whisper==1.2.1" \
      "ctranslate2==4.8.1" \
 && find /usr/local/lib/python3.12 -name '__pycache__' -type d -prune -exec rm -rf {} + \
 && rm -rf /root/.cache

COPY server.py /app/server.py
WORKDIR /app

# Models are downloaded once into a volume, not baked into the image.
ENV WHISPER_MODEL_DIR=/data/whisper \
    PYTHONUNBUFFERED=1
VOLUME ["/data/whisper"]

EXPOSE 9000
HEALTHCHECK --interval=30s --timeout=5s --start-period=180s --retries=3 \
  CMD python -c "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:9000/health',timeout=4).status==200 else 1)"

CMD ["python", "/app/server.py"]
