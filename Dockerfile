# Build stage
FROM dunglas/frankenphp:php8.5-trixie AS builder

ARG APP_ENV=dev

RUN apt update && apt install -y git unzip

# Install PHP extensions
RUN install-php-extensions pdo_pgsql intl apcu

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy application files
COPY . /app

# Install PHP dependencies
RUN APP_ENV=$APP_ENV composer install $([ "$APP_ENV" = "prod" ] && echo "--no-dev --optimize-autoloader --classmap-authoritative") --no-interaction

# Build assets for prod
RUN if [ "$APP_ENV" = "prod" ]; then \
        php bin/console sass:build && \
        php bin/console asset-map:compile; \
    fi

# Set permissions for nonroot user (65532)
RUN chown -R 65532:65532 /app/var

# Runtime stage - distroless
FROM gcr.io/distroless/base-debian13:nonroot AS runtime

ARG APP_ENV
ENV APP_ENV=${APP_ENV}
ENV SERVER_NAME=:80

# Copy PHP and shared libraries
COPY --from=builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp
COPY --from=builder /usr/local/bin/php /usr/local/bin/php
COPY --from=builder /usr/local/lib/*.so* /usr/lib/
COPY --from=builder /usr/local/lib/php /usr/local/lib/php
COPY --from=builder /usr/local/etc/php /usr/local/etc/php
COPY --from=builder /usr/lib/ /usr/lib/

# Copy application
COPY --from=builder /app /app

WORKDIR /app

EXPOSE 80 443 443/udp

ENTRYPOINT ["/usr/local/bin/frankenphp", "run", "--config", "/app/Caddyfile"]
