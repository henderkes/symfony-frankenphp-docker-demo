# Final stage
FROM dunglas/frankenphp:php8.5-trixie

ARG APP_ENV=dev
ENV APP_ENV=${APP_ENV}
ENV SERVER_NAME=:80

RUN apt update && apt-get install -y git unzip

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

EXPOSE 80 443 443/udp

ENTRYPOINT ["frankenphp", "run", "--config", "/app/Caddyfile"]
