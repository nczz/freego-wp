# Contributing

Contributions are welcome when they improve Freego/WCAG alignment, WordPress compatibility, or review workflow quality.

## Development Principles

- Keep automatic repair scoped to elements that match a known failing condition.
- Do not silently replace human semantic review with fake content.
- When adding aggressive repair behavior, keep it behind the explicit setting.
- Add or update rule metadata when Freego changes.
- Run PHP syntax checks and JavaScript syntax checks before opening a pull request.

## Verification

Recommended checks:

```sh
docker run --rm -v "$PWD:/app:ro" -w /app php:8.2-cli sh -lc 'for f in $(find . -name "*.php" -print); do php -l "$f" || exit 1; done'
node --check assets/js/runtime.js
tools/extract-freego-v3-rules.sh /Applications/Freego.app/Contents/app/freego.jar
```

## Release Process

1. Update `FREEGO_WP_VERSION` and the plugin header version in `freego-wp.php`.
2. Commit the change.
3. Run `scripts/build-release.sh`.
4. Create a GitHub release tagged as `vX.Y.Z` and upload `dist/freego-wp.zip`.
5. WordPress sites using this plugin will see the release through the built-in GitHub updater.
