# Linkrot CLI
export PATH := $(PATH):/usr/local/go/bin:$(HOME)/go/bin

BIN      := bin/linkrot
GOFUMPT  := mvdan.cc/gofumpt@latest
GOLANGCI := github.com/golangci/golangci-lint/v2/cmd/golangci-lint@latest

.PHONY: install build run test lint fmt clean precommit

install:
	go mod download
	@command -v gofumpt >/dev/null || go install $(GOFUMPT)
	@command -v golangci-lint >/dev/null || go install $(GOLANGCI)

build:
	go build -trimpath -o $(BIN) ./cmd/linkrot

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
	rm -rf bin

precommit: lint test
