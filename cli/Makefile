# Linkrot CLI
export PATH := $(PATH):/usr/local/go/bin:$(HOME)/go/bin

BIN      := bin/linkrot
VERSION  ?= dev
LDFLAGS  := -X github.com/schorsch3000/Linkrot/internal/cli.Version=$(VERSION)
GOFUMPT  := mvdan.cc/gofumpt@v0.12.0
GOLANGCI := github.com/golangci/golangci-lint/v2/cmd/golangci-lint@v2.15.0

.PHONY: install build dist run test lint fmt clean precommit

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
	go vet ./...
	golangci-lint run ./...
	@out="$$(gofumpt -l .)"; test -z "$$out" || { echo "gofumpt would change:"; echo "$$out"; exit 1; }

fmt:
	gofumpt -w .

clean:
	rm -rf bin dist

precommit: lint test
