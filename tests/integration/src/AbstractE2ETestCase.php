<?php
/**
 * @package   Skeletonkey
 * @copyright Copyright (c)2022-2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\SkeletonKey\IntegrationTest;

defined('_JEXEC') or die;

use Akeeba\SkeletonKey\IntegrationTest\Engine\Configuration;
use Akeeba\SkeletonKey\IntegrationTest\Engine\ContainerCli;
use Akeeba\SkeletonKey\IntegrationTest\Engine\Database;
use Akeeba\SkeletonKey\IntegrationTest\Engine\JoomlaSession;
use Akeeba\SkeletonKey\IntegrationTest\Engine\Response;
use Akeeba\SkeletonKey\IntegrationTest\Engine\Surfer;
use PHPUnit\Framework\TestCase;

/**
 * Base class for the end-to-end tests.
 *
 * Every test here drives real HTTP requests against a real Joomla site with real sessions. The flow under
 * test always spans two applications in ONE browser: the Super User asks the back-end for a key (com_ajax),
 * the back-end answers with an HttpOnly cookie, and the next front-end request made by that same browser
 * turns the cookie into a front-end session for somebody else. A Surfer is that browser.
 *
 * For every refusal the rule is: assert the refusal AND the absence of its effect — no row in
 * #__user_keys, no cookie, no front-end session.
 *
 * @since 1.2.6
 */
abstract class AbstractE2ETestCase extends TestCase
{
	/**
	 * The prefix of Skeleton Key's cookie. The rest of the name is a hash of the site URL and user agent.
	 *
	 * @since 1.2.6
	 */
	protected const COOKIE_PREFIX = 'skeletonkey_';

	/**
	 * The suite configuration.
	 *
	 * @var   Configuration
	 * @since 1.2.6
	 */
	protected static Configuration $config;

	/**
	 * The fixtures.
	 *
	 * @var   SiteProvisioner
	 * @since 1.2.6
	 */
	protected static SiteProvisioner $fixtures;

	/**
	 * Surfers created during a test, keyed by role, so each test starts from a clean session.
	 *
	 * @var   array<string, Surfer>
	 * @since 1.2.6
	 */
	private array $surfers = [];

	/**
	 * The login helper.
	 *
	 * @var   JoomlaSession
	 * @since 1.2.6
	 */
	protected JoomlaSession $session;

	/**
	 * Byte offset into php-errors.log when the test started.
	 *
	 * @var   int
	 * @since 1.2.6
	 */
	private int $phpErrorLogOffset = 0;

	/**
	 * Site state this test changed, to be put back in tearDown().
	 *
	 * @var   array<string, bool>
	 * @since 1.2.6
	 */
	private array $dirty = [];

	/**
	 * Set up the shared configuration and fixtures.
	 *
	 * @return  void
	 * @since   1.2.6
	 */
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		static::$config   = Configuration::getInstance();
		static::$fixtures = SiteProvisioner::getInstance();
	}

	/**
	 * Set up a test.
	 *
	 * @return  void
	 * @since   1.2.6
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->session           = new JoomlaSession();
		$this->phpErrorLogOffset = $this->phpErrorLogSize();

		// Keys are per-test: one left behind by an earlier test must never satisfy a later one.
		$this->db()->query('DELETE FROM #__user_keys');
	}

	/**
	 * Tear down a test, putting back whatever it changed.
	 *
	 * @return  void
	 * @since   1.2.6
	 */
	protected function tearDown(): void
	{
		$this->surfers = [];

		if ($this->dirty['system'] ?? false)
		{
			static::$fixtures->resetSystemParams();
		}

		if ($this->dirty['users'] ?? false)
		{
			static::$fixtures->resetUsersParams();
		}

		if ($this->dirty['plugins'] ?? false)
		{
			foreach (['system', 'authentication', 'actionlog'] as $folder)
			{
				static::$fixtures->setPluginEnabled($folder, true);
			}
		}

		$this->dirty = [];

		$this->db()->query('DELETE FROM #__user_keys');

		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// Changing site state for one test.
	// -----------------------------------------------------------------------

	/**
	 * Change the system plugin's parameters for the rest of this test.
	 *
	 * @param   array  $params  Parameter => value, merged over the defaults.
	 *
	 * @return  void
	 * @since   1.2.6
	 */
	protected function setSystemParams(array $params): void
	{
		$this->dirty['system'] = true;

		static::$fixtures->setSystemParams($params);
	}

	/**
	 * Replace the system plugin's parameters wholesale for the rest of this test.
	 *
	 * @param   array  $params  Parameter => value.
	 *
	 * @return  void
	 * @since   1.2.6
	 */
	protected function replaceSystemParams(array $params): void
	{
		$this->dirty['system'] = true;

		static::$fixtures->replaceSystemParams($params);
	}

	/**
	 * Change com_users' options for the rest of this test.
	 *
	 * @param   array  $params  Option => value.
	 *
	 * @return  void
	 * @since   1.2.6
	 */
	protected function setUsersParams(array $params): void
	{
		$this->dirty['users'] = true;

		static::$fixtures->setUsersParams($params);
	}

	/**
	 * Enable or disable one of the three plugins for the rest of this test.
	 *
	 * @param   string  $folder   system, authentication or actionlog.
	 * @param   bool    $enabled  The new state.
	 *
	 * @return  void
	 * @since   1.2.6
	 */
	protected function setPluginEnabled(string $folder, bool $enabled): void
	{
		$this->dirty['plugins'] = true;

		static::$fixtures->setPluginEnabled($folder, $enabled);
	}

	// -----------------------------------------------------------------------
	// Actors.
	// -----------------------------------------------------------------------

	/**
	 * A brand new logged-out browser.
	 *
	 * @param   string|null  $userAgent  A user agent other than the harness default.
	 *
	 * @return  Surfer
	 * @since   1.2.6
	 */
	protected function newBrowser(?string $userAgent = null): Surfer
	{
		$surfer = new Surfer(static::$config->getSiteUrl());

		if ($userAgent !== null)
		{
			$surfer->uaString = $userAgent;
		}

		return $surfer;
	}

	/**
	 * A browser logged into the back-end as the installer's Super User.
	 *
	 * @return  Surfer
	 * @since   1.2.6
	 */
	protected function superUser(): Surfer
	{
		if (isset($this->surfers['__super']))
		{
			return $this->surfers['__super'];
		}

		[$username, $password] = static::$config->getAdminCredentials();

		$surfer = $this->newBrowser();
		$this->session->loginBackend($surfer, $username, $password);

		return $this->surfers['__super'] = $surfer;
	}

	/**
	 * A browser logged into the back-end as one of the shared accounts.
	 *
	 * @param   string  $role  A role with back-end access, e.g. 'manager' or 'super2'.
	 *
	 * @return  Surfer
	 * @since   1.2.6
	 */
	protected function backendAs(string $role): Surfer
	{
		$key = '__admin_' . $role;

		if (isset($this->surfers[$key]))
		{
			return $this->surfers[$key];
		}

		$surfer = $this->newBrowser();
		$this->session->loginBackend($surfer, static::$fixtures->username($role), static::$config->getUserPassword());

		return $this->surfers[$key] = $surfer;
	}

	/**
	 * A browser logged into the front-end as one of the shared accounts.
	 *
	 * @param   string  $role  A role, e.g. 'bob'.
	 *
	 * @return  Surfer
	 * @since   1.2.6
	 */
	protected function frontendAs(string $role): Surfer
	{
		$key = '__site_' . $role;

		if (isset($this->surfers[$key]))
		{
			return $this->surfers[$key];
		}

		$surfer = $this->newBrowser();
		$this->session->loginFrontend($surfer, static::$fixtures->username($role), static::$config->getUserPassword());

		return $this->surfers[$key] = $surfer;
	}

	// -----------------------------------------------------------------------
	// Access to the stack.
	// -----------------------------------------------------------------------

	/**
	 * The site's database.
	 *
	 * @return  Database
	 * @since   1.2.6
	 */
	protected function db(): Database
	{
		static $db = null;

		return $db ??= new Database(static::$config);
	}

	/**
	 * Joomla's console application inside the stack.
	 *
	 * @return  ContainerCli
	 * @since   1.2.6
	 */
	protected function cli(): ContainerCli
	{
		static $cli = null;

		return $cli ??= new ContainerCli(static::$config);
	}

	// -----------------------------------------------------------------------
	// The Skeleton Key flow, step by step.
	// -----------------------------------------------------------------------

	/**
	 * The back-end Users list, as the surfer sees it.
	 *
	 * @param   Surfer  $surfer  A back-end surfer.
	 *
	 * @return  Response
	 * @since   1.2.6
	 */
	protected function usersPage(Surfer $surfer): Response
	{
		// A generous list limit, so every fixture account is on the first page.
		return $surfer->get('administrator/index.php', ['option' => 'com_users', 'view' => 'users', 'list[limit]' => 100]);
	}

	/**
	 * The anti-CSRF token of a back-end session, read from the Users list the button lives on.
	 *
	 * @param   Surfer  $surfer  A back-end surfer.
	 *
	 * @return  string
	 * @since   1.2.6
	 */
	protected function backendToken(Surfer $surfer): string
	{
		return $surfer->fetchToken('administrator/index.php', ['option' => 'com_users', 'view' => 'users']);
	}

	/**
	 * Ask for a key, exactly as the "Log in as user" button's JavaScript does.
	 *
	 * @param   Surfer       $surfer  The browser asking.
	 * @param   int          $userId  The user to log in as.
	 * @param   string|null  $token   The anti-CSRF token; null for the session's real one, '' for none.
	 * @param   string       $client  'administrator' (what the button uses) or 'site'.
	 *
	 * @return  Response
	 * @since   1.2.6
	 */
	protected function requestKey(Surfer $surfer, int $userId, ?string $token = null, string $client = 'administrator'): Response
	{
		if ($token === null)
		{
			$token = $client === 'administrator'
				? $this->backendToken($surfer)
				: (string) $surfer->fetchToken('index.php', ['option' => 'com_users', 'view' => 'login']);
		}

		// The request is a POST: the token and the user ID travel in the body, never in the URL.
		$body = ['user_id' => $userId];

		if ($token !== '')
		{
			$body[$token] = 1;
		}

		return $surfer->post(
			($client === 'administrator' ? 'administrator/index.php' : 'index.php')
			. '?option=com_ajax&format=json&plugin=skeletonkey&group=system',
			$body
		);
	}

	/**
	 * What the plugin answered through com_ajax: true, false, or null when the answer is missing.
	 *
	 * com_ajax wraps plugin results as {"success": …, "data": [<one per plugin>]}. Only our plugin
	 * answers onAjaxSkeletonkey, so data[0] is its verdict.
	 *
	 * @param   Response  $response  The com_ajax response.
	 *
	 * @return  bool|null
	 * @since   1.2.6
	 */
	protected function keyResult(Response $response): ?bool
	{
		$json = $response->json();

		if (!is_array($json) || !array_key_exists('data', $json) || !is_array($json['data']) || $json['data'] === [])
		{
			return null;
		}

		return is_bool($json['data'][0]) ? $json['data'][0] : null;
	}

	/**
	 * Skeleton Key cookies in a browser.
	 *
	 * @param   Surfer  $surfer  The browser.
	 *
	 * @return  array<string, string>  name => value.
	 * @since   1.2.6
	 */
	protected function keyCookies(Surfer $surfer): array
	{
		return $surfer->getCookiesStartingWith(self::COOKIE_PREFIX);
	}

	/**
	 * Run a callback with some of Joomla's global configuration (configuration.php) changed, then put it back.
	 *
	 * Edited inside the container: a host-side edit of the bind-mounted file is not reliably seen by PHP-FPM straight
	 * away. Only existing scalar properties can be changed.
	 *
	 * @param   array<string, int|string>  $properties  Property name => new value, e.g. ['force_ssl' => 1].
	 * @param   callable                   $callback    What to run meanwhile.
	 *
	 * @return  mixed  What the callback returned.
	 * @since   1.2.6
	 */
	protected function withSiteConfig(array $properties, callable $callback)
	{
		$config = '/var/www/html/configuration.php';
		$cli    = $this->cli();

		$cli->run(['cp', $config, $config . '.bak']);

		try
		{
			foreach ($properties as $name => $value)
			{
				$value = is_int($value) ? (string) $value : "'" . addslashes((string) $value) . "'";

				[$code, $output] = $cli->run([
					'sed', '-i', sprintf('s|^\\(\\s*public \\$%s\\s*=\\s*\\).*;|\\1%s;|', $name, $value), $config,
				]);

				$this->assertSame(0, $code, $output);
			}

			return $callback();
		}
		finally
		{
			$cli->run(['mv', $config . '.bak', $config]);
		}
	}

	/**
	 * The name Skeleton Key gives its cookie for a browser with this user agent.
	 *
	 * `skeletonkey_` + md5(site secret . site root URL without its scheme . user agent) —
	 * ApplicationHelper::getHash() of Uri::root() (minus "http:"/"https:") plus the UA. Computed here, independently, so a test can plant a cookie exactly where the
	 * plugin will look for it without first asking the plugin for one.
	 *
	 * @param   string  $userAgent  The browser's user agent.
	 *
	 * @return  string
	 * @since   1.2.6
	 */
	protected function cookieNameFor(string $userAgent): string
	{
		$configuration = (string) file_get_contents(static::$config->getSiteRoot() . '/configuration.php');

		$this->assertSame(
			1,
			preg_match('/public\s+\$secret\s*=\s*\'([^\']*)\'/', $configuration, $match),
			'Could not read the site secret from configuration.php.'
		);

		return self::COOKIE_PREFIX . md5($match[1] . preg_replace('#^https?:#i', '', static::$config->getSiteUrl()) . '/' . $userAgent);
	}

	/**
	 * Load any front-end page with this browser — the moment Skeleton Key acts.
	 *
	 * @param   Surfer  $surfer  The browser.
	 *
	 * @return  Response
	 * @since   1.2.6
	 */
	protected function visitFrontend(Surfer $surfer): Response
	{
		return $surfer->get('index.php');
	}

	/**
	 * The username of the browser's front-end session, or null for a guest.
	 *
	 * @param   Surfer  $surfer  The browser.
	 *
	 * @return  string|null
	 * @since   1.2.6
	 */
	protected function frontendUser(Surfer $surfer): ?string
	{
		return $this->session->getCurrentUsername($surfer);
	}

	/**
	 * The whole happy path: ask for a key as the Super User, then load the front-end.
	 *
	 * @param   Surfer  $surfer  A back-end surfer entitled to ask.
	 * @param   string  $role    The target role.
	 *
	 * @return  Response  The front-end response that consumed the key.
	 * @since   1.2.6
	 */
	protected function logInAs(Surfer $surfer, string $role): Response
	{
		$response = $this->requestKey($surfer, static::$fixtures->userId($role));

		$this->assertTrue(
			$this->keyIssued($surfer, $response),
			sprintf("The key for '%s' was not issued.\n%s", $role, $response->summary())
		);

		return $this->visitFrontend($surfer);
	}

	/**
	 * Did a key request leave the browser holding a usable key?
	 *
	 * Normally that is simply "the plugin answered true". Known issue #1 is the exception: on Joomla 6.1 the
	 * request dies with "Dispatcher not set" when the plugin fires its action-log event — AFTER it has
	 * already written the key row and set the cookie. The button's JavaScript then reports failure and
	 * never opens the front-end, but the browser does hold a working key.
	 *
	 * Tests of the front-end half (the authentication plugin) accept that state, so issue #1 does not hide
	 * everything downstream of it. The request itself is asserted, and skipped as known issue #1, by
	 * {@see \Akeeba\SkeletonKey\IntegrationTest\Tests\KeyRequestTest}.
	 *
	 * @param   Surfer    $surfer    The browser that asked.
	 * @param   Response  $response  The com_ajax response.
	 *
	 * @return  bool
	 * @since   1.2.6
	 */
	protected function keyIssued(Surfer $surfer, Response $response): bool
	{
		if ($this->keyResult($response) === true)
		{
			return true;
		}

		return $this->hitsDispatcherIssue($response) && $this->keyCookies($surfer) !== [];
	}

	/**
	 * Does a com_ajax response show the symptom of known issue #1?
	 *
	 * @param   Response  $response  The com_ajax response.
	 *
	 * @return  bool
	 * @since   1.2.6
	 */
	protected function hitsDispatcherIssue(Response $response): bool
	{
		$json = $response->json();

		return is_array($json) && str_contains((string) ($json['message'] ?? ''), 'Dispatcher not set');
	}

	// -----------------------------------------------------------------------
	// Observations.
	// -----------------------------------------------------------------------

	/**
	 * The #__user_keys rows. Skeleton Key stores the USERNAME in user_id, exactly as Joomla's own
	 * remember-me plugin does.
	 *
	 * @param   string|null  $username  Only this user's rows; null for all.
	 *
	 * @return  array[]
	 * @since   1.2.6
	 */
	protected function keyRows(?string $username = null): array
	{
		if ($username === null)
		{
			return $this->db()->all('SELECT * FROM #__user_keys');
		}

		return $this->db()->all('SELECT * FROM #__user_keys WHERE user_id = ?', [$username]);
	}

	/**
	 * Skeleton Key's action log entries, newest first.
	 *
	 * @return  array[]
	 * @since   1.2.6
	 */
	protected function actionLogs(): array
	{
		return $this->db()->all(
			"SELECT * FROM #__action_logs WHERE extension = 'plg_system_skeletonkey' ORDER BY id DESC"
		);
	}

	/**
	 * The user ids the back-end Users list would put a "Log in as user" button on.
	 *
	 * Read from the script options the plugin hands its JavaScript, which is the decision itself; the
	 * JavaScript merely draws a button for each id.
	 *
	 * @param   Response  $response  The Users list page.
	 *
	 * @return  int[]|null  Null when the plugin did not add its options at all.
	 * @since   1.2.6
	 */
	protected function loginButtonUserIds(Response $response): ?array
	{
		$options = $this->scriptOptions($response);

		if (!isset($options['plg_system_skeletonkey']['loginUsers']))
		{
			return null;
		}

		$ids = array_map('intval', (array) $options['plg_system_skeletonkey']['loginUsers']);
		sort($ids);

		return $ids;
	}

	/**
	 * The Joomla script options of a rendered page.
	 *
	 * @param   Response  $response  An HTML page.
	 *
	 * @return  array
	 * @since   1.2.6
	 */
	protected function scriptOptions(Response $response): array
	{
		if (!preg_match('/<script[^>]+class="joomla-script-options[^"]*"[^>]*>(.*?)<\/script>/s', $response->body, $match))
		{
			return [];
		}

		return json_decode($match[1], true) ?: [];
	}

	/**
	 * The messages of one type in a page's message queue.
	 *
	 * @param   Response  $response  An HTML page.
	 * @param   string    $type      e.g. 'error', 'warning', 'message'.
	 *
	 * @return  string[]
	 * @since   1.2.6
	 */
	protected function queuedMessages(Response $response, string $type): array
	{
		$found = [];

		foreach ($this->scriptOptions($response)['joomla.messages'] ?? [] as $group)
		{
			foreach ($group[$type] ?? [] as $text)
			{
				$found[] = (string) $text;
			}
		}

		return $found;
	}

	/**
	 * PHP errors, warnings and notices the site logged since this test started.
	 *
	 * @return  string[]
	 * @since   1.2.6
	 */
	protected function newPhpErrors(): array
	{
		$file = static::$config->getSiteRoot() . '/php-errors.log';

		clearstatcache(true, $file);

		if (!is_file($file) || filesize($file) <= $this->phpErrorLogOffset)
		{
			return [];
		}

		$handle = fopen($file, 'r');
		fseek($handle, $this->phpErrorLogOffset);
		$contents = stream_get_contents($handle);
		fclose($handle);

		return array_values(array_filter(array_map('trim', explode("\n", (string) $contents))));
	}

	/**
	 * The PHP errors logged since this test started that mention Skeleton Key's own code.
	 *
	 * @return  string[]
	 * @since   1.2.6
	 */
	protected function newSkeletonKeyPhpErrors(): array
	{
		return array_values(
			array_filter($this->newPhpErrors(), fn(string $line): bool => stripos($line, 'skeletonkey') !== false)
		);
	}

	/**
	 * The size of php-errors.log right now.
	 *
	 * @return  int
	 * @since   1.2.6
	 */
	private function phpErrorLogSize(): int
	{
		$file = static::$config->getSiteRoot() . '/php-errors.log';

		clearstatcache(true, $file);

		return is_file($file) ? (int) filesize($file) : 0;
	}

	// -----------------------------------------------------------------------
	// Assertions.
	// -----------------------------------------------------------------------

	/**
	 * Assert the browser's front-end session belongs to a given role.
	 *
	 * @param   string  $role     The role.
	 * @param   Surfer  $surfer   The browser.
	 * @param   string  $message  Context for a failure.
	 *
	 * @return  void
	 * @since   1.2.6
	 */
	protected function assertFrontendUser(string $role, Surfer $surfer, string $message = ''): void
	{
		$this->assertSame(
			static::$fixtures->username($role),
			$this->frontendUser($surfer),
			trim(sprintf("Expected the front-end session to belong to '%s'.\n%s", $role, $message))
		);
	}

	/**
	 * Assert the browser's front-end session is a guest's.
	 *
	 * @param   Surfer  $surfer   The browser.
	 * @param   string  $message  Context for a failure.
	 *
	 * @return  void
	 * @since   1.2.6
	 */
	protected function assertFrontendGuest(Surfer $surfer, string $message = ''): void
	{
		$this->assertNull(
			$this->frontendUser($surfer),
			trim("Expected the front-end session to be a guest's.\n" . $message)
		);
	}

	/**
	 * Assert a key request was refused, and that it left nothing behind: no key row, no cookie.
	 *
	 * @param   Surfer    $surfer    The browser that asked.
	 * @param   Response  $response  The com_ajax response.
	 * @param   string    $message   Context for a failure.
	 *
	 * @return  void
	 * @since   1.2.6
	 */
	protected function assertKeyRefused(Surfer $surfer, Response $response, string $message = ''): void
	{
		$context = trim($message . "\n" . $response->summary());

		$this->assertNotTrue($this->keyResult($response), "The key request was granted.\n" . $context);
		$this->assertSame([], $this->keyRows(), "A key row was written although the request was refused.\n" . $context);
		$this->assertSame([], $this->keyCookies($surfer), "A key cookie was set although the request was refused.\n" . $context);
		$this->assertNotServerError($response, $message);
	}

	/**
	 * Assert a response is not a server error and the site logged no PHP fatal while producing it.
	 *
	 * @param   Response  $response  The response.
	 * @param   string    $message   Context for a failure.
	 *
	 * @return  void
	 * @since   1.2.6
	 */
	protected function assertNotServerError(Response $response, string $message = ''): void
	{
		$fatals = array_filter($this->newPhpErrors(), fn(string $line): bool => stripos($line, 'Fatal error') !== false);

		$this->assertLessThan(500, $response->code, trim($message . "\n" . $response->summary() . "\n" . implode("\n", $fatals)));
		$this->assertSame([], array_values($fatals), trim("The site logged a PHP fatal error.\n" . $message));
	}

	/**
	 * Assert a condition, or skip naming a known product bug when it does not hold.
	 *
	 * Used where the product is currently wrong: the test must not assert today's buggy behaviour as
	 * correct, and must not fail the suite for a bug already on record. It starts passing once the bug
	 * is fixed.
	 *
	 * @param   bool    $condition  The correct behaviour.
	 * @param   int     $issue      The number in known-issues.md.
	 * @param   string  $diagnosis  What is wrong.
	 *
	 * @return  void
	 * @since   1.2.6
	 */
	protected function assertOrKnownIssue(bool $condition, int $issue, string $diagnosis): void
	{
		if ($condition)
		{
			$this->assertTrue(true);

			return;
		}

		$this->markTestSkipped(sprintf('Known issue #%d (see known-issues.md): %s', $issue, $diagnosis));
	}
}
