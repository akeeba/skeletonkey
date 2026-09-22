<?php
/**
 * @package   Skeletonkey
 * @copyright Copyright (c)2022-2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

/**
 * Stand-ins for Joomla\Database\DatabaseInterface and QueryInterface.
 *
 * Helper\DbQuery only uses them as type declarations, and decides what to call with method_exists() — which
 * is the very thing under test. Empty interfaces are therefore faithful for this purpose, and they keep the
 * real framework, which would drag in half of Joomla, out of the unit suite. The test doubles in
 * DbQueryTest declare the methods (createQuery(), getQuery()) each Joomla version actually has.
 */

namespace Joomla\Database;

if (!interface_exists(QueryInterface::class, false))
{
	interface QueryInterface
	{
	}
}

if (!interface_exists(DatabaseInterface::class, false))
{
	interface DatabaseInterface
	{
	}
}
