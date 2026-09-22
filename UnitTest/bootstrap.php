<?php
/**
 * @package   Skeletonkey
 * @copyright Copyright (c)2022-2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

// Required for classes which guard against direct web access.
define('_JEXEC', 1);

/**
 * PSR-4 autoloading for the code under test and the tests themselves.
 *
 * Not Composer's: the project has no Composer dependencies, vendor/ is not committed, and `composer install`
 * must not be a prerequisite of running the unit tests.
 */
spl_autoload_register(
	static function (string $class): void {
		$prefixes = [
			'Joomla\\Plugin\\System\\Skeletonkey\\'         => __DIR__ . '/../plugins/system/skeletonkey/src/',
			'Joomla\\Plugin\\Authentication\\Skeletonkey\\' => __DIR__ . '/../plugins/authentication/skeletonkey/src/',
			'Akeeba\\SkeletonKey\\UnitTest\\'                 => __DIR__ . '/',
		];

		foreach ($prefixes as $prefix => $dir)
		{
			if (strncmp($class, $prefix, strlen($prefix)) !== 0)
			{
				continue;
			}

			$file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

			if (is_file($file))
			{
				require_once $file;
			}

			return;
		}
	}
);

/**
 * Stand-ins for the two Joomla Framework interfaces Helper\DbQuery is typed against. See the file for why
 * empty interfaces are enough.
 */
require_once __DIR__ . '/Stubs/Database.php';

// Enable verbose error and notices
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Set the timezone to UTC to avoid surprises.
date_default_timezone_set('UTC');
