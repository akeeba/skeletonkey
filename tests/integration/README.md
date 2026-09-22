# Skeleton Key end-to-end tests

These tests drive a **real, disposable Joomla site over real HTTP**, exactly as a browser or an attacker
would. The whole stack is stood up in Docker, provisioned from nothing, tested, and thrown away:

| Service | What it is |
|---|---|
| `db` | MySQL |
| `php` | PHP-FPM running Joomla, and the Joomla CLI. **No route to the internet** — the site only sees `db` and `web` |
| `web` | Apache, proxying to `php` over FastCGI |

There is no mail service: Skeleton Key sends no mail. There is no NginX front-end either: Skeleton Key ships no
`.htaccess` rules and has no web-server-specific behaviour.

Nothing boots Joomla inside the PHPUnit process. You never configure a site by hand, and a run that fails or
crashes mid-way never leaves anything behind in an unknown state.

The unit tests are a separate suite; see [`UnitTest/README.md`](../../UnitTest/README.md).

## Requirements

* **Docker** with the Compose plugin.
* **PHP CLI** (with `curl`, `pdo_mysql`, `simplexml`) and **PHPUnit 11**, installed globally
  (`composer global require phpunit/phpunit ^11`) and on your `PATH`. The test runner is a host-side process
  that talks to the site over its published ports.
* **Phing** on your `PATH`, plus the `../buildfiles` checkout and `node`, to build the package (`phing git`; see
  the repository's `README.md`). Skip with `--skip-build` if `release/` already holds a package.
* `unzip`, `curl`, `gunzip` and `jq`, used to resolve, fetch and extract Joomla.

## Quick start

```
tests/integration/docker/run.sh
```

That one command scrubs, brings the stack up, installs Joomla, builds and installs Skeleton Key, provisions the
fixtures, runs the suite, and tears the stack down.

To iterate, keep the stack up and re-run PHPUnit directly — provisioning is the slow part, the tests themselves
take seconds:

```
tests/integration/docker/run.sh --keep-containers
phpunit -c phpunit-integration.xml
phpunit -c phpunit-integration.xml --filter KeyConsumptionTest
php tests/integration/provision.php      # put the fixtures back
tests/integration/docker/run.sh --down   # tear it all down
```

While the stack is up the site is on <http://localhost:8200> (`admin` / `test`; every other account's password
is `test` too).

## `run.sh` options

| Option | Effect |
|---|---|
| `-j`, `--joomla=V` | Override `JOOMLA_VERSION` for this run (`6`, `6.1`, `6.1.3`) |
| `-p`, `--php=V` | Override `PHP_VERSION` for this run (`8.1`, `8.3`, `8.5`) |
| `--matrix` | Run every Joomla/PHP pair in `JOOMLA_MATRIX`, then exit |
| `-f`, `--filter=NAME` | Passed through to PHPUnit |
| `--skip-build` | Don't run `phing git`; install the newest package already in `release/` |
| `--no-tests` | Provision the site but don't run the suite (leaves it up) |
| `--keep-containers` | Leave the stack running afterwards |
| `--down` | Tear everything down and exit |
| `-- <args>` | Everything after `--` goes to PHPUnit |

Joomla packages are cached in `inbox/`; drop a `Joomla_X.Y.Z-Stable-Full_Package.zip` there to pin an exact build
or to run offline. Everything else is configured in `docker/.env` (created from `docker/env.dist` on first run).

## The version matrix

```
tests/integration/docker/run.sh --matrix
```

Runs the suite once per pair in `JOOMLA_MATRIX` — by default Joomla **5.4 on PHP 8.1 and 8.5, 6.0 on PHP 8.3 and
8.5, 6.1 on PHP 8.3 and 8.5**. Where each bound comes from:

* **Joomla ≥ 5.4.0, < 6.3** — `$minimumJoomla` / `$maximumJoomla` in
  `plugins/system/skeletonkey/script.plg_system_skeletonkey.php` (the same values as `composer.json`
  `extra.akcompat.limit`, and in the other two plugins' scripts; the unit suite's `VersionLimitsTest` keeps them in
  step). `run.sh` reads the bounds out of that script and refuses a version outside them before touching Docker. No
  6.2 release exists yet, so 6.1 is the ceiling in practice.
* **PHP ≥ 8.1, < 8.7** — `$minimumPhp` / `$maximumPhp` in the same script (and `composer.json` `require.php`).
  Joomla 6 itself needs PHP 8.3, which `run.sh` reads from the extracted package and enforces. PHP 8.6 has no
  released `php:*-fpm` image yet, so 8.5 is the ceiling in practice.

Why these pairs, and not just "the latest":

* **Joomla 5.4 and 6.x both have to be there.** Skeleton Key has a Joomla 5 / Joomla 6 fork in how it sets and
  destroys its cookie (`version_compare(JVERSION, '5.999.999', …)` in both the system and the authentication
  plugin). More importantly, Joomla 6.1 changed `PluginHelper::import()` so it no longer injects a dispatcher into
  `SubscriberInterface` plugins — which is exactly what breaks the key request on 6.1 and not on 5.4 or 6.0 (known
  issue #1). A single-version run hides that in either direction: on 5.4 or 6.0 everything looks fine, and on 6.1
  the action-log tests are skipped rather than run.
* **6.0 and 6.1 both.** 6.0 is the first release on the new cookie branch; 6.1 is the latest, and the first with
  the new plugin import behaviour. Minor releases do change behaviour Skeleton Key depends on — issue #1 is proof.
* **The lowest and the highest PHP on each branch.** 8.1 is the declared floor (and only reachable on 5.4); 8.5 is
  the newest PHP that can actually run.

A green single-version run proves less than it looks. In particular, the default run (Joomla 6.1) skips the
action-log assertions because of issue #1; only the 5.4 and 6.0 legs of the matrix exercise them.

## What the suite covers

| Test | Covers |
|---|---|
| `HarnessTest` | The harness itself: plugins installed and enabled, the core group ids, the fixture accounts' group membership, the identity probe |
| `LoginAsUserTest` | The feature: a Super User lands on the front-end as the target; the key is random, hashed at rest, HttpOnly, short-lived and single-use; a fresh session id; logout |
| `KeyRequestTest` | Who may ask, for whom, and how: CSRF (missing, wrong, POSTed), front-end and anonymous callers, users outside the control groups, disallowed and out-of-tree targets, bogus user ids, disabled authentication plugin |
| `KeyConsumptionTest` | What the front-end does with a cookie: expired, forged token (purge on attack), unknown series, stolen into another user agent, crafted series, malformed, blocked / must-reset / deleted target, plugins disabled, a stale cookie during a password login |
| `UsersPageTest` | Which Users-list rows get a button, the script and its strings, the warning when the authentication plugin is off |
| `ConfigurationTest` | Every option, both directions: control groups, target groups, disallowed-wins, the installer's string-form parameters, no parameters at all, key length, lifetime |
| `MfaTest` | "Bypass Multi-factor Authentication" against Joomla's real MFA gate: captive page and mandatory setup, with and without the bypass, and that it never leaks into the target's own logins |
| `ActionLogTest` | The audit trail: entries written, attributed and rendered; the MFA-bypass variant; the action log plugin disabled |

Every refusal is asserted as "refused, **and** no key row, **and** no cookie, **and** no front-end session".
Where the product is currently wrong, the test skips with `Known issue #N (see known-issues.md)` instead of
asserting the wrong behaviour; it starts passing once the issue is fixed.

## How it works

* `src/Engine/` — the host-side client: `Surfer` (a cURL browser with a cookie jar you can read and plant cookies
  in — Skeleton Key's cookie is HttpOnly, so this is the only way to observe it), `Request` / `Response`,
  `JoomlaSession` (real form logins on both sides), `Database` (PDO, for fixtures and observation only),
  `ContainerCli`, `Configuration`.
* `src/SiteProvisioner.php` — one account per interesting position in Joomla's stock group tree (see the file),
  the three plugins enabled with their default parameters, com_users' MFA options at Joomla's defaults, and an MFA
  record for `mfauser`.
* `assets/e2e-probe.php` — deployed into the throwaway site. Boots the site application far enough to say which
  user a browser's front-end session belongs to, without routing or dispatching. A test fixture, never part of a
  package.
* `bootstrap-e2e.php` loads no Joomla and opens no database connection; it only checks the site is reachable, so
  forgetting `run.sh` gives one clear message.

The flow under test always spans both applications in **one** browser: the Super User's back-end session asks
`com_ajax` for a key, and the next front-end request made by the same cookie jar turns it into the target's
session. Tests model that literally: one `Surfer` is one browser.
