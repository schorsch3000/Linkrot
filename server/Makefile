# Linkrot server
BIN      := app/vendor/bin
DATA_DIR ?= $(CURDIR)/app_data
PORT     ?= 8080
VERSION  ?= dev
DIST     := build/dist
TARBALL  := build/linkrot-server-$(VERSION).tar.gz

.PHONY: install build dist run test lint fmt clean precommit

install:
	composer --working-dir=app install --no-interaction

# PHP needs no compilation. `build` assembles exactly what is deployed (htdocs + app, without vendor/):
# the target runs `composer install --no-dev` itself as part of the deployment.
build:
	rm -rf $(DIST)
	mkdir -p $(DIST)/app $(DIST)/htdocs
	cp -r app/src app/bin app/composer.json app/composer.lock $(DIST)/app/
	cp -r htdocs/. $(DIST)/htdocs/

# Release tarball: two top-level folders, htdocs and app. Reproducible (sorted, fixed owner and mtime).
dist: build
	tar --sort=name --owner=0 --group=0 --numeric-owner --mtime='@$(shell git log -1 --format=%ct 2>/dev/null || echo 0)' \
		-C $(DIST) -czf $(TARBALL) app htdocs
	@top="$$(tar -tzf $(TARBALL) | cut -d/ -f1 | sort -u | tr '\n' ' ')"; \
		test "$$top" = "app htdocs " || { echo "unexpected top-level entries: $$top"; exit 1; }
	@if tar -tzf $(TARBALL) | grep -qE '(^|/)(vendor|tests?|\.[a-z-]+\.(php|neon|xml|cache))(/|$$)'; then \
		echo "tarball contains files that do not belong into a release"; exit 1; fi
	@echo $(TARBALL)

run: install
	@mkdir -p $(DATA_DIR)/files
	@test -f $(DATA_DIR)/config.php || printf "<?php\nreturn [\n    'base_url' => 'http://localhost:$(PORT)',\n    'download_mode' => 'php',\n];\n" > $(DATA_DIR)/config.php
	LINKROT_DATA=$(DATA_DIR) php -d apc.enable_cli=1 -S localhost:$(PORT) -t htdocs htdocs/index.php

test:
	php -d apc.enable_cli=1 $(BIN)/phpunit

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
