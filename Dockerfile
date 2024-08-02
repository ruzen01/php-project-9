FROM php:8.2-cli

COPY . .

RUN composer install

CMD ["bash", "-c", "make start"]