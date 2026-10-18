<?php

namespace TomGould\RSSToJson\Tests;

use PHPUnit\Framework\TestCase;
use TomGould\RSSToJson\RSSToJson;

class FeedFormatsTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function parseFixture(string $name): array
    {
        $result = (new RSSToJson())->parse(__DIR__ . '/fixtures/' . $name);
        $this->assertIsArray($result, "Fixture $name should parse");
        $this->assertArrayHasKey('channel', $result);

        return $result['channel'];
    }

    /**
     * Collect every array key in a parsed structure, at any depth.
     *
     * @param mixed $data
     * @return string[]
     */
    private function allKeys($data): array
    {
        if (!is_array($data)) {
            return [];
        }
        $keys = [];
        foreach ($data as $key => $value) {
            $keys[] = (string) $key;
            $keys = array_merge($keys, $this->allKeys($value));
        }
        return $keys;
    }

    public function testRss2ChannelAndItems(): void
    {
        $channel = $this->parseFixture('rss2.xml');

        $this->assertSame('Example News', $channel['title']);
        $this->assertSame('https://example.com/', $channel['link']);
        $this->assertSame('en-gb', $channel['language']);
        $this->assertSame('https://example.com/logo.png', $channel['image']['url']);
        $this->assertSame(
            ['href' => 'https://example.com/feed.xml', 'rel' => 'self', 'type' => 'application/rss+xml'],
            $channel['atom:link']['@attributes']
        );

        $this->assertCount(2, $channel['item']);
        $first = $channel['item'][0];
        $this->assertSame('First story', $first['title']);
        $this->assertSame(['World', 'Politics'], $first['category']);
        $this->assertSame(['@attributes' => ['isPermaLink' => 'false'], '@value' => 'story-1'], $first['guid']);
        $this->assertSame(
            ['url' => 'https://example.com/first.mp3', 'length' => '12345', 'type' => 'audio/mpeg'],
            $first['enclosure']['@attributes']
        );
    }

    public function testRss2DecodesEntitiesAndCharacterReferences(): void
    {
        $second = $this->parseFixture('rss2.xml')['item'][1];

        $this->assertSame('Fish & chips', $second['title']);
        $this->assertSame("Second story <b>with markup</b> and \u{00A9} \u{2013} references.", $second['description']);
    }

    public function testAtomKeysAreNotDuplicated(): void
    {
        $channel = $this->parseFixture('atom.xml');

        foreach ($this->allKeys($channel) as $key) {
            $this->assertStringStartsNotWith(':', $key, 'Atom keys must not carry an empty namespace prefix');
        }
        $this->assertSame(
            ['title', 'subtitle', 'link', 'id', 'updated', 'author', 'entry', 'item'],
            array_keys($channel)
        );
    }

    public function testAtomFeedAndEntries(): void
    {
        $channel = $this->parseFixture('atom.xml');

        $this->assertSame('Example Blog', $channel['title']);
        $this->assertSame(['name' => 'Jane Doe', 'email' => 'jane@example.org'], $channel['author']);
        $this->assertSame('self', $channel['link'][0]['@attributes']['rel']);
        $this->assertSame('https://example.org/', $channel['link'][1]['@attributes']['href']);

        // Entries are exposed under 'item' like RSS items, and still under 'entry'.
        $this->assertCount(2, $channel['item']);
        $this->assertSame($channel['entry'], $channel['item']);

        $first = $channel['item'][0];
        $this->assertSame('Atom-Powered Robots Run Amok', $first['title']);
        $this->assertSame('https://example.org/2026/10/17/robots', $first['link']['@attributes']['href']);
        $this->assertSame('robots', $first['category']['@attributes']['term']);

        $second = $channel['item'][1];
        $this->assertSame(['@attributes' => ['type' => 'html'], '@value' => 'Second <em>entry</em>'], $second['title']);
        $this->assertSame(['@attributes' => ['type' => 'html'], '@value' => '<p>Hello & welcome</p>'], $second['content']);
    }

    public function testDublinCoreContentAndMediaModules(): void
    {
        $channel = $this->parseFixture('modules.xml');
        $this->assertSame('en', $channel['dc:language']);

        $item = $channel['item'][0];
        $this->assertSame(['Alice Smith', 'Bob Jones'], $item['dc:creator']);
        $this->assertSame('2026-10-17T12:00:00Z', $item['dc:date']);
        $this->assertSame(
            '<p>Show notes with a <a href="https://example.net/?a=1&b=2">link</a> & more.</p>',
            $item['content:encoded']
        );

        $contents = $item['media:group']['media:content'];
        $this->assertCount(2, $contents);
        $this->assertSame('https://example.net/videos/1-hd.mp4', $contents[0]['@attributes']['url']);
        $this->assertSame('true', $contents[0]['@attributes']['isDefault']);
        $this->assertSame('640', $contents[1]['@attributes']['width']);

        $this->assertSame('https://example.net/thumbs/1.jpg', $item['media:thumbnail']['@attributes']['url']);
        $this->assertSame(
            ['@attributes' => ['role' => 'author', 'scheme' => 'urn:ebu'], '@value' => 'Alice Smith'],
            $item['media:credit']
        );
    }

    public function testCdataIsReturnedAsText(): void
    {
        $channel = $this->parseFixture('cdata.xml');

        $this->assertSame("Tom & Jerry's <Feed>", $channel['title']);
        // Entities inside CDATA are not decoded.
        $this->assertSame('Contains &amp; an escaped entity, kept literally', $channel['description']);
        $this->assertSame('Fish & Chips', $channel['item'][0]['title']);
        $this->assertSame('Mixed <b>bold</b> text', $channel['item'][0]['description']);
    }

    public function testRss1RdfItemsAreRead(): void
    {
        $channel = $this->parseFixture('rdf.xml');

        $this->assertSame('RDF Site Summary', $channel['title']);
        $this->assertSame('An RSS 1.0 feed', $channel['description']);
        foreach ($this->allKeys($channel) as $key) {
            $this->assertStringStartsNotWith(':', $key);
        }

        $this->assertCount(2, $channel['item']);
        $this->assertSame(
            ['dc:creator' => 'Carol', 'title' => 'Item one', 'link' => 'https://example.com/one'],
            $channel['item'][0]
        );
        $this->assertSame('2026-10-17', $channel['item'][1]['dc:date']);
    }

    public function testSingleItemIsStillAList(): void
    {
        $channel = $this->parseFixture('modules.xml');

        $this->assertCount(1, $channel['item']);
        $this->assertArrayHasKey(0, $channel['item']);
    }
}
