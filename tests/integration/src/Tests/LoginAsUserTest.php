<?php
/**
 * @package   Skeletonkey
 * @copyright Copyright (c)2022-2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\SkeletonKey\IntegrationTest\Tests;

defined('_JEXEC') or die;

use Akeeba\SkeletonKey\IntegrationTest\AbstractE2ETestCase;

/**
 * The feature itself: a Super User clicks "Log in as user" and the same browser lands on the front-end as
 * that user.
 *
 * Also pins the properties the README's security claims rest on: the key is random, short-lived, stored
 * only as a hash, carried in an HttpOnly cookie, and consumed on first use.
 *
 * @since 1.2.6
 */
class LoginAsUserTest extends AbstractE2ETestCase
{
	public function testSuperUserLogsInToTheFrontendAsARegisteredUser(): void
	{
		$browser = $this->superUser();

		$this->assertFrontendGuest($browser, 'The browser must start out as a front-end guest.');

		$landing = $this->logInAs($browser, 'alice');

		$this->assertNotServerError($landing);
		$this->assertFrontendUser('alice', $browser);
		// …while the Super User's own back-end session is untouched.
		$this->assertTrue($this->session->isLoggedInBackend($browser), 'Logging in as alice logged the Super User out of the back-end.');
	}

	public function testUsersInheritingFromAnAllowedGroupCanBeImpersonated(): void
	{
		$browser = $this->superUser();

		$this->logInAs($browser, 'author');

		$this->assertFrontendUser('author', $browser);
	}

	public function testTheKeyIsStoredAsAHashAndCarriedInAnHttpOnlyCookie(): void
	{
		$browser  = $this->superUser();
		$before   = time();
		$response = $this->requestKey($browser, static::$fixtures->userId('alice'));

		$this->assertTrue($this->keyIssued($browser, $response), $response->summary());

		// The cookie: exactly one, under the name the plugin derives from the site and the user agent.
		$cookies = $this->keyCookies($browser);
		$name    = $this->cookieNameFor($browser->uaString);

		$this->assertSame([$name], array_keys($cookies), 'Expected exactly one Skeleton Key cookie, named after the user agent.');
		$this->assertTrue($browser->getCookies()[$name]['httpOnly'], 'The Skeleton Key cookie must be HttpOnly.');
		$this->assertSame('/', $browser->getCookies()[$name]['path']);

		// Its value is <token>.<series>.
		$parts = explode('.', $cookies[$name]);

		$this->assertCount(2, $parts, 'The cookie value must be <token>.<series>.');
		[$token, $series] = $parts;

		$this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $token, 'The token must be key_length (32) random characters.');
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9]{20}$/', $series);

		// The row: one, for alice (by USERNAME, as Joomla's remember-me does), holding only a hash.
		$rows = $this->keyRows();

		$this->assertCount(1, $rows);
		$row = $rows[0];

		$this->assertSame(static::$fixtures->username('alice'), $row['user_id']);
		$this->assertSame($series, $row['series']);
		$this->assertSame($name, $row['uastring']);
		$this->assertNotSame($token, $row['token'], 'The token must not be stored in plain text.');
		$this->assertStringNotContainsString($token, $row['token']);
		$this->assertTrue(password_verify($token, $row['token']), 'The stored hash does not verify the cookie token.');

		// The expiry is cookie_lifetime (10) seconds out, in both the row and the cookie.
		$this->assertGreaterThanOrEqual($before + 10, (int) $row['time']);
		$this->assertLessThanOrEqual(time() + 10, (int) $row['time']);

		$expires = $browser->getCookies()[$name]['expires'];

		$this->assertGreaterThanOrEqual($before + 9, $expires);
		$this->assertLessThanOrEqual(time() + 11, $expires);
	}

	public function testTheKeyIsConsumedOnFirstUse(): void
	{
		$browser = $this->superUser();

		$this->requestKey($browser, static::$fixtures->userId('alice'));

		[$name, $value] = [array_key_first($this->keyCookies($browser)), current($this->keyCookies($browser))];

		$this->visitFrontend($browser);

		$this->assertFrontendUser('alice', $browser);
		$this->assertSame([], $this->keyRows(), 'The key row must be deleted once used.');
		$this->assertSame([], $this->keyCookies($browser), 'The key cookie must be deleted once used.');

		// Replay the very same cookie from a fresh browser with the same user agent.
		$replay = $this->newBrowser();
		$replay->setCookie($name, $value);

		$this->assertNotServerError($this->visitFrontend($replay));
		$this->assertFrontendGuest($replay, 'A used key logged somebody in a second time.');
	}

	public function testEachKeyIsUnique(): void
	{
		$tokens = [];
		$series = [];

		for ($i = 0; $i < 3; $i++)
		{
			$browser = $this->newBrowser('Mozilla/5.0 (Skeleton Key E2E; uniqueness ' . $i . ')');
			[$username, $password] = static::$config->getAdminCredentials();
			$this->session->loginBackend($browser, $username, $password);

			$this->assertTrue($this->keyIssued($browser, $this->requestKey($browser, static::$fixtures->userId('alice'))));

			[$tokens[], $series[]] = explode('.', current($this->keyCookies($browser)));
		}

		$this->assertCount(3, array_unique($tokens));
		$this->assertCount(3, array_unique($series));
		$this->assertCount(3, $this->keyRows('alice'));
	}

	public function testTheKeyReplacesAnExistingFrontendSessionInTheSameBrowser(): void
	{
		$browser = $this->superUser();
		$this->session->loginFrontend($browser, static::$fixtures->username('bob'), static::$config->getUserPassword());

		$this->assertFrontendUser('bob', $browser);

		$this->logInAs($browser, 'alice');

		$this->assertFrontendUser('alice', $browser);
	}

	public function testTheImpersonatedSessionGetsAFreshSessionId(): void
	{
		$browser = $this->superUser();

		// Establish a front-end guest session first, so there is an id that could be fixated.
		$this->visitFrontend($browser);

		$before = array_map(fn(array $cookie): string => $cookie['value'], $browser->getCookies());

		$this->logInAs($browser, 'alice');

		$sessionIds = $this->db()->column(
			'SELECT session_id FROM #__session WHERE client_id = 0 AND userid = ?',
			[static::$fixtures->userId('alice')]
		);

		$this->assertNotEmpty($sessionIds, 'No front-end session row for alice.');

		foreach ($sessionIds as $sessionId)
		{
			$this->assertNotContains($sessionId, $before, 'The impersonated session re-used the pre-login session id.');
		}
	}

	public function testLoggingOutEndsTheImpersonatedSession(): void
	{
		$browser = $this->superUser();

		$this->logInAs($browser, 'alice');
		$this->assertFrontendUser('alice', $browser);

		$this->session->logoutFrontend($browser);

		$this->assertFrontendGuest($browser);
		$this->assertSame([], $this->keyRows());
		$this->assertSame([], $this->keyCookies($browser));
		$this->assertTrue($this->session->isLoggedInBackend($browser), 'Front-end logout ended the back-end session.');
	}

	public function testASecondSuperUserCanAlsoUseIt(): void
	{
		$browser = $this->backendAs('super2');

		$this->logInAs($browser, 'bob');

		$this->assertFrontendUser('bob', $browser);
	}
}
