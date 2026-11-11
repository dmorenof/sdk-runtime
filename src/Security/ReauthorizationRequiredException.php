<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

/** No usable token and no way to renew it silently: the user must go through the authorization step again. */
final class ReauthorizationRequiredException extends OAuth2Exception {}
