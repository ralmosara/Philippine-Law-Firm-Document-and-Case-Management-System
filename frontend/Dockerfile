# Build the SPA, then serve it with nginx. docker-compose.yml mounts the
# server config (backend/docker/nginx/default.conf), which also proxies /api.
FROM node:22-alpine AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM nginx:1.27-alpine
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 80
