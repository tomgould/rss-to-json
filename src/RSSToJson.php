<?php

namespace TomGould\RSSToJson;

/**
 * Parses RSS 0.9x/1.0/2.0, Atom 1.0 and other XML feeds into plain arrays
 * that can be passed straight to json_encode().
 *
 * Every input, whether fetched from a URL, read from a file or given as a
 * string, goes through the same steps:
 *
 * - A UTF-8 byte order mark and surrounding whitespace are removed.
 * - Bare '&' characters outside CDATA sections are escaped to '&amp;',
 *   unless they already start one of XML's five predefined entities or a
 *   numeric character reference. Other entity references, such as '&nbsp;'
 *   or a DTD-declared '&xxe;', are kept as literal text, never expanded.
 * - The XML is loaded with network access disabled and without entity
 *   substitution (see loadXml()).
 *
 * UTF-16 input skips the first two steps, which assume an ASCII-compatible
 * encoding, so entities declared in its own DTD are expanded. External
 * entities are still never loaded.
 *
 * The parsed result is wrapped as ['channel' => [...]]. Elements become keys,
 * repeated elements become lists, attributes go under '@attributes', text
 * beside attributes or children goes under '@value', and prefixed elements
 * keep their prefix (e.g. 'media:content'). Items (RSS <item>, Atom <entry>)
 * are always collected as a list under channel['item'].
 */
class RSSToJson
{
    /**
     * Defaults for the constructor options.
     */
    private const DEFAULT_OPTIONS = [
        'timeout' => 10,
        'connect_timeout' => 5,
        'max_redirects' => 5,
        'max_size' => 20971520,
        'block_private_ips' => false,
        'user_agent' => 'RSStoJSON/1.0 (+http://dealmobile.co.uk)',
    ];

    /**
     * @var array{timeout: int|float, connect_timeout: int|float, max_redirects: int, max_size: int, block_private_ips: bool, user_agent: string}
     */
    private $options;

    /**
     * @var string|null
     */
    private $lastError;

    /**
     * All options are optional, and only affect fetching from a URL.
     *
     * - timeout (int|float, default 10): seconds allowed for the whole fetch,
     *   redirects included.
     * - connect_timeout (int|float, default 5): seconds allowed to connect.
     * - max_redirects (int, default 5): redirects to follow; 0 follows none.
     * - max_size (int, default 20971520, i.e. 20 MiB): largest response body
     *   accepted, in bytes.
     * - block_private_ips (bool, default false): refuse to connect to private,
     *   loopback, link-local, reserved and cloud metadata addresses, checked
     *   for the URL and every redirect before connecting. Turn this on when
     *   the URL comes from someone you don't trust. See checkUrl(). DNS
     *   lookups for this check cannot be interrupted, so a slow resolver can
     *   push a fetch past the timeout.
     * - user_agent (string): the User-Agent header to send.
     *
     * @param array<string, mixed> $options
     * @throws \InvalidArgumentException For an unknown option or an invalid value
     */
    public function __construct(array $options = [])
    {
        foreach (array_keys($options) as $name) {
            if (!array_key_exists($name, self::DEFAULT_OPTIONS)) {
                throw new \InvalidArgumentException(sprintf('Unknown option "%s"', $name));
            }
        }
        $options += self::DEFAULT_OPTIONS;

        foreach (['timeout', 'connect_timeout'] as $name) {
            if (!(is_int($options[$name]) || is_float($options[$name])) || $options[$name] <= 0) {
                throw new \InvalidArgumentException(sprintf('Option "%s" must be a positive number of seconds', $name));
            }
        }
        if (!is_int($options['max_redirects']) || $options['max_redirects'] < 0) {
            throw new \InvalidArgumentException('Option "max_redirects" must be an integer of 0 or more');
        }
        if (!is_int($options['max_size']) || $options['max_size'] < 1) {
            throw new \InvalidArgumentException('Option "max_size" must be a positive integer (bytes)');
        }
        if (!is_bool($options['block_private_ips'])) {
            throw new \InvalidArgumentException('Option "block_private_ips" must be a boolean');
        }
        if (!is_string($options['user_agent'])) {
            throw new \InvalidArgumentException('Option "user_agent" must be a string');
        }

        $this->options = [
            'timeout' => $options['timeout'],
            'connect_timeout' => $options['connect_timeout'],
            'max_redirects' => $options['max_redirects'],
            'max_size' => $options['max_size'],
            'block_private_ips' => $options['block_private_ips'],
            'user_agent' => $options['user_agent'],
        ];
    }

    /**
     * Load and parse a feed from a URL or a local file.
     *
     * A string that passes FILTER_VALIDATE_URL is fetched as a URL; anything
     * else is treated as a local path and read with PHP's file functions,
     * which also accept stream wrappers. Never pass untrusted input here: use
     * parseUrl() for user-supplied URLs, which refuses anything that is not
     * http or https, or parseString() for XML you already hold.
     *
     * @param string $source URL or file path
     * @return array<string, mixed>|null The parsed feed, or null on failure (see getLastError())
     */
    public function parse(string $source): ?array
    {
        if ($this->isUrl($source)) {
            return $this->parseUrl($source);
        }

        return $this->parseFile($source);
    }

    /**
     * Fetch a feed over http or https and parse it.
     *
     * Other schemes are refused, including on redirects. Timeouts, the
     * redirect limit and the response size cap come from the constructor
     * options.
     *
     * @param string $url
     * @return array<string, mixed>|null The parsed feed, or null on failure (see getLastError())
     */
    public function parseUrl(string $url): ?array
    {
        $this->lastError = null;

        $xmlContent = $this->fetchRemoteFeed($url);
        if ($xmlContent === null) {
            return null;
        }

        return $this->parseXml($xmlContent);
    }

    /**
     * Read a feed from a local file and parse it.
     *
     * The path is passed to file_get_contents(), so stream wrappers work too.
     *
     * @param string $path
     * @return array<string, mixed>|null The parsed feed, or null on failure (see getLastError())
     */
    public function parseFile(string $path): ?array
    {
        $this->lastError = null;

        $xmlContent = $path === '' ? false : @file_get_contents($path);
        if ($xmlContent === false) {
            $this->lastError = "Could not read file: $path";
            return null;
        }

        return $this->parseXml($xmlContent);
    }

    /**
     * Parse a feed held in a string.
     *
     * @param string $xml
     * @return array<string, mixed>|null The parsed feed, or null on failure (see getLastError())
     */
    public function parseString(string $xml): ?array
    {
        $this->lastError = null;

        return $this->parseXml($xml);
    }

    /**
     * Explain why the most recent parse call returned null.
     *
     * @return string|null The reason, or null if the last call succeeded
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Sanitise, load and convert an XML document.
     *
     * @param string $xmlContent
     * @return array<string, mixed>|null
     */
    private function parseXml(string $xmlContent): ?array
    {
        $xml = $this->loadXml($this->sanitize($xmlContent));
        if ($xml === null) {
            return null;
        }

        $namespaces = $xml->getNamespaces(true);
        $channel = $xml->channel ?? $xml;
        $parsedChannel = $this->parseElement($channel, $namespaces);
        if (!is_array($parsedChannel)) {
            // A channel element holding nothing but text.
            $parsedChannel = ['@value' => $parsedChannel];
        }

        // RSS 0.9x/2.0 keep items inside <channel>, RSS 1.0 (RDF) puts them
        // beside it under the root, and Atom calls them <entry>.
        $items = $channel->item ?? $xml->item ?? $xml->entry ?? [];
        $parsedItems = [];
        foreach ($items as $item) {
            $parsedItems[] = $this->parseElement($item, $namespaces);
        }

        // Embed parsed items directly in the channel array under 'item'
        $parsedChannel['item'] = $parsedItems;

        return [
            'channel' => $parsedChannel
        ];
    }

    /**
     * Clean up common feed problems before handing the text to libxml.
     *
     * @param string $xml
     * @return string
     */
    private function sanitize(string $xml): string
    {
        // UTF-16 (and UTF-32 LE) documents start with a BOM that libxml uses
        // to pick the encoding. The byte-level fixes below assume an
        // ASCII-compatible encoding, so leave these untouched.
        if (strncmp($xml, "\xFF\xFE", 2) === 0 || strncmp($xml, "\xFE\xFF", 2) === 0) {
            return $xml;
        }

        // Remove a UTF-8 byte order mark and surrounding whitespace: the XML
        // declaration has to be the very first thing in the document.
        if (strncmp($xml, "\xEF\xBB\xBF", 3) === 0) {
            $xml = substr($xml, 3);
        }
        $xml = trim($xml);

        // Escape bare ampersands outside CDATA sections. Text inside CDATA is
        // already literal, so escaping there would change the content.
        $result = '';
        $offset = 0;
        $length = strlen($xml);
        while ($offset < $length) {
            $start = strpos($xml, '<![CDATA[', $offset);
            if ($start === false) {
                $result .= $this->escapeAmpersands(substr($xml, $offset));
                break;
            }
            $end = strpos($xml, ']]>', $start + 9);
            if ($end === false) {
                // Unterminated CDATA: leave the rest for libxml to report.
                $result .= $this->escapeAmpersands(substr($xml, $offset, $start - $offset)) . substr($xml, $start);
                break;
            }
            $result .= $this->escapeAmpersands(substr($xml, $offset, $start - $offset))
                . substr($xml, $start, $end + 3 - $start);
            $offset = $end + 3;
        }

        return $result;
    }

    /**
     * Replace '&' with '&amp;' unless it starts a predefined XML entity or a
     * numeric character reference (e.g. "Tom & Jerry" or "&nbsp;").
     *
     * @param string $text
     * @return string
     */
    private function escapeAmpersands(string $text): string
    {
        $escaped = preg_replace('/&(?!amp;|lt;|gt;|quot;|apos;|#[0-9]+;|#x[0-9a-fA-F]+;)/', '&amp;', $text);

        return $escaped ?? $text;
    }

    /**
     * Load an XML string into SimpleXML.
     *
     * Network access is disabled (LIBXML_NONET) and entities are never
     * substituted (no LIBXML_NOENT), so external entities and DTDs are not
     * fetched. PHP 8 and libxml2 2.9+ already refuse to load external
     * entities by default; on PHP 7.4 the entity loader is also switched off
     * for the duration of the call.
     *
     * @param string $xml
     * @return \SimpleXMLElement|null
     */
    private function loadXml(string $xml): ?\SimpleXMLElement
    {
        if ($xml === '') {
            $this->lastError = 'XML parse error: document is empty';
            return null;
        }

        $previousUseErrors = libxml_use_internal_errors(true);
        $disableEntityLoader = \PHP_VERSION_ID < 80000;
        $previousEntityLoader = false;
        if ($disableEntityLoader) {
            $previousEntityLoader = libxml_disable_entity_loader(true);
        }

        try {
            $element = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);

            if ($element === false) {
                $firstError = null;
                foreach (libxml_get_errors() as $error) {
                    $message = trim($error->message);
                    error_log("XML error ({$error->code}): {$message} on line {$error->line}");
                    if ($firstError === null) {
                        $firstError = "{$message} on line {$error->line}";
                    }
                }
                $this->lastError = 'XML parse error: ' . ($firstError ?? 'unknown error');
                libxml_clear_errors();
                return null;
            }

            return $element;
        } finally {
            if ($disableEntityLoader) {
                libxml_disable_entity_loader($previousEntityLoader);
            }
            if (!$previousUseErrors) {
                libxml_clear_errors();
            }
            libxml_use_internal_errors($previousUseErrors);
        }
    }

    /**
     * Determine if a string is a URL.
     *
     * @param string $input
     * @return bool
     */
    private function isUrl(string $input): bool
    {
        return filter_var($input, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Fetch a feed over http or https.
     *
     * Redirects are followed here rather than by cURL so that every hop is
     * checked by checkUrl() before it is requested. Only XML content types
     * with a 2xx status are accepted.
     *
     * @param string $url
     * @return string|null
     */
    private function fetchRemoteFeed(string $url): ?string
    {
        $deadline = microtime(true) + $this->options['timeout'];
        $redirects = 0;

        while (true) {
            $target = $this->checkUrl($url);
            if ($target === null) {
                return null;
            }

            $response = $this->request($url, $target, $deadline);
            if ($response === null) {
                return null;
            }

            if ($response['status'] >= 300 && $response['status'] < 400 && $response['location'] !== '') {
                if ($redirects >= $this->options['max_redirects']) {
                    $this->lastError = sprintf('Too many redirects (max_redirects is %d)', $this->options['max_redirects']);
                    return null;
                }
                $redirects++;
                $url = $response['location'];
                continue;
            }

            if (stripos($response['content_type'], 'xml') === false) {
                $this->lastError = sprintf('Not an XML response (Content-Type "%s") from %s', $response['content_type'], $url);
                return null;
            }
            if ($response['status'] < 200 || $response['status'] >= 300) {
                $this->lastError = sprintf('HTTP %d from %s', $response['status'], $url);
                return null;
            }

            return $response['body'];
        }
    }

    /**
     * Check that a URL may be requested: it must be absolute and use http or
     * https.
     *
     * With block_private_ips on, the host is also resolved here and every
     * address it resolves to must pass isAddressAllowed(). The request is
     * then pinned to one of those addresses, so a second DNS lookup cannot
     * swap in another (DNS rebinding). URLs with credentials, whitespace or
     * backslashes, and host names that are not plain ASCII (percent-encoded
     * or Unicode; use the xn-- form instead), are refused in this mode,
     * because PHP and libcurl could read a different host from them.
     *
     * @param string $url
     * @return array{host: string, port: int, ip: string|null}|null Null if refused
     */
    private function checkUrl(string $url): ?array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== '' && $scheme !== 'http' && $scheme !== 'https') {
            $this->lastError = sprintf('Refusing %s URL: only http and https are allowed', $scheme);
            return null;
        }
        if ($scheme === '' || !isset($parts['host']) || $parts['host'] === '') {
            $this->lastError = "Invalid URL: $url";
            return null;
        }

        // parse_url() keeps the brackets around an IPv6 literal.
        $host = trim($parts['host'], '[]');
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $target = ['host' => $host, 'port' => $port, 'ip' => null];
        if (!$this->options['block_private_ips']) {
            return $target;
        }

        if (isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7F\\\\]/', $url)) {
            $this->lastError = "Refusing URL with credentials, whitespace or backslashes: $url";
            return null;
        }

        $isIpLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (!$isIpLiteral && !preg_match('/^(?:[a-z0-9_](?:[a-z0-9_-]*[a-z0-9_])?\.)*[a-z0-9_](?:[a-z0-9_-]*[a-z0-9_])?\.?$/i', $host)) {
            $this->lastError = "Refusing host name that is not plain ASCII: $host";
            return null;
        }

        $addresses = $isIpLiteral ? [$host] : $this->resolveHost($host);
        if ($addresses === []) {
            $this->lastError = "Could not resolve host: $host";
            return null;
        }
        foreach ($addresses as $address) {
            if (!$this->isAddressAllowed($address)) {
                $this->lastError = sprintf('Refusing to connect to %s (%s): private or reserved address', $host, $address);
                return null;
            }
        }

        // Prefer IPv4, which every libcurl accepts in CURLOPT_RESOLVE.
        $target['ip'] = $addresses[0];
        foreach ($addresses as $address) {
            if (strpos($address, ':') === false) {
                $target['ip'] = $address;
                break;
            }
        }

        return $target;
    }

    /**
     * Decide whether block_private_ips allows connecting to an address.
     *
     * Override this to allow particular internal addresses, for example a
     * known feed server on your own network.
     *
     * @param string $ip An IPv4 or IPv6 address
     * @return bool
     */
    protected function isAddressAllowed(string $ip): bool
    {
        return IpAddress::isPublic($ip);
    }

    /**
     * Resolve a host name to its IPv4 and IPv6 addresses.
     *
     * @param string $host
     * @return string[]
     */
    private function resolveHost(string $host): array
    {
        $addresses = [];

        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            $addresses = $ipv4;
        }

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                        $addresses[] = $record['ipv6'];
                    }
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Make a single GET request without following redirects.
     *
     * @param string $url
     * @param array{host: string, port: int, ip: string|null} $target From checkUrl()
     * @param float $deadline microtime(true) value by which the whole fetch must finish
     * @return array{status: int, content_type: string, location: string, body: string}|null
     */
    private function request(string $url, array $target, float $deadline): ?array
    {
        $remainingMs = (int) floor(($deadline - microtime(true)) * 1000);
        if ($remainingMs < 1) {
            $this->lastError = sprintf('Timed out after %s seconds', $this->options['timeout']);
            return null;
        }

        $ch = curl_init();
        if ($ch === false) {
            $this->lastError = 'Could not initialise cURL';
            return null;
        }

        $body = '';
        $tooLarge = false;
        $maxSize = $this->options['max_size'];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => false,
            // Lets sub-second timeouts work with the synchronous resolver.
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT_MS => min($remainingMs, (int) ceil($this->options['connect_timeout'] * 1000)),
            CURLOPT_TIMEOUT_MS => $remainingMs,
            // Refuses early when the server announces a larger Content-Length.
            CURLOPT_MAXFILESIZE => $maxSize,
            CURLOPT_USERAGENT => $this->options['user_agent'],
            CURLOPT_HTTPHEADER => [
                'Accept: application/rss+xml, application/atom+xml, application/xml;q=0.9, */*;q=0.8'
            ],
            // Enforces the cap while downloading, whatever the headers said.
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge, $maxSize): int {
                if (strlen($body) + strlen($chunk) > $maxSize) {
                    $tooLarge = true;
                    return 0; // Anything other than strlen($chunk) aborts the transfer.
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);

        // Limit cURL to http and https, for the request and any redirect.
        // libcurl 7.85 deprecated the bitmask options in favour of string
        // ones, which PHP exposes from 8.3 when libcurl supports them.
        $protocolsOption = defined('CURLOPT_PROTOCOLS_STR') ? constant('CURLOPT_PROTOCOLS_STR') : null;
        $redirProtocolsOption = defined('CURLOPT_REDIR_PROTOCOLS_STR') ? constant('CURLOPT_REDIR_PROTOCOLS_STR') : null;
        $protocolsRestricted = is_int($protocolsOption) && is_int($redirProtocolsOption)
            && curl_setopt($ch, $protocolsOption, 'http,https')
            && curl_setopt($ch, $redirProtocolsOption, 'http,https');
        if (!$protocolsRestricted) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }

        if ($target['ip'] !== null) {
            // A proxy would make its own connection, out of our sight.
            curl_setopt($ch, CURLOPT_PROXY, '');

            // Connect to the address that was checked, not a fresh lookup.
            // An IP literal involves no lookup, so there is nothing to pin.
            if (filter_var($target['host'], FILTER_VALIDATE_IP) === false) {
                $address = strpos($target['ip'], ':') === false ? $target['ip'] : '[' . $target['ip'] . ']';
                curl_setopt($ch, CURLOPT_RESOLVE, [sprintf('%s:%d:%s', $target['host'], $target['port'], $address)]);
            }
        }

        // PHP 8.4+ with libcurl 7.80+ can check the connected address before
        // the request is sent, as a second line of defence after the pin.
        $refusedIp = null;
        $prereqOption = defined('CURLOPT_PREREQFUNCTION') ? constant('CURLOPT_PREREQFUNCTION') : null;
        if ($target['ip'] !== null && is_int($prereqOption)) {
            $abort = constant('CURL_PREREQFUNC_ABORT');
            $proceed = constant('CURL_PREREQFUNC_OK');
            curl_setopt($ch, $prereqOption, function ($handle, string $primaryIp) use (&$refusedIp, $abort, $proceed) {
                if (!$this->isAddressAllowed($primaryIp)) {
                    $refusedIp = $primaryIp;
                    return $abort;
                }
                return $proceed;
            });
        }

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $primaryIp = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        if (\PHP_VERSION_ID < 80000) {
            // A no-op since PHP 8.0 and deprecated in 8.5.
            curl_close($ch);
        }

        if ($tooLarge || $errno === 63) { // 63 is CURLE_FILESIZE_EXCEEDED
            $this->lastError = sprintf('Response from %s is larger than max_size (%d bytes)', $url, $maxSize);
            return null;
        }
        if ($refusedIp !== null) {
            $this->lastError = sprintf('Refusing to connect to %s (%s): private or reserved address', $target['host'], $refusedIp);
            return null;
        }
        if ($target['ip'] !== null && $primaryIp !== '' && !$this->isAddressAllowed($primaryIp)) {
            // Backstop for PHP before 8.4: should not happen while the
            // address is pinned, and the response is discarded.
            $this->lastError = sprintf('Refusing response from %s (%s): private or reserved address', $target['host'], $primaryIp);
            return null;
        }
        if ($ok === false || $errno !== 0) {
            $this->lastError = sprintf('Request to %s failed: %s', $url, $error !== '' ? $error : "cURL error $errno");
            return null;
        }

        return [
            'status' => $status,
            'content_type' => is_string($contentType) ? $contentType : '',
            'location' => is_string($location) ? $location : '',
            'body' => $body,
        ];
    }

    /**
     * Recursively parse a SimpleXMLElement into a full array.
     * Includes attributes and handles namespaces.
     *
     * @param \SimpleXMLElement $element
     * @param array<string> $namespaces Prefix => URI, from getNamespaces(true)
     * @return array<string, mixed>|string A string when the element holds only text
     */
    private function parseElement(\SimpleXMLElement $element, array $namespaces = [])
    {
        $data = [];

        // Add element attributes
        foreach ($element->attributes() as $attrName => $attrValue) {
            $data['@attributes'][$attrName] = (string)$attrValue;
        }

        // Prefixed children, e.g. media:content. The default namespace
        // (prefix '') is skipped because children() below already reads it.
        // Other prefixes match on the namespace URI, as in 2.0, so a URI
        // bound to two prefixes (atom: and atom10: in FeedBurner feeds)
        // keeps its 2.0 output. A prefix bound to the default namespace's
        // URI matches on the prefix instead, or the unprefixed elements in
        // that namespace would be read a second time.
        $defaultUri = $namespaces[''] ?? null;
        foreach ($namespaces as $prefix => $uri) {
            $prefix = (string) $prefix;
            if ($prefix === '') {
                continue;
            }
            $children = $uri === $defaultUri ? $element->children($prefix, true) : $element->children($uri);
            foreach ($children as $childName => $child) {
                $qualifiedName = $prefix . ':' . $childName;
                $data[$qualifiedName][] = $this->parseElement($child, $namespaces);
            }
        }

        // Unprefixed children, in no namespace or the default namespace
        foreach ($element->children() as $childName => $child) {
            $data[$childName][] = $this->parseElement($child, $namespaces);
        }

        // Flatten single-child arrays
        foreach ($data as $key => $val) {
            if (count($val) === 1 && array_key_exists(0, $val)) {
                $data[$key] = $val[0];
            }
        }

        // Add text content if element has no children
        $text = trim((string)$element);
        if (empty($data) && $text !== '') {
            return $text;
        } elseif ($text !== '') {
            $data['@value'] = $text;
        }

        return $data;
    }
}

