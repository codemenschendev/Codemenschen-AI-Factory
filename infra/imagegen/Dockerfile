# Appwerk image agent: OpenAI's Codex CLI signed in with the ChatGPT (Codex) subscription, wrapped
# as a small HTTP service. It renders pictures, and, when the owner switches the prototype writer to
# Codex in the admin panel, it writes prototype pages too (POST /v1/chat/completions). Codex runs
# read-only and needs no shell: pictures come from Codex's own output folder, pages from its last
# message.
FROM node:22-slim

ARG CODEX_VERSION=0.154.0

# node:22-slim ships no CA bundle. Node carries its own, Codex (Rust) reads the system one and
# fails every request to auth.openai.com without it.
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && npm install -g "@openai/codex@${CODEX_VERSION}" && npm cache clean --force \
    && mkdir -p /codex-home /app && chown node:node /codex-home /app

COPY --chown=node:node server.mjs config.toml /app/

USER node
ENV CODEX_HOME=/codex-home PORT=8400
WORKDIR /app
EXPOSE 8400
CMD ["node", "/app/server.mjs"]
