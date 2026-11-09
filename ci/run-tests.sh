#!/usr/bin/env bash
set -euo pipefail

# A new repository has no behavior to test yet. Never report invented coverage.
find src tests -type f -name '*.php' -print0 | xargs -0 -r -n1 php -l
composer dump-autoload --optimize --strict-psr --strict-ambiguous

if ! find src -type f -name '*.php' -print -quit | grep -q .; then
  echo "SCAFFOLD ONLY: no production PHP code; no behavioral tests or coverage claimed."
  exit 0
fi

if ! find tests -type f -name '*Test.php' -print -quit | grep -q .; then
  echo "Production PHP code exists but no PHPUnit *Test.php tests were found." >&2
  exit 1
fi

# Static analysis at the strictest level: the typed API is the point of this package.
vendor/bin/phpstan analyse --no-progress --memory-limit=1G

if [[ "${1:-}" == "--coverage" ]]; then
  mkdir -p coverage
  vendor/bin/phpunit -c phpunit.xml --coverage-text --coverage-clover coverage/clover.xml
else
  vendor/bin/phpunit -c phpunit.xml
fi

# A body larger than the process limit must pass as a stream, including a repeat send.
php -d memory_limit=16M tests/streaming-smoke.php "$PWD/vendor/autoload.php"
php -d memory_limit=16M tests/streaming-smoke.php "$PWD/vendor/autoload.php" '' list

php -d memory_limit=16M tests/download-smoke.php "$PWD/vendor/autoload.php"

php tests/status-response-smoke.php "$PWD/vendor/autoload.php"

php tests/simple-parameters-smoke.php "$PWD/vendor/autoload.php"

php tests/object-parameters-smoke.php "$PWD/vendor/autoload.php"
