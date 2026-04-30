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
# =============================================================================
FROM public.ecr.aws/docker/library/php:8.3-cli-alpine

RUN apk add --no-cache \
        postgresql-dev curl-dev libxml2-dev \
        freetype-dev libjpeg-turbo-dev libpng-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_pgsql bcmath gd simplexml

# Increase PHP upload/memory limits (default CLI limits are 2M/128M which
# cause 502 Bad Gateway on any file upload through App Runner)
RUN { \
        echo "upload_max_filesize = 50M"; \
        echo "post_max_size = 55M"; \
        echo "memory_limit = 256M"; \
        echo "max_execution_time = 120"; \
    } > /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .

# Generate an APP_KEY at image-build time so the app can boot.
# In production (App Runner) the real APP_KEY env var always takes precedence.
RUN cp .env.example .env && php artisan key:generate --force

EXPOSE 8080

CMD ["sh", "-c", "php artisan migrate --force || true && php artisan migrate --path=database/migrations_rrhh --force || true && php artisan route:clear && php artisan optimize && php artisan schedule:work >> storage/logs/scheduler.log 2>&1 & php artisan serve --host=0.0.0.0 --port=8080"]
