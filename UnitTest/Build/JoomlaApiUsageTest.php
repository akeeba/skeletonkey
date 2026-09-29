<?php
/**
 * @package   Skeletonkey
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\SkeletonKey\UnitTest\Build;

use PHPUnit\Framework\TestCase;

/**
 * How the plugins call Joomla, given the supported floor (Joomla 5.4).
 *
 * @since 1.2.6
 */
class JoomlaApiUsageTest extends TestCase
{
	public function testCookiesAreSetWithTheOptionsArrayFormOnly(): void
	{
		// Joomla 5.4's Cookie::set() (joomla/input 3.x) already takes the options array; the positional signature
		// is deprecated. There is nothing to fork on.
		foreach (['system', 'authentication'] as $plugin)
		{
			$code = $this->extensionCode($plugin);

			$this->assertStringNotContainsString("5.999.999", $code, sprintf('%s still forks on the Joomla version.', $plugin));
			$this->assertDoesNotMatchRegularExpression(
				'/cookie->set\(\s*\$cookieName,\s*[^\[]*?,\s*(\$future|1),/s',
				$code,
				sprintf('%s still uses the deprecated positional Cookie::set() signature.', $plugin)
			);
		}
	}

	public function testThePluginConstructorTakesTheConfigArrayLikeCmsPlugin(): void
	{
		// The service provider calls `new Skeletonkey($config)`. CMSPlugin::__construct($config = []) has had that
		// shape since before Joomla 5.4; a by-reference `$subject` first parameter is the pre-Joomla 4 shape.
		$code = $this->extensionCode('system');

		$this->assertStringNotContainsString('&$subject', $code);
		$this->assertMatchesRegularExpression('/function __construct\(\$config = \[\]\)/', $code);
	}

	private function extensionCode(string $plugin): string
	{
		return (string) file_get_contents(
			dirname(__DIR__, 2) . sprintf('/plugins/%s/skeletonkey/src/Extension/Skeletonkey.php', $plugin)
		);
	}
}
