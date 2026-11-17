<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Overview;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Presentation\Base\OpenApi\Attributes\Required;

/**
 * Who reported a client event (sections WEB, WEB_SERVER, MOBILE): runtime, app, release, browser and screen, the route
 * pattern and the redacted URL. Read from the `client.*`, `user_agent.*` and `url.*` fields of the logged line.
 */
class IssueEventClientContext extends ValueObject
{
    /** @var string|null browser, node or react-native */
    #[Required]
    public ?string $runtime = null;

    /** @var string|null The client app, e.g. web */
    #[Required]
    public ?string $app = null;

    /** @var string|null The client build's release */
    #[Required]
    public ?string $release = null;

    /** @var string|null Browser name parsed from the user agent */
    #[Required]
    public ?string $browserName = null;

    /** @var string|null Browser version parsed from the user agent */
    #[Required]
    public ?string $browserVersion = null;

    /** @var string|null Operating system parsed from the user agent */
    #[Required]
    public ?string $osName = null;

    /** @var int|null Viewport width in CSS pixels */
    #[Required]
    public ?int $viewportWidth = null;

    /** @var int|null Viewport height in CSS pixels */
    #[Required]
    public ?int $viewportHeight = null;

    /** @var string|null A random id per browser tab, tells one user's repeats from many users */
    #[Required]
    public ?string $tabId = null;

    /** @var string|null The client route pattern, e.g. /orders/[orderId] */
    #[Required]
    public ?string $routePattern = null;

    /** @var string|null The page URL, redacted (tokens and ids removed) */
    #[Required]
    public ?string $url = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
