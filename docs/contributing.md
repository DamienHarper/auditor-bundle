This document explains how to contribute to auditor-bundle.

## Getting Started

### Prerequisites

- PHP 8.4+
- Composer
- Git

### Clone and Setup

```bash
git clone https://github.com/DamienHarper/auditor-bundle.git
cd auditor-bundle
composer install
```

## Development Workflow

### Running Tests

```bash
# Run all tests
composer test

# With coverage
composer test:coverage

# With testdox output
composer testdox
```

### Code Quality

```bash
# Run all QA tools
composer qa

# Individual tools
composer cs-fix        # Fix code style
composer cs-check      # Check code style (dry-run)
composer phpstan       # Static analysis
composer rector        # Automated refactoring
composer rector-check  # Check refactoring (dry-run)
```

## Submitting Changes

### Pull Request Process

1. Fork the repository
2. Create a feature branch from `master`
3. Make your changes
4. Add tests for new features
5. Ensure all tests pass
6. Submit a pull request

### Commit Messages

Use clear, descriptive commit messages:

```
Add support for custom audit table schemas

- Add SchemaCustomizer interface
- Update SchemaManager to use customizers
- Add documentation for schema customization
```

### Code Style

The project follows PSR-12. Run `composer cs-fix` before committing.

## Reporting Issues

### Bug Reports

Include:
- PHP version
- Symfony version
- Bundle version
- Steps to reproduce
- Expected vs actual behavior
- Error messages/stack traces

### Feature Requests

Describe:
- The feature you'd like
- Why it would be useful
- How it should work

## Documentation

Documentation is in the `docs/` directory. When adding features, update relevant documentation.

### Documentation Style

- Use Markdown format
- Use tables with dashes (not pipes) for compatibility with auditor docs
- Include code examples
- Be concise and direct

## License

Contributions are licensed under MIT License.
