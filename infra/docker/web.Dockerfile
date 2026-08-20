FROM node:22-alpine AS build
WORKDIR /repo
# NEXT_PUBLIC_* values are inlined at build time
ARG NEXT_PUBLIC_API_BASE_URL=https://api.appwerk.codemenschen.at
ENV NEXT_PUBLIC_API_BASE_URL=$NEXT_PUBLIC_API_BASE_URL
COPY package.json package-lock.json ./
COPY apps/web/package.json apps/web/package.json
COPY packages packages
RUN npm ci --workspace apps/web --include-workspace-root
COPY apps/web apps/web
RUN npm run build --workspace apps/web

FROM node:22-alpine
WORKDIR /repo
ENV NODE_ENV=production
COPY --from=build /repo/apps/web/.next/standalone ./
COPY --from=build /repo/apps/web/.next/static apps/web/.next/static
COPY --from=build /repo/apps/web/public apps/web/public
EXPOSE 3000
CMD ["node", "apps/web/server.js"]
