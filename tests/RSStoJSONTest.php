<?php

use PHPUnit\Framework\TestCase;
use RSStoJSON\RSStoJSON;

class RSStoJSONTest extends TestCase
{
    public function testParsesValidMRSSFeed()
    {
        $parser = new RSStoJSON();
        $result = $parser->parse(__DIR__ . '/sample-mrss.xml');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('channel', $result);
        $this->assertArrayHasKey('item', $result['channel']);
        $this->assertIsArray($result['channel']['item']);
        $this->assertGreaterThan(0, count($result['channel']['item']));
    }

    public function testParsesVideoMetadata()
    {
        $parser = new RSStoJSON();
        $result = $parser->parse(__DIR__ . '/sample-mrss.xml');

        $firstItem = $result['channel']['item'][0];

        // Check basic RSS fields
        $this->assertArrayHasKey('title', $firstItem);
        $this->assertArrayHasKey('link', $firstItem);
        $this->assertArrayHasKey('description', $firstItem);

        // Check media:content exists (MRSS namespace)
        $this->assertArrayHasKey('media:content', $firstItem);
    }

    public function testParsesMediaThumbnails()
    {
        $parser = new RSStoJSON();
        $result = $parser->parse(__DIR__ . '/sample-mrss.xml');

        $firstItem = $result['channel']['item'][0];

        // Check media:thumbnail exists
        $this->assertArrayHasKey('media:thumbnail', $firstItem);

        // Check thumbnail has attributes
        if (isset($firstItem['media:thumbnail']['@attributes'])) {
            $this->assertArrayHasKey('url', $firstItem['media:thumbnail']['@attributes']);
        }
    }

    public function testReturnsNullForInvalidFile()
    {
        $parser = new RSStoJSON();
        $result = $parser->parse('/path/to/nowhere.xml');

        $this->assertNull($result);
    }

    public function testHandlesNamespaces()
    {
        $parser = new RSStoJSON();
        $result = $parser->parse(__DIR__ . '/sample-mrss.xml');

        $firstItem = $result['channel']['item'][0];

        // Should have media: namespaced elements
        $hasMediaNamespace = false;
        foreach (array_keys($firstItem) as $key) {
            if (strpos($key, 'media:') === 0) {
                $hasMediaNamespace = true;
                break;
            }
        }

        $this->assertTrue($hasMediaNamespace, 'Feed should contain media: namespaced elements');
    }
}

