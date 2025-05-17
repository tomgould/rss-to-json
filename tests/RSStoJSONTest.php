<?php

use PHPUnit\Framework\TestCase;
use RSStoJSON\RSStoJSON;

class RSStoJSONTest extends TestCase
{
    public function testParsesValidFeed()
    {
        $parser = new RSStoJSON();
        $result = $parser->parse(__DIR__ . '/../examples/sample.xml');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('channel', $result);
        $this->assertArrayHasKey('item', $result['channel']);
    }

    public function testReturnsNullForInvalidFile()
    {
        $parser = new RSStoJSON();
        $result = $parser->parse('/path/to/nowhere.xml');

        $this->assertNull($result);
    }
}
