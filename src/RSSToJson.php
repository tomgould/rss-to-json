<?php

namespace TomGould\RSSToJson;

/**
 * Handles RSS, Atom, and custom XML from either a file or URL
 *
 * Sanitizes feed content:
 *
 * Fixes illegal & characters (& → &amp; unless a valid entity)
 *
 * Strips UTF-8 BOM (\xEF\xBB\xBF)
 *
 * Trims whitespace
 *
 * Recursively parses elements, capturing:
 *
 * Attributes under @attributes
 *
 * Namespaced children like media:thumbnail
 *
 * Text values under @value
 *
 * Returns everything as a clean PHP array, compatible with json_encode
 *
 * Feeds are wrapped in channel => [...], with all items merged directly under channel['item'] (no separate top-level items array)
 *
 * cURL is configured to only request XML-type content, with custom User-Agent and Accept headers to avoid common 403 errors or HTML responses
 */
class RSSToJson
{

    /**
     * Load and parse a feed from a URL or local file.
     *
     * @param string $source URL or file path
     * @return array|null
     */
    public function parse(string $source): ?array
    {
        libxml_use_internal_errors(true);

        if ($this->isUrl($source)) {
            $xmlContent = $this->fetchRemoteFeed($source);
            if (!$xmlContent) {
                return null;
            }

            // Fix illegal bare ampersands in content (e.g. "Tom & Jerry")
            // by replacing them with &amp; unless they're already a valid XML entity.
            $xmlContent = preg_replace(
                '/&(?!amp;|lt;|gt;|quot;|apos;|#[0-9]+;|#x[0-9a-fA-F]+;)/',
                '&amp;',
                $xmlContent
            );

            // Remove UTF-8 Byte Order Mark (BOM) if present at the start of the document.
            // This hidden character can break XML parsing in some cases.
            $xmlContent = preg_replace('/^\xEF\xBB\xBF/', '', $xmlContent);

            // Trim leading/trailing whitespace, just in case
            $xmlContent = trim($xmlContent);

            $xml = simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NOCDATA);
        } else {
            $xml = simplexml_load_file($source, 'SimpleXMLElement', LIBXML_NOCDATA);
        }

        if ($xml === false) {
            foreach (libxml_get_errors() as $error) {
                error_log("XML error ({$error->code}): {$error->message} on line {$error->line}");
            }
            libxml_clear_errors();
            return null;
        }

        $namespaces = $xml->getNamespaces(true);
        $channel = $xml->channel ?? $xml;
        $parsedChannel = $this->parseElement($channel, $namespaces);

        $items = $channel->item ?? $xml->entry ?? [];
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

        // Handle namespaces
        foreach ($namespaces as $prefix => $ns) {
            foreach ($element->children($ns) as $childName => $child) {
                $qualifiedName = $prefix . ':' . $childName;
                $data[$qualifiedName][] = $this->parseElement($child, $namespaces);
            }
        }

        // Regular children (non-namespaced)
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

--- DELETE: src/RSStoJSON.php

