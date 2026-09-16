ARG PHP_VERSION=8.5
ARG UV_REF=670a609efc36c9043be37bae4126f06ed30fde21
FROM node:22-bookworm-slim AS node
FROM composer:2 AS composer
FROM php:${PHP_VERSION}-cli-bookworm AS php-base
ARG UV_REF
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libicu-dev libuv1-dev libzip-dev time procps \
    && mkdir -p /usr/src/php/ext/uv \
    && cd /usr/src/php/ext/uv \
    && git init \
    && git remote add origin https://github.com/amphp/ext-uv.git \
    && git fetch --depth=1 origin "$UV_REF" \
    && git checkout --detach FETCH_HEAD \
    && docker-php-ext-install -j2 intl zip pcntl uv \
    && rm -rf /var/lib/apt/lists/*

FROM php-base AS extension
ARG PHP_QUICKJS_REF=7ba5eafceba67b01d778e931d9a150be9ef33a28
RUN apt-get update && apt-get install -y --no-install-recommends clang libclang-dev curl \
    && curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --profile minimal --default-toolchain 1.96
ENV PATH="/root/.cargo/bin:${PATH}"
WORKDIR /build
RUN git init && git remote add origin https://github.com/xtrime-ru/php-quickjs.git \
    && git fetch --depth=1 origin "$PHP_QUICKJS_REF" && git checkout --detach FETCH_HEAD \
    && cargo build --release --locked
RUN cargo test --lib --locked \
    && for test in tests/php/[0-9]*.php; do php -d extension=/build/target/release/libphp_quickjs.so "$test" || exit 1; done

FROM php-base
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=extension /build/target/release/libphp_quickjs.so /usr/local/lib/php_quickjs.so
RUN echo 'extension=/usr/local/lib/php_quickjs.so' > /usr/local/etc/php/conf.d/quickjs.ini \
    && useradd --create-home --uid 1000 app
ENV PUPPETEER_SKIP_DOWNLOAD=true
WORKDIR /app
RUN chown app:app /app
USER app
ENTRYPOINT []
CMD []
