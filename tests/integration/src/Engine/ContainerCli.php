<?php
/**
 * @package   Skeletonkey
 * @copyright Copyright (c)2022-2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\SkeletonKey\IntegrationTest\Engine;

defined('_JEXEC') or die;

use RuntimeException;

/**
 * Runs a command inside the site's php container.
 *
 * Used for what is not reachable over HTTP at all: Joomla's own console application, e.g. to clean
 * the cache after the suite changed the component's parameters directly in the database.
 *
 * @since 1.2.6
 */
class ContainerCli
{
	/**
	 * The suite configuration.
	 *
	 * @var   Configuration
	 * @since 1.2.6
	 */
	private Configuration $config;

	/**
	 * Constructor.
	 *
	 * @param   Configuration|null  $config  The suite configuration.
	 *
	 * @since   1.2.6
	 */
	public function __construct(?Configuration $config = null)
	{
		$this->config = $config ?? Configuration::getInstance();
	}

	/**
	 * Run Joomla's console application inside the container.
	 *
	 * @param   string[]  $arguments  Arguments after `cli/joomla.php`, e.g. ['cache:clean'].
	 *
	 * @return  array{0: int, 1: string}  Exit code and combined output.
	 * @since   1.2.6
	 */
	public function joomla(array $arguments): array
	{
		return $this->run(array_merge(['php', 'cli/joomla.php'], $arguments));
	}

	/**
	 * Run Joomla's console application, failing loudly on a non-zero exit.
	 *
	 * @param   string[]  $arguments  Arguments after `cli/joomla.php`.
	 *
	 * @return  string  The combined output.
	 * @throws  RuntimeException  When the command fails.
	 * @since   1.2.6
	 */
	public function joomlaOrFail(array $arguments): string
	{
		[$exitCode, $output] = $this->joomla($arguments);

		if ($exitCode !== 0)
		{
			throw new RuntimeException(
				sprintf("`cli/joomla.php %s` failed (exit %d):\n%s", implode(' ', $arguments), $exitCode, $output)
			);
		}

		return $output;
	}

	/**
	 * Run an arbitrary command inside the php container.
	 *
	 * @param   string[]  $command  The command and its arguments.
	 *
	 * @return  array{0: int, 1: string}  Exit code and combined output.
	 * @since   1.2.6
	 */
	public function run(array $command): array
	{
		$docker = $this->config->getDocker();

		return $this->compose(
			array_merge(['exec', '-T', '-w', '/var/www/html', $docker['php']], $command)
		);
	}


	/**
	 * Run a docker compose sub-command against the stack.
	 *
	 * @param   string[]  $arguments  Everything after `docker compose -f <file>`.
	 *
	 * @return  array{0: int, 1: string}  Exit code and combined output.
	 * @since   1.2.6
	 */
	private function compose(array $arguments): array
	{
		$docker = $this->config->getDocker();

		$parts = array_merge(
			// composeBin may be "docker compose" (two words) or "docker-compose".
			explode(' ', $docker['bin']),
			['-f', $docker['file']],
			$arguments
		);

		$escaped = implode(' ', array_map('escapeshellarg', $parts)) . ' 2>&1';

		exec($escaped, $output, $exitCode);

		return [$exitCode, implode("\n", $output)];
	}
}
