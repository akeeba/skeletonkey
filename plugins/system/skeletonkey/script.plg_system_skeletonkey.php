<?php
/*
 * @package   Skeletonkey
 * @copyright Copyright (c)2022-2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

\defined('_JEXEC') || die;

use Joomla\CMS\Installer\InstallerScript;
use Joomla\CMS\Log\Log;

class plgSystemSkeletonkeyInstallerScript extends InstallerScript
{
	protected $minimumPhp = '8.1.0';

	protected $maximumPhp = '8.7';

	protected $minimumJoomla = '5.4.0';

	protected $maximumJoomla = '6.3';

	protected $allowDowngrades = true;

	public function preflight($type, $parent)
	{
		if (!parent::preflight($type, $parent))
		{
			return false;
		}

		// Check for the maximum PHP version before continuing
		$maxPhp = !empty($this->maximumPhp) ? trim($this->maximumPhp) : null;

		if (!empty($maxPhp) && version_compare(PHP_VERSION, $maxPhp, 'ge'))
		{
			Log::add(
				sprintf(
					'This extension supports PHP versions lower than %s. Your server has a newer PHP version (%s) which has not been tested with it. The installation cannot proceed.',
					$maxPhp, PHP_VERSION
				),
				Log::WARNING,
				'jerror'
			);

			return false;
		}

		// Check for the maximum Joomla version before continuing
		$maxJoomla = !empty($this->maximumJoomla) ? trim($this->maximumJoomla) : null;

		if (!empty($maxJoomla) && version_compare(JVERSION, $maxJoomla, 'ge'))
		{
			Log::add(
				sprintf(
					'This extension supports Joomla! versions lower than %s. Your site has a newer Joomla! version (%s) which has not been tested with it. The installation cannot proceed.',
					$maxJoomla, JVERSION
				),
				Log::WARNING,
				'jerror'
			);

			return false;
		}

		return true;
	}
}
