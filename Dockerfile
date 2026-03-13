# ── Stage 1: dependencias ────────────────────────────────────────────────────
# Usando ECR Public para evitar rate limit de Docker Hub en CodeBuild
FROM public.ecr.aws/docker/library/composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --optimize-autoloader \
    --prefer-dist

# ── Stage 2: imagen final ─────────────────────────────────────────────────────
FROM public.ecr.aws/docker/library/php:8.3-cli-alpine

# Dependencias del sistema
RUN apk add --no-cache \
    curl \
    libpq-dev \
    oniguruma-dev \
    libxml2-dev \
    openssl-dev

# Extensiones PHP que NO vienen por defecto en la imagen alpine
# tokenizer, dom, xml, fileinfo ya están compiladas en el core — NO reinstalar
RUN docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    bcmath \
    mbstring \
    opcache

# Configuración opcache para producción
RUN echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.memory_consumption=128" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.validate_timestamps=0" >> /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /var/www/html

# Copiar vendor desde stage 1
COPY --from=vendor /app/vendor ./vendor

# Copiar el resto del proyecto
COPY . .

# Permisos de storage y cache
RUN mkdir -p storage/logs storage/framework/{sessions,views,cache} bootstrap/cache && \
    chmod -R 775 storage bootstrap/cache

# App Runner escucha en 8080
EXPOSE 8080

# Start: cachear config/rutas, migrar y servir
CMD php artisan config:cache && \
    php artisan route:cache && \
    php artisan migrate --force && \
    php artisan serve --host=0.0.0.0 --port=8080
