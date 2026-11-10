FROM dunglas/frankenphp:1-php8.4-bookworm

RUN install-php-extensions pdo_mysql redis opcache \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/zz-production.ini "$PHP_INI_DIR/conf.d/zz-production.ini"

WORKDIR /app

COPY Caddyfile /etc/caddy/Caddyfile
COPY index.html index.js static-demo.js style.css favicon.svg ./
COPY config/database.php ./config/database.php
COPY helpers/response.php ./helpers/response.php
COPY api/analytics.php api/auth.php api/categories.php api/customers.php ./api/
COPY api/fake-api.php api/products.php api/purchases.php api/sales.php ./api/
COPY api/status.php api/stock.php api/suppliers.php ./api/

ENV APP_ENV=production \
    PORT=80

EXPOSE 80

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
