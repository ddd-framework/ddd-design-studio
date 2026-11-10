# design-board-renderer — Playwright's image (Chromium + fonts + deps), version = the npm playwright in package.json.
# Pinned by digest: mcr.microsoft.com/playwright:v1.64.1-noble (multi-arch index digest).
FROM mcr.microsoft.com/playwright:v1.64.1-noble@sha256:2f05f8a734509a7b3f26a912639488ee08f58a7b292d14d62271bcff9038768f

ENV NODE_ENV=production \
    PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
    PLAYWRIGHT_BROWSERS_PATH=/ms-playwright \
    HOME=/tmp \
    XDG_CONFIG_HOME=/tmp/.chromium \
    XDG_CACHE_HOME=/tmp/.chromium

WORKDIR /app
COPY package.json ./
RUN npm install --omit=dev --no-audit --no-fund && npm cache clean --force
COPY renderer.js server.js ./
COPY fonts ./fonts

# non-root; works with readOnlyRootFilesystem as long as /tmp is a writable emptyDir (Chromium profile, crash dumps)
USER pwuser
EXPOSE 8080
CMD ["node", "server.js"]
