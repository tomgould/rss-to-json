# RSStoJSON

RSStoJSON is a PHP library that parses RSS, Atom, or generic XML feeds (including MRSS video feeds) into structured, JSON-compatible arrays. It works with remote URLs or local files and is designed to be framework-agnostic.

## 🚀 Features

- Supports RSS, Atom, MRSS (Media RSS), and XML feeds
- Works with local file paths or remote URLs
- Handles namespaced elements and attributes (e.g., `media:content`, `media:thumbnail`)
- JSON-encoding ready output
- Includes CLI tool for terminal use
- PHP 7.4+ compatible (works with Drupal 8, 9, 10, 11, and other modern PHP frameworks)
- Composer-ready for easy installation

## 📦 Installation

Via Composer:
```bash
composer require tomgould/rsstojson
```

## 🛠 Usage

### Programmatic
```php
<?php

require 'vendor/autoload.php';

use RSStoJSON\RSStoJSON;

$parser = new RSStoJSON();
$feed = $parser->parse('https://example.com/feed.xml');
echo json_encode($feed, JSON_PRETTY_PRINT);
```

### Parse Local Files
```php
<?php

require 'vendor/autoload.php';

use RSStoJSON\RSStoJSON;

$parser = new RSStoJSON();
$feed = $parser->parse('/path/to/local/feed.xml');
echo json_encode($feed, JSON_PRETTY_PRINT);
```

### CLI

After installing via composer, you can use the CLI tool:
```bash
vendor/bin/rsstojson https://example.com/feed.xml
```

Or if installed globally:
```bash
rsstojson https://example.com/feed.xml
```

## 📁 Examples

See the `/examples` directory for sample code:

- `examples/parse-url.php` - Parse a remote feed
- `examples/parse-local.php` - Parse a local MRSS video feed

## 🧪 Testing

Run the test suite:
```bash
composer test
```

Or using PHPUnit directly:
```bash
vendor/bin/phpunit tests
```

## 🎥 MRSS Support

This library fully supports Media RSS (MRSS) feeds, commonly used for video content. It properly handles namespaced elements like:

- `media:content` - Video URLs and metadata
- `media:thumbnail` - Video thumbnail images
- `media:category` - Content categories
- `media:keywords` - Content tags

See `tests/sample-mrss.xml` for an example MRSS feed structure.

## 🔧 Requirements

- PHP 7.4 or higher
- ext-libxml
- ext-curl

## 🪪 License

MIT — free for commercial and personal use.

## 🤝 Contributing

Issues and pull requests are welcome on the GitHub repository.

