<?php
/**
 * @package   Skeletonkey
 * @copyright Copyright (c)2022-2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\SkeletonKey\IntegrationTest\Tests;

defined('_JEXEC') or die;

use Akeeba\SkeletonKey\IntegrationTest\AbstractE2ETestCase;
use Akeeba\SkeletonKey\IntegrationTest\Engine\Response;
use Akeeba\SkeletonKey\IntegrationTest\Engine\Surfer;
use Akeeba\SkeletonKey\IntegrationTest\SiteProvisioner;

/**
 * The "Bypass Multi-factor Authentication" option against Joomla's real MFA gate.
 *
 * Joomla decides on every front-end page load whether a logged-in session must first pass the captive MFA
 * page (the user has an MFA method) or the mandatory MFA setup page (the user is in a group that must have
 * one). Both are observable from outside as a 307 to com_users' captive / methods view.
 *
 * Two Joomla options shape that gate for logins like Skeleton Key's, which reports itself as a "Cookie"
 * (remember-me style) login: com_users' "MFA on silent login" (`mfaonsilent`, default No) and the list of
 * response types counted as silent. These tests set them explicitly rather than trusting the defaults.
 *
 * @since 1.2.6
 */
class MfaTest extends AbstractE2ETestCase
{
	public function testWithMfaOnSilentLoginTheCaptivePageIsShownWithoutTheBypass(): void
	{
		$this->setUsersParams(['mfaonsilent' => 1]);

		$browser = $this->superUser();

		$this->assertRedirectsTo('view=captive', $this->landOnFrontend($browser, 'mfauser'));
	}

	public function testWithMfaOnSilentLoginTheBypassSkipsTheCaptivePage(): void
	{
		$this->setUsersParams(['mfaonsilent' => 1]);
		$this->setSystemParams(['bypass_mfa' => 1]);

		$browser  = $this->superUser();
		$response = $this->landOnFrontend($browser, 'mfauser');

		$this->assertNotMfaRedirect($response);
		$this->assertFrontendUser('mfauser', $browser);
	}

	public function testWithMfaOnSilentLoginMandatoryMfaSetupIsEnforcedWithoutTheBypass(): void
	{
		$this->setUsersParams(['mfaonsilent' => 1, 'forceMFAUserGroups' => [SiteProvisioner::GROUPS['registered']]]);

		$this->assertRedirectsTo('view=methods', $this->landOnFrontend($this->superUser(), 'alice'));
	}

	public function testWithMfaOnSilentLoginTheBypassSkipsMandatoryMfaSetup(): void
	{
		$this->setUsersParams(['mfaonsilent' => 1, 'forceMFAUserGroups' => [SiteProvisioner::GROUPS['registered']]]);
		$this->setSystemParams(['bypass_mfa' => 1]);

		$browser = $this->superUser();

		$this->assertNotMfaRedirect($this->landOnFrontend($browser, 'alice'));
		$this->assertFrontendUser('alice', $browser);
	}

	public function testTheBypassDoesNotLeakIntoTheTargetsOwnLogins(): void
	{
		$this->setUsersParams(['mfaonsilent' => 1]);
		$this->setSystemParams(['bypass_mfa' => 1]);

		// Impersonate once, with the bypass…
		$this->landOnFrontend($this->superUser(), 'mfauser');

		// …then the user logs in normally, in their own browser: MFA must still be demanded.
		$own = $this->newBrowser();
		$this->session->loginFrontend($own, static::$fixtures->username('mfauser'), static::$config->getUserPassword());

		$this->assertRedirectsTo('view=captive', $own->get('index.php'));
	}

	public function testWithJoomlasDefaultsMfaStillAppliesWhenTheBypassIsOff(): void
	{
		// Joomla's defaults: mfaonsilent = No, and "cookie" counts as a silent login. With "Bypass MFA" off the
		// impersonated session must go through MFA, so Skeleton Key must not report its logins as "Cookie".
		$this->setUsersParams(['mfaonsilent' => 0, 'silentresponses' => 'cookie, passwordless']);

		$browser  = $this->superUser();
		$response = $this->landOnFrontend($browser, 'mfauser');

		$this->assertTrue($this->isMfaRedirect($response, 'view=captive'), 'MFA was skipped with the bypass off');
	}

	public function testWithJoomlasDefaultsTheBypassSkipsMfa(): void
	{
		$this->setUsersParams(['mfaonsilent' => 0, 'silentresponses' => 'cookie, passwordless']);
		$this->setSystemParams(['bypass_mfa' => 1]);

		$browser  = $this->superUser();
		$response = $this->landOnFrontend($browser, 'mfauser');

		$this->assertNotMfaRedirect($response);
		$this->assertFrontendUser('mfauser', $browser);
	}

	/**
	 * Impersonate a user and return the first front-end response the impersonated session gets.
	 *
	 * The key is consumed by the first front-end request; Joomla's MFA gate may act on that same request or
	 * on the next one, so the second page load is what is returned when the first was not already a redirect.
	 *
	 * @param   Surfer  $browser  A back-end browser entitled to ask.
	 * @param   string  $role     The target.
	 *
	 * @return  Response
	 */
	private function landOnFrontend(Surfer $browser, string $role): Response
	{
		$first = $this->logInAs($browser, $role);

		$this->assertNotServerError($first);

		if ($this->isMfaRedirect($first))
		{
			return $first;
		}

		return $browser->get('index.php');
	}

	private function isMfaRedirect(Response $response, ?string $view = null): bool
	{
		if (!$response->isRedirect())
		{
			return false;
		}

		$location = (string) $response->getLocation();

		if ($view !== null)
		{
			return str_contains($location, 'option=com_users') && str_contains($location, $view);
		}

		return str_contains($location, 'view=captive') || str_contains($location, 'view=methods');
	}

	private function assertRedirectsTo(string $view, Response $response): void
	{
		$this->assertTrue($this->isMfaRedirect($response, $view), sprintf("Expected a redirect to %s.\n%s", $view, $response->summary()));
	}

	private function assertNotMfaRedirect(Response $response): void
	{
		$this->assertFalse($this->isMfaRedirect($response), "The impersonated session was sent to an MFA page.\n" . $response->summary());
	}
}
