# Используем официальный образ PHP с FPM
FROM php:8.1-fpm

# Устанавливаем необходимые системные пакеты
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    locales \
    zip \
    unzip \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd

# Установка расширений PHP
RUN docker-php-ext-install pdo pdo_mysql

# Настройка рабочей директории
WORKDIR /var/www/html

# Копируем файлы проекта в контейнер
COPY . /var/www/html

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Установка зависимостей через Composer
RUN composer install --no-dev --optimize-autoloader

# Настройка прав доступа
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Указание порта для запуска
EXPOSE 8000

CMD ["bash", "-c", "make start"]