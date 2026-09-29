<?php
/**
 * @package   Skeletonkey
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\SkeletonKey\IntegrationTest\Tests;

defined('_JEXEC') or die;

use Akeeba\SkeletonKey\IntegrationTest\AbstractE2ETestCase;

/**
 * Installing the package over an existing installation.
 *
 * A fresh installation enables the three plugins (checked by docker/run.sh straight after installing, before the
 * fixtures are provisioned). An update must leave them as the site owner set them.
 *
 * @since 1.2.6
 */
class InstallTest extends AbstractE2ETestCase
{
	public function testUpdatingThePackageKeepsAPluginTheOwnerDisabled(): void
	{
		$this->setPluginEnabled('system', false);

		$this->installNewestPackage();

		$this->assertSame(
			0,
			(int) $this->db()->value("SELECT enabled FROM #__extensions WHERE type = 'plugin' AND folder = 'system' AND element = 'skeletonkey'"),
			'Updating the package re-enabled a plugin the site owner had disabled.'
		);
	}

	private function installNewestPackage(): void
	{
		$packages = glob(dirname(__DIR__, 4) . '/release/pkg_skeletonkey-*.zip') ?: [];

		$this->assertNotEmpty($packages, 'There is no built package in release/.');

		usort($packages, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

		$target = static::$config->getSiteRoot() . '/e2e-install-package.zip';

		copy($packages[0], $target);

		try
		{
			$this->cli()->joomlaOrFail(['extension:install', '--path=/var/www/html/e2e-install-package.zip']);
		}
		finally
		{
			@unlink($target);
		}
	}
}
