FROM php:8.0-cli

# Установка необходимых расширений PHP
RUN docker-php-ext-install pdo pdo_mysql

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . /app

# Очистка кэша Composer и установка зависимостей
RUN composer clear-cache \
    && composer install --no-dev --optimize-autoloader --verbose --no-progress

# Отладочные команды
RUN composer diagnose
RUN composer check-platform-reqs

CMD ["bash", "-c", "make start"]