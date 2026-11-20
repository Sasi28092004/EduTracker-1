# ==============================================================================
# EduTracker - ONE Render web service, ONE URL
#
#   Browser ──► Apache + PHP (port $PORT)   pages: /, /frontend/...
#                 ├── /api/...      ──► Node/Express on 127.0.0.1:3000
#                 └── /uploads/...  ──► Node/Express on 127.0.0.1:3000
#   PHP pages call the API inside the container: http://127.0.0.1:3000/api
#   Node talks to MongoDB Atlas.
# ==============================================================================

# Node.js 22 binaries (copied into the PHP image below)
FROM node:22-bookworm-slim AS node

FROM php:8.2-apache-bookworm

# ---- Node.js + npm --------------------------------------------------------------
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && node --version && npm --version

# ---- PHP settings -------------------------------------------------------------
# curl and mbstring are already built into the official PHP image.
RUN { \
    echo 'output_buffering = 4096'; \
    echo 'upload_max_filesize = 10M'; \
    echo 'post_max_size = 12M'; \
    echo 'memory_limit = 128M'; \
    echo 'max_execution_time = 120'; \
    echo 'display_errors = Off'; \
    echo 'log_errors = On'; \
    echo 'date.timezone = UTC'; \
} > /usr/local/etc/php/conf.d/edutracker.ini

# ---- Apache: forward /api and /uploads to Node ----------------------------------
COPY docker/apache-edutracker.conf /etc/apache2/conf-available/edutracker.conf
RUN a2enmod rewrite headers proxy proxy_http \
    && a2enconf edutracker

# ---- Node API (installed outside the web root) ---------------------------------
WORKDIR /app/server
COPY server/package.json server/package-lock.json ./
RUN npm ci --omit=dev --no-audit --no-fund || npm install --omit=dev --no-audit --no-fund
COPY server/ ./
RUN mkdir -p uploads/assignments

# ---- PHP pages (web root) -------------------------------------------------------
COPY index.php logout.php /var/www/html/
COPY frontend /var/www/html/frontend
COPY php /var/www/html/php
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

# ---- Start both ---------------------------------------------------------------
COPY docker/start.sh /usr/local/bin/edutracker-start
RUN sed -i 's/\r$//' /usr/local/bin/edutracker-start && chmod +x /usr/local/bin/edutracker-start

WORKDIR /var/www/html
EXPOSE 10000
CMD ["edutracker-start"]
