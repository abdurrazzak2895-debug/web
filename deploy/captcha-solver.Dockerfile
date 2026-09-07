FROM node:22-bookworm

ENV NODE_ENV=production \
    HOME=/app/storage \
    PUPPETEER_SKIP_DOWNLOAD=true \
    PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium \
    PUPPETEER_CACHE_DIR=/app/.cache \
    CAPTCHA_SOLVER_HOST=0.0.0.0 \
    CAPTCHA_SOLVER_PORT=8788 \
    CAPTCHA_SOLVER_API_TOKEN= \
    CAPTCHA_SOLVER_STORAGE_DIR=/app/storage \
    CAPTCHA_SOLVER_CAPTCHA_DIR=/app/storage/captcha \
    CAPTCHA_SOLVER_MAX_QUEUE=32 \
    CAPTCHA_SOLVER_TIMEOUT_MS=45000 \
    CAPTCHA_SOLVER_IDLE_MS=60000 \
    CAPTCHA_SOLVER_RECYCLE_AFTER=400

RUN apt-get update && apt-get install -y --no-install-recommends \
    chromium \
    ca-certificates \
    fonts-liberation \
    fonts-noto-color-emoji \
    libasound2 \
    libatk-bridge2.0-0 \
    libatk1.0-0 \
    libcups2 \
    libdbus-1-3 \
    libdrm2 \
    libgbm1 \
    libgtk-3-0 \
    libnspr4 \
    libnss3 \
    libu2f-udev \
    libxcomposite1 \
    libxdamage1 \
    libxfixes3 \
    libxkbcommon0 \
    libxrandr2 \
    xdg-utils \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY package*.json ./
RUN npm ci --omit=dev --ignore-scripts

COPY app/Scripts/in_house_captcha_solver.cjs app/Scripts/captcha_live_runtime.cjs app/Scripts/captcha_dom_stub.cjs ./app/Scripts/
COPY deploy/captcha-solver-entrypoint.sh /usr/local/bin/captcha-solver-entrypoint

RUN mkdir -p /app/storage/captcha /app/.cache \
    && chmod +x /usr/local/bin/captcha-solver-entrypoint \
    && chmod -R 777 /app/storage /app/.cache

EXPOSE 8788

ENTRYPOINT ["/usr/local/bin/captcha-solver-entrypoint"]
