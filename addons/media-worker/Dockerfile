# ── Media Worker — Design Stack ─────────────────────────────
# Node.js API for AI image/video generation, optimization,
# social media publishing, and document pipeline.
#
# Build:  wsl docker compose build media-worker
# Rebuild after package changes: wsl docker compose build --no-cache media-worker

FROM node:22-alpine

# ── System dependencies ────────────────────────────────────
# ffmpeg          — video processing (transcode, trim, GIF)
# chromium        — Puppeteer (PDF rendering, screenshots)
# cairo / pango   — node-canvas (server-side Canvas API)
# vips-dev        — sharp (libvips, image optimization)
# fontconfig      — PDF / canvas font rendering
# build-base      — gcc/make for compiling native modules (canvas)
RUN apk add --no-cache \
    ffmpeg \
    chromium \
    nss freetype harfbuzz ca-certificates ttf-freefont \
    cairo-dev pango-dev jpeg-dev giflib-dev librsvg-dev \
    vips-dev \
    fontconfig \
    build-base python3 pkgconfig pixman-dev

# Tell Puppeteer to use system Chromium
ENV PUPPETEER_SKIP_DOWNLOAD=true
ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium-browser

WORKDIR /app

# Install dependencies (native modules like canvas need build tools above)
COPY package.json package-lock.json* ./
RUN npm ci --only=production 2>/dev/null || npm install \
    && apk del build-base python3 pkgconfig pixman-dev

# Copy source
COPY src/ ./src/

# Best-effort: allow Chromium's setuid sandbox helper to work for the
# non-root user (Alpine chromium path; || true for portability).
RUN chmod 4755 /usr/lib/chromium/chrome-sandbox 2>/dev/null || true

# Temp directory for file processing
RUN mkdir -p /data/temp && chown node:node /data/temp

# Run as non-root so Chromium can use its sandbox. Never --no-sandbox in
# production (see docs/project/proposals/024-...-security-plan.md).
USER node

EXPOSE 3100

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
  CMD wget --no-verbose --tries=1 --spider http://localhost:3100/api/health || exit 1

CMD ["node", "src/index.js"]
