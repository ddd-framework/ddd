<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Files\Dtos;

use DDD\Infrastructure\Validation\Constraints\Choice;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Asks for the bytes of one file: a small or a large rendered picture, or the file as it is stored.
 */
class FileSourceFileContentGetRequestDto extends FileSourceFileGetRequestDto
{
    /** @var string A rendered picture for lists and tiles */
    public const string VARIANT_THUMBNAIL = 'THUMBNAIL';

    /** @var string A rendered picture for a viewer */
    public const string VARIANT_PREVIEW = 'PREVIEW';

    /** @var string The stored bytes */
    public const string VARIANT_ORIGINAL = 'ORIGINAL';

    /** @var string[] */
    public const array VARIANTS = [self::VARIANT_THUMBNAIL, self::VARIANT_PREVIEW, self::VARIANT_ORIGINAL];

    /** @var string THUMBNAIL or PREVIEW (pictures only: rendered upright, without metadata) or ORIGINAL (default) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    #[Choice(choices: self::VARIANTS)]
    public string $variant = self::VARIANT_ORIGINAL;
}
