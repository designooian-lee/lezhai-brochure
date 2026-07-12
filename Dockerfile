FROM node:22-alpine AS website
WORKDIR /app
ARG PUBLIC_SITE_ENV=production
ARG PUBLIC_FORM_ENDPOINT
ARG PUBLIC_STATICFORMS_API_KEY
ARG PUBLIC_FORM_SUBJECT
ARG PUBLIC_PHONE
ARG PUBLIC_ADDRESS
ARG PUBLIC_BUSINESS_HOURS
ENV PUBLIC_SITE_ENV=$PUBLIC_SITE_ENV PUBLIC_FORM_ENDPOINT=$PUBLIC_FORM_ENDPOINT PUBLIC_STATICFORMS_API_KEY=$PUBLIC_STATICFORMS_API_KEY PUBLIC_FORM_SUBJECT=$PUBLIC_FORM_SUBJECT PUBLIC_PHONE=$PUBLIC_PHONE PUBLIC_ADDRESS=$PUBLIC_ADDRESS PUBLIC_BUSINESS_HOURS=$PUBLIC_BUSINESS_HOURS
RUN corepack enable
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
RUN pnpm install --frozen-lockfile
COPY website ./website
RUN pnpm run build:website

FROM php:8.3-fpm-alpine
RUN apk add --no-cache libpq-dev libzip-dev icu-dev libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev nginx supervisor curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo_pgsql pgsql zip intl gd \
    && mkdir -p /run/nginx /app/storage/runtime /app/storage/logs /app/public/uploads
WORKDIR /app
COPY . .
COPY --from=website /app/storage/website-dist ./storage/website-dist
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
RUN chown -R www-data:www-data storage public/uploads \
    && chmod +x scripts/container-entrypoint.sh
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD curl -fsS http://127.0.0.1:8080/health || exit 1
ENTRYPOINT ["/app/scripts/container-entrypoint.sh"]
CMD ["/usr/bin/supervisord","-c","/etc/supervisord.conf"]
