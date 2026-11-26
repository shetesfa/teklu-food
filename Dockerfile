# Teklu Food & Business Management — Docker image for Render (free tier)
# PHP + Apache serving the static PWA (index.html) and the PHP API (api/).
# No secrets in this file: all database credentials come from environment
# variables at runtime (see config/db.php — DB_HOST, DB_PORT, DB_NAME,
# DB_USER, DB_PASS). Never commit a .env file or a real password here.
FROM php:8.2-apache

# MySQL driver for PDO (used by config/db.php) + CA certificates so the
# app can connect to TiDB Cloud Serverless over TLS.
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo pdo_mysql \
    && a2enmod rewrite

# Production PHP settings: never show errors (paths, SQL) to visitors;
# log them to the container log instead.
RUN printf 'display_errors=Off\nlog_errors=On\n' > /usr/local/etc/php/conf.d/production.ini

# Copy the whole app into the Apache document root.
COPY . /var/www/html/

# Render injects a $PORT env var (default 10000) and expects the service
# to listen on it; Apache defaults to 80. The entrypoint rewrites the
# Listen/VirtualHost port when $PORT is set, and is a no-op otherwise
# (local Docker runs keep working on port 80).
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

CMD ["docker-entrypoint.sh"]
