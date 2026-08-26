FROM node:22-alpine
# The container runs as the host's openclaw uid (compose `user:`); tools like
# eas-cli call os.userInfo(), which needs a passwd entry for that uid.
ARG WORKER_UID=1000
ARG WORKER_GID=1000
RUN apk add --no-cache git tar && npm install -g eas-cli@latest \
 && (getent group "$WORKER_GID" >/dev/null || addgroup -g "$WORKER_GID" worker) \
 && adduser -D -u "$WORKER_UID" -G "$(getent group "$WORKER_GID" | cut -d: -f1)" -h /home/worker worker
WORKDIR /repo
COPY package.json package-lock.json ./
COPY workers/pipeline/package.json workers/pipeline/package.json
COPY packages packages
RUN npm ci --workspace workers/pipeline --include-workspace-root
COPY workers/pipeline workers/pipeline
COPY templates /templates
EXPOSE 8300
CMD ["node", "--experimental-strip-types", "workers/pipeline/src/index.ts"]
