# NV oOS MCP Gateway — production image.
#
# Stateless HTTP proxy: no native modules, no Redis, no state on disk.
# Velocity-style platforms can run the same app directly from git; this
# image exists for parity with the media-worker deployment story.

FROM node:22-slim

ENV NODE_ENV=production
WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --omit=dev --no-audit --no-fund

COPY src/ ./src/

EXPOSE 8080
CMD ["npm", "start"]
