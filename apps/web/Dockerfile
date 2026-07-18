# syntax=docker/dockerfile:1.7

FROM docker.io/library/node:22.22.2-alpine3.22@sha256:b77017c37f430e4466ff497058948a2f16e8b59779600d53711eeb7b999b0f4e AS build

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY index.html tsconfig.json tsconfig.app.json tsconfig.node.json vite.config.ts ./
COPY public ./public
COPY src ./src
RUN npm run build

FROM docker.io/library/nginx:1.28.0-alpine3.21@sha256:30f1c0d78e0ad60901648be663a710bdadf19e4c10ac6782c235200619158284 AS production

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY --from=build --chown=101:101 /app/dist /usr/share/nginx/html

USER 101:101

EXPOSE 8080

ENTRYPOINT []
HEALTHCHECK --interval=15s --timeout=3s --retries=5 --start-period=10s CMD ["wget", "--quiet", "--output-document=/dev/null", "http://127.0.0.1:8080/up"]
CMD ["nginx", "-g", "daemon off;"]
