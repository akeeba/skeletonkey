<?php
/**
 * @package   Skeletonkey
 * @copyright Copyright (c)2022-2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\SkeletonKey\UnitTest;

/**
 * Reads Joomla language (.ini) files the way Joomla does, for tests that check the shipped strings.
 *
 * Not parse_ini_file(): Joomla's files use `\"` inside double-quoted values and literal `\n`, which
 * PHP's INI scanner handles differently depending on the mode. One `KEY="value"` per line, comments
 * start with `;`, and — like Joomla — a key defined twice keeps its LAST value.
 *
 * @since 1.2.6
 */
final class LanguageFile
{
	/**
	 * Parsed files, by path.
	 *
	 * @var   array<string, array{strings: array<string, string>, duplicates: string[]}>
	 * @since 1.2.6
	 */
	private static array $cache = [];

	/**
	 * The strings of a language file.
	 *
	 * @param   string  $path  The .ini file.
	 *
	 * @return  array<string, string>
	 * @since   1.2.6
	 */
	public static function load(string $path): array
	{
		return self::parse($path)['strings'];
	}

	/**
	 * The keys a language file defines more than once.
	 *
	 * @param   string  $path  The .ini file.
	 *
	 * @return  string[]
	 * @since   1.2.6
	 */
	public static function duplicates(string $path): array
	{
		return self::parse($path)['duplicates'];
	}

	/**
	 * Parse a language file.
	 *
	 * @param   string  $path  The .ini file.
	 *
	 * @return  array{strings: array<string, string>, duplicates: string[]}
	 * @since   1.2.6
	 */
	private static function parse(string $path): array
	{
		if (isset(self::$cache[$path]))
		{
			return self::$cache[$path];
		}

		$strings    = [];
		$duplicates = [];

		foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line)
		{
			if (!preg_match('/^\s*([A-Z0-9_\-.]+)\s*=\s*"(.*)"\s*$/', $line, $match))
			{
				continue;
			}

			if (array_key_exists($match[1], $strings))
			{
				$duplicates[] = $match[1];
			}

			$strings[$match[1]] = str_replace('\\"', '"', $match[2]);
		}

		return self::$cache[$path] = ['strings' => $strings, 'duplicates' => array_values(array_unique($duplicates))];
	}
}
