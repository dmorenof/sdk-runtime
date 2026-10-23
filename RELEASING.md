# Releases

Versions follow semantic versioning. Generated SDKs constrain this package
(for example `^0.1`), so a breaking change to the public runtime classes
requires a new minor version while the package is 0.x.

## Publishing a version

1. Open a PR to the protected `main` branch. All GitHub Actions checks must
   pass, including the `test` job, which runs the PHPUnit suite.
2. Change the root `VERSION` file in that PR to the new `X.Y.Z` (no leading
   `v`) and describe the change in the PR.
3. Merge the PR. `.github/workflows/release.yml` runs Composer validation,
   dependency installation, syntax checks, PHPUnit and a security audit.
4. If those checks pass, the workflow creates immutable tag `vX.Y.Z` at the
   merge commit and publishes a GitHub Release.
5. Register the package in Packagist once and enable repository
   synchronization. Packagist reads versions from Git tags; never write a
   `version` field in `composer.json`.
6. Confirm a clean `composer require dmorenof/sdk-runtime` before updating the
   generator's `RUNTIME_CONSTRAINT`.

Existing tags are never force-moved. A duplicate version pointing to a
different commit makes the release workflow fail safely.

This is package release automation, not the SDK code generation engine.
