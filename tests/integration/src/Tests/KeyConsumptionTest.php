<?php
/**
 * @package   Skeletonkey
 * @copyright Copyright (c)2022-2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\SkeletonKey\IntegrationTest\Tests;

defined('_JEXEC') or die;

use Akeeba\SkeletonKey\IntegrationTest\AbstractE2ETestCase;
use Akeeba\SkeletonKey\IntegrationTest\Engine\Surfer;

/**
 * The front-end half: what the authentication plugin does with a cookie it is handed.
 *
 * These are attacker's-eye tests. The browser is handed a cookie that is expired, forged, stolen into a
 * different browser, malformed, or for a user Joomla will not let in, and must end up a guest every time —
 * with the key's database state changed only in the ways the plugin promises (single use, purge on
 * attack).
 *
 * @since 1.2.6
 */
class KeyConsumptionTest extends AbstractE2ETestCase
{
	public function testAnExpiredKeyIsRefusedAndPurged(): void
	{
		$browser = $this->superUser();
		$this->issueKey($browser, 'alice');

		// Age the key past its lifetime server-side, keeping the browser's copy of the cookie alive.
		$this->db()->query('UPDATE #__user_keys SET time = ?', [time() - 5]);

		$this->assertNotServerError($this->visitFrontend($browser));
		$this->assertFrontendGuest($browser, 'An expired key logged the browser in.');
		$this->assertSame([], $this->keyRows(), 'The expired key was not purged.');
	}

	public function testAnExpiredKeyIsRefusedEvenWhenThePurgeFails(): void
	{
		$browser = $this->superUser();
		$this->issueKey($browser, 'alice');

		$this->db()->query('UPDATE #__user_keys SET time = ?', [time() - 5]);

		// The expired-key purge swallows its errors (lock timeout, replica lag…). Make every DELETE fail, so the
		// expired row is still there when the key is looked up: expiry must not depend on the purge succeeding.
		$db = $this->db();
		$db->query('DROP TRIGGER IF EXISTS skeletonkey_e2e_no_delete');
		$db->query(
			"CREATE TRIGGER skeletonkey_e2e_no_delete BEFORE DELETE ON #__user_keys FOR EACH ROW "
			. "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purge failed'"
		);

		try
		{
			$this->assertNotServerError($this->visitFrontend($browser));
			$this->assertFrontendGuest($browser, 'An expired key logged the browser in because the purge failed.');
		}
		finally
		{
			$db->query('DROP TRIGGER IF EXISTS skeletonkey_e2e_no_delete');
		}
	}

	public function testAWrongTokenForARealSeriesIsTreatedAsAnAttack(): void
	{
		// Two outstanding keys for alice, from two different browsers, and one for bob.
		$first  = $this->superUser();
		$second = $this->superUserWithAgent('Mozilla/5.0 (Skeleton Key E2E; second browser)');
		$third  = $this->superUserWithAgent('Mozilla/5.0 (Skeleton Key E2E; third browser)');

		[$name, $value] = $this->issueKey($first, 'alice');
		$this->issueKey($second, 'alice');
		$this->issueKey($third, 'bob');

		$this->assertCount(2, $this->keyRows('alice'));

		// Keep the series, forge the token.
		[, $series] = explode('.', $value);
		$first->setCookie($name, str_repeat('x', 32) . '.' . $series);

		$this->assertNotServerError($this->visitFrontend($first));
		$this->assertFrontendGuest($first, 'A forged token logged the browser in.');

		// Every key alice has is gone — including the perfectly good one in the second browser — and bob's
		// key is untouched.
		$this->assertSame([], $this->keyRows('alice'), 'Not all of the attacked user\'s keys were purged.');
		$this->assertCount(1, $this->keyRows('bob'), 'An unrelated user\'s key was purged.');

		$this->visitFrontend($second);
		$this->assertFrontendGuest($second, 'A key purged after an attack still worked.');
	}

	public function testAnAttackIsLoggedWithTheUsernameAndTheUserId(): void
	{
		$browser = $this->superUser();
		[$name, $value] = $this->issueKey($browser, 'alice');

		[, $series] = explode('.', $value);
		$browser->setCookie($name, str_repeat('x', 32) . '.' . $series);

		$log = $this->logEverythingDuring(fn() => $this->visitFrontend($browser));

		$this->assertStringContainsString(
			sprintf(
				'Skeleton Key login failed for user %s (#%d).',
				static::$fixtures->username('alice'),
				static::$fixtures->userId('alice')
			),
			$log
		);
	}

	/**
	 * Remove a user a test created, so it does not show up in other tests' view of the Users list.
	 *
	 * @param   int  $userId
	 *
	 * @return  void
	 */
	private function dropUser(int $userId): void
	{
		$this->db()->query('DELETE FROM #__user_usergroup_map WHERE user_id = ?', [$userId]);
		$this->db()->query('DELETE FROM #__users WHERE id = ?', [$userId]);
	}

	/**
	 * Run a callback with Joomla's "log everything" option on and return what was logged meanwhile.
	 *
	 * @param   callable  $callback
	 *
	 * @return  string
	 */
	private function logEverythingDuring(callable $callback): string
	{
		// Edited inside the container: a host-side edit of the bind-mounted file is not reliably seen by PHP-FPM
		// right away.
		$config = '/var/www/html/configuration.php';
		$log    = '/var/www/html/administrator/logs/everything.php';
		$cli    = $this->cli();

		$cli->run(['sh', '-c', sprintf('rm -f %s; cp %s %s.bak', $log, $config, $config)]);
		// configuration.php has no log_everything property by default: add it right after log_path.
		[$code, $output] = $cli->run([
			'sed', '-i', 's|^\\(\\s*public \\$log_path.*\\)$|\\1\\n\\tpublic \\$log_everything = 1;|', $config,
		]);
		$this->assertSame(0, $code, $output);

		try
		{
			$callback();

			return $cli->run(['sh', '-c', sprintf('cat %s 2>/dev/null', $log)])[1];
		}
		finally
		{
			$cli->run(['sh', '-c', sprintf('mv %s.bak %s; rm -f %s', $config, $config, $log)]);
		}
	}

	public function testAnUnknownSeriesIsRefused(): void
	{
		$browser = $this->superUser();
		[$name] = $this->issueKey($browser, 'alice');

		$browser->setCookie($name, str_repeat('a', 32) . '.' . str_repeat('b', 20));

		$this->assertNotServerError($this->visitFrontend($browser));
		$this->assertFrontendGuest($browser);
		// Nothing matched, so there was nothing to purge: alice's real key is still there.
		$this->assertCount(1, $this->keyRows('alice'));
	}

	public function testAKeyStolenIntoABrowserWithADifferentUserAgentDoesNotWork(): void
	{
		$victim = $this->superUser();
		[$name, $value] = $this->issueKey($victim, 'alice');

		$thief = $this->newBrowser('Mozilla/5.0 (Skeleton Key E2E; a different browser)');
		$thief->setCookie($name, $value);

		$this->assertNotServerError($this->visitFrontend($thief));
		$this->assertFrontendGuest($thief, 'A cookie copied into a browser with another user agent logged it in.');

		// Planting it under the name the thief's own user agent maps to does not help either: the row is
		// bound to the name it was issued under.
		$thief->setCookie($this->cookieNameFor($thief->uaString), $value);

		$this->visitFrontend($thief);
		$this->assertFrontendGuest($thief, 'A cookie renamed for another user agent logged it in.');

		// The thief never held a valid token for its own user agent, so its attempts should leave the
		// victim's key alone.
		$this->assertCount(1, $this->keyRows('alice'), 'A failed attempt from another browser voided the victim\'s key.');

		$this->visitFrontend($victim);

		$this->assertFrontendUser('alice', $victim);
	}

	public function testASeriesIsNotAnInjectionVector(): void
	{
		$browser = $this->superUser();
		[$name] = $this->issueKey($browser, 'alice');

		$browser->setCookie($name, str_repeat('a', 32) . ".' OR '1'='1");

		$this->assertNotServerError($this->visitFrontend($browser));
		$this->assertFrontendGuest($browser);
		$this->assertCount(1, $this->keyRows('alice'), 'A crafted series deleted or consumed a key.');
	}

	public function testAMalformedCookieIsRefusedCleanly(): void
	{
		$browser = $this->newBrowser();
		$browser->setCookie($this->cookieNameFor($browser->uaString), 'no-dot-in-this-value');

		$response = $this->visitFrontend($browser);

		$this->assertNotServerError($response);
		$this->assertFrontendGuest($browser);

		$warnings = $this->newSkeletonKeyPhpErrors();

		$this->assertSame([], $warnings, 'A malformed cookie raised PHP warnings: ' . implode(' | ', $warnings));
	}

	public function testABlockedUserIsNotLoggedIn(): void
	{
		// The key request itself refuses blocked users; this is the front-end's own defence for a key that was issued
		// before the user got blocked.
		$victim  = static::$fixtures->createUser(['username' => 'soonblocked' . bin2hex(random_bytes(3))]);
		$browser = $this->superUser();

		$response = $this->requestKey($browser, $victim);
		$this->assertTrue($this->keyIssued($browser, $response), $response->summary());

		try
		{
			$this->db()->query('UPDATE #__users SET block = 1 WHERE id = ?', [$victim]);

			$this->assertNotServerError($this->visitFrontend($browser));
			$this->assertFrontendGuest($browser, 'A blocked user was logged in with a key.');
		}
		finally
		{
			$this->dropUser($victim);
		}
	}

	public function testAUserWhoMustResetTheirPasswordIsNotLoggedIn(): void
	{
		$victim  = static::$fixtures->createUser(['username' => 'mustresetlater' . bin2hex(random_bytes(3))]);
		$browser = $this->superUser();

		$response = $this->requestKey($browser, $victim);
		$this->assertTrue($this->keyIssued($browser, $response), $response->summary());

		try
		{
			$this->db()->query('UPDATE #__users SET requireReset = 1 WHERE id = ?', [$victim]);

			$this->assertNotServerError($this->visitFrontend($browser));
			$this->assertFrontendGuest($browser);
			$this->assertSame([], $this->keyRows(), 'The key was not consumed.');
		}
		finally
		{
			$this->dropUser($victim);
		}
	}

	public function testAUserDeletedAfterTheKeyWasIssuedIsNotLoggedIn(): void
	{
		$victim  = static::$fixtures->createUser(['username' => 'shortlived' . bin2hex(random_bytes(3))]);
		$browser = $this->superUser();

		$response = $this->requestKey($browser, $victim);
		$this->assertTrue($this->keyIssued($browser, $response), $response->summary());

		$this->db()->query('DELETE FROM #__user_usergroup_map WHERE user_id = ?', [$victim]);
		$this->db()->query('DELETE FROM #__users WHERE id = ?', [$victim]);

		$this->assertNotServerError($this->visitFrontend($browser));
		$this->assertFrontendGuest($browser);
	}

	public function testNothingHappensInTheBackendWithACookie(): void
	{
		// The cookie is only ever honoured by the site application: loading the back-end with it must not
		// consume it, nor log the browser into anything.
		$issuer = $this->superUser();
		[$name, $value] = $this->issueKey($issuer, 'alice');

		$browser = $this->newBrowser();
		$browser->setCookie($name, $value);

		$this->assertNotServerError($browser->get('administrator/index.php'));
		$this->assertFalse($this->session->isLoggedInBackend($browser));
		$this->assertCount(1, $this->keyRows('alice'), 'Loading the back-end consumed the key.');
	}

	public function testAKeyIsInertWhileTheSystemPluginIsDisabled(): void
	{
		$browser = $this->superUser();
		$this->issueKey($browser, 'alice');

		$this->setPluginEnabled('system', false);

		$this->assertNotServerError($this->visitFrontend($browser));
		$this->assertFrontendGuest($browser);
	}

	public function testAKeyIsInertWhileTheAuthenticationPluginIsDisabled(): void
	{
		$browser = $this->superUser();
		$this->issueKey($browser, 'alice');

		$this->setPluginEnabled('authentication', false);

		$this->assertNotServerError($this->visitFrontend($browser));
		$this->assertFrontendGuest($browser);
	}

	public function testAPasswordLoginStillWorksWithAStaleKeyCookie(): void
	{
		// A leftover, unusable key cookie in the browser must not get in the way of a normal login.
		$browser = $this->newBrowser();
		$browser->setCookie($this->cookieNameFor($browser->uaString), str_repeat('a', 32) . '.' . str_repeat('b', 20));

		$this->session->loginFrontend($browser, static::$fixtures->username('bob'), static::$config->getUserPassword());

		$this->assertFrontendUser('bob', $browser);
	}

	/**
	 * Issue a key for a role and return the cookie the browser received.
	 *
	 * @param   Surfer  $browser  A back-end browser entitled to ask.
	 * @param   string  $role     The target.
	 *
	 * @return  array{0: string, 1: string}  Cookie name and value.
	 */
	private function issueKey(Surfer $browser, string $role): array
	{
		$response = $this->requestKey($browser, static::$fixtures->userId($role));

		$this->assertTrue($this->keyIssued($browser, $response), $response->summary());

		$cookies = $this->keyCookies($browser);

		$this->assertCount(1, $cookies);

		return [array_key_first($cookies), current($cookies)];
	}

	/**
	 * A back-end Super User session in a browser with a specific user agent.
	 *
	 * @param   string  $userAgent  The user agent.
	 *
	 * @return  Surfer
	 */
	private function superUserWithAgent(string $userAgent): Surfer
	{
		$browser = $this->newBrowser($userAgent);
		[$username, $password] = static::$config->getAdminCredentials();

		$this->session->loginBackend($browser, $username, $password);

		return $browser;
	}
}
