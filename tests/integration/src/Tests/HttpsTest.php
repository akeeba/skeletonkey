<?php
/**
 * @package   Skeletonkey
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\SkeletonKey\IntegrationTest\Tests;

defined('_JEXEC') or die;

use Akeeba\SkeletonKey\IntegrationTest\AbstractE2ETestCase;
use Akeeba\SkeletonKey\IntegrationTest\Engine\Surfer;

/**
 * The hand-over of the key from the back-end to the front-end when the two are served over different schemes.
 *
 * The stack only speaks HTTP, so "HTTPS" is simulated: a request carrying `X-Forwarded-Proto: https` looks like an
 * HTTPS request to PHP (see the Apache virtual host). With "Force HTTPS: Administrator only" the back-end requests
 * carry it and the front-end ones do not.
 *
 * @since 1.2.6
 */
class HttpsTest extends AbstractE2ETestCase
{
	public function testAdministratorOnlyHttpsStillHandsTheKeyToAnHttpFrontend(): void
	{
		$this->withSiteConfig(['force_ssl' => 1], function () {
			$browser  = $this->httpsBackend();
			$response = $this->requestKey($browser, static::$fixtures->userId('alice'));

			$this->assertTrue($this->keyIssued($browser, $response), $response->summary());

			// The front-end is plain HTTP: the cookie must not be marked Secure, or the browser will not send it.
			$this->assertFalse($this->keyCookie($browser)['secure'], 'The key cookie is Secure although the front-end is HTTP.');

			$browser->defaultHeaders = [];

			$this->visitFrontend($browser);

			$this->assertFrontendUser('alice', $browser, 'The key did not survive the HTTPS back-end / HTTP front-end hand-over.');
		});
	}

	public function testWholeSiteHttpsMarksTheCookieSecure(): void
	{
		$this->withSiteConfig(['force_ssl' => 2], function () {
			$browser  = $this->httpsBackend();
			$response = $this->requestKey($browser, static::$fixtures->userId('alice'));

			$this->assertTrue($this->keyIssued($browser, $response), $response->summary());
			$this->assertTrue($this->keyCookie($browser)['secure'], 'The key cookie is not Secure although the whole site is HTTPS.');
		});
	}

	/**
	 * A Super User logged into a back-end that looks like it is served over HTTPS.
	 *
	 * @return  Surfer
	 */
	private function httpsBackend(): Surfer
	{
		[$username, $password] = static::$config->getAdminCredentials();

		$browser                                    = $this->newBrowser();
		$browser->defaultHeaders['X-Forwarded-Proto'] = 'https';

		$this->session->loginBackend($browser, $username, $password);

		return $browser;
	}

	/**
	 * The one Skeleton Key cookie in the browser's jar, with its attributes.
	 *
	 * @param   Surfer  $browser
	 *
	 * @return  array
	 */
	private function keyCookie(Surfer $browser): array
	{
		$cookies = array_filter(
			$browser->getCookies(),
			static fn(string $name): bool => str_starts_with($name, self::COOKIE_PREFIX),
			ARRAY_FILTER_USE_KEY
		);

		$this->assertCount(1, $cookies, 'Expected exactly one Skeleton Key cookie.');

		return current($cookies);
	}
}
