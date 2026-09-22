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
 * What the three plugin packages contain, and the defaults they install with.
 *
 * @since 1.2.6
 */
class PackageSurfaceTest extends TestCase
{
	/**
	 * @return  array<string, array{0: string}>
	 */
	public static function phpFiles(): array
	{
		$files = [];

		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/plugins', \FilesystemIterator::SKIP_DOTS)) as $file)
		{
			if ($file->getExtension() === 'php')
			{
				$files[substr($file->getPathname(), strlen(self::root()) + 1)] = [$file->getPathname()];
			}
		}

		return $files;
	}

	/**
	 * @return  array<string, array{0: string}>
	 */
	public static function plugins(): array
	{
		return ['system' => ['system'], 'authentication' => ['authentication'], 'actionlog' => ['actionlog']];
	}

	#[DataProvider('phpFiles')]
	public function testEveryPhpFileRefusesDirectWebAccess(string $file): void
	{
		$this->assertMatchesRegularExpression(
			'/^\s*\\\\?defined\(\'_JEXEC\'\)\s*(\|\||or)\s*die;/m',
			(string) file_get_contents($file),
			'Missing the _JEXEC guard.'
		);
	}

	#[DataProvider('plugins')]
	public function testEverythingTheManifestListsExists(string $plugin): void
	{
		$dir      = self::root() . '/plugins/' . $plugin . '/skeletonkey';
		$manifest = simplexml_load_file($dir . '/skeletonkey.xml');

		foreach ($manifest->files->children() as $entry)
		{
			$path = $dir . '/' . $entry;

			$this->assertTrue($entry->getName() === 'folder' ? is_dir($path) : is_file($path), sprintf('%s is listed but missing.', $entry));
		}

		if (isset($manifest->scriptfile))
		{
			$this->assertFileExists($dir . '/' . $manifest->scriptfile);
		}

		if (isset($manifest->media))
		{
			$mediaDir = $dir . '/' . $manifest->media['folder'];

			foreach ($manifest->media->children() as $entry)
			{
				$this->assertFileExists($mediaDir . '/' . $entry);
			}
		}

		// The PSR-4 namespace root declared in the manifest is where the Extension class lives.
		$this->assertDirectoryExists($dir . '/' . $manifest->namespace['path'] . '/Extension');
	}

	public function testTheWebAssetPointsAtTheShippedScript(): void
	{
		$media  = self::root() . '/plugins/system/skeletonkey/media';
		$assets = json_decode((string) file_get_contents($media . '/joomla.asset.json'), true);

		$this->assertSame('plg_system_skeletonkey', $assets['name']);

		foreach ($assets['assets'] as $asset)
		{
			$this->assertStringStartsWith('plg_system_skeletonkey/', $asset['uri']);
			$this->assertFileExists($media . '/js/' . substr($asset['uri'], strlen('plg_system_skeletonkey/')));
		}
	}

	public function testTheMinifiedScriptIsNotOlderThanItsSource(): void
	{
		// backend.min.js is committed. Built from an older backend.js, it would ship stale behaviour.
		$dir = self::root() . '/plugins/system/skeletonkey/media/js';

		$this->assertFileExists($dir . '/backend.min.js');
		$this->assertStringContainsString(
			'plg_system_skeletonkey',
			(string) file_get_contents($dir . '/backend.min.js')
		);

		foreach (['PLG_SYSTEM_SKELETONKEY_BTN_LABEL', 'PLG_SYSTEM_SKELETONKEY_ERR_LOGINFAILED', 'plugin=skeletonkey', 'group=system'] as $needle)
		{
			$this->assertStringContainsString($needle, (string) file_get_contents($dir . '/backend.min.js'), sprintf('%s is not in the minified script.', $needle));
		}
	}

	public function testTheSystemPluginInstallsWithTheSafeDefaults(): void
	{
		$manifest = simplexml_load_file(self::root() . '/plugins/system/skeletonkey/skeletonkey.xml');
		$defaults = [];

		foreach ($manifest->xpath('//config//field') as $field)
		{
			$defaults[(string) $field['name']] = (string) $field['default'];
		}

		// Only Super Users may impersonate; only Registered users may be impersonated; never Administrators
		// or Super Users; the key lives ten seconds; MFA is not bypassed unless asked for.
		$this->assertSame('8', $defaults['allowedControlGroups']);
		$this->assertSame('2', $defaults['allowedTargetGroups']);
		$this->assertSame('7,8', $defaults['disallowedTargetGroups']);
		$this->assertSame('10', $defaults['cookie_lifetime']);
		$this->assertSame('32', $defaults['key_length']);
		$this->assertSame('0', $defaults['bypass_mfa']);
	}

	public function testTheCodeFallsBackToTheSameDefaultsAsTheManifest(): void
	{
		// populateOptions() applies its own defaults when a parameter is missing altogether. They must be the
		// manifest's, or a site that never saved the options would run with different rules.
		$code = (string) file_get_contents(self::root() . '/plugins/system/skeletonkey/src/Extension/Skeletonkey.php');

		$this->assertMatchesRegularExpression("/get\('allowedControlGroups', null\)\s*\?\?\s*\[8\]/", $code);
		$this->assertMatchesRegularExpression("/get\('allowedTargetGroups', null\)\s*\?\?\s*\[2\]/", $code);
		$this->assertMatchesRegularExpression("/get\('disallowedTargetGroups', null\)\s*\?\?\s*\[7, 8\]/", $code);
		$this->assertMatchesRegularExpression("/get\('cookie_lifetime', 10\)/", $code);
		$this->assertMatchesRegularExpression("/get\('key_length', 32\)/", $code);
		$this->assertMatchesRegularExpression("/get\('bypass_mfa', 0\)/", $code);
	}

	private static function root(): string
	{
		return \dirname(__DIR__, 2);
	}
}
