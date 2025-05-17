# RSStoJSON

RSStoJSON is a PHP library that parses RSS, Atom, or generic XML feeds into structured, JSON-compatible arrays. It works with remote URLs or local files and is designed to be framework-agnostic.

## 🚀 Features

- Supports RSS, Atom, and XML feeds
- Works with local file paths or remote URLs
- Handles namespaced elements and attributes
- JSON-encoding ready output
- Includes CLI tool for terminal use
- PHP 7.4+ compatible

## 📦 Installation

Via Composer:

```bash
composer require tomgould/rsstojson
```

## 🛠 Usage

### Programmatic

```php
require 'vendor/autoload.php';

$parser = new RSStoJSON\RSStoJSON();
$feed = $parser->parse('https://example.com/feed.xml');
echo json_encode($feed, JSON_PRETTY_PRINT);
```

### CLI

```bash
php bin/rsstojson https://example.com/feed.xml
```

## 📁 Examples

See `/examples` for how to parse a local or remote feed.

## 🪪 License

MIT — free for commercial and personal use.
