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
 * Proves the harness itself before anything else relies on it.
 *
 * A refusal test that passes because the fixture is broken — the target is not in the group we think, the
 * probe reports everyone as a guest, the plugin is not even enabled — is worse than no test. These pin the
 * things every other test takes for granted.
 *
 * @since 1.2.6
 */
class HarnessTest extends AbstractE2ETestCase
{
	public function testTheThreePluginsAreInstalledAndEnabled(): void
	{
		foreach (['system', 'authentication', 'actionlog'] as $folder)
		{
			$enabled = $this->db()->value(
				"SELECT enabled FROM #__extensions WHERE type = 'plugin' AND folder = ? AND element = 'skeletonkey'",
				[$folder]
			);

			$this->assertSame(1, (int) $enabled, sprintf('plg_%s_skeletonkey is not installed and enabled.', $folder));
		}
	}

	public function testTheProvisionedJoomlaVersionIsTheOneConfigured(): void
	{
		$identity = $this->session->probeIdentity($this->newBrowser());

		$this->assertIsArray($identity, 'The identity probe did not answer.');
		$this->assertSame(static::$config->getJoomlaVersion(), $identity['joomla']);
	}

	public function testTheCoreGroupIdsAreTheOnesTheFixturesAssume(): void
	{
		$titles = [
			'public'        => 'Public',
			'registered'    => 'Registered',
			'author'        => 'Author',
			'manager'       => 'Manager',
			'administrator' => 'Administrator',
			'super'         => 'Super Users',
			'guest'         => 'Guest',
		];

		foreach (SiteProvisioner::GROUPS as $key => $id)
		{
			$this->assertSame(
				$titles[$key],
				$this->db()->value('SELECT title FROM #__usergroups WHERE id = ?', [$id]),
				sprintf('Group %d is not "%s".', $id, $titles[$key])
			);
		}
	}

	public function testTheProbeSeesAPasswordLoginAndAGuest(): void
	{
		$this->assertFrontendGuest($this->newBrowser());
		$this->assertFrontendUser('bob', $this->frontendAs('bob'));
	}

	public function testTheFixtureAccountsSitWhereTheTestsExpect(): void
	{
		// Group membership as Joomla computes it, inheritance included.
		$expectations = [
			'alice'         => [SiteProvisioner::GROUPS['registered']],
			'author'        => [SiteProvisioner::GROUPS['registered'], SiteProvisioner::GROUPS['author']],
			'manager'       => [SiteProvisioner::GROUPS['manager']],
			'administrator' => [SiteProvisioner::GROUPS['manager'], SiteProvisioner::GROUPS['administrator']],
		];

		foreach ($expectations as $role => $mustInclude)
		{
			$identity = $this->session->probeIdentity($this->frontendAs($role));

			$this->assertIsArray($identity);

			foreach ($mustInclude as $groupId)
			{
				$this->assertContains($groupId, $identity['groups'], sprintf("'%s' is not in group %d.", $role, $groupId));
			}
		}

		// The accounts whose position in the tree the default configuration hinges on.
		foreach (['manager', 'administrator'] as $role)
		{
			$this->assertNotContains(
				SiteProvisioner::GROUPS['registered'],
				$this->session->probeIdentity($this->frontendAs($role))['groups'],
				sprintf("'%s' unexpectedly inherits from Registered.", $role)
			);
		}

		// Members of only the Guest group may not log in at all (no core.login.site), so ask the database.
		$this->assertSame(
			[SiteProvisioner::GROUPS['guest']],
			array_map('intval', $this->db()->column(
				'SELECT group_id FROM #__user_usergroup_map WHERE user_id = ?',
				[static::$fixtures->userId('guestonly')]
			))
		);

		$this->assertTrue($this->session->isLoggedInBackend($this->superUser()));
		$this->assertTrue($this->session->isLoggedInBackend($this->backendAs('manager')));
	}

	public function testTheUserKeysTableStartsEmpty(): void
	{
		$this->assertSame([], $this->keyRows());
	}
}
