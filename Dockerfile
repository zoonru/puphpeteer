ARG PHP_VERSION=8.5
ARG PHP_QUICKJS_REF=8150c4b11a1df2fdd7d0892852bbce075827ed9a
FROM node:24-trixie-slim AS node
FROM composer:2 AS composer
FROM php:${PHP_VERSION}-cli-trixie AS php-base
RUN --mount=type=bind,from=ghcr.io/php/pie:bin,source=/pie,target=/usr/local/bin/pie \
    apt-get update && apt-get install -y --no-install-recommends \
    git unzip libicu-dev libuv1-dev libzip-dev time procps \
    && docker-php-ext-install -j2 intl zip pcntl sockets \
    && pie install --no-interaction --no-cache amphp/uv \
    && rm -rf /var/lib/apt/lists/*

FROM php-base AS extension
ARG PHP_QUICKJS_REF
RUN apt-get update && apt-get install -y --no-install-recommends clang libclang-dev curl \
    && curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --profile minimal --default-toolchain 1.99.1
ENV PATH="/root/.cargo/bin:${PATH}"
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /build
RUN git init && git remote add origin https://github.com/xtrime-ru/php-quickjs.git \
    && git fetch --depth=1 origin "$PHP_QUICKJS_REF" && git checkout --detach FETCH_HEAD \
    && cargo build --release --locked
RUN composer install --no-interaction --prefer-dist \
    && cargo test --release --lib --locked \
    && for test in tests/php/[0-9]*.php; do php -d extension=/build/target/release/libphp_quickjs.so "$test" || exit 1; done

FROM php-base
ARG PHP_QUICKJS_REF
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=extension /build/target/release/libphp_quickjs.so /usr/local/lib/php_quickjs.so
RUN echo 'extension=/usr/local/lib/php_quickjs.so' > /usr/local/etc/php/conf.d/quickjs.ini \
    && useradd --create-home --uid 1000 app
ENV PUPPETEER_SKIP_DOWNLOAD=true PHP_QUICKJS_REF=${PHP_QUICKJS_REF}
WORKDIR /app
RUN chown app:app /app
USER app
ENTRYPOINT []
CMD []
