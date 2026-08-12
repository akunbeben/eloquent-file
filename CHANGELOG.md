# Release Notes

## [Unreleased](https://github.com/akunbeben/eloquent-file/compare/v0.2.0...HEAD)

- Added schema metadata and table/column prefix suggestions for integrations and AI tools.
- Changed the default dump storage to a temporary file-backed SQLite database for large dumps.
- Added configurable temporary-directory cleanup, stale-file removal, free-space checks, and explicit `close()` lifecycle management.
- Added a reproducible `composer benchmark` command with human-readable and JSON output.
- Cached prepared INSERT statements during imports to reduce repeated preparation overhead.

## [v0.2.0](https://github.com/akunbeben/eloquent-file/compare/v0.1.2...v0.2.0) - 2026-08-12

### Highlights

- Use temporary file-backed SQLite storage by default for large SQL dumps.
- Add explicit `close()` lifecycle management and cleanup for temporary databases and SQLite sidecars.
- Add stale-file cleanup, free-space checks, ownership-safe connections, and Windows-friendly cleanup retries.
- Cache prepared INSERT statements during imports.
- Add `composer benchmark` with human-readable and JSON output.
- Keep `table()` on Laravel's normal Query Builder behavior; `cursor()` and `lazy()` remain opt-in for large result sets.

### Benchmark

Against a 932,572,777-byte dump: approximately 24 seconds import time, 914.6 MB reconstructed SQLite file, 6 MB PHP peak memory, and temporary file cleanup after close.

### Compatibility

Validated across PHP 8.3, 8.4, and 8.5; Laravel 12 and 13; Ubuntu and Windows; prefer-lowest and prefer-stable dependency lanes.

## [v0.1.2](https://github.com/akunbeben/eloquent-file/compare/v0.1.1...v0.1.2) - 2026-08-11

<!-- Release notes generated using configuration in .github/release.yml at v0.1.2 -->
### What's Changed

#### Other Changes

* feat: expose schema metadata for integrations by @akunbeben in https://github.com/akunbeben/eloquent-file/pull/1

### New Contributors

* @akunbeben made their first contribution in https://github.com/akunbeben/eloquent-file/pull/1

**Full Changelog**: https://github.com/akunbeben/eloquent-file/compare/v0.1.0...v0.1.2

## [v0.1.1](https://github.com/akunbeben/eloquent-file/compare/v0.1.0...v0.1.1) - 2026-07-24

<!-- Release notes generated using configuration in .github/release.yml at v0.1.1 -->
**Full Changelog**: https://github.com/akunbeben/eloquent-file/commits/v0.1.1

## [v0.1.0](https://github.com/akunbeben/eloquent-file/compare/...v0.1.0) - 202x-xx-xx

Initial pre-release.
