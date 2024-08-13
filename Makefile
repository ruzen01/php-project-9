lint:
	./vendor/bin/phpcs --standard=PSR12 src

lint-fix:
	./vendor/bin/phpcbf --standard=PSR12 src

update:
	composer update

install:
	composer install

PORT ?= 8000
start:
	PHP_CLI_SERVER_WORKERS=5 php -S 0.0.0.0:$(PORT) -t public
