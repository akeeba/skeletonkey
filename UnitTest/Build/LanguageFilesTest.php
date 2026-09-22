<?php
/**
 * @package   Skeletonkey
 * @copyright Copyright (c)2022-2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\SkeletonKey\UnitTest\Build;

use Akeeba\SkeletonKey\UnitTest\KnownIssueTrait;
use Akeeba\SkeletonKey\UnitTest\LanguageFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The language files the three plugins ship.
 *
 * @since 1.2.6
 */
class LanguageFilesTest extends TestCase
{
	use KnownIssueTrait;

	private const PLUGINS = ['system', 'authentication', 'actionlog'];

	/**
	 * Every .ini file shipped, keyed by a readable label.
	 *
	 * @return  array<string, array{0: string}>
	 */
	public static function iniFiles(): array
	{
		$files = [];

		foreach (glob(self::root() . '/plugins/*/skeletonkey/language/*/*.ini') ?: [] as $file)
		{
			$files[substr($file, strlen(self::root()) + 1)] = [$file];
		}

		foreach (glob(self::root() . '/build/templates/language/*/*.ini') ?: [] as $file)
		{
			$files[substr($file, strlen(self::root()) + 1)] = [$file];
		}

		return $files;
	}

	/**
	 * Every translated .ini file, paired with its en-GB original.
	 *
	 * @return  array<string, array{0: string, 1: string}>
	 */
	public static function translations(): array
	{
		$pairs = [];

		foreach (self::iniFiles() as $label => [$file])
		{
			if (str_contains($file, '/en-GB/'))
			{
				continue;
			}

			$tag      = basename(\dirname($file));
			$original = \dirname($file, 2) . '/en-GB/' . str_replace($tag, 'en-GB', basename($file));

			$pairs[$label] = [$file, $original];
		}

		return $pairs;
	}

	#[DataProvider('iniFiles')]
	public function testEveryLineIsACommentABlankOrAnAsciiQuotedString(string $file): void
	{
		$bad = [];

		foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $number => $line)
		{
			$trimmed = trim($line);

			if ($trimmed === '' || $trimmed[0] === ';')
			{
				continue;
			}

			// KEY="value", with only \" escapes inside.
			if (!preg_match('/^[A-Z0-9_\-.]+="(?:[^"\\\\]|\\\\.)*"$/', $trimmed))
			{
				$bad[] = sprintf('line %d: %s', $number + 1, mb_strimwidth($trimmed, 0, 80, '…'));
			}
		}

		if ($bad !== [] && str_contains(implode("\n", $bad), '”'))
		{
			$this->assertOrKnownIssue(
				false,
				3,
				'Values delimited by typographic quotes (U+201D), which Joomla keeps as part of the string: ' . implode('; ', $bad)
			);
		}

		$this->assertSame([], $bad, 'Lines Joomla would not parse as intended.');
	}

	#[DataProvider('iniFiles')]
	public function testNoKeyIsDefinedTwice(string $file): void
	{
		$this->assertSame([], LanguageFile::duplicates($file));
	}

	#[DataProvider('translations')]
	public function testTranslationsDefineNoKeysTheOriginalDoesNot(string $file, string $original): void
	{
		$this->assertFileExists($original, 'A translation without an en-GB original.');

		$extra = array_diff(array_keys($this->keysOf($file)), array_keys($this->keysOf($original)));

		$this->assertSame([], array_values($extra), 'Keys that no longer exist in en-GB.');
	}

	#[DataProvider('translations')]
	public function testTranslationsKeepThePlaceholders(string $file, string $original): void
	{
		$source = $this->keysOf($original);
		$broken = [];

		foreach ($this->keysOf($file) as $key => $value)
		{
			if (!isset($source[$key]))
			{
				continue;
			}

			preg_match_all('/\{[a-z_]+\}|%[sd]/', $source[$key], $want);
			preg_match_all('/\{[a-z_]+\}|%[sd]/', $value, $have);

			sort($want[0]);
			sort($have[0]);

			if ($want[0] !== $have[0])
			{
				$broken[] = $key;
			}
		}

		$this->assertSame([], $broken, 'Translations that lost or gained a placeholder.');
	}

	public function testEveryKeyTheCodeUsesExistsInEnglish(): void
	{
		$missing = [];

		foreach (self::PLUGINS as $plugin)
		{
			$dir     = self::root() . '/plugins/' . $plugin . '/skeletonkey';
			$defined = [];

			foreach (glob($dir . '/language/en-GB/*.ini') ?: [] as $ini)
			{
				$defined += $this->keysOf($ini);
			}

			$code = '';

			foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file)
			{
				if (\in_array($file->getExtension(), ['php', 'xml'], true) || $file->getFilename() === 'backend.js')
				{
					$code .= file_get_contents($file->getPathname());
				}
			}

			preg_match_all('/\b(PLG_' . strtoupper($plugin) . '_SKELETONKEY[A-Z0-9_]*)\b/', $code, $matches);

			foreach (array_unique($matches[1]) as $key)
			{
				if (!array_key_exists($key, $defined))
				{
					$missing[] = $plugin . ': ' . $key;
				}
			}
		}

		$this->assertSame([], $missing);
	}

	public function testEveryLanguageFolderIsRegisteredInItsManifest(): void
	{
		$problems = [];

		foreach (self::PLUGINS as $plugin)
		{
			$dir        = self::root() . '/plugins/' . $plugin . '/skeletonkey';
			$manifest   = simplexml_load_file($dir . '/skeletonkey.xml');
			$registered = [];

			foreach ($manifest->languages->language as $language)
			{
				$registered[] = (string) $language;

				if (!is_file($dir . '/language/' . $language))
				{
					$problems[] = sprintf('%s: the manifest lists %s, which does not exist', $plugin, $language);
				}
			}

			foreach (glob($dir . '/language/*/*.ini') ?: [] as $ini)
			{
				$relative = substr($ini, strlen($dir . '/language/'));

				if (!\in_array($relative, $registered, true))
				{
					$problems[] = sprintf('%s: %s is shipped but not registered, so it is never installed', $plugin, $relative);
				}
			}
		}

		$this->assertSame([], $problems);
	}

	/**
	 * The strings of a language file, parsed the way Joomla does — but tolerating the typographic-quote
	 * lines, so issue #3 does not also hide every key on those lines from the other checks.
	 *
	 * @param   string  $file  The .ini file.
	 *
	 * @return  array<string, string>
	 */
	private function keysOf(string $file): array
	{
		$strings = LanguageFile::load($file);

		foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line)
		{
			if (preg_match('/^\s*([A-Z0-9_\-.]+)\s*=\s*”(.*)”\s*$/u', $line, $match) && !isset($strings[$match[1]]))
			{
				$strings[$match[1]] = $match[2];
			}
		}

		return $strings;
	}

	private static function root(): string
	{
		return \dirname(__DIR__, 2);
	}
}
