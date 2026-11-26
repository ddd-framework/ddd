<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Files\Sources;

/**
 * The kind of a file as its readers need it (a picture is decoded, a PDF goes through poppler, a ride file through a
 * parser). Derived from the content type the source measured from the bytes, never from a client's claim.
 */
class FileFamily
{
    public const string IMAGE = 'IMAGE';
    public const string PDF = 'PDF';
    public const string DOCUMENT = 'DOCUMENT';
    public const string SPREADSHEET = 'SPREADSHEET';
    public const string PRESENTATION = 'PRESENTATION';
    public const string TEXT = 'TEXT';
    public const string RIDE_FILE = 'RIDE_FILE';
    public const string VIDEO = 'VIDEO';
    public const string OTHER = 'OTHER';

    /** @var string[] */
    public const array FAMILIES = [
        self::IMAGE,
        self::PDF,
        self::DOCUMENT,
        self::SPREADSHEET,
        self::PRESENTATION,
        self::TEXT,
        self::RIDE_FILE,
        self::VIDEO,
        self::OTHER,
    ];

    /** @var array<string, string> Exact content types of a family */
    public const array FAMILY_BY_CONTENT_TYPE = [
        'application/pdf' => self::PDF,
        'application/msword' => self::DOCUMENT,
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => self::DOCUMENT,
        'application/vnd.oasis.opendocument.text' => self::DOCUMENT,
        'application/rtf' => self::DOCUMENT,
        'application/vnd.ms-excel' => self::SPREADSHEET,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => self::SPREADSHEET,
        'application/vnd.oasis.opendocument.spreadsheet' => self::SPREADSHEET,
        'text/csv' => self::SPREADSHEET,
        'application/vnd.ms-powerpoint' => self::PRESENTATION,
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => self::PRESENTATION,
        'application/vnd.oasis.opendocument.presentation' => self::PRESENTATION,
        'application/gpx+xml' => self::RIDE_FILE,
        'application/json' => self::TEXT,
        'application/xml' => self::TEXT,
    ];

    /** @var array<string, string> File name extensions that decide where the content type cannot (XML that is a GPX) */
    public const array FAMILY_BY_EXTENSION = [
        'gpx' => self::RIDE_FILE,
    ];

    /**
     * The family of a measured content type; the file name only decides between families one content type covers
     * (a GPX is XML to a byte sniffer).
     */
    public static function fromContentType(?string $contentType, ?string $name = null): string
    {
        $normalizedContentType = strtolower(trim(explode(';', (string)$contentType)[0]));
        $extension = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
        $isXmlOrPlainText = in_array($normalizedContentType, ['application/xml', 'text/xml', 'text/plain', ''], true);
        if ($isXmlOrPlainText && isset(static::FAMILY_BY_EXTENSION[$extension])) {
            return static::FAMILY_BY_EXTENSION[$extension];
        }
        if (isset(static::FAMILY_BY_CONTENT_TYPE[$normalizedContentType])) {
            return static::FAMILY_BY_CONTENT_TYPE[$normalizedContentType];
        }
        if (str_starts_with($normalizedContentType, 'image/')) {
            return static::IMAGE;
        }
        if (str_starts_with($normalizedContentType, 'video/')) {
            return static::VIDEO;
        }
        if (str_starts_with($normalizedContentType, 'text/')) {
            return static::TEXT;
        }
        return static::OTHER;
    }
}
