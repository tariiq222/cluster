# syntax=docker/dockerfile:1.7

FROM docker.io/library/node:22.22.2-alpine3.22@sha256:b77017c37f430e4466ff497058948a2f16e8b59779600d53711eeb7b999b0f4e AS build

WORKDIR /app

COPY apps/web/package.json apps/web/package-lock.json ./
RUN npm ci

COPY apps/web/index.html apps/web/tsconfig.json apps/web/tsconfig.app.json apps/web/tsconfig.node.json apps/web/vite.config.ts ./
COPY apps/web/public ./public
COPY apps/web/src ./src
# SwaggerUiScreen resolves ../../../../../docs/contracts/api/openapi.yaml
# from /app/src/features/docs up to /, so the contract must sit at /docs.
COPY docs/contracts/api/openapi.yaml /docs/contracts/api/openapi.yaml
RUN npm run build

FROM docker.io/library/nginx:1.28.0-alpine3.21@sha256:30f1c0d78e0ad60901648be663a710bdadf19e4c10ac6782c235200619158284 AS production

COPY apps/web/docker/nginx.conf /etc/nginx/nginx.conf
COPY --from=build --chown=101:101 /app/dist /usr/share/nginx/html

USER 101:101

HEALTHCHECK --interval=10s --timeout=3s --retries=6 --start-period=10s \
  CMD wget --quiet --output-document=/dev/null http://127.0.0.1:8080/up || exit 1
