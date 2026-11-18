# Default values
php ?= 8.5
sf ?= 8.0
args ?=

# Allowed PHP and Symfony version combinations
# (PHP_VERSION;SYMFONY_VERSION)
valid_combinations = \
    8.4;8.0 \
    8.5;8.0

current_combination = $(php);$(sf)

# list of config files to provide to docker compose
compose_files = -f tools/docker/compose.yaml

# Help target
.PHONY: help
help:
	@echo "Usage: make <target> [php=<php_version>] [sf=<symfony_version>] [args=<phpunit_args>]"
	@echo ""
	@echo "Targets:"
	@echo "  tests        - Run the test suite using PHPUnit."
	@echo "  cs-fix       - Run PHP-CS-Fixer to fix coding standards issues."
	@echo "  phpstan      - Run PHPStan for static code analysis."
	@echo "  rector       - Run Rector to apply automated refactorings."
	@echo "  qa           - Run Rector, PHP-CS-Fixer and PHPStan, in this order (ignores args)."
	@echo "  build-assets - Build CSS assets using Tailwind CSS (minified for production)."
	@echo "  watch-assets - Watch and rebuild CSS assets on changes (development mode)."
	@echo ""
	@echo "Options:"
	@echo "  php      - PHP version to use (default: $(php)). Supported: 8.4, 8.5"
	@echo "  sf       - Symfony version to use (default: $(sf)). Supported: 8.0"
	@echo "  args     - Additional arguments:"
	$(eval $(call set_args,tests))
	@echo "             Defaults for 'tests' target:    $(args)"
	$(eval $(call set_args,phpstan))
	@echo "             Defaults for 'phpstan' target:  $(args)"
	$(eval $(call set_args,cs-fix))
	@echo "             Defaults for 'cs-fix' target:   $(args)"
	$(eval $(call set_args,rector))
	@echo "             Defaults for 'rector' target:   $(args)"
	@echo ""
	@echo "Examples:"
	@echo "  make tests php=8.4 sf=8.0"
	@echo "  make cs-fix"
	@echo "  make phpstan"
	@echo "  make build-assets"
	@echo "  make tests args='--filter=TestClassName'"
	@echo ""
	@echo "Note: Ensure that the PHP and Symfony versions are valid combinations:"
	@echo "      $(valid_combinations) (PHP_VERSION;SYMFONY_VERSION)"
	@echo ""
	@echo "Git worktrees (parallel development in .claude/worktrees/<name>/, host PHP):"
	@echo "  wt-create branch=<b> [base=<ref>]   - Create a worktree (new branch from origin/HEAD) and install it."
	@echo "  wt-init [wt=<name>]                 - Install the dependencies of an existing worktree."
	@echo "  wt-list                             - List the worktrees."
	@echo "  wt-exec [wt=<name>] args=<cmd>      - Run a command in a worktree."
	@echo "  wt-qa [wt=<name>]                   - composer agent-qa in a worktree."
	@echo "  wt-test [wt=<name>] [args=<...>]    - composer agent-test in a worktree (args go to PHPUnit)."
	@echo "  wt-phpstan [wt=<name>]              - composer agent-phpstan in a worktree."
	@echo "  wt-clean [wt=<name>] [confirm=yes]  - Remove a clean worktree (the branch is kept)."
	@echo "  wt=<name> is optional when make runs from .claude/worktrees/<name>/."

# Common target setup
define common_setup
	@rm -f composer.lock
	@PHP_VERSION=$(php) SYMFONY_VERSION=$(sf) \
	sh -c "docker compose $(compose_files) run --rm --remove-orphans php-cli composer global config --no-plugins allow-plugins.symfony/flex true"
	@PHP_VERSION=$(php) SYMFONY_VERSION=$(sf) \
	sh -c "docker compose $(compose_files) run --rm --remove-orphans php-cli composer global require --no-progress --no-scripts --no-plugins symfony/flex --quiet"
	@PHP_VERSION=$(php) SYMFONY_VERSION=$(sf) \
	sh -c "docker compose $(compose_files) run --rm --remove-orphans php-cli composer install --quiet"
	@PHP_VERSION=$(php) SYMFONY_VERSION=$(sf) \
	sh -c "docker compose $(compose_files) run --rm --remove-orphans php-cli composer reinstall damienharper/auditor --prefer-install=source --quiet"
endef

# Run PHPUnit target
.PHONY: tests
tests: validate_matrix
	$(eval $(call set_args,tests))
	$(call common_setup)
	PHP_VERSION=$(php) SYMFONY_VERSION=$(sf) \
	sh -c "docker compose $(compose_files) run --rm --remove-orphans php-cli vendor/bin/phpunit $(args)"

# Run PHPStan target
.PHONY: phpstan
phpstan: validate_matrix
	$(eval $(call set_args,phpstan))
	$(call common_setup)
	@PHP_VERSION=$(php) SYMFONY_VERSION=$(sf) \
	sh -c "docker compose $(compose_files) run --rm --remove-orphans php-cli composer install --working-dir=tools/phpstan --quiet"
	PHP_VERSION=$(php) SYMFONY_VERSION=$(sf) \
	sh -c "docker compose $(compose_files) run --rm --remove-orphans php-cli tools/phpstan/vendor/bin/phpstan $(args)"

# Run PHP-CS-Fixer target
.PHONY: cs-fix
cs-fix: validate_matrix
	$(eval $(call set_args,cs-fix))
	$(call common_setup)
	@PHP_VERSION=$(php) SYMFONY_VERSION=$(sf) \
	sh -c "docker compose $(compose_files) run --rm --remove-orphans php-cli composer install --working-dir=tools/php-cs-fixer --quiet"
	PHP_VERSION=$(php) SYMFONY_VERSION=$(sf) \
	sh -c "docker compose $(compose_files) run --rm --remove-orphans php-cli tools/php-cs-fixer/vendor/bin/php-cs-fixer $(args)"

# Run Rector target
.PHONY: rector
rector: validate_matrix
	$(eval $(call set_args,rector))
	$(call common_setup)
	@PHP_VERSION=$(php) SYMFONY_VERSION=$(sf) \
	sh -c "docker compose $(compose_files) run --rm --remove-orphans php-cli composer install --working-dir=tools/rector --quiet"
	PHP_VERSION=$(php) SYMFONY_VERSION=$(sf) \
	sh -c "docker compose $(compose_files) run --rm --remove-orphans php-cli tools/rector/vendor/bin/rector $(args)"

# Run the full QA suite: Rector, PHP-CS-Fixer, then PHPStan (default arguments only)
.PHONY: qa
qa: validate_matrix
	$(call common_setup)
	@for tool in rector php-cs-fixer phpstan; do \
		PHP_VERSION=$(php) SYMFONY_VERSION=$(sf) \
		sh -c "docker compose $(compose_files) run --rm --remove-orphans php-cli composer install --working-dir=tools/$$tool --quiet" || exit 1; \
	done
	PHP_VERSION=$(php) SYMFONY_VERSION=$(sf) \
	sh -c "docker compose $(compose_files) run --rm --remove-orphans php-cli tools/rector/vendor/bin/rector process --ansi"
	PHP_VERSION=$(php) SYMFONY_VERSION=$(sf) \
	sh -c "docker compose $(compose_files) run --rm --remove-orphans php-cli tools/php-cs-fixer/vendor/bin/php-cs-fixer fix --using-cache=no --verbose --ansi"
	PHP_VERSION=$(php) SYMFONY_VERSION=$(sf) \
	sh -c "docker compose $(compose_files) run --rm --remove-orphans php-cli tools/phpstan/vendor/bin/phpstan analyse src --memory-limit=1G --ansi"

# Validate PHP and Symfony version matrix
validate_matrix:
	@if ! echo "$(valid_combinations)" | grep -q "$(current_combination)"; then \
		echo "Error: Invalid combination of PHP and Symfony versions: php=$(php), sf=$(sf)"; \
		echo "Allowed combinations are:"; \
		echo "(PHP_VERSION;SYMFONY_VERSION)"; \
		echo "$(valid_combinations)" | tr ' ' '\n'; \
		exit 1; \
	fi

# Set default args for each target
define set_args
  ifeq ($(1),tests)
    args := --colors=always --no-coverage
  else ifeq ($(1),phpstan)
    args := analyse src --memory-limit=1G --ansi
  else ifeq ($(1),cs-fix)
    args := fix --using-cache=no --verbose --ansi
  else ifeq ($(1),rector)
    args := process --ansi
  endif
endef

# Build CSS assets using Tailwind CSS (for production/release)
.PHONY: build-assets
build-assets:
	@echo "Building CSS assets with Tailwind CSS..."
	cd tests/App && php bin/console tailwind:build --minify
	@cp tests/App/var/tailwind/app.built.css src/Resources/public/app.css
	@echo "Assets built successfully: src/Resources/public/app.css"

# Watch and rebuild CSS assets on changes (for development)
.PHONY: watch-assets
watch-assets:
	@echo "Watching CSS assets with Tailwind CSS..."
	cd tests/App && php bin/console tailwind:build --watch

# ============================================================================
# Git worktrees — parallel development in .claude/worktrees/<name>/
#
# Each worktree has its own vendor/ and tools; QA and tests run on the host PHP
# through the composer agent-* scripts. For the Docker matrix, run the targets
# above from the worktree: make -C .claude/worktrees/<name> tests php=8.4
# ============================================================================

wt ?=
branch ?=
base ?=
confirm ?=

# Root of the main working tree, whether make runs there or inside a worktree.
_wt_root = $(shell dirname "$$(git rev-parse --path-format=absolute --git-common-dir)")
# Worktree name: wt=<name>, or detected from the current directory.
_wt_detected = $(if $(wt),$(wt),$(shell pwd | sed -n 's|.*/\.claude/worktrees/\([^/]*\).*|\1|p'))
_wt_dir = $(_wt_root)/.claude/worktrees/$(_wt_detected)

# Every wt-* target but wt-create and wt-list needs an existing worktree.
_check_wt:
	@if [ -z "$(_wt_detected)" ]; then \
		echo "No worktree detected: pass wt=<name> or run make from .claude/worktrees/<name>/."; \
		exit 1; \
	fi
	@if [ ! -d "$(_wt_dir)" ]; then \
		echo "No worktree at $(_wt_dir) (see make wt-list)."; \
		exit 1; \
	fi

# Creates a worktree for <branch> and installs its dependencies.
# A new branch starts from base=<ref>, or from the remote default branch (origin/HEAD),
# freshly fetched — never from the HEAD of the main working tree.
.PHONY: wt-create
wt-create:
	@if [ -z "$(branch)" ]; then \
		echo "Usage: make wt-create branch=<branch> [base=<ref>]"; \
		echo "Example: make wt-create branch=feature/audit-retention"; \
		exit 1; \
	fi
	@name=$$(echo "$(branch)" | tr '/' '-'); \
	dir="$(_wt_root)/.claude/worktrees/$$name"; \
	if [ -e "$$dir" ]; then echo "$$dir already exists."; exit 1; fi; \
	git -C "$(_wt_root)" fetch --quiet origin || exit 1; \
	if git -C "$(_wt_root)" show-ref --verify --quiet "refs/heads/$(branch)"; then \
		git -C "$(_wt_root)" worktree add "$$dir" "$(branch)" || exit 1; \
	elif git -C "$(_wt_root)" show-ref --verify --quiet "refs/remotes/origin/$(branch)"; then \
		git -C "$(_wt_root)" worktree add --track -b "$(branch)" "$$dir" "origin/$(branch)" || exit 1; \
	else \
		start="$(base)"; \
		if [ -z "$$start" ]; then \
			start=$$(git -C "$(_wt_root)" symbolic-ref --quiet --short refs/remotes/origin/HEAD) || { \
				echo "origin/HEAD is not set: run git remote set-head origin --auto, or pass base=<ref>."; \
				exit 1; \
			}; \
		fi; \
		git -C "$(_wt_root)" worktree add --no-track -b "$(branch)" "$$dir" "$$start" || exit 1; \
	fi; \
	$(MAKE) --no-print-directory wt-init wt="$$name"

# Installs the dependencies of an existing worktree (project and tools/).
# A fresh worktree has no symfony.lock, so Symfony Flex would install its recipes: the host
# install runs without plugins, and the root symfony.lock (if any) is copied for the Docker targets.
.PHONY: wt-init
wt-init: _check_wt
	@echo "Installing dependencies in worktree $(_wt_detected)..."
	@if [ -f "$(_wt_root)/symfony.lock" ] && [ ! -e "$(_wt_dir)/symfony.lock" ]; then \
		cp "$(_wt_root)/symfony.lock" "$(_wt_dir)/symfony.lock"; \
	fi
	@cd "$(_wt_dir)" && composer install --no-interaction --no-progress --quiet --no-plugins
	@for tool in php-cs-fixer phpstan rector; do \
		composer install --working-dir="$(_wt_dir)/tools/$$tool" --no-interaction --no-progress --quiet || exit 1; \
	done
	@echo "Worktree ready: $(_wt_dir)"

# Lists the worktrees of the repository.
.PHONY: wt-list
wt-list:
	@git -C "$(_wt_root)" worktree list

# Runs a command in the worktree.
.PHONY: wt-exec
wt-exec: _check_wt
	@cd "$(_wt_dir)" && $(args)

# composer agent-qa (rector + php-cs-fixer + phpstan) in the worktree.
.PHONY: wt-qa
wt-qa: _check_wt
	@echo "QA in worktree $(_wt_detected)"
	@cd "$(_wt_dir)" && composer agent-qa

# composer agent-test in the worktree; args=<phpunit arguments> is passed to PHPUnit.
.PHONY: wt-test
wt-test: _check_wt
	@echo "Tests in worktree $(_wt_detected)"
	@cd "$(_wt_dir)" && composer agent-test $(if $(args),-- $(args))

# composer agent-phpstan in the worktree.
.PHONY: wt-phpstan
wt-phpstan: _check_wt
	@cd "$(_wt_dir)" && composer agent-phpstan

# Removes a clean worktree (confirm=yes); the branch is kept.
.PHONY: wt-clean
wt-clean: _check_wt
	@if [ -n "$$(git -C "$(_wt_dir)" status --porcelain)" ]; then \
		echo "Worktree $(_wt_detected) has uncommitted changes:"; \
		git -C "$(_wt_dir)" status --short; \
		exit 1; \
	fi
	@if [ "$(confirm)" = "yes" ]; then \
		git -C "$(_wt_root)" worktree remove "$(_wt_dir)" || exit 1; \
		echo "Worktree $(_wt_detected) removed. Its branch is kept: delete it once merged."; \
	else \
		echo "Worktree $(_wt_detected) is clean. To remove it: make wt-clean wt=$(_wt_detected) confirm=yes"; \
	fi
