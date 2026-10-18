<?php

namespace TomGould\RSSToJson\Tests;

use PHPUnit\Framework\TestCase;
use TomGould\RSSToJson\RSSToJson;

/**
 * Exercises URL fetching against PHP's built-in web server on 127.0.0.1.
 */
class RemoteFetchTest extends TestCase
{
    /**
     * @var resource|null
     */
    private static $server;

    /**
     * @var string
     */
    private static $baseUrl = '';

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            self::markTestSkipped("Cannot open a local socket: $errstr");
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($name, strrpos($name, ':') + 1);

        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/server/router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            self::markTestSkipped('Cannot start the PHP built-in web server');
        }
        self::$server = $process;
        self::$baseUrl = "http://127.0.0.1:$port";

        for ($i = 0; $i < 100; $i++) {
            $connection = @fsockopen('127.0.0.1', $port);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(50000);
        }
        self::markTestSkipped('The PHP built-in web server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        self::$server = null;
    }

    private function url(string $path): string
    {
        return self::$baseUrl . $path;
    }

    public function testFetchesAndParsesAFeed(): void
    {
        $parser = new RSSToJson();
        $expected = $parser->parseFile(__DIR__ . '/fixtures/rss2.xml');

        $this->assertIsArray($expected);
        $this->assertSame($expected, $parser->parseUrl($this->url('/rss2')));
        $this->assertSame($expected, $parser->parse($this->url('/rss2')), 'parse() should dispatch URLs to parseUrl()');
        $this->assertNull($parser->getLastError());
    }

    public function testFetchesAtom(): void
    {
        $result = (new RSSToJson())->parseUrl($this->url('/atom'));

        $this->assertIsArray($result);
        $this->assertSame('Example Blog', $result['channel']['title']);
        $this->assertCount(2, $result['channel']['item']);
    }

    /**
     * @dataProvider localFixtureProvider
     */
    public function testRemoteInputIsSanitisedLikeLocalInput(string $route, string $fixture): void
    {
        $parser = new RSSToJson();
        $fromFile = $parser->parseFile(__DIR__ . '/fixtures/' . $fixture);

        $this->assertIsArray($fromFile);
        $this->assertSame($fromFile, $parser->parseUrl($this->url($route)));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public function localFixtureProvider(): array
    {
        return [
            'bare ampersands' => ['/bare-ampersand', 'bare-ampersand.xml'],
            'CDATA' => ['/cdata', 'cdata.xml'],
        ];
    }

    public function testFollowsRelativeAndAbsoluteRedirects(): void
    {
        $parser = new RSSToJson();

        $relative = $parser->parseUrl($this->url('/redirect?to=' . rawurlencode('/rss2')));
        $this->assertIsArray($relative);
        $this->assertSame('Example News', $relative['channel']['title']);

        $absolute = $parser->parseUrl($this->url('/redirect?code=301&to=' . rawurlencode($this->url('/atom'))));
        $this->assertIsArray($absolute);
        $this->assertSame('Example Blog', $absolute['channel']['title']);
    }

    public function testStopsAfterMaxRedirects(): void
    {
        $parser = new RSSToJson();
        $this->assertIsArray($parser->parseUrl($this->url('/chain/5')), 'Five redirects are allowed by default');

        $this->assertNull($parser->parseUrl($this->url('/chain/6')));
        $this->assertStringContainsString('Too many redirects', (string) $parser->getLastError());

        $noRedirects = new RSSToJson(['max_redirects' => 0]);
        $this->assertIsArray($noRedirects->parseUrl($this->url('/chain/0')));
        $this->assertNull($noRedirects->parseUrl($this->url('/chain/1')));
    }

    /**
     * @dataProvider nonHttpUrlProvider
     */
    public function testRefusesRedirectsToOtherProtocols(string $target): void
    {
        $parser = new RSSToJson();

        $this->assertNull($parser->parseUrl($this->url('/redirect?to=' . rawurlencode($target))));
        $this->assertStringContainsString('only http and https are allowed', (string) $parser->getLastError());
    }

    /**
     * @dataProvider nonHttpUrlProvider
     */
    public function testRefusesOtherProtocols(string $url): void
    {
        $parser = new RSSToJson();

        $this->assertNull($parser->parseUrl($url));
        $this->assertStringContainsString('only http and https are allowed', (string) $parser->getLastError());
    }

    /**
     * @return array<string, array{string}>
     */
    public function nonHttpUrlProvider(): array
    {
        return [
            'file' => ['file:///etc/passwd'],
            'ftp' => ['ftp://127.0.0.1/feed.xml'],
            'gopher' => ['gopher://127.0.0.1:70/_feed'],
            'dict' => ['dict://127.0.0.1:11211/stat'],
        ];
    }

    public function testEnforcesMaxSizeWithContentLength(): void
    {
        $parser = new RSSToJson(['max_size' => 1000]);

        $this->assertIsArray($parser->parseUrl($this->url('/large?length=1&bytes=900')));
        $this->assertNull($parser->parseUrl($this->url('/large?length=1&bytes=5000')));
        $this->assertStringContainsString('larger than max_size', (string) $parser->getLastError());
    }

    public function testEnforcesMaxSizeWhileStreaming(): void
    {
        $parser = new RSSToJson(['max_size' => 10000]);

        $this->assertIsArray($parser->parseUrl($this->url('/large?bytes=9000')));
        $this->assertNull($parser->parseUrl($this->url('/large?bytes=50000')));
        $this->assertStringContainsString('larger than max_size', (string) $parser->getLastError());
    }

    public function testTimesOut(): void
    {
        $parser = new RSSToJson(['timeout' => 1]);
        $start = microtime(true);

        $this->assertNull($parser->parseUrl($this->url('/slow?seconds=3')));
        $this->assertLessThan(2.5, microtime(true) - $start);
        $this->assertStringContainsString('failed', (string) $parser->getLastError());
    }

    public function testRejectsNonXmlResponses(): void
    {
        $parser = new RSSToJson();

        $this->assertNull($parser->parseUrl($this->url('/html')));
        $this->assertStringContainsString('Not an XML response', (string) $parser->getLastError());
    }

    public function testRejectsHttpErrors(): void
    {
        $parser = new RSSToJson();

        $this->assertNull($parser->parseUrl($this->url('/missing')));
        $this->assertStringContainsString('HTTP 404', (string) $parser->getLastError());
    }

    public function testBlockPrivateIpsRefusesLoopback(): void
    {
        $parser = new RSSToJson(['block_private_ips' => true]);

        $this->assertNull($parser->parseUrl($this->url('/rss2')));
        $this->assertStringContainsString('(127.0.0.1): private or reserved address', (string) $parser->getLastError());
    }

    public function testBlockPrivateIpsChecksResolvedAddresses(): void
    {
        $parser = new RSSToJson(['block_private_ips' => true]);
        $url = str_replace('127.0.0.1', 'localhost', $this->url('/rss2'));

        $this->assertNull($parser->parseUrl($url));
        $this->assertStringContainsString('Refusing to connect to localhost', (string) $parser->getLastError());
    }

    public function testBlockPrivateIpsIsOffByDefault(): void
    {
        $this->assertIsArray((new RSSToJson())->parseUrl($this->url('/rss2')));
    }

    public function testBlockPrivateIpsFetchesAllowedAddresses(): void
    {
        $parser = $this->parserAllowingTestServer();

        $result = $parser->parseUrl($this->url('/rss2'));
        $this->assertIsArray($result, (string) $parser->getLastError());
        $this->assertSame('Example News', $result['channel']['title']);

        $redirected = $parser->parseUrl($this->url('/redirect?to=' . rawurlencode('/atom')));
        $this->assertIsArray($redirected, (string) $parser->getLastError());
    }

    /**
     * @dataProvider privateRedirectProvider
     */
    public function testBlockPrivateIpsChecksEveryRedirect(string $target, string $address): void
    {
        $parser = $this->parserAllowingTestServer();

        $this->assertNull($parser->parseUrl($this->url('/redirect?to=' . rawurlencode($target))));
        $this->assertStringContainsString("($address): private or reserved address", (string) $parser->getLastError());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public function privateRedirectProvider(): array
    {
        return [
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/', '169.254.169.254'],
            'private network' => ['http://10.0.0.1/feed.xml', '10.0.0.1'],
            'other loopback address' => ['http://127.0.0.2/feed.xml', '127.0.0.2'],
            'IPv6 loopback' => ['http://[::1]/feed.xml', '::1'],
            'IPv4-mapped IPv6' => ['http://[::ffff:169.254.169.254]/', '::ffff:169.254.169.254'],
        ];
    }

    public function testBlockPrivateIpsRefusesAmbiguousUrls(): void
    {
        $parser = $this->parserAllowingTestServer();
        $withCredentials = str_replace('http://', 'http://user:pass@', $this->url('/rss2'));

        $this->assertNull($parser->parseUrl($withCredentials));
        $this->assertStringContainsString('credentials, whitespace or backslashes', (string) $parser->getLastError());

        $this->assertNull($parser->parseUrl($this->url('/rss2') . '\\@169.254.169.254/'));
        $this->assertStringContainsString('credentials, whitespace or backslashes', (string) $parser->getLastError());
    }

    /**
     * @dataProvider nonAsciiHostProvider
     */
    public function testBlockPrivateIpsRefusesHostsCurlWouldDecode(string $host): void
    {
        $parser = $this->parserAllowingTestServer();
        $port = (string) parse_url(self::$baseUrl, PHP_URL_PORT);

        $this->assertNull($parser->parseUrl("http://$host:$port/rss2"));
        $this->assertStringContainsString('not plain ASCII', (string) $parser->getLastError());
    }

    /**
     * @return array<string, array{string}>
     */
    public function nonAsciiHostProvider(): array
    {
        return [
            'percent-encoded' => ['local%68ost'],
            'percent-encoded dot' => ['x%2eexample.com'],
            'Unicode' => ["local\u{24D7}ost"],
        ];
    }

    public function testBlockPrivateIpsChecksTheConnectedAddress(): void
    {
        // Allows the address when the URL is checked, then refuses it when
        // cURL connects, as if DNS had changed in between. PHP 8.4+ refuses
        // before sending the request; older versions discard the response.
        $parser = new class (['block_private_ips' => true]) extends RSSToJson {
            /** @var int */
            private $calls = 0;

            protected function isAddressAllowed(string $ip): bool
            {
                return $this->calls++ === 0;
            }
        };

        $this->assertNull($parser->parseUrl($this->url('/rss2')));
        $this->assertStringContainsString('(127.0.0.1): private or reserved address', (string) $parser->getLastError());
    }

    /**
     * A parser with block_private_ips on that still lets the tests reach the
     * local server at 127.0.0.1, and nothing else on loopback.
     */
    private function parserAllowingTestServer(): RSSToJson
    {
        return new class (['block_private_ips' => true]) extends RSSToJson {
            protected function isAddressAllowed(string $ip): bool
            {
                return $ip === '127.0.0.1' || parent::isAddressAllowed($ip);
            }
        };
    }

    /**
     * @dataProvider invalidOptionsProvider
     * @param array<string, mixed> $options
     */
    public function testRejectsInvalidOptions(array $options): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RSSToJson($options);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public function invalidOptionsProvider(): array
    {
        return [
            'unknown option' => [['timeot' => 5]],
            'zero timeout' => [['timeout' => 0]],
            'string timeout' => [['timeout' => '10']],
            'negative connect_timeout' => [['connect_timeout' => -1]],
            'negative max_redirects' => [['max_redirects' => -1]],
            'zero max_size' => [['max_size' => 0]],
            'float max_size' => [['max_size' => 1.5]],
            'non-bool block_private_ips' => [['block_private_ips' => 1]],
            'non-string user_agent' => [['user_agent' => false]],
        ];
    }
}
