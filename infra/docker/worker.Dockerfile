FROM node:22-alpine
RUN apk add --no-cache git tar
WORKDIR /repo
COPY package.json package-lock.json ./
COPY workers/pipeline/package.json workers/pipeline/package.json
COPY packages packages
RUN npm ci --workspace workers/pipeline --include-workspace-root
COPY workers/pipeline workers/pipeline
COPY templates /templates
EXPOSE 8300
CMD ["node", "--experimental-strip-types", "workers/pipeline/src/index.ts"]
