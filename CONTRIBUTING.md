# Contributing

Develop on a branch and open a PR. A version is published only when its work is finished and proven and the owner confirms; versions are not published piecemeal, and a branch is not deleted unless the owner asks. See [AGENTS.md](AGENTS.md) and [RELEASING.md](RELEASING.md).

Keep responsibilities separate and reuse abstractions. Public API changes need concrete use cases and meaningful regression coverage. Validate PHP 8.3/8.4/8.5, minimum dependencies and advisories through GitHub Actions.

## Checks

```sh
composer validate --strict --no-check-lock
composer install --no-interaction --prefer-dist
bash ci/run-tests.sh
composer audit --no-interaction
```

Review API stability and PHP minimum requirements before publication. Never move release tags or commit secrets.

## Public API changes

The public API is frozen by `tests/Fixtures/public-api.txt`. If a change is intentional, regenerate it with `php ci/api-snapshot.php > tests/Fixtures/public-api.txt`, review the diff against the rules in [docs/PUBLIC_API.md](docs/PUBLIC_API.md) and record it in the CHANGELOG. Tag helpers that applications must not use with `@internal`.
