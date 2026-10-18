<?php

namespace TomGould\RSSToJson\Tests;

use PHPUnit\Framework\TestCase;
use TomGould\RSSToJson\RSSToJson;

class XmlSafetyTest extends TestCase
{
    private const SECRET = 'TOP-SECRET-7f3a9c';

    /**
     * @var string[]
     */
    private $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
        libxml_set_external_entity_loader(null);
    }

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rsstojson');
        $this->assertIsString($path);
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function xxeFeed(): string
    {
        $secretPath = $this->tempFile(self::SECRET);

        return '<?xml version="1.0"?>' . "\n"
            . '<!DOCTYPE rss [<!ENTITY xxe SYSTEM "file://' . $secretPath . '">]>' . "\n"
            . '<rss version="2.0"><channel><title>&xxe;</title>'
            . '<item><title>a</title><description>&xxe;</description></item>'
            . '</channel></rss>';
    }

    /**
     * Record every external resource libxml asks PHP to load.
     *
     * @return \ArrayObject<int, string>
     */
    private function recordEntityLoads(): \ArrayObject
    {
        $loads = new \ArrayObject();
        libxml_set_external_entity_loader(function ($public, $system) use ($loads) {
            $loads[] = (string) $system;
            return null;
        });

        return $loads;
    }

    public function testExternalEntityIsNotExpandedFromString(): void
    {
        $result = (new RSSToJson())->parseString($this->xxeFeed());

        $this->assertIsArray($result);
        $this->assertStringNotContainsString(self::SECRET, (string) json_encode($result));
        // The reference is kept as literal text instead of being expanded.
        $this->assertSame('&xxe;', $result['channel']['title']);
    }

    public function testExternalEntityIsNotExpandedFromFile(): void
    {
        $result = (new RSSToJson())->parse($this->tempFile($this->xxeFeed()));

        $this->assertIsArray($result);
        $this->assertStringNotContainsString(self::SECRET, (string) json_encode($result));
    }

    public function testLoaderDoesNotExpandExternalEntitiesEvenUnsanitised(): void
    {
        // Call the loader directly, skipping the ampersand escaping, to show
        // the libxml flags hold on their own.
        $parser = new RSSToJson();
        $method = new \ReflectionMethod(RSSToJson::class, 'loadXml');
        $method->setAccessible(true);
        $loads = $this->recordEntityLoads();

        $element = $method->invoke($parser, $this->xxeFeed());

        $this->assertInstanceOf(\SimpleXMLElement::class, $element);
        $this->assertStringNotContainsString(self::SECRET, (string) $element->asXML());
        $this->assertStringNotContainsString(self::SECRET, (string) $element->channel->title);
        $this->assertCount(0, $loads, 'No external entity should be loaded');
    }

    public function testExternalDtdAndParameterEntitiesAreNotLoaded(): void
    {
        $dtdPath = $this->tempFile('<!ENTITY leaked "' . self::SECRET . '">');
        $xml = '<?xml version="1.0"?>' . "\n"
            . '<!DOCTYPE rss SYSTEM "http://127.0.0.1:9/feed.dtd" [<!ENTITY % remote SYSTEM "file://' . $dtdPath . '"> %remote;]>' . "\n"
            . '<rss version="2.0"><channel><title>Safe</title></channel></rss>';
        $loads = $this->recordEntityLoads();

        $result = (new RSSToJson())->parseString($xml);

        $this->assertIsArray($result);
        $this->assertSame('Safe', $result['channel']['title']);
        $this->assertCount(0, $loads, 'Neither the external DTD nor the parameter entity should be loaded');
    }

    public function testFileAndStringShareTheSameSanitising(): void
    {
        $path = __DIR__ . '/fixtures/bare-ampersand.xml';
        $parser = new RSSToJson();

        $fromFile = $parser->parse($path);
        $fromString = $parser->parseString((string) file_get_contents($path));

        $this->assertIsArray($fromFile, 'Bare ampersands in a local file should be repaired, not fail');
        $this->assertSame($fromFile, $fromString);
        $this->assertSame($fromFile, $parser->parseFile($path));

        $channel = $fromFile['channel'];
        $this->assertSame('Tom & Jerry', $channel['title']);
        $this->assertSame('https://example.com/?a=1&b=2', $channel['link']);
        $this->assertSame('Uses an HTML entity&nbsp;that XML does not define', $channel['description']);
        $this->assertSame('Salt & pepper', $channel['item'][0]['title']);
    }

    public function testAmpersandsInsideCdataAreLeftAlone(): void
    {
        $result = (new RSSToJson())->parseString(
            '<rss><channel><title><![CDATA[A & B &amp; C]]></title><description>x & y</description></channel></rss>'
        );

        $this->assertIsArray($result);
        $this->assertSame('A & B &amp; C', $result['channel']['title']);
        $this->assertSame('x & y', $result['channel']['description']);
    }

    public function testByteOrderMarkAndWhitespaceAreStripped(): void
    {
        $result = (new RSSToJson())->parseString(
            "\xEF\xBB\xBF\n  <?xml version=\"1.0\" encoding=\"UTF-8\"?><rss><channel><title>BOM</title></channel></rss>\n"
        );

        $this->assertIsArray($result);
        $this->assertSame('BOM', $result['channel']['title']);
    }

    public function testUtf16DocumentIsParsed(): void
    {
        $utf8 = '<?xml version="1.0" encoding="UTF-16"?><rss><channel><title>Caf' . "\u{00E9}" . '</title></channel></rss>';
        $utf16 = "\xFF\xFE" . iconv('UTF-8', 'UTF-16LE', $utf8);

        $result = (new RSSToJson())->parseString($utf16);

        $this->assertIsArray($result);
        $this->assertSame("Caf\u{00E9}", $result['channel']['title']);
    }

    public function testInvalidXmlReturnsNullWithAReason(): void
    {
        $parser = new RSSToJson();

        $this->assertNull($parser->parseString('<rss><channel><title>Unclosed</channel>'));
        $this->assertStringStartsWith('XML parse error:', (string) $parser->getLastError());

        $this->assertNull($parser->parseString('   '));
        $this->assertStringStartsWith('XML parse error:', (string) $parser->getLastError());

        $this->assertIsArray($parser->parseString('<rss><channel/></rss>'));
        $this->assertNull($parser->getLastError(), 'A successful parse clears the previous error');
    }

    public function testMissingFileReturnsNullWithAReason(): void
    {
        $parser = new RSSToJson();

        $this->assertNull($parser->parseFile('/path/to/nowhere.xml'));
        $this->assertStringContainsString('Could not read file', (string) $parser->getLastError());
    }

    public function testCallerLibxmlErrorSettingIsRestored(): void
    {
        libxml_use_internal_errors(false);

        (new RSSToJson())->parseString('<rss><broken>');

        $this->assertFalse(libxml_use_internal_errors(false), 'parse should not leave libxml internal errors switched on');
    }
}
