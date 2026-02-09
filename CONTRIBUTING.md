Contributing
============

`auditor-bundle` is an open source project. Contributions made by the community are welcome. Send us your ideas, code reviews, pull requests and feature requests to help us improve this project.

Do not forget to provide unit tests when contributing to this project. To do so, follow instructions in [this dedicated README](tests/README.md)


Building Assets
---------------

This bundle uses [Tailwind CSS 4](https://tailwindcss.com/) for styling the audit viewer. Assets are pre-compiled and included in releases, so end users don't need to compile them.

### For contributors

If you need to modify the CSS styles, you'll need to rebuild the assets:

1. Install dependencies (including dev dependencies):
   ```bash
   composer install
   ```

2. Build the CSS for production (minified):
   ```bash
   make build-assets
   ```

3. Or watch for changes during development:
   ```bash
   make watch-assets
   ```

The source CSS file is located at `src/Resources/assets/css/app.css` and the compiled output is placed in `src/Resources/public/app.css`.

**Note:** The compiled `src/Resources/public/app.css` file must be committed before creating a release.
