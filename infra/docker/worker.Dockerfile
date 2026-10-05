FROM node:22-alpine
# The container runs as the host's openclaw uid (compose `user:`); tools like
# eas-cli call os.userInfo(), which needs a passwd entry for that uid.
ARG WORKER_UID=1000
ARG WORKER_GID=1000
# PHP and zip for Sofabuilt's WordPress plugins (test sandbox, Plugin Check, release ZIP); openssl for
# Prisma in Shopify apps.
RUN apk add --no-cache git tar zip unzip curl openssl \
    php83 php83-phar php83-mbstring php83-xml php83-xmlreader php83-xmlwriter php83-simplexml php83-dom \
    php83-tokenizer php83-ctype php83-pdo php83-pdo_sqlite php83-sqlite3 php83-curl php83-openssl php83-zip \
    php83-iconv php83-fileinfo php83-session php83-intl php83-gd \
 && ln -sf /usr/bin/php83 /usr/bin/php \
 && npm install -g eas-cli@latest \
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
