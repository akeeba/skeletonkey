<?php
/**
 * @package   Skeletonkey
 * @copyright Copyright (c)2022-2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

defined('_JEXEC') or die;

/**
 * Defaults for the end-to-end suite.
 *
 * docker/run.sh writes a config.php next to this file with the values it actually provisioned; this
 * template is what the suite falls back to when that file is absent. Keep it in step with
 * docker/env.dist — between the two, a fresh clone can run the suite without configuring anything.
 *
 * To point the suite at a site you provisioned some other way, copy this file to config.php (which
 * is git-ignored) and edit it.
 */
return [
	'site'          => [
		// The Apache front-end, as seen from the host running PHPUnit.
		'url'  => 'http://localhost:8200',
		'root' => __DIR__ . '/docker/www',
	],
	'db'            => [
		'host'   => '127.0.0.1',
		'port'   => 33320,
		'name'   => 'ske2e',
		'user'   => 'ske2e',
		'pass'   => 'ske2e',
		'prefix' => 'e2e_',
	],
	'docker'        => [
		'composeBin'  => 'docker compose',
		'composeFile' => __DIR__ . '/docker/docker-compose.yml',
		'phpService'  => 'php',
	],
	'users'         => [
		'adminUsername' => 'admin',
		'adminPassword' => 'test',
		'adminEmail'    => 'admin@example.test',
		'password'      => 'test',
	],
	'site_name'     => 'Skeleton Key End-to-End',
	// Overwritten by run.sh with the versions it actually resolved and installed.
	'joomlaVersion' => '0.0.0',
	'phpVersion'    => '0.0',
];
