# =============================================================================
# Stage 1: Composer dependencies
# Using public.ecr.aws to avoid Docker Hub rate limits (429)
# =============================================================================
FROM public.ecr.aws/docker/library/composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --ignore-platform-reqs

COPY . .

RUN composer dump-autoload --no-dev --optimize

# =============================================================================
# Stage 2: PHP runtime
# Using public.ecr.aws to avoid Docker Hub rate limits (429)
# Only install extensions NOT already bundled in the PHP image:
#   - pdo_pgsql: PostgreSQL driver (needs postgresql-dev headers)
#   - bcmath:    arbitrary precision math
# Bundled (do NOT pass to docker-php-ext-install or it will fail):
#   tokenizer, dom, xml, fileinfo, json, mbstring, pdo, openssl, etc.
# =============================================================================
FROM public.ecr.aws/docker/library/php:8.3-cli-alpine

RUN apk add --no-cache postgresql-dev \
    && docker-php-ext-install pdo_pgsql bcmath

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .

# Generate an APP_KEY at image-build time so the app can boot.
# In production (App Runner) the real APP_KEY env var always takes precedence.
RUN cp .env.example .env && php artisan key:generate --force

EXPOSE 8080

CMD ["sh", "-c", "php artisan migrate --force || true && php artisan optimize && php artisan serve --host=0.0.0.0 --port=8080"]
