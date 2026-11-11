<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

/** An OAuth2 exchange failed. Messages carry error codes only, never secrets, tokens or server text. */
class OAuth2Exception extends \RuntimeException {}
