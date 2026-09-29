<?php
/*
 * @package   Skeletonkey
 * @copyright Copyright (c)2022-2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

\defined('_JEXEC') || die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Installation script of the Skeleton Key package.
 */
class Pkg_SkeletonkeyInstallerScript
{
	/**
	 * Enable the three plugins after a fresh installation.
	 *
	 * Joomla installs plugins disabled, and Skeleton Key does nothing until all three are enabled. On an update the
	 * plugins are left alone: we cannot know whether the site owner disabled one on purpose.
	 *
	 * @param   string  $type    The installation type: install, update, discover_install…
	 * @param   mixed   $parent  The installer adapter
	 *
	 * @return  bool
	 */
	public function postflight($type, $parent): bool
	{
		if ($type !== 'install')
		{
			return true;
		}

		/** @var DatabaseInterface $db */
		$db      = Factory::getContainer()->get(DatabaseInterface::class);
		$folders = ['authentication', 'system', 'actionlog'];
		$element = 'skeletonkey';
		$query   = $db->createQuery()
			->update($db->quoteName('#__extensions'))
			->set($db->quoteName('enabled') . ' = 1')
			->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
			->where($db->quoteName('element') . ' = :element')
			->whereIn($db->quoteName('folder'), $folders, ParameterType::STRING)
			->bind(':element', $element);

		try
		{
			$db->setQuery($query)->execute();
		}
		catch (\Exception $e)
		{
			// Not fatal: the plugins are installed, the site owner can enable them by hand.
		}

		return true;
	}
}
