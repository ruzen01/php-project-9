lint:
	./vendor/bin/phpcs --standard=PSR12 src

lint-fix:
	./vendor/bin/phpcbf --standard=PSR12 src

update:
	composer update --with-all-dependencies

install:
	composer install

test:
	composer exec --verbose phpunit tests

test-coverage:
	XDEBUG_MODE=coverage composer exec --verbose phpunit tests -- --coverage-clover build/logs/clover.xml

test-coverage-text:
	XDEBUG_MODE=coverage composer exec --verbose phpunit tests -- --coverage-text

.PHONY: install lint lint-fix test test-coverage test-coverage-text

PORT ?= 8000
start:
	PHP_CLI_SERVER_WORKERS=5 php -S 0.0.0.0:$(PORT) -t public
