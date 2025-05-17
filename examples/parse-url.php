<?php

use RSStoJSON\RSStoJSON;

require __DIR__ . '/../src/RSStoJSON.php';

$parser = new \RSStoJSON\RSStoJSON();
$feed = $parser->parse('https://example.com/feed.xml');
echo json_encode($feed, JSON_PRETTY_PRINT);
