# Default values
php ?= 8.3
sf ?= 7.1
args ?=

# Allowed PHP and Symfony version combinations
# (PHP_VERSION;SYMFONY_VERSION)
valid_combinations = \
    8.3;7.1 \
    8.3;6.4 \
    8.3;5.4 \
    8.2;7.1 \
    8.2;6.4 \
    8.2;5.4

current_combination = $(php);$(sf)

# list of config files to provide to docker compose
compose_files = -f tools/docker/compose.yaml

# Help target
.PHONY: help
help:
	@echo "Usage: make <target> [php=<php_version>] [sf=<symfony_version>] [args=<phpunit_args>]"
	@echo ""
	@echo "Targets:"
	@echo "  tests    - Run the test suite using PHPUnit."
	@echo "  cs-fix   - Run PHP-CS-Fixer to fix coding standards issues."
	@echo "  phpstan  - Run PHPStan for static code analysis."
	@echo ""
	@echo "Options:"
	@echo "  php      - PHP version to use (default: $(php)). Supported: 8.2, 8.3"
	@echo "  sf       - Symfony version to use (default: $(sf)). Supported: 5.4, 6.4, 7.1"
	@echo "  args     - Additional arguments:"
	$(eval $(call set_args,tests))
	@echo "             Defaults for 'tests' target:    $(args)"
	$(eval $(call set_args,phpstan))
	@echo "             Defaults for 'phpstan' target:  $(args)"
	$(eval $(call set_args,cs-fix))
	@echo "             Defaults for 'cs-fix' target:   $(args)"
	@echo ""
	@echo "Examples:"
	@echo "  make tests php=8.2 sf=6.4"
	@echo "  make cs-fix"
	@echo "  make phpstan"
	@echo "  make tests args='--filter=TestClassName'"
	@echo ""
	@echo "Note: Ensure that the PHP and Symfony versions are valid combinations:"
	@echo "      $(valid_combinations) (PHP_VERSION;SYMFONY_VERSION)"

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
	#$(call common_setup)
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
  endif
endef
