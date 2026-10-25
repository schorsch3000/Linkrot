# Linkrot server
BIN      := app/vendor/bin
DATA_DIR ?= $(CURDIR)/app_data
PORT     ?= 8080
VERSION  ?= dev
DIST     := build/dist
TARBALL  := build/linkrot-server-$(VERSION).tar.gz

.PHONY: install build dist dist-test preview run test lint audit fmt clean precommit

install:
	composer --working-dir=app install --no-interaction

# PHP needs no compilation. `build` assembles exactly what is deployed (htdocs + app, without vendor/):
# the target runs `composer install --no-dev` itself as part of the deployment. The files in htdocs/assets get
# content-hashed names (cache busting), see scripts/build-assets.sh. A release version (1.2.3) is written
# into the composer.json of the build; the default dev and the ci of the CI leave it alone.
build:
	rm -rf $(DIST)
	mkdir -p $(DIST)/app $(DIST)/htdocs
	cp -r app/src app/bin app/composer.lock app/install.sh $(DIST)/app/
	scripts/stamp-version.sh app/composer.json $(DIST)/app/composer.json $(VERSION)
	cp -r htdocs/. $(DIST)/htdocs/
	scripts/build-assets.sh $(DIST)/htdocs $(DIST)/app

# Release tarball: two top-level folders, htdocs and app. Reproducible (sorted, fixed owner and mtime).
dist: build
	tar --sort=name --owner=0 --group=0 --numeric-owner --mtime='@$(shell git log -1 --format=%ct 2>/dev/null || echo 0)' \
		-C $(DIST) -czf $(TARBALL) app htdocs
	@top="$$(tar -tzf $(TARBALL) | cut -d/ -f1 | sort -u | tr '\n' ' ')"; \
		test "$$top" = "app htdocs " || { echo "unexpected top-level entries: $$top"; exit 1; }
	@if tar -tzf $(TARBALL) | grep -qE '(^|/)(vendor|tests?|\.[a-z-]+\.(php|neon|xml|cache))(/|$$)'; then \
		echo "tarball contains files that do not belong into a release"; exit 1; fi
	@echo $(TARBALL)

# Unpacks the tarball like a deployment and runs app/install.sh (needs composer).
dist-test: dist
	scripts/dist-test.sh $(TARBALL)

# Sample pages of the download site for every icon type, to open in a browser (build/preview/index.html).
preview:
	php scripts/preview.php

run: install
	@mkdir -p $(DATA_DIR)/files
	@test -f $(DATA_DIR)/config.php || printf "<?php\nreturn [\n    'base_url' => 'http://localhost:$(PORT)',\n    'download_mode' => 'php',\n];\n" > $(DATA_DIR)/config.php
	LINKROT_DATA=$(DATA_DIR) php app/bin/migrate
	LINKROT_DATA=$(DATA_DIR) php -d apc.enable_cli=1 -S localhost:$(PORT) -t htdocs htdocs/index.php

test:
	php -d apc.enable_cli=1 $(BIN)/phpunit

lint:
	shellcheck app/install.sh scripts/dist-test.sh scripts/stamp-version.sh scripts/build-assets.sh
	@if command -v node >/dev/null; then node --check htdocs/assets/page.js; else echo 'node not found, page.js is not syntax checked'; fi
	$(BIN)/phpstan analyse --memory-limit=512M --no-progress
	$(BIN)/rector process --dry-run --no-progress-bar
	$(BIN)/php-cs-fixer fix --dry-run --diff

# Known vulnerabilities of the locked dependencies (needs the network); fails on any advisory.
audit:
	composer --working-dir=app audit --locked

fmt:
	$(BIN)/rector process --no-progress-bar
	$(BIN)/php-cs-fixer fix

clean:
	rm -rf build .phpunit.cache .php-cs-fixer.cache

precommit: lint test
