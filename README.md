# RSStoJSON

[![Latest version](https://img.shields.io/packagist/v/tomgould/rsstojson.svg)](https://packagist.org/packages/tomgould/rsstojson)
[![Total downloads](https://img.shields.io/packagist/dt/tomgould/rsstojson.svg)](https://packagist.org/packages/tomgould/rsstojson)
[![CI](https://github.com/tomgould/rss-to-json/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/tomgould/rss-to-json/actions/workflows/ci.yml)

A small PHP library that parses RSS, Atom and other XML feeds into plain arrays you can pass straight to `json_encode()`. It reads from a URL, a local file or a string, keeps namespaced elements such as `media:content` and `dc:creator`, and has no dependencies beyond PHP's own extensions.

Supported formats: RSS 0.9x and 2.0, RSS 1.0 (RDF), Atom 1.0, and extensions such as Media RSS, Dublin Core and `content:encoded`. Other XML is parsed generically.

## Installation

```bash
composer require tomgould/rsstojson
```

Requires PHP 7.4 or later with the `curl`, `libxml` and `simplexml` extensions.

## Usage

```php
<?php

require 'vendor/autoload.php';

use TomGould\RSSToJson\RSSToJson;

$parser = new RSSToJson();

$feed = $parser->parseUrl('https://example.com/feed.xml'); // fetch over http or https
$feed = $parser->parseFile('/path/to/feed.xml');           // read a local file
$feed = $parser->parseString($xml);                        // XML you already have
$feed = $parser->parse($urlOrPath);                        // URL or local path, as in 2.0

if ($feed === null) {
    echo $parser->getLastError(), PHP_EOL;
} else {
    echo json_encode($feed, JSON_PRETTY_PRINT);
}
```

Every method returns `null` on failure, and `getLastError()` says why. XML errors are also written to `error_log()`, as in earlier versions.

### Options

Options are passed to the constructor and only affect fetching from a URL. All are optional.

```php
$parser = new RSSToJson([
    'timeout' => 10,                // seconds for the whole fetch, redirects included
    'connect_timeout' => 5,         // seconds to connect
    'max_redirects' => 5,           // 0 follows none
    'max_size' => 20 * 1024 * 1024, // largest response body, in bytes
    'block_private_ips' => false,   // see Security
    'user_agent' => 'RSStoJSON/1.0 (+http://dealmobile.co.uk)',
]);
```

The values shown are the defaults. An unknown option or an invalid value throws `InvalidArgumentException`.

### CLI

```bash
vendor/bin/rsstojson https://example.com/feed.xml
vendor/bin/rsstojson /path/to/feed.xml
```

It prints the feed as pretty-printed JSON, or the reason for the failure on stderr.

## Output

This feed:

```xml
<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/">
  <channel>
    <title>Example Feed</title>
    <link>https://example.com/</link>
    <item>
      <title>Fish &amp; chips</title>
      <guid isPermaLink="false">post-1</guid>
      <category>Food</category>
      <category>UK</category>
      <media:thumbnail url="https://example.com/1.jpg" width="640"/>
    </item>
  </channel>
</rss>
```

becomes (with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES`):

```json
{
    "channel": {
        "title": "Example Feed",
        "link": "https://example.com/",
        "item": [
            {
                "media:thumbnail": {
                    "@attributes": {
                        "url": "https://example.com/1.jpg",
                        "width": "640"
                    }
                },
                "title": "Fish & chips",
                "guid": {
                    "@attributes": {
                        "isPermaLink": "false"
                    },
                    "@value": "post-1"
                },
                "category": [
                    "Food",
                    "UK"
                ]
            }
        ]
    }
}
```

The rules:

- The result is always `['channel' => [...]]`. For Atom, and for XML without a `<channel>`, the root element's children go there.
- Items are always a list under `channel['item']`, even when there is only one. That covers RSS `<item>` and Atom `<entry>`. Atom entries also stay under `channel['entry']`.
- An element with only text becomes a string. Repeated elements become a list.
- Attributes go under `@attributes`. Text next to attributes or child elements goes under `@value`.
- Prefixed elements keep their prefix, e.g. `media:thumbnail` or `dc:creator`. Within an element, prefixed children come before unprefixed ones.
- CDATA becomes plain text. Entities are decoded, except those XML doesn't define (see Security).
- Namespaced attributes such as `rdf:about` or `xml:lang` are not included.
- An empty element becomes an empty array.

## Security

What is safe by default:

- XML is loaded with network access disabled (`LIBXML_NONET`) and without entity substitution (`LIBXML_NOENT` is never used), so external entities and DTDs are not loaded. On PHP 7.4 the libxml entity loader is also switched off while parsing. Before loading, any `&` that does not start one of XML's five predefined entities or a numeric character reference is escaped, so something like `&nbsp;` or a DTD-declared `&xxe;` comes out as literal text. UTF-16 documents skip that step, so entities declared in their own DTD are expanded, but external entities are still not loaded.
- When fetching, only `http` and `https` are allowed, for the URL and for every redirect. Redirects stop after 5, the whole fetch times out after 10 seconds (5 to connect), and responses larger than 20 MiB are refused. Only responses with a 2xx status and an XML content type are accepted. All of these can be changed with the options above.

Blocking private addresses (opt-in): set `'block_private_ips' => true` whenever the URL comes from someone you don't trust. The host of the URL, and of every redirect, is then resolved before connecting, and the request is refused if any address is private, loopback, link-local, carrier-grade NAT, multicast or otherwise reserved. That includes cloud metadata addresses such as `169.254.169.254` and IPv4 addresses embedded in IPv6. The connection is pinned to the address that was checked, so DNS rebinding cannot switch it, and proxy settings are ignored. URLs with credentials, whitespace or backslashes, and host names that are not plain ASCII (use the `xn--` form for international domains), are refused in this mode. On PHP 8.4 and later, the address cURL connects to is checked again before the request is sent. To allow a particular internal host, extend the class and override `isAddressAllowed(string $ip): bool`.

Local files: `parse()` treats any string that is not a valid URL as a local path, and reads it with PHP's file functions. Those also accept stream wrappers such as `php://` and `compress.zlib://`. This is kept for backward compatibility, but it means you should never pass untrusted input to `parse()` or `parseFile()`, because it could read any file the PHP process can. For a user-supplied URL, use `parseUrl()` (which refuses anything but `http` and `https`) with `block_private_ips` on.

## Examples

See the `examples` directory:

- `examples/parse-url.php` parses a remote feed.
- `examples/parse-local.php` parses a local Media RSS feed.

## Development

```bash
composer install
composer test     # PHPUnit
composer analyse  # PHPStan
```

The remote-fetch tests start PHP's built-in web server on 127.0.0.1.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT. See [LICENSE](LICENSE).
