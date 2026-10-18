# Changelog

All notable changes to this project are documented here. The project follows [Semantic Versioning](https://semver.org/).

## [2.1.0] - Unreleased

The public API is backward compatible: `new RSSToJson()` and `parse()` work as before, and everything new is additive.

### Fixed

- Atom feeds, and any feed with a default namespace, no longer return every element twice (as `:title` and `title`, `:entry` and `entry`).
- RSS 1.0 (RDF) feeds now return their items in `channel['item']`. It used to be empty.
- Local files now go through the same clean-up as remote feeds (BOM, whitespace, bare `&`), so a feed parses the same from disk as from a URL.
- A bare `&` inside a CDATA section is no longer escaped to `&amp;`.
- Parsing no longer leaves `libxml_use_internal_errors()` switched on for the rest of the process.

### Security

- XML is loaded with `LIBXML_NONET` and never with `LIBXML_NOENT`, and on PHP 7.4 the libxml entity loader is disabled while parsing, so external entities and DTDs are not loaded on any supported PHP version.
- URL fetching is limited to `http` and `https`, for redirects too. Redirects are capped, the timeout now covers the whole fetch, a connect timeout is set, and the response size is capped.
- New opt-in `block_private_ips` option refuses private, loopback, link-local, reserved and cloud metadata addresses, for the URL and every redirect, and pins the connection to the checked address.

### Added

- `parseUrl()`, `parseFile()` and `parseString()`, for when you know what the input is. `parseUrl()` refuses anything but `http` and `https`.
- `getLastError()` explains why the last call returned `null`.
- Constructor options: `timeout`, `connect_timeout`, `max_redirects`, `max_size`, `block_private_ips` and `user_agent`.
- The CLI prints the failure reason.
- Tests and fixtures for RSS 2.0, Atom 1.0, RSS 1.0, Dublin Core, `content:encoded`, Media RSS, CDATA, XML entity handling and remote fetching. GitHub Actions runs them on PHP 7.4 and 8.0 to 8.4, with `composer validate --strict` and PHPStan.

### Behaviour changes you might notice

- Atom keys lose the leading `:`. If you read `:title` or `:entry` to work around the duplication, read `title` and `entry` instead.
- Redirects stop after 5. They used to be unlimited. Set `max_redirects` to change it.
- Responses over 20 MiB are refused. There used to be no limit. Set `max_size` to change it.
- The 10-second timeout now covers the whole fetch, redirects included, and connecting has its own 5-second limit.
- Local files with a bare `&` or an entity XML doesn't define, such as `&nbsp;`, now parse instead of returning `null`. The undefined entity comes back as literal text, as it already did for remote feeds.
- Entities declared in a feed's own DTD (for example `<!ENTITY copy "...">`) are no longer expanded in local files. They come back as literal text, such as `&copy;`, as they already did for remote feeds.
- Remote feeds with a bare `&` inside CDATA now return it as `&` instead of `&amp;`.

### Packaging

- The author email in `composer.json` is now a real address.
- `ext-simplexml` is declared as a requirement. It was always needed.

## [2.0.1] - 2025-11-17

- Removed the old `RSStoJSON.php` file, leaving only `RSSToJson.php`.

## [2.0.0] - 2025-11-17

- The namespace changed to `TomGould\RSSToJson` for PSR-4 compliance.

## [1.0.2] - 2025-11-17

- Fixed the CLI's autoload path when installed as a dependency.

## [1.0.1] - 2025-11-17

- PHP 7.4 compatibility fixes.

## [1.0.0] - 2025-05-17

- First release.

[2.1.0]: https://github.com/tomgould/rss-to-json/compare/v2.0.1...v2.1
[2.0.1]: https://github.com/tomgould/rss-to-json/compare/v2.0.0...v2.0.1
[2.0.0]: https://github.com/tomgould/rss-to-json/compare/v1.0.2...v2.0.0
[1.0.2]: https://github.com/tomgould/rss-to-json/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/tomgould/rss-to-json/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/tomgould/rss-to-json/releases/tag/v1.0.0
