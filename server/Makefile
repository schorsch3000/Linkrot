# Linkrot server
BIN      := app/vendor/bin
DATA_DIR ?= $(CURDIR)/app_data
PORT     ?= 8080

.PHONY: install build run test lint fmt clean precommit

install:
	composer install --no-interaction

# PHP needs no compilation; assemble a deployable tree (without app_data) into build/.
build: install
	rm -rf build
	mkdir -p build/app build/app_htdocs
	composer install --no-interaction --no-dev --optimize-autoloader
	cp -r app/src app/bin app/vendor build/app/
	cp -r app_htdocs/. build/app_htdocs/
	composer install --no-interaction

run: install
	@mkdir -p $(DATA_DIR)/files
	@test -f $(DATA_DIR)/config.php || printf "<?php\nreturn [\n    'base_url' => 'http://localhost:$(PORT)',\n    'download_mode' => 'php',\n];\n" > $(DATA_DIR)/config.php
	LINKROT_DATA=$(DATA_DIR) php -S localhost:$(PORT) -t app_htdocs app_htdocs/index.php

test:
	$(BIN)/phpunit

lint:
	$(BIN)/phpstan analyse --memory-limit=512M --no-progress
	$(BIN)/rector process --dry-run --no-progress-bar
	$(BIN)/php-cs-fixer fix --dry-run --diff

fmt:
	$(BIN)/rector process --no-progress-bar
	$(BIN)/php-cs-fixer fix

clean:
	rm -rf build .phpunit.cache .php-cs-fixer.cache

precommit: lint test
