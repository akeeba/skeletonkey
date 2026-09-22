<?php
/**
 * @package   Skeletonkey
 * @copyright Copyright (c)2022-2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\SkeletonKey\UnitTest\Build;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The supported PHP and Joomla ranges, declared once in composer.json and repeated in each plugin's
 * installer script, must say the same thing everywhere. The E2E harness reads its bounds from the system
 * plugin's script, so drift here would also silently change what the matrix accepts.
 *
 * @since 1.2.6
 */
class VersionLimitsTest extends TestCase
{
	/**
	 * @return  array<string, array{0: string}>
	 */
	public static function installerScripts(): array
	{
		$scripts = [];

		foreach (['system', 'authentication', 'actionlog'] as $plugin)
		{
			$scripts[$plugin] = [self::root() . sprintf('/plugins/%1$s/skeletonkey/script.plg_%1$s_skeletonkey.php', $plugin)];
		}

		return $scripts;
	}

	#[DataProvider('installerScripts')]
	public function testTheInstallerScriptAgreesWithComposerJson(string $script): void
	{
		$composer = json_decode((string) file_get_contents(self::root() . '/composer.json'), true);

		[$minPhp, $maxPhp]       = $this->range($composer['require']['php']);
		[$minJoomla, $maxJoomla] = $this->range($composer['extra']['akcompat']['limit']);

		$this->assertSame($minPhp, $this->declared($script, 'minimumPhp'));
		$this->assertSame($maxPhp, $this->declared($script, 'maximumPhp'));
		$this->assertSame($minJoomla, $this->declared($script, 'minimumJoomla'));
		$this->assertSame($maxJoomla, $this->declared($script, 'maximumJoomla'));
	}

	#[DataProvider('installerScripts')]
	public function testTheInstallerScriptEnforcesBothMaximums(string $script): void
	{
		// Joomla's InstallerScript enforces the minimums itself; the maximums are this project's own code.
		$code = (string) file_get_contents($script);

		$this->assertMatchesRegularExpression('/version_compare\(PHP_VERSION,\s*\$maxPhp,\s*\'ge\'\)/', $code);
		$this->assertMatchesRegularExpression('/version_compare\(JVERSION,\s*\$maxJoomla,\s*\'ge\'\)/', $code);
		$this->assertStringContainsString('parent::preflight($type, $parent)', $code);
	}

	public function testThePackageManifestInstallsEveryPlugin(): void
	{
		$manifest = simplexml_load_file(self::root() . '/build/templates/pkg_skeletonkey.xml');
		$plugins  = [];

		foreach ($manifest->files->file as $file)
		{
			$plugins[] = (string) $file['group'] . '/' . (string) $file['id'];
		}

		sort($plugins);

		$this->assertSame(['actionlog/skeletonkey', 'authentication/skeletonkey', 'system/skeletonkey'], $plugins);
	}

	/**
	 * A ">=A <B" constraint as [A, B].
	 *
	 * @param   string  $constraint  The constraint.
	 *
	 * @return  array{0: string, 1: string}
	 */
	private function range(string $constraint): array
	{
		$this->assertMatchesRegularExpression('/^>=\s*([0-9.]+)\s+<\s*([0-9.]+)$/', trim($constraint));
		preg_match('/^>=\s*([0-9.]+)\s+<\s*([0-9.]+)$/', trim($constraint), $match);

		return [$match[1], $match[2]];
	}

	private function declared(string $script, string $property): string
	{
		$code = (string) file_get_contents($script);

		$this->assertMatchesRegularExpression('/\$' . $property . '\s*=\s*\'([0-9.]+)\'/', $code, sprintf('%s is not declared.', $property));
		preg_match('/\$' . $property . '\s*=\s*\'([0-9.]+)\'/', $code, $match);

		return $match[1];
	}

	private static function root(): string
	{
		return \dirname(__DIR__, 2);
	}
}
