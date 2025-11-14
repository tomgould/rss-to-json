<?php

require __DIR__ . '/../vendor/autoload.php';

use RSStoJSON\RSStoJSON;

$parser = new RSStoJSON();
$feed = $parser->parse(__DIR__ . '/../tests/sample-mrss.xml');
echo json_encode($feed, JSON_PRETTY_PRINT);

