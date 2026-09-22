<?php
/**
 * @package   Skeletonkey
 * @copyright Copyright (c)2022-2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\SkeletonKey\UnitTest\Helper;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use Joomla\Plugin\Authentication\Skeletonkey\Helper\DbQuery as AuthenticationDbQuery;
use Joomla\Plugin\System\Skeletonkey\Helper\DbQuery as SystemDbQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Helper\DbQuery, in both plugins that carry a copy of it.
 *
 * The helper is the single place the createQuery() / getQuery(true) compatibility branch lives. Both
 * branches are exercised against drivers shaped like the two Joomla generations: one that has
 * createQuery() (Joomla 5.1+) and one that only has the old getQuery($new).
 *
 * @since 1.2.6
 */
#[CoversClass(SystemDbQuery::class)]
#[CoversClass(AuthenticationDbQuery::class)]
class DbQueryTest extends TestCase
{
	/**
	 * @return  array<string, array{0: class-string}>
	 */
	public static function helpers(): array
	{
		return [
			'system plugin'         => [SystemDbQuery::class],
			'authentication plugin' => [AuthenticationDbQuery::class],
		];
	}

	#[DataProvider('helpers')]
	public function testPrefersCreateQueryWhenTheDriverHasIt(string $helper): void
	{
		$driver = new class implements DatabaseInterface {
			public array $calls = [];

			public function createQuery(): QueryInterface
			{
				$this->calls[] = 'createQuery';

				return new class implements QueryInterface {
				};
			}

			public function getQuery($new = false)
			{
				$this->calls[] = 'getQuery';

				return new class implements QueryInterface {
				};
			}
		};

		$query = $helper::create($driver);

		$this->assertInstanceOf(QueryInterface::class, $query);
		$this->assertSame(['createQuery'], $driver->calls, 'The deprecated getQuery() was used although createQuery() exists.');
	}

	#[DataProvider('helpers')]
	public function testFallsBackToANewGetQueryOnOlderDrivers(string $helper): void
	{
		$driver = new class implements DatabaseInterface {
			public array $calls = [];

			public function getQuery($new = false)
			{
				$this->calls[] = ['getQuery', $new];

				return new class implements QueryInterface {
				};
			}
		};

		$query = $helper::create($driver);

		$this->assertInstanceOf(QueryInterface::class, $query);
		// getQuery(false) would hand back the driver's shared, possibly half-built query object.
		$this->assertSame([['getQuery', true]], $driver->calls);
	}

	#[DataProvider('helpers')]
	public function testEveryCallReturnsAFreshQuery(string $helper): void
	{
		$driver = new class implements DatabaseInterface {
			public function createQuery(): QueryInterface
			{
				return new class implements QueryInterface {
				};
			}
		};

		$this->assertNotSame($helper::create($driver), $helper::create($driver));
	}

	public function testTheTwoCopiesAreIdenticalApartFromTheirNamespace(): void
	{
		$root   = \dirname(__DIR__, 2);
		$system = (string) file_get_contents($root . '/plugins/system/skeletonkey/src/Helper/DbQuery.php');
		$auth   = (string) file_get_contents($root . '/plugins/authentication/skeletonkey/src/Helper/DbQuery.php');

		$this->assertSame(
			str_replace('Joomla\\Plugin\\System\\Skeletonkey', '@', $system),
			str_replace('Joomla\\Plugin\\Authentication\\Skeletonkey', '@', $auth),
			'The two DbQuery copies have drifted apart; a fix applied to one was not applied to the other.'
		);
	}
}
