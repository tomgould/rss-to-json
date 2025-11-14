<?php

require __DIR__ . '/../vendor/autoload.php';

use RSStoJSON\RSStoJSON;

$parser = new RSStoJSON();
$feed = $parser->parse('https://example.com/feed.xml');
echo json_encode($feed, JSON_PRETTY_PRINT);

