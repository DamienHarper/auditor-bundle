# Contributing

> **Help improve auditor-bundle**

Thank you for considering contributing to auditor-bundle!

## 🤝 Ways to Contribute

- 🐛 **Report bugs** - Submit issues on GitHub
- 💡 **Suggest features** - Open a discussion or issue
- 📖 **Improve documentation** - Fix typos, add examples, clarify explanations
- 🔧 **Submit code** - Fix bugs or implement new features
- ⭐ **Star the project** - Show your support

## 💻 Code Contributions

All code contributions are made via **Pull Requests (PR)**. Direct commits to the `master` branch are not allowed.

### 🚀 Development Setup

1. Fork the repository on GitHub
2. Clone your fork locally:

```bash
git clone https://github.com/YOUR_USERNAME/auditor-bundle.git
cd auditor-bundle
```

3. Install dependencies:

```bash
composer install
```

4. Create a branch for your changes:

```bash
git checkout -b feature/my-new-feature
```

### ✅ Running Tests

#### Quick Tests (Local PHP)

```bash
# Run all tests
composer test

# Run tests with coverage
composer test:coverage

# Run tests with testdox output
composer testdox
```

#### 🐳 Testing with Docker (Recommended)

The project includes a `Makefile` that allows you to test against different combinations of PHP versions and Symfony versions using Docker containers. This ensures your code works across all supported environments.

**Prerequisites:**
- Docker
- Docker Compose
- Make

**Available Make Targets:**

| Target    | Description                              |
|-----------|------------------------------------------|
| `tests`   | Run the test suite using PHPUnit         |
| `cs-fix`  | Run PHP-CS-Fixer to fix coding standards |
| `phpstan` | Run PHPStan for static code analysis     |
| `help`    | Display available commands and options   |

**Options:**

| Option | Values                     | Default  | Description          |
|--------|----------------------------|----------|----------------------|
| `php`  | `8.4`, `8.5`               | `8.5`    | PHP version          |
| `sf`   | `8.0`                      | `8.0`    | Symfony version      |
| `args` | Any PHPUnit/tool arguments | (varies) | Additional arguments |

**Valid PHP/Symfony Combinations:**

| PHP Version | Symfony Versions |
|-------------|------------------|
| 8.4         | 8.0              |
| 8.5         | 8.0              |

**Examples:**

```bash
# Show all available commands and options
make help

# Run tests with defaults (PHP 8.5, Symfony 8.0)
make tests

# Run tests with specific PHP version
make tests php=8.4

# Run specific test class
make tests args='--filter=ViewerControllerTest'

# Run tests with coverage
make tests args='--coverage-html=coverage'
```

**Testing Multiple Versions:**

> [!TIP]
> Before submitting a pull request, it's recommended to test against multiple PHP versions:

```bash
# Test different PHP versions
make tests php=8.4
make tests php=8.5
```

### 🔍 Code Quality

Before submitting, ensure your code passes all quality checks.

#### Using Composer (Local)

```bash
# Run all QA tools
composer qa

# Individual tools:
composer cs-check    # Check code style
composer cs-fix      # Fix code style
composer phpstan     # Static analysis
composer rector      # Automated refactoring suggestions
```

#### Using Make (Docker)

```bash
# Run PHP-CS-Fixer
make cs-fix

# Run PHPStan
make phpstan

# With specific PHP version
make phpstan php=8.4

# With custom arguments
make cs-fix args='fix --dry-run'
```

### 📝 Commit Messages

Write clear, concise commit messages:

- ✅ Use the present tense ("Add feature" not "Added feature")
- ✅ Use the imperative mood ("Move cursor to..." not "Moves cursor to...")
- ✅ Limit the first line to 72 characters
- ✅ Reference issues and pull requests when relevant

**Good examples:**
- `Add support for custom viewer templates`
- `Fix RoleChecker when no user is authenticated`
- `Update documentation for v7 migration`

### 📤 Pull Request Process

1. ✅ Ensure all tests pass (ideally on multiple PHP/Symfony combinations)
2. ✅ Run code quality tools (`make cs-fix`, `make phpstan`)
3. 📖 Update documentation if needed
4. 📤 Submit the pull request
5. 💬 Respond to review feedback

### 🤖 Continuous Integration (CI)

When you submit a Pull Request, GitHub Actions will automatically run:

- **PHPUnit tests** across the full matrix:
  - PHP versions: 8.4, 8.5
  - Symfony version: 8.0
- **PHP-CS-Fixer** for code style validation
- **PHPStan** for static analysis
- **Code coverage** report

> [!IMPORTANT]
> Your PR must pass all CI checks before it can be merged. If a check fails, review the logs to identify and fix the issue.

> [!TIP]
> Run `make tests php=8.4` and `make tests php=8.5` locally before pushing to catch compatibility issues early.

### ✍️ Writing Tests

Tests are **highly encouraged** and often required for new features or bug fixes:

- 📁 Place tests in the `tests/` directory, mirroring the `src/` structure
- 📝 Use meaningful test method names that describe the behavior being tested
- ✅ Include both positive and negative test cases
- 🔍 Test edge cases and error conditions

**Test Structure Example:**

```php
namespace DH\AuditorBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ViewerControllerTest extends WebTestCase
{
    public function testAuditListIsAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/audit');

        $this->assertResponseIsSuccessful();
    }

    public function testAuditListRequiresAuthentication(): void
    {
        // ...
    }
}
```

**Running Your Tests:**

```bash
# Run only your new tests
make tests args='--filter=ViewerControllerTest'

# Run with coverage to ensure good test coverage
composer test:coverage
```

## 🐛 Reporting Bugs

When reporting bugs, please include:

1. 📦 **auditor-bundle version** - `composer show damienharper/auditor-bundle`
2. 📦 **auditor version** - `composer show damienharper/auditor`
3. 🐘 **PHP version** - `php -v`
4. 🎵 **Symfony version** - `composer show symfony/framework-bundle`
5. 📋 **Steps to reproduce** - Minimal code example
6. ✅ **Expected behavior** - What should happen
7. ❌ **Actual behavior** - What actually happens
8. 📝 **Error messages** - Full stack trace if available

## 💡 Feature Requests

For feature requests:

1. 🔍 Check existing issues to avoid duplicates
2. 📝 Describe the use case
3. 💭 Explain why existing features don't meet your needs
4. 🔧 Suggest a possible implementation if you have ideas

## 📖 Documentation Contributions

Documentation lives in the `docs/` directory and uses Markdown.

### Style Guide

- ✅ Use clear, simple language
- ✅ Include code examples
- ✅ Add internal links to related content
- ✅ Use standard Markdown tables with pipes
- ✅ Test all code examples

## ❓ Questions?

- Open a [GitHub Issue](https://github.com/DamienHarper/auditor-bundle/issues)
- Check existing issues first
- Be patient - maintainers are volunteers

## 📜 License

By contributing, you agree that your contributions will be licensed under the MIT License.
