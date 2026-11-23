<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Untrusted;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One check of {@see \DDD\Infrastructure\Services\UntrustedInputRuntimeCheckService}.
 */
class UntrustedInputRuntimeCheck extends ValueObject
{
    /** The runtime refuses what it must refuse */
    public const string STATUS_SAFE = 'SAFE';

    /** An interpreter is reachable: fix the image (ImageMagick policy, remove Ghostscript) */
    public const string STATUS_UNSAFE = 'UNSAFE';

    /** Something the application needs does not work (a raster coder, the Imagick extension) */
    public const string STATUS_BROKEN = 'BROKEN';

    /** @var string Short name (`imagemagick-reads-postscript`, `ghostscript-on-path`, …) */
    public string $name;

    /** @var string STATUS_* */
    public string $status;

    /** @var string What was found */
    public string $detail = '';

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->name);
    }
}
