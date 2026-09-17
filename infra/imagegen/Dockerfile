# Appwerk image agent: OpenAI's Codex CLI signed in with the ChatGPT (Codex) subscription, wrapped
# as a small HTTP service for the paid ad pipeline. It renders pictures and nothing else: the
# scene, the words and the facts are written by Claude before a prompt reaches it. Codex runs
# read-only and needs no shell: the service picks the picture up from Codex's own output folder.
FROM node:22-slim

ARG CODEX_VERSION=0.154.0

RUN npm install -g "@openai/codex@${CODEX_VERSION}" && npm cache clean --force \
    && mkdir -p /codex-home /app && chown node:node /codex-home /app

COPY --chown=node:node server.mjs config.toml /app/

USER node
ENV CODEX_HOME=/codex-home PORT=8400
WORKDIR /app
EXPOSE 8400
CMD ["node", "/app/server.mjs"]
