<?php
/**
 * @package   Skeletonkey
 * @copyright Copyright (c)2022-2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\SkeletonKey\IntegrationTest\Tests;

defined('_JEXEC') or die;

use Akeeba\SkeletonKey\IntegrationTest\AbstractE2ETestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The back-end half: who may ask for a key, for whom, and how.
 *
 * Every refusal is asserted three ways — the plugin said no, no key row was written, no cookie was set —
 * and then the same browser is sent to the front-end to prove no session appears. The legitimate variant
 * of each request is covered by the first test, so a refusal here cannot pass merely because the request
 * was malformed.
 *
 * @since 1.2.6
 */
class KeyRequestTest extends AbstractE2ETestCase
{
	public function testTheButtonsRequestIsAnsweredWithSuccess(): void
	{
		$browser  = $this->superUser();
		$response = $this->requestKey($browser, static::$fixtures->userId('alice'));

		$this->assertFalse($this->hitsDispatcherIssue($response), $response->summary());

		$json = $response->json();

		$this->assertStatus200Json($response);
		$this->assertTrue($json['success'] ?? null, $response->summary());
		$this->assertSame([true], $json['data'] ?? null, $response->summary());
	}

	public function testTheKeyCookieIsHttpOnlyAndSameSiteStrictOnEveryJoomlaVersion(): void
	{
		$browser  = $this->superUser();
		$response = $this->requestKey($browser, static::$fixtures->userId('alice'));

		$this->assertTrue($this->keyIssued($browser, $response), $response->summary());

		$cookies = array_values(array_filter(
			$response->getHeaders('Set-Cookie'),
			static fn(string $header): bool => str_starts_with($header, 'skeletonkey_')
		));

		$this->assertCount(1, $cookies, 'Expected one Skeleton Key Set-Cookie header.');
		$this->assertStringContainsString('HttpOnly', $cookies[0]);
		$this->assertStringContainsString('SameSite=Strict', $cookies[0]);
	}

	public function testMissingAntiCsrfTokenIsRefused(): void
	{
		$browser = $this->superUser();

		$this->assertRefusedAndInert($browser, $this->requestKey($browser, static::$fixtures->userId('alice'), ''));
	}

	public function testWrongAntiCsrfTokenIsRefused(): void
	{
		$browser = $this->superUser();
		$token   = $browser->corruptToken($this->backendToken($browser));

		$this->assertRefusedAndInert($browser, $this->requestKey($browser, static::$fixtures->userId('alice'), $token));
	}

	public function testARequestOverGetIsRefused(): void
	{
		// The key request changes state, so it must be a POST. A GET carrying a perfectly valid token and user ID
		// (which would put the token in URLs and access logs) is not honoured.
		$browser  = $this->superUser();
		$token    = $this->backendToken($browser);
		$response = $browser->get(
			'administrator/index.php?option=com_ajax&format=json&plugin=skeletonkey&group=system&user_id='
			. static::$fixtures->userId('alice') . '&' . $token . '=1'
		);

		$this->assertRefusedAndInert($browser, $response);
	}

	public function testAnAnonymousBackendRequestIsRefused(): void
	{
		$browser = $this->newBrowser();
		// A back-end guest still gets a session, and therefore a token, from the login page.
		$token    = $browser->fetchToken('administrator/index.php');
		$response = $this->requestKey($browser, static::$fixtures->userId('alice'), $token);

		$this->assertRefusedAndInert($browser, $response);
	}

	public function testAFrontendGuestIsRefused(): void
	{
		$browser = $this->newBrowser();

		$this->assertRefusedAndInert($browser, $this->requestKey($browser, static::$fixtures->userId('alice'), null, 'site'));
	}

	public function testAFrontendSessionIsRefusedEvenForASuperUser(): void
	{
		// The front-end com_ajax endpoint must never mint keys, whoever is logged in there.
		$browser = $this->newBrowser();
		[$username, $password] = static::$config->getAdminCredentials();
		$this->session->loginFrontend($browser, $username, $password);

		$this->assertRefusedAndInert($browser, $this->requestKey($browser, static::$fixtures->userId('alice'), null, 'site'), 'admin');
	}

	public function testABackendUserOutsideTheControlGroupsIsRefused(): void
	{
		$browser = $this->backendAs('manager');

		$this->assertRefusedAndInert($browser, $this->requestKey($browser, static::$fixtures->userId('alice')));
	}

	/**
	 * @return  array<string, array{0: string}>
	 */
	public static function forbiddenTargets(): array
	{
		return [
			'an Administrator (disallowed group 7)'        => ['administrator'],
			'another Super User (disallowed group 8)'      => ['super2'],
			'the requesting Super User themselves'         => ['admin'],
			'a Manager (not under Registered)'             => ['manager'],
			'a user only in the Guest group'               => ['guestonly'],
		];
	}

	#[DataProvider('forbiddenTargets')]
	public function testForbiddenTargetsAreRefused(string $role): void
	{
		$browser = $this->superUser();

		$this->assertRefusedAndInert($browser, $this->requestKey($browser, static::$fixtures->userId($role)));
	}

	/**
	 * @return  array<string, array{0: string}>
	 */
	public static function bogusUserIds(): array
	{
		return [
			'a user id that does not exist' => ['999999'],
			'zero'                          => ['0'],
			'a negative id'                 => ['-1'],
			'not a number'                  => ['alice'],
			'an array'                      => ['__array__'],
		];
	}

	#[DataProvider('bogusUserIds')]
	public function testBogusUserIdsAreRefused(string $userId): void
	{
		$browser = $this->superUser();
		$token   = $this->backendToken($browser);
		$body    = [$token => 1, 'user_id' => $userId === '__array__' ? [static::$fixtures->userId('alice')] : $userId];

		$this->assertRefusedAndInert(
			$browser,
			$browser->post('administrator/index.php?option=com_ajax&format=json&plugin=skeletonkey&group=system', $body)
		);
	}

	public function testAUserIdWithTrailingGarbageDoesNotTargetSomebodyElse(): void
	{
		// getInt() turns "<alice's id>abc" into alice's id. That must still only ever be alice.
		$browser  = $this->superUser();
		$token    = $this->backendToken($browser);
		$response = $browser->post(
			'administrator/index.php?option=com_ajax&format=json&plugin=skeletonkey&group=system',
			[$token => 1, 'user_id' => static::$fixtures->userId('alice') . 'abc']
		);

		$this->assertTrue($this->keyIssued($browser, $response), $response->summary());
		$this->assertCount(1, $this->keyRows('alice'));
		$this->assertCount(1, $this->keyRows());
	}

	public function testNoKeyIsIssuedWhileTheAuthenticationPluginIsDisabled(): void
	{
		$this->setPluginEnabled('authentication', false);

		$browser = $this->superUser();

		$this->assertRefusedAndInert($browser, $this->requestKey($browser, static::$fixtures->userId('alice')));
	}

	public function testUsersWhoCanNeverLogInAreRefusedWithAnExplanation(): void
	{
		// A blocked user, or one who must reset their password, can never be logged in by a key. The operator is
		// told why instead of being sent to a blank guest page, and no key is issued.
		$expected = ['blocked' => ['blocked', 'BLOCKED'], 'mustreset' => ['mustreset', 'MUSTRESET']];

		foreach ($expected as $role => [$code, $logKey])
		{
			$browser  = $this->superUser();
			$response = $this->requestKey($browser, static::$fixtures->userId($role));

			$this->assertKeyRefused($browser, $response, $role);
			$this->assertSame([$code], $response->json()['data'] ?? null, $response->summary());
			$this->assertSame(
				'PLG_ACTIONLOG_SKELETONKEY_LOG_REFUSED_' . $logKey,
				$this->actionLogs()[0]['message_language_key'] ?? null,
				'The refusal was not audited with its reason.'
			);
		}
	}

	/**
	 * Assert a key request was refused and left nothing behind — then prove it on the front-end, too.
	 *
	 * @param   \Akeeba\SkeletonKey\IntegrationTest\Engine\Surfer    $browser   The browser that asked.
	 * @param   \Akeeba\SkeletonKey\IntegrationTest\Engine\Response  $response  The response.
	 * @param   string|null                                          $stillAs   The role the browser was already
	 *                                                                          logged into the front-end as, if any.
	 *
	 * @return  void
	 */
	private function assertRefusedAndInert($browser, $response, ?string $stillAs = null): void
	{
		$this->assertKeyRefused($browser, $response);
		$this->assertFalse(
			$this->hitsDispatcherIssue($response),
			"A refused request reached the action-log dispatch, i.e. it was not refused early.\n" . $response->summary()
		);

		$this->visitFrontend($browser);

		if ($stillAs !== null)
		{
			$this->assertFrontendUser($stillAs, $browser, 'A refused key request changed the front-end session.');

			return;
		}

		$this->assertFrontendGuest($browser, 'A refused key request still produced a front-end session.');
	}

	/**
	 * Assert com_ajax answered with a JSON document.
	 *
	 * @param   \Akeeba\SkeletonKey\IntegrationTest\Engine\Response  $response  The response.
	 *
	 * @return  void
	 */
	private function assertStatus200Json($response): void
	{
		$this->assertSame(200, $response->code, $response->summary());
		$this->assertIsArray($response->json(), "com_ajax did not answer with JSON.\n" . $response->summary());
	}
}
