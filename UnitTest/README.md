# Unit tests

Pure PHPUnit unit tests for Skeleton Key. They cover what needs no Joomla at all: the `DbQuery` compatibility
helper, and the shape of what the package ships — language files, version limits, manifests, defaults. They run in
milliseconds, without Docker.

Everything that needs a booted Joomla — the three plugins' event handlers, the cookie, authentication, MFA, the
action log — is tested end-to-end instead; see [`tests/integration/README.md`](../tests/integration/README.md).

## Requirements

- PHP 8.5 with `simplexml`. `composer.json` allows `>=8.1.0 <8.7`; the highest version that range allows and that
  is actually released today is 8.5, so that is the target.
- PHPUnit 11, installed **globally** with Composer — never as a project dependency:
  `composer global require phpunit/phpunit ^11`. Make sure Composer's global `vendor/bin` is on your `PATH`, so
  plain `phpunit` resolves.
- Nothing else: the bootstrap autoloads the plugins' classes itself, so `composer install` is not needed.

## Running

From the repository root:

```bash
phpunit                                      # the whole unit suite
phpunit --testdox                            # human-readable output
phpunit --filter DbQueryTest                 # one class
```

Configuration is `phpunit.xml` at the repository root; the bootstrap is `UnitTest/bootstrap.php`. It defines
`_JEXEC`, autoloads the system and authentication plugins' PSR-4 prefixes, and loads `UnitTest/Stubs/Database.php`
— empty stand-ins for the two Joomla Framework interfaces `DbQuery` is typed against.

## What is here

| Test | Covers |
|---|---|
| `Helper/DbQueryTest` | Both copies of `Helper\DbQuery`: `createQuery()` when the driver has it, `getQuery(true)` otherwise, a fresh query every time; the two copies have not drifted apart |
| `Build/LanguageFilesTest` | Every line parses the way Joomla parses it; no key defined twice; translations keep the original's keys and placeholders; every key the code uses exists in en-GB; every shipped file is registered in its manifest |
| `Build/VersionLimitsTest` | All three installer scripts agree with `composer.json` and enforce both maximums; the package installs all three plugins |
| `Build/PackageSurfaceTest` | `_JEXEC` guards; everything the manifests list exists; the web asset points at the shipped script; the safe defaults, in the manifest and in the code's own fallbacks |

## Conventions

- Namespace `Akeeba\SkeletonKey\UnitTest\...`, mirroring the directory under `UnitTest/`.
- PHPUnit attributes (`#[CoversClass]`, `#[DataProvider]`), not annotations.
- Where the product is currently wrong, the test skips with `Known issue #N (see known-issues.md)` through
  `KnownIssueTrait::assertOrKnownIssue()` instead of failing or asserting the wrong behaviour; it passes once the
  issue is fixed.
