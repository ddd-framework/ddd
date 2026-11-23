<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Untrusted;

use DOMDocument;
use SimpleXMLElement;
use XMLReader;

/**
 * Parses XML and HTML from outside: no network, no DTD load, no entity substitution, no `PARSEHUGE`, a size cap, and
 * a document with a DOCTYPE is refused (no internal entities, no billion laughs). libxml errors never reach the output.
 */
class SafeXml
{
    /** @var int Largest document parsed (bytes) */
    public const int DEFAULT_MAX_BYTES = 10485760;

    /** @var int libxml flags of every parse: no network; nothing that loads a DTD or substitutes entities is set */
    public const int LIBXML_FLAGS = LIBXML_NONET | LIBXML_NOCDATA;

    /**
     * @param string $xml
     * @param int $maxBytes
     * @return DOMDocument
     * @throws UntrustedContentException too large, a DOCTYPE, or not well-formed
     */
    public static function loadXml(string $xml, int $maxBytes = self::DEFAULT_MAX_BYTES): DOMDocument
    {
        static::assertParsable($xml, $maxBytes);
        $domDocument = new DOMDocument();
        $previousErrorHandling = libxml_use_internal_errors(true);
        try {
            $loaded = $domDocument->loadXML($xml, static::LIBXML_FLAGS);
            if (!$loaded || $domDocument->doctype !== null) {
                throw new UntrustedContentException('The XML document is not well-formed or declares a DOCTYPE.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorHandling);
        }
        return $domDocument;
    }

    /**
     * @param string $xml
     * @param int $maxBytes
     * @return SimpleXMLElement
     * @throws UntrustedContentException
     */
    public static function loadSimpleXml(string $xml, int $maxBytes = self::DEFAULT_MAX_BYTES): SimpleXMLElement
    {
        $simpleXmlElement = simplexml_import_dom(static::loadXml($xml, $maxBytes));
        if (!$simpleXmlElement instanceof SimpleXMLElement) {
            throw new UntrustedContentException('The XML document has no root element.');
        }
        return $simpleXmlElement;
    }

    /**
     * Parses HTML (tolerant, as a browser would) without network and without implied DOCTYPE handling. Scripts in it
     * stay inert text nodes: nothing here runs them.
     * @param string $html
     * @param int $maxBytes
     * @return DOMDocument
     * @throws UntrustedContentException too large
     */
    public static function loadHtml(string $html, int $maxBytes = self::DEFAULT_MAX_BYTES): DOMDocument
    {
        if (strlen($html) > $maxBytes) {
            throw new UntrustedContentException(sprintf('The HTML document is larger than %d bytes.', $maxBytes));
        }
        $domDocument = new DOMDocument();
        $previousErrorHandling = libxml_use_internal_errors(true);
        try {
            $domDocument->loadHTML('<?xml encoding="UTF-8">' . $html, static::LIBXML_FLAGS | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorHandling);
        }
        return $domDocument;
    }

    /**
     * A pull reader over a document from outside, with the same flags.
     * @param string $xml
     * @param int $maxBytes
     * @return XMLReader
     * @throws UntrustedContentException
     */
    public static function createReader(string $xml, int $maxBytes = self::DEFAULT_MAX_BYTES): XMLReader
    {
        static::assertParsable($xml, $maxBytes);
        $xmlReader = XMLReader::XML($xml, null, static::LIBXML_FLAGS);
        if (!$xmlReader instanceof XMLReader) {
            throw new UntrustedContentException('The XML document cannot be read.');
        }
        $xmlReader->setParserProperty(XMLReader::LOADDTD, false);
        $xmlReader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
        return $xmlReader;
    }

    /**
     * @param string $xml
     * @param int $maxBytes
     * @return void
     * @throws UntrustedContentException
     */
    protected static function assertParsable(string $xml, int $maxBytes): void
    {
        if (strlen($xml) > $maxBytes) {
            throw new UntrustedContentException(sprintf('The XML document is larger than %d bytes.', $maxBytes));
        }
        if (preg_match('/<!DOCTYPE/i', $xml) === 1 || preg_match('/<!ENTITY/i', $xml) === 1) {
            throw new UntrustedContentException('An XML document from outside must not declare a DOCTYPE or entities.');
        }
    }
}
