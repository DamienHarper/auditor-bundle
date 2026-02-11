# Testing

This document explains how to run the test suite for auditor-bundle.

## Quick Start

```bash
# Run tests with your local PHP
composer test

# Run tests with coverage
composer test:coverage

# Run tests with testdox output
composer testdox
```

## Testing with Docker (Recommended)

The project includes a `Makefile` that lets you test across different PHP and Symfony versions using Docker.

### Prerequisites

- Docker
- Docker Compose
- Make

### Available Commands

```bash
# Show help
make help

# Run tests (defaults: PHP 8.5, Symfony 8.0)
make tests

# Run tests with specific PHP version
make tests php=8.4

# Run tests with specific Symfony version
make tests sf=7.3

# Run tests with specific PHP and Symfony versions
make tests php=8.4 sf=7.3

# Run a specific test
make tests args='--filter=ViewerControllerTest'
```

### Supported Matrix

| Option | Values                                |
|--------|---------------------------------------|
| `php`  | `8.2`, `8.3`, `8.4`, `8.5`            |
| `sf`   | `5.4`, `6.4`, `7.1`, `7.2`, `7.3`, `8.0` |

> **Note:** Not all combinations are valid. Run `make help` to see allowed combinations.

### Testing Multiple Versions

Before submitting a PR, test against multiple PHP/Symfony combinations:

```bash
make tests php=8.4 sf=7.3
make tests php=8.4 sf=8.0
make tests php=8.5 sf=8.0
```

## Code Quality Tools

```bash
# Run PHP-CS-Fixer
make cs-fix

# Run PHPStan
make phpstan
```

## Writing Tests

- Place tests in `tests/` mirroring the `src/` structure
- Use meaningful test method names
- Include positive and negative test cases

See [docs/contributing.md](../docs/contributing.md) for detailed guidelines.
