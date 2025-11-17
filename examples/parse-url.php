<?php

require __DIR__ . '/../vendor/autoload.php';

use TomGould\RSSToJson\RSSToJson;

$parser = new RSSToJson();
$feed = $parser->parse('https://example.com/feed.xml');
echo json_encode($feed, JSON_PRETTY_PRINT);

