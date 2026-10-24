# Root Makefile: forwards targets to every part that has a Makefile.
PARTS := $(patsubst %/Makefile,%,$(wildcard server/Makefile cli/Makefile))

TARGETS := install build run test lint fmt clean precommit

.PHONY: $(TARGETS) install-hooks

# `run` is deliberately not forwarded: server and cli are started separately.
$(filter-out run,$(TARGETS)):
	@set -e; for p in $(PARTS); do echo "==> $$p: $@"; $(MAKE) -C $$p $@; done

run:
	@echo "run a part directly: make -C server run | make -C cli run"; exit 2

install-hooks:
	git config core.hooksPath .githooks
	@echo "git hooks enabled (.githooks)"
