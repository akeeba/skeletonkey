<?php
/**
 * @package   Skeletonkey
 * @copyright Copyright (c)2022-2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

/**
 * Provision the Skeleton Key fixtures against an already-running stack.
 *
 * docker/run.sh calls this after installing the package. You can also run it by hand against a
 * stack left up with --keep-containers, to put the fixtures back the way they started:
 *
 *     php tests/integration/provision.php
 */

require_once __DIR__ . '/autoload.php';

use Akeeba\SkeletonKey\IntegrationTest\Engine\Configuration;
use Akeeba\SkeletonKey\IntegrationTest\SiteProvisioner;

$config = Configuration::getInstance();

fwrite(STDOUT, sprintf("Provisioning Skeleton Key fixtures (config: %s)\n", $config->getSourceFile()));

try
{
	$manifest = (new SiteProvisioner($config))->provision();
}
catch (Throwable $e)
{
	fwrite(STDERR, $e->getMessage() . "\n");

	exit(1);
}

fwrite(STDOUT, sprintf("  %d users, 3 plugins enabled\n", count($manifest['users'] ?? [])));

exit(0);
