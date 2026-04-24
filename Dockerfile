# syntax=docker/dockerfile:1

# Build FrankenPHP from the master branch
FROM php:8.5-zts-trixie AS php-base
FROM golang:1.26-trixie AS golang-base

FROM php-base AS common

WORKDIR /app

RUN apt-get update && \
	apt-get -y --no-install-recommends install \
		mailcap \
		libcap2-bin \
	&& \
	apt-get clean && \
	rm -rf /var/lib/apt/lists/*

RUN set -eux; \
	mkdir -p \
		/app/public \
		/config/caddy \
		/data/caddy \
		/etc/caddy \
		/etc/frankenphp; \
	sed -i 's/php/frankenphp run/g' /usr/local/bin/docker-php-entrypoint; \
	echo '<?php phpinfo();' > /app/public/index.php

RUN curl -sSLf \
		-o /usr/local/bin/install-php-extensions \
		https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions && \
	chmod +x /usr/local/bin/install-php-extensions

ENV XDG_CONFIG_HOME=/config
ENV XDG_DATA_HOME=/data


FROM common AS builder

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

COPY --from=golang-base /usr/local/go /usr/local/go

ENV PATH=/usr/local/go/bin:$PATH
ENV GOTOOLCHAIN=local

RUN apt-get update && \
	apt-get -y --no-install-recommends install \
	cmake \
	git \
	libargon2-dev \
	libbrotli-dev \
	libcurl4-openssl-dev \
	libonig-dev \
	libreadline-dev \
	libsodium-dev \
	libsqlite3-dev \
	libssl-dev \
	libxml2-dev \
	zlib1g-dev \
	&& \
	apt-get clean

# Install e-dant/watcher (necessary for file watching)
WORKDIR /usr/local/src/watcher
RUN curl -s https://api.github.com/repos/e-dant/watcher/releases/latest | \
    grep tarball_url | \
    awk '{ print $2 }' | \
    sed 's/,$//' | \
    sed 's/"//g' | \
    xargs curl -L | \
    tar xz --strip-components 1 && \
    cmake -S . -B build -DCMAKE_BUILD_TYPE=Release -DCMAKE_CXX_FLAGS="-Wno-error=use-after-free" && \
    cmake --build build && \
    cmake --install build && \
    ldconfig

WORKDIR /go/src/app
RUN git clone --depth 1 --branch main https://github.com/php/frankenphp.git .

RUN go mod download

WORKDIR /go/src/app/caddy
RUN go mod download

WORKDIR /go/src/app

ENV CGO_CFLAGS="-DFRANKENPHP_VERSION=dev $PHP_CFLAGS"
ENV CGO_CPPFLAGS=$PHP_CPPFLAGS
ENV CGO_LDFLAGS="-L/usr/local/lib -lssl -lcrypto -lreadline -largon2 -lcurl -lonig -lz $PHP_LDFLAGS"

WORKDIR /go/src/app/caddy/frankenphp
RUN GOBIN=/usr/local/bin \
	../../go.sh install -ldflags "-w -s -X 'github.com/caddyserver/caddy/v2.CustomVersion=FrankenPHP dev PHP ${PHP_VERSION} Caddy' -X 'github.com/caddyserver/caddy/v2.CustomBinaryName=frankenphp' -X 'github.com/caddyserver/caddy/v2/modules/caddyhttp.ServerHeader=FrankenPHP Caddy'" -buildvcs=true && \
	setcap cap_net_bind_service=+ep /usr/local/bin/frankenphp && \
	frankenphp version && \
	frankenphp build-info


FROM common AS runner

ENV GODEBUG=cgocheck=0

COPY --from=builder /usr/local/lib/libwatcher* /usr/local/lib/
RUN apt-get update && \
	apt-get install -y --no-install-recommends libstdc++6 git unzip && \
	apt-get clean && \
	rm -rf /var/lib/apt/lists/* && \
	ldconfig

COPY --from=builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp
RUN setcap cap_net_bind_service=+ep /usr/local/bin/frankenphp && \
	frankenphp version && \
	frankenphp build-info

# --- Application layer ---
ARG APP_ENV=dev
ENV APP_ENV=${APP_ENV}
ENV SERVER_NAME=:80

RUN install-php-extensions pdo_pgsql intl apcu parallel shmop sysvsem pcntl posix

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . /app

RUN APP_ENV=$APP_ENV composer install $([ "$APP_ENV" = "prod" ] && echo "--no-dev --optimize-autoloader --classmap-authoritative") --no-interaction

RUN if [ "$APP_ENV" = "prod" ]; then \
        php bin/console sass:build && \
        php bin/console asset-map:compile; \
    fi

EXPOSE 80 443 443/udp

ENTRYPOINT ["frankenphp", "run", "--config", "/app/Caddyfile"]
