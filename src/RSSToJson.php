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
 * The parsed result is wrapped as ['channel' => [...]]. Elements become keys,
 * repeated elements become lists, attributes go under '@attributes', text
 * beside attributes or children goes under '@value', and prefixed elements
 * keep their prefix (e.g. 'media:content'). Items (RSS <item>, Atom <entry>)
 * are always collected as a list under channel['item'].
 */
class RSSToJson
{
    /**
     * @var string|null
     */
    private $lastError;

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
     * Fetch remote feed via cURL.
     *
     * @param string $url
     * @return string|null
     */
    private function fetchRemoteFeed(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'RSStoJSON/1.0 (+http://dealmobile.co.uk)');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/rss+xml, application/atom+xml, application/xml;q=0.9, */*;q=0.8'
        ]);

        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        // PHP 7.4 compatible: use stripos instead of str_contains
        if ($contentType === false || stripos($contentType, 'xml') === false) {
            curl_close($ch);
            return null;
        }
        curl_close($ch);

        return ($httpCode >= 200 && $httpCode < 300) ? $data : null;
    }

    /**
     * Recursively parse a SimpleXMLElement into a full array.
     * Includes attributes and handles namespaces.
     *
     * @param \SimpleXMLElement $element
     * @param array $namespaces
     * @return array|string|null
     */
    private function parseElement(\SimpleXMLElement $element, array $namespaces = [])
    {
        $data = [];

        // Add element attributes
        foreach ($element->attributes() as $attrName => $attrValue) {
            $data['@attributes'][$attrName] = (string)$attrValue;
        }

        // Prefixed children, e.g. media:content. Match on the prefix rather
        // than the namespace URI so that an element is only ever read once:
        // the default namespace (prefix '') is covered by children() below,
        // and a URI bound to two prefixes no longer yields two copies.
        foreach (array_keys($namespaces) as $prefix) {
            if ($prefix === '') {
                continue;
            }
            foreach ($element->children($prefix, true) as $childName => $child) {
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
            if (is_array($val) && count($val) === 1 && array_key_exists(0, $val)) {
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

