<?php

// Router for PHP's built-in web server, used by RemoteFetchTest.
// Run as: php -S 127.0.0.1:PORT tests/server/router.php

$uri = (string) $_SERVER['REQUEST_URI'];
$path = (string) parse_url($uri, PHP_URL_PATH);
parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
$fixtures = dirname(__DIR__) . '/fixtures';

$serve = static function (string $file, string $type) use ($fixtures): void {
    header('Content-Type: ' . $type);
    readfile($fixtures . '/' . $file);
};

if ($path === '/rss2') {
    $serve('rss2.xml', 'application/rss+xml; charset=utf-8');
} elseif ($path === '/atom') {
    $serve('atom.xml', 'application/atom+xml');
} elseif ($path === '/bare-ampersand') {
    $serve('bare-ampersand.xml', 'text/xml');
} elseif ($path === '/cdata') {
    $serve('cdata.xml', 'application/xml');
} elseif ($path === '/html') {
    header('Content-Type: text/html');
    echo '<html><body>Not a feed</body></html>';
} elseif ($path === '/missing') {
    http_response_code(404);
    header('Content-Type: application/xml');
    echo '<error>Not found</error>';
} elseif ($path === '/redirect') {
    http_response_code(isset($query['code']) ? (int) $query['code'] : 302);
    header('Location: ' . (string) ($query['to'] ?? '/rss2'));
} elseif (preg_match('#^/chain/(\d+)$#', $path, $m)) {
    // /chain/N redirects N times before serving the feed.
    $n = (int) $m[1];
    if ($n > 0) {
        http_response_code(302);
        header('Location: /chain/' . ($n - 1));
    } else {
        $serve('rss2.xml', 'application/rss+xml');
    }
} elseif ($path === '/large') {
    // An XML document of roughly ?bytes=N bytes. With ?length=1 the size is
    // announced in Content-Length; otherwise it is streamed without one.
    $bytes = (int) ($query['bytes'] ?? 1000);
    $doc = '<rss><channel><title>' . str_repeat('a', max(0, $bytes - 46)) . '</title></channel></rss>';
    header('Content-Type: application/xml');
    if (!empty($query['length'])) {
        header('Content-Length: ' . strlen($doc));
        echo $doc;
    } else {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        foreach (str_split($doc, 4096) as $chunk) {
            echo $chunk;
            flush();
        }
    }
} elseif ($path === '/slow') {
    sleep((int) ($query['seconds'] ?? 2));
    $serve('rss2.xml', 'application/rss+xml');
} else {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Unknown test route';
}
