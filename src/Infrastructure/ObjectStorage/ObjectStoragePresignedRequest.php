<?php

declare(strict_types=1);

namespace DDD\Infrastructure\ObjectStorage;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * A request a client sends to the store without credentials: method, URL and the headers it must send unchanged (they
 * are signed into the URL, e.g. `If-None-Match: *` of a create-only upload). Valid until `expiresAt`.
 */
class ObjectStoragePresignedRequest extends ValueObject
{
    public const string METHOD_GET = 'GET';
    public const string METHOD_PUT = 'PUT';

    public string $method = self::METHOD_PUT;

    public string $url = '';

    /** @var string[] Headers the client sends exactly as given, header name => value */
    public array $headers = [];

    public ?DateTime $expiresAt = null;
}
