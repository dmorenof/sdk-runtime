<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

/** An ID token is not valid: it must not be trusted. Messages name the failed check, never the token. */
final class IdTokenException extends OAuth2Exception {}
