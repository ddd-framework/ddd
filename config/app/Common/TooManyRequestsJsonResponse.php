<?php

declare(strict_types=1);

/**
 * Framework DEFAULT of the JSON answer to a rate-limit rejection (module-level). A consuming application overrides this
 * whole file at `config/app/Common/TooManyRequestsJsonResponse.php`.
 *
 * Read by {@see DDD\Symfony\EventListeners\TooManyRequestsJsonResponseListener} via
 * `Config::get('Common.TooManyRequestsJsonResponse')`.
 *
 * 'pathPrefixes' — the request path prefixes (as the kernel sees them) whose 429 is answered with the JSON body
 *                  `{"error": "<message>"}`. Empty (the default): the listener answers nothing.
 */
return [
    'pathPrefixes' => [],
];
