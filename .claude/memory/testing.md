# End-to-end testing notes

Gotchas learned while adding tests to `tests/integration/`. Read before writing or changing an end-to-end test.

## Edit the site's files from inside the container

Rule: change `configuration.php` (or any file PHP-FPM must see change immediately) through `$this->cli()->run([...])`,
not with `file_put_contents()` from the host.

**Why:** `tests/integration/docker/www` is a bind mount. A host-side edit is not reliably visible to PHP-FPM straight
away, so a test that switches a setting on and immediately makes a request passes or fails at random. A test that
captured Joomla's log this way failed four runs in a row until the edit and the read moved into the container.

**How to apply:** see `KeyConsumptionTest::logEverythingDuring()`. Joomla's `log_everything` option writes every log
entry (including our `security` category) to `administrator/logs/everything.php`; the property is absent from the
default `configuration.php`, so add it after `log_path`, and restore the file in a `finally`.

## Prove ordering and swallowed failures with a MySQL trigger

Rule: to show that one thing happens before another, or that a swallowed error cannot leave a bad state behind, create
a trigger in the test that makes the database refuse the second thing unless the first has happened, and drop it in a
`finally`.

**Why:** the plugins swallow database errors on purpose in places, and an action-log write cannot be made to fail
from outside. `ActionLogTest::testTheImpersonationIsLoggedBeforeTheKeyIsIssued` (key insert refused until an audit row
exists) and `KeyConsumptionTest::testAnExpiredKeyIsRefusedEvenWhenThePurgeFails` (every DELETE refused) both fail on the
old code this way.

**How to apply:** the stack's MySQL runs with `--log-bin-trust-function-creators=1` (in
`tests/integration/docker/docker-compose.yml`); without it `CREATE TRIGGER` fails for the non-root test user. Anything
that touches the database schema this way must clean up after itself.

## Facts about the data that tests rely on

- `#__user_keys.user_id` holds the **username**, not the numeric ID, and `time` is a varchar holding a Unix timestamp;
  core's Remember Me plugin shares the table. Use `keyRows($username)`.
- The key request is a **POST** to `index.php?option=com_ajax&format=json&plugin=skeletonkey&group=system` with
  `user_id` and the anti-CSRF token in the body; `requestKey()` does this. A GET is refused.
- Only authenticated users' refusals are written to the action log; guests never are.
- Action-log message keys: `…LOG_REQUEST_*` (granted / failed), `…LOG_REFUSED_*` (one per reason) and `…LOG_REDEEMED`.
  Filter by prefix when counting entries, because one impersonation now writes two.

## Known-issue skips

`known-issues.md` (git-ignored, kept outside the repository) lists product bugs; a test that covers one uses
`assertOrKnownIssue()` and skips. When the issue is fixed, replace the call with a plain assertion.
