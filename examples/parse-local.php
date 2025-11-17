<?php

require __DIR__ . '/../vendor/autoload.php';

use TomGould\RSSToJson\RSSToJson;

$parser = new RSSToJson();
$feed = $parser->parse(__DIR__ . '/../tests/sample-mrss.xml');
echo json_encode($feed, JSON_PRETTY_PRINT);

