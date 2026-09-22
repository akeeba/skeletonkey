<?php
/**
 * @package   Skeletonkey
 * @copyright Copyright (c)2022-2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\SkeletonKey\IntegrationTest\Tests;

defined('_JEXEC') or die;

use Akeeba\SkeletonKey\IntegrationTest\AbstractE2ETestCase;
use Akeeba\SkeletonKey\IntegrationTest\SiteProvisioner;

/**
 * The system plugin's options really change what happens.
 *
 * Each test flips one option and proves the effect over HTTP, in both directions where it matters: what the
 * option now allows, and what it still forbids.
 *
 * @since 1.2.6
 */
class ConfigurationTest extends AbstractE2ETestCase
{
	public function testAddingAControlGroupLetsItsMembersUseSkeletonKey(): void
	{
		$this->setSystemParams(['allowedControlGroups' => [SiteProvisioner::GROUPS['super'], SiteProvisioner::GROUPS['manager']]]);

		// A Manager has no access to the Users list by default, so there is no button to click; the plugin
		// still honours the configuration at the endpoint the button calls.
		$browser = $this->backendAs('manager');

		$this->logInAs($browser, 'alice');

		$this->assertFrontendUser('alice', $browser);
	}

	public function testANewControllerStillCannotTargetDisallowedGroups(): void
	{
		$this->setSystemParams(['allowedControlGroups' => [SiteProvisioner::GROUPS['manager']]]);

		$browser = $this->backendAs('manager');

		foreach (['administrator', 'admin', 'super2'] as $role)
		{
			$this->assertKeyRefused($browser, $this->requestKey($browser, static::$fixtures->userId($role)), $role);
		}
	}

	public function testRemovingTheSuperUsersFromTheControlGroupsLocksThemOut(): void
	{
		$this->setSystemParams(['allowedControlGroups' => [SiteProvisioner::GROUPS['manager']]]);

		$browser = $this->superUser();

		$this->assertNull($this->loginButtonUserIds($this->usersPage($browser)));
		$this->assertKeyRefused($browser, $this->requestKey($browser, static::$fixtures->userId('alice')));
	}

	public function testNarrowingTheTargetGroups(): void
	{
		$this->setSystemParams(['allowedTargetGroups' => [SiteProvisioner::GROUPS['author']]]);

		$browser = $this->superUser();

		$this->assertSame([static::$fixtures->userId('author')], $this->loginButtonUserIds($this->usersPage($browser)));
		$this->assertKeyRefused($browser, $this->requestKey($browser, static::$fixtures->userId('alice')), 'alice is no longer a target');

		$this->logInAs($browser, 'author');
		$this->assertFrontendUser('author', $browser);
	}

	public function testDisallowedGroupsWinOverAllowedOnes(): void
	{
		// author is in Registered (allowed) AND Author (now disallowed).
		$this->setSystemParams(['disallowedTargetGroups' => [SiteProvisioner::GROUPS['author'], 7, 8]]);

		$browser = $this->superUser();

		$this->assertNotContains(static::$fixtures->userId('author'), $this->loginButtonUserIds($this->usersPage($browser)));
		$this->assertKeyRefused($browser, $this->requestKey($browser, static::$fixtures->userId('author')));
	}

	public function testTheParametersAsFirstWrittenByTheInstallerAreHonoured(): void
	{
		// Until the options are saved once through the form, the installer's manifest defaults are stored as
		// comma-separated strings, not arrays.
		$this->replaceSystemParams([
			'allowedControlGroups'   => '8',
			'allowedTargetGroups'    => '2',
			'disallowedTargetGroups' => '7,8',
			'cookie_lifetime'        => '10',
			'key_length'             => '32',
			'bypass_mfa'             => '0',
		]);

		$browser = $this->superUser();

		$this->assertKeyRefused($browser, $this->requestKey($browser, static::$fixtures->userId('administrator')));

		$this->logInAs($browser, 'alice');
		$this->assertFrontendUser('alice', $browser);
	}

	public function testNoParametersAtAllFallsBackToTheSafeDefaults(): void
	{
		$this->replaceSystemParams([]);

		$browser = $this->superUser();

		$this->assertKeyRefused($browser, $this->requestKey($browser, static::$fixtures->userId('super2')));
		$this->assertKeyRefused($this->backendAs('manager'), $this->requestKey($this->backendAs('manager'), static::$fixtures->userId('alice')));

		$this->logInAs($browser, 'alice');
		$this->assertFrontendUser('alice', $browser);
	}

	public function testKeyLengthIsHonoured(): void
	{
		$this->setSystemParams(['key_length' => 64]);

		$browser = $this->superUser();
		$this->assertTrue($this->keyIssued($browser, $this->requestKey($browser, static::$fixtures->userId('alice'))));

		[$token] = explode('.', current($this->keyCookies($browser)));

		$this->assertSame(64, strlen($token));

		$this->visitFrontend($browser);
		$this->assertFrontendUser('alice', $browser);
	}

	public function testCookieLifetimeIsHonoured(): void
	{
		$this->setSystemParams(['cookie_lifetime' => 120]);

		$browser = $this->superUser();
		$before  = time();

		$this->assertTrue($this->keyIssued($browser, $this->requestKey($browser, static::$fixtures->userId('alice'))));

		$time = (int) $this->keyRows('alice')[0]['time'];

		$this->assertGreaterThanOrEqual($before + 120, $time);
		$this->assertLessThanOrEqual(time() + 120, $time);
	}

	public function testAShortLifetimeReallyExpires(): void
	{
		$this->setSystemParams(['cookie_lifetime' => 1]);

		$browser = $this->superUser();
		$this->assertTrue($this->keyIssued($browser, $this->requestKey($browser, static::$fixtures->userId('alice'))));

		[$name, $value] = [array_key_first($this->keyCookies($browser)), current($this->keyCookies($browser))];

		sleep(3);

		// The browser dropped the expired cookie on its own; put it back, as an attacker replaying it would.
		$browser->setCookie($name, $value);

		$this->visitFrontend($browser);

		$this->assertFrontendGuest($browser);
	}
}
