<?php
/*
 * @package   Skeletonkey
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

defined('_JEXEC') || die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Plugin\Authentication\Skeletonkey\Extension\Skeletonkey;

return new class implements ServiceProviderInterface {
	/**
	 * Registers the service provider with a DI container.
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  void
	 *
	 * @since   1.0.0
	 */
	public function register(Container $container)
	{
		// Enforce minimum / maximum PHP and Joomla versions.
		$minimumPhp    = '8.1.0';
		$maximumPhp    = '8.7';
		$minimumJoomla = '5.4.0';
		$maximumJoomla = '6.3';

		if (
			version_compare(PHP_VERSION, $minimumPhp, 'lt')
			|| version_compare(PHP_VERSION, $maximumPhp, 'ge')
			|| version_compare(JVERSION, $minimumJoomla, 'lt')
			|| version_compare(JVERSION, $maximumJoomla, 'ge')
		)
		{
			return;
		}

		$container->set(
			PluginInterface::class,
			function (Container $container) {
				$config = (array) PluginHelper::getPlugin('authentication', 'skeletonkey');
				$plugin = new Skeletonkey($config);

				$plugin->setApplication(Factory::getApplication());

				if ($plugin instanceof DatabaseAwareInterface)
				{
					$plugin->setDatabase($container->get(DatabaseInterface::class));
				}

				return $plugin;
			}
		);
	}
};
