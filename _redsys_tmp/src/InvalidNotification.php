<?php
declare(strict_types=1);

namespace Redsys;

/** A notification that must not be trusted: fields missing, parameters that are not JSON, or a signature that does not match. */
final class InvalidNotification extends \RuntimeException
{
}
