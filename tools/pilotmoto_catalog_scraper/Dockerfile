FROM python:3.12-slim-bookworm

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY pilotmoto_scraper ./pilotmoto_scraper
COPY config.yaml run.py ./

ENV PYTHONUNBUFFERED=1

CMD ["python", "run.py"]
