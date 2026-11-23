<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Untrusted;

use DDD\Infrastructure\Exceptions\BadRequestException;

/**
 * Foreign content was refused by one of the helpers in this namespace (a path outside its root, an archive over its
 * caps, an XML document with a DOCTYPE, …). A BadRequestException: the content came from outside, so it is the
 * sender's error (400), never a server fault.
 */
class UntrustedContentException extends BadRequestException
{
}
