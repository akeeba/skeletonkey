<?php
/**
 * @package   Skeletonkey
 * @copyright Copyright (c)2022-2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\SkeletonKey\IntegrationTest\Engine;

defined('_JEXEC') or die;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * A thin PDO wrapper over the provisioned site's database.
 *
 * Deliberately not a port of Admin Tools' Engine\Driver: that is a hand-rolled mysqli layer written
 * for PHP 5, and none of what it does beyond "run a query" is needed here. PDO with prepared
 * statements is shorter, safer and does not need maintaining.
 *
 * Tests use this to *observe* — was a key row really written, was it really consumed, was it hashed? —
 * and to seed fixtures. They never use it to assert what an HTTP request should have proved.
 *
 * @since 1.2.6
 */
class Database
{
	/**
	 * The PDO connection.
	 *
	 * @var   PDO
	 * @since 1.2.6
	 */
	private PDO $pdo;

	/**
	 * The site's table prefix.
	 *
	 * @var   string
	 * @since 1.2.6
	 */
	private string $prefix;

	/**
	 * Constructor.
	 *
	 * @param   Configuration|null  $config  The suite configuration. Defaults to the singleton.
	 *
	 * @since   1.2.6
	 */
	public function __construct(?Configuration $config = null)
	{
		$config       = $config ?? Configuration::getInstance();
		$this->prefix = $config->getDbPrefix();

		$dsn = sprintf(
			'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
			$config->get('db.host', '127.0.0.1'),
			(int) $config->get('db.port', 33320),
			$config->get('db.name', 'ske2e')
		);

		try
		{
			$this->pdo = new PDO(
				$dsn,
				(string) $config->get('db.user', 'ske2e'),
				(string) $config->get('db.pass', 'ske2e'),
				[
					PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
					PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
					PDO::ATTR_EMULATE_PREPARES   => false,
				]
			);
		}
		catch (PDOException $e)
		{
			throw new RuntimeException(
				sprintf(
					"Could not connect to the test database (%s): %s\nIs the stack up? tests/integration/docker/run.sh --no-tests",
					$dsn,
					$e->getMessage()
				),
				0,
				$e
			);
		}
	}

	/**
	 * Replace the `#__` placeholder with the site's table prefix.
	 *
	 * @param   string  $sql  The SQL.
	 *
	 * @return  string
	 * @since   1.2.6
	 */
	public function prefix(string $sql): string
	{
		return str_replace('#__', $this->prefix, $sql);
	}

	/**
	 * Run a query.
	 *
	 * @param   string  $sql     The SQL, using `#__` for the table prefix.
	 * @param   array   $params  Bound parameters.
	 *
	 * @return  PDOStatement
	 * @since   1.2.6
	 */
	public function query(string $sql, array $params = []): PDOStatement
	{
		$statement = $this->pdo->prepare($this->prefix($sql));
		$statement->execute($params);

		return $statement;
	}

	/**
	 * Fetch every row of a query.
	 *
	 * @param   string  $sql     The SQL.
	 * @param   array   $params  Bound parameters.
	 *
	 * @return  array[]
	 * @since   1.2.6
	 */
	public function all(string $sql, array $params = []): array
	{
		return $this->query($sql, $params)->fetchAll();
	}

	/**
	 * Fetch the first row of a query.
	 *
	 * @param   string  $sql     The SQL.
	 * @param   array   $params  Bound parameters.
	 *
	 * @return  array|null
	 * @since   1.2.6
	 */
	public function row(string $sql, array $params = []): ?array
	{
		$row = $this->query($sql, $params)->fetch();

		return $row === false ? null : $row;
	}

	/**
	 * Fetch the first column of the first row.
	 *
	 * @param   string  $sql     The SQL.
	 * @param   array   $params  Bound parameters.
	 *
	 * @return  mixed  Null when there is no row.
	 * @since   1.2.6
	 */
	public function value(string $sql, array $params = [])
	{
		$value = $this->query($sql, $params)->fetchColumn();

		return $value === false ? null : $value;
	}

	/**
	 * Fetch the first column of every row.
	 *
	 * @param   string  $sql     The SQL.
	 * @param   array   $params  Bound parameters.
	 *
	 * @return  array
	 * @since   1.2.6
	 */
	public function column(string $sql, array $params = []): array
	{
		return $this->query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
	}

	/**
	 * Insert a row and return its auto-increment id.
	 *
	 * @param   string  $table  The table name, using `#__`.
	 * @param   array   $data   Column => value.
	 *
	 * @return  int
	 * @since   1.2.6
	 */
	public function insert(string $table, array $data): int
	{
		$columns      = array_keys($data);
		$placeholders = array_map(static fn(string $c): string => ':' . $c, $columns);

		$sql = sprintf(
			'INSERT INTO `%s` (%s) VALUES (%s)',
			$this->prefix($table),
			implode(', ', array_map(static fn(string $c): string => '`' . $c . '`', $columns)),
			implode(', ', $placeholders)
		);

		$this->pdo->prepare($sql)->execute(array_combine($placeholders, array_values($data)));

		return (int) $this->pdo->lastInsertId();
	}

	/**
	 * The underlying PDO handle, for the rare case that needs it.
	 *
	 * @return  PDO
	 * @since   1.2.6
	 */
	public function getPdo(): PDO
	{
		return $this->pdo;
	}
}
