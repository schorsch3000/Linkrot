# Linkrot CLI
export PATH := $(PATH):/usr/local/go/bin:$(HOME)/go/bin

BIN      := bin/linkrot
VERSION  ?= dev
LDFLAGS  := -X github.com/schorsch3000/Linkrot/internal/cli.Version=$(VERSION)
GOFUMPT  := mvdan.cc/gofumpt@v0.12.0
GOLANGCI := github.com/golangci/golangci-lint/v2/cmd/golangci-lint@v2.15.0
GOVULN   := golang.org/x/vuln/cmd/govulncheck@v1.9.0

.PHONY: install build dist run test lint audit repro fmt clean precommit

install:
	go mod download
	@command -v gofumpt >/dev/null || go install $(GOFUMPT)
	@command -v golangci-lint >/dev/null || go install $(GOLANGCI)

build:
	go build -trimpath -ldflags '$(LDFLAGS)' -o $(BIN) ./cmd/linkrot

# Release archives for all platforms (dist/), see scripts/dist.sh
dist:
	scripts/dist.sh $(VERSION)

run:
	go run ./cmd/linkrot $(ARGS)

test:
	go test ./...

lint:
	shellcheck scripts/dist.sh
	go vet ./...
	golangci-lint run ./...
	@out="$$(gofumpt -l .)"; test -z "$$out" || { echo "gofumpt would change:"; echo "$$out"; exit 1; }

# Known vulnerabilities in the dependencies and the standard library that the code really reaches (needs the
# network, vuln.go.dev); fails on any finding.
audit:
	go run $(GOVULN) ./...

# The release archives must be bit for bit the same whoever builds them: build twice with different umasks and compare.
repro:
	@set -e; v=0.0.0-repro; \
	$(MAKE) --no-print-directory dist VERSION=$$v >/dev/null; a=$$(sha256sum dist/SHA256SUMS | cut -d' ' -f1); \
	(umask 077; $(MAKE) --no-print-directory dist VERSION=$$v >/dev/null); b=$$(sha256sum dist/SHA256SUMS | cut -d' ' -f1); \
	if [ "$$a" = "$$b" ]; then echo "reproducible: $$a"; else echo "NOT reproducible: $$a != $$b"; exit 1; fi

fmt:
	gofumpt -w .

clean:
	rm -rf bin dist

precommit: lint test
