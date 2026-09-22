<?php
/**
 * @package   Skeletonkey
 * @copyright Copyright (c)2022-2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\SkeletonKey\IntegrationTest;

defined('_JEXEC') or die;

use Akeeba\SkeletonKey\IntegrationTest\Engine\Configuration;
use RuntimeException;

/**
 * Deploys and locates the identity probe.
 *
 * @see assets/e2e-probe.php for what the probe does and why it exists.
 *
 * @since 1.2.6
 */
abstract class SiteProbe
{
	/**
	 * Where the probe lives, relative to the site root.
	 *
	 * @since 1.2.6
	 */
	public const ENDPOINT = 'e2e-probe.php';

	/**
	 * Write the probe into the provisioned site's document root.
	 *
	 * @param   Configuration  $config  The suite configuration.
	 *
	 * @return  void
	 * @since   1.2.6
	 */
	public static function deploy(Configuration $config): void
	{
		$source = \dirname(__DIR__) . '/assets/e2e-probe.php';
		$target = rtrim($config->getSiteRoot(), '/') . '/' . self::ENDPOINT;

		if (!is_file($source))
		{
			throw new RuntimeException(sprintf('The probe source %s is missing.', $source));
		}

		$code = file_get_contents($source);

		if ($code === false)
		{
			throw new RuntimeException(sprintf('Could not read the probe source %s.', $source));
		}

		$code = str_replace('##SECRET##', $config->getProbeSecret(), $code);

		if (file_put_contents($target, $code) === false)
		{
			throw new RuntimeException(sprintf('Could not write the probe to %s.', $target));
		}
	}

	/**
	 * Remove the probe from the site.
	 *
	 * @param   Configuration  $config  The suite configuration.
	 *
	 * @return  void
	 * @since   1.2.6
	 */
	public static function remove(Configuration $config): void
	{
		$target = rtrim($config->getSiteRoot(), '/') . '/' . self::ENDPOINT;

		if (is_file($target))
		{
			@unlink($target);
		}
	}
}
