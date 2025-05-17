<?php

use RSStoJSON\RSStoJSON;

require __DIR__ . '/../src/RSStoJSON.php';

$parser = new \RSStoJSON\RSStoJSON();
$feed = $parser->parse(__DIR__ . '/../tests/sample.xml');
echo json_encode($feed, JSON_PRETTY_PRINT);
