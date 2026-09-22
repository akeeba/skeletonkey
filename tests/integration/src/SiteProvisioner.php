<?php
/**
 * @package   Skeletonkey
 * @copyright Copyright (c)2022-2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\SkeletonKey\IntegrationTest;

defined('_JEXEC') or die;

use Akeeba\SkeletonKey\IntegrationTest\Engine\Configuration;
use Akeeba\SkeletonKey\IntegrationTest\Engine\Database;
use RuntimeException;

/**
 * Puts the site into the known state every test starts from.
 *
 * Skeleton Key's whole security model is expressed in Joomla's stock user groups — who may ask for a key
 * (Super Users by default) and who may be impersonated (Registered, minus Administrator and Super Users) —
 * so the fixtures are one account per interesting position in that default group tree, rather than any
 * custom ACL. The core group ids a fresh Joomla install creates are fixed; HarnessTest verifies them.
 *
 * Everything here is written straight into the database. That is the one place the suite is allowed to
 * do so: fixtures are setup, not behaviour under test.
 *
 * @since 1.2.6
 */
class SiteProvisioner
{
	/**
	 * The fixture manifest, written into the web root so a kept-up stack and a later PHPUnit run agree.
	 *
	 * @since 1.2.6
	 */
	private const MANIFEST = 'e2e-manifest.json';

	/**
	 * Joomla's stock user groups, as a fresh installation creates them.
	 *
	 * @since 1.2.6
	 */
	public const GROUPS = [
		'public'        => 1,
		'registered'    => 2,
		'author'        => 3,
		'manager'       => 6,
		'administrator' => 7,
		'super'         => 8,
		'guest'         => 9,
	];

	/**
	 * The shared accounts: role => [name, group keys, extra #__users columns].
	 *
	 * @since 1.2.6
	 */
	private const ROLES = [
		// The everyday target: a plain registered user.
		'alice'         => ['Alice Example', ['registered'], []],
		// A second target, for "the key is for alice, not bob" assertions.
		'bob'           => ['Bob Example', ['registered'], []],
		// A child of Registered: allowed by inheritance.
		'author'        => ['Arthur Author', ['author'], []],
		// Manager sits under Public, not Registered, so it is not a target by default. It can log into the
		// back-end, but is not in the default control groups either, so it must not be able to ask for a key.
		'manager'       => ['Mona Manager', ['manager'], []],
		// A child of Manager. Outside the allowed groups AND explicitly in the disallowed ones by default.
		'administrator' => ['Adam Administrator', ['administrator'], []],
		// A second Super User: disallowed as a target, allowed as a controller.
		'super2'        => ['Second Super', ['super'], []],
		// Only in the Guest group, which is outside Registered altogether.
		'guestonly'     => ['Gus Guestgroup', ['guest'], []],
		// Allowed by group, but blocked: Joomla itself must refuse the login.
		'blocked'       => ['Blocked Example', ['registered'], ['block' => 1]],
		// Allowed by group, but must reset their password.
		'mustreset'     => ['Reset Example', ['registered'], ['requireReset' => 1]],
		// A target with Multi-factor Authentication set up (a TOTP record is provisioned for them).
		'mfauser'       => ['Mfa Example', ['registered'], []],
	];

	/**
	 * The system plugin's parameters as the plugin's own options form saves them.
	 *
	 * These are the manifest defaults, in the shape `filter="int_array"` gives them on save.
	 *
	 * @since 1.2.6
	 */
	public const SYSTEM_PARAMS = [
		'allowedControlGroups'   => [8],
		'allowedTargetGroups'    => [2],
		'disallowedTargetGroups' => [7, 8],
		'cookie_lifetime'        => 10,
		'key_length'             => 32,
		'bypass_mfa'             => 0,
	];

	/**
	 * The com_users options the MFA tests change, with Joomla's own defaults.
	 *
	 * @since 1.2.6
	 */
	public const USERS_PARAMS = [
		'mfaonsilent'        => 0,
		'silentresponses'    => 'cookie, passwordless',
		'forceMFAUserGroups' => [],
		'neverMFAUserGroups' => [],
		'mfaredirectonlogin' => 0,
	];

	/**
	 * The suite configuration.
	 *
	 * @var   Configuration
	 * @since 1.2.6
	 */
	private Configuration $config;

	/**
	 * The loaded manifest.
	 *
	 * @var   array|null
	 * @since 1.2.6
	 */
	private ?array $manifest = null;

	/**
	 * The singleton instance.
	 *
	 * @var   self|null
	 * @since 1.2.6
	 */
	private static ?self $instance = null;

	/**
	 * The site database.
	 *
	 * @var   Database|null
	 * @since 1.2.6
	 */
	private ?Database $db = null;

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
	 * The shared instance tests use.
	 *
	 * @return  self
	 * @since   1.2.6
	 */
	public static function getInstance(): self
	{
		return self::$instance ??= new self();
	}

	/**
	 * Provision everything from scratch.
	 *
	 * @return  array  The manifest.
	 * @since   1.2.6
	 */
	public function provision(): array
	{
		$db = $this->db();

		foreach (['system', 'authentication', 'actionlog'] as $folder)
		{
			if ($this->pluginId($folder) <= 0)
			{
				throw new RuntimeException(sprintf('plg_%s_skeletonkey is not installed.', $folder));
			}
		}

		$manifest = [
			'users'     => [],
			'usernames' => [],
		];

		// Everyone out. Sessions of accounts about to be deleted would otherwise linger.
		$db->query('DELETE FROM #__session');
		$db->query('DELETE FROM #__user_keys');
		$db->query('DELETE FROM #__user_mfa');
		$db->query("DELETE FROM #__action_logs WHERE extension = 'plg_system_skeletonkey'");

		$this->deleteAllUsersButTheSuperUser();

		[$adminUsername] = $this->config->getAdminCredentials();
		$adminId         = (int) $db->value('SELECT id FROM #__users WHERE username = ?', [$adminUsername]);

		if ($adminId <= 0)
		{
			throw new RuntimeException(sprintf('The Super User "%s" does not exist.', $adminUsername));
		}

		$db->query('UPDATE #__users SET block = 0, requireReset = 0 WHERE id = ?', [$adminId]);

		$manifest['users']['admin']     = $adminId;
		$manifest['usernames']['admin'] = $adminUsername;

		foreach (self::ROLES as $role => [$name, $groupKeys, $extra])
		{
			$manifest['users'][$role]     = $this->createUser(
				[
					'username' => $role,
					'name'     => $name,
					'groups'   => array_map(fn(string $key): int => self::GROUPS[$key], $groupKeys),
				] + $extra
			);
			$manifest['usernames'][$role] = $role;
		}

		$this->createMfaRecord($manifest['users']['mfauser']);

		foreach (['system', 'authentication', 'actionlog'] as $folder)
		{
			$this->setPluginEnabled($folder, true);
		}

		// Joomla's own action log plugins must be on, or no action is ever logged.
		$db->query("UPDATE #__extensions SET enabled = 1 WHERE type = 'plugin' AND folder = 'system' AND element = 'actionlogs'");

		$this->resetSystemParams();
		$this->resetUsersParams();

		SiteProbe::deploy($this->config);

		$this->writeManifest($manifest);

		return $this->manifest = $manifest;
	}

	/**
	 * The manifest of the last provisioning run.
	 *
	 * @return  array
	 * @since   1.2.6
	 */
	public function getManifest(): array
	{
		if ($this->manifest !== null)
		{
			return $this->manifest;
		}

		$file = $this->siteRoot() . '/' . self::MANIFEST;

		if (is_file($file))
		{
			$data = json_decode((string) file_get_contents($file), true);

			if (is_array($data))
			{
				return $this->manifest = $data;
			}
		}

		return $this->provision();
	}

	/**
	 * The user id of a role.
	 *
	 * @param   string  $role  e.g. 'alice'.
	 *
	 * @return  int
	 * @since   1.2.6
	 */
	public function userId(string $role): int
	{
		return (int) $this->lookup('users', $role);
	}

	/**
	 * The username of a role.
	 *
	 * @param   string  $role  e.g. 'alice'.
	 *
	 * @return  string
	 * @since   1.2.6
	 */
	public function username(string $role): string
	{
		return (string) $this->lookup('usernames', $role);
	}

	/**
	 * Every role the manifest knows.
	 *
	 * @return  string[]
	 * @since   1.2.6
	 */
	public function roles(): array
	{
		return array_keys($this->getManifest()['users'] ?? []);
	}

	/**
	 * Create a user directly in the database.
	 *
	 * @param   array  $spec  username, name, email, password, groups (ids), block, requireReset.
	 *
	 * @return  int  The new user id.
	 * @since   1.2.6
	 */
	public function createUser(array $spec = []): int
	{
		$db       = $this->db();
		$suffix   = bin2hex(random_bytes(4));
		$username = $spec['username'] ?? ('user' . $suffix);

		$id = $db->insert(
			'#__users',
			[
				'name'          => $spec['name'] ?? ('User ' . $suffix),
				'username'      => $username,
				'email'         => $spec['email'] ?? ($username . '@example.test'),
				'password'      => password_hash($spec['password'] ?? $this->config->getUserPassword(), PASSWORD_BCRYPT),
				'block'         => (int) ($spec['block'] ?? 0),
				'sendEmail'     => 0,
				'registerDate'  => gmdate('Y-m-d H:i:s', time() - 86400),
				'lastvisitDate' => gmdate('Y-m-d H:i:s'),
				'activation'    => '',
				'params'        => '{}',
				'requireReset'  => (int) ($spec['requireReset'] ?? 0),
				'resetCount'    => 0,
			]
		);

		foreach ($spec['groups'] ?? [self::GROUPS['registered']] as $groupId)
		{
			$db->insert('#__user_usergroup_map', ['user_id' => $id, 'group_id' => (int) $groupId]);
		}

		return $id;
	}

	/**
	 * Give a user an MFA method, so Joomla considers MFA "set up" for them.
	 *
	 * All the MFA gate asks is whether an enabled method has a record for the user with non-empty options, and
	 * a test never gets as far as entering a code, so a fixed dummy secret is stored. Unencrypted is fine:
	 * Joomla passes options without the ###AES128### prefix through as plain JSON.
	 *
	 * @param   int  $userId  The user.
	 *
	 * @return  void
	 * @since   1.2.6
	 */
	public function createMfaRecord(int $userId): void
	{
		$this->db()->insert(
			'#__user_mfa',
			[
				'user_id'    => $userId,
				'title'      => 'E2E authenticator',
				'method'     => 'totp',
				'default'    => 1,
				'options'    => json_encode(['key' => 'JBSWY3DPEHPK3PXP']),
				'created_on' => gmdate('Y-m-d H:i:s'),
				'last_used'  => null,
			]
		);
	}

	/**
	 * Merge parameters over the system plugin's current ones.
	 *
	 * @param   array  $params  Parameter => value.
	 *
	 * @return  void
	 * @since   1.2.6
	 */
	public function setSystemParams(array $params): void
	{
		$this->writePluginParams('system', array_merge($this->readPluginParams('system'), $params));
	}

	/**
	 * Replace the system plugin's parameters wholesale — e.g. with the raw, un-saved shape.
	 *
	 * @param   array  $params  Parameter => value.
	 *
	 * @return  void
	 * @since   1.2.6
	 */
	public function replaceSystemParams(array $params): void
	{
		$this->writePluginParams('system', $params);
	}

	/**
	 * Put the system plugin's parameters back to their defaults.
	 *
	 * @return  void
	 * @since   1.2.6
	 */
	public function resetSystemParams(): void
	{
		$this->writePluginParams('system', self::SYSTEM_PARAMS);
	}

	/**
	 * Merge options over com_users' current ones.
	 *
	 * @param   array  $params  Option => value.
	 *
	 * @return  void
	 * @since   1.2.6
	 */
	public function setUsersParams(array $params): void
	{
		$db      = $this->db();
		$raw     = $db->value("SELECT params FROM #__extensions WHERE type = 'component' AND element = 'com_users'");
		$current = json_decode((string) $raw, true) ?: [];

		$db->query(
			"UPDATE #__extensions SET params = ? WHERE type = 'component' AND element = 'com_users'",
			[json_encode(array_merge($current, $params))]
		);
	}

	/**
	 * Put com_users' MFA options back to Joomla's defaults.
	 *
	 * @return  void
	 * @since   1.2.6
	 */
	public function resetUsersParams(): void
	{
		$this->setUsersParams(self::USERS_PARAMS);
	}

	/**
	 * Enable or disable one of the three plugins.
	 *
	 * @param   string  $folder   system, authentication or actionlog.
	 * @param   bool    $enabled  The new state.
	 *
	 * @return  void
	 * @since   1.2.6
	 */
	public function setPluginEnabled(string $folder, bool $enabled): void
	{
		$this->db()->query(
			"UPDATE #__extensions SET enabled = ? WHERE type = 'plugin' AND folder = ? AND element = 'skeletonkey'",
			[$enabled ? 1 : 0, $folder]
		);
	}

	/**
	 * The extension id of one of the three plugins; 0 when it is not installed.
	 *
	 * @param   string  $folder  system, authentication or actionlog.
	 *
	 * @return  int
	 * @since   1.2.6
	 */
	public function pluginId(string $folder): int
	{
		return (int) $this->db()->value(
			"SELECT extension_id FROM #__extensions WHERE type = 'plugin' AND folder = ? AND element = 'skeletonkey'",
			[$folder]
		);
	}

	/**
	 * Read a plugin's parameters.
	 *
	 * @param   string  $folder  The plugin folder.
	 *
	 * @return  array
	 * @since   1.2.6
	 */
	private function readPluginParams(string $folder): array
	{
		$raw = $this->db()->value(
			"SELECT params FROM #__extensions WHERE type = 'plugin' AND folder = ? AND element = 'skeletonkey'",
			[$folder]
		);

		return json_decode((string) $raw, true) ?: [];
	}

	/**
	 * Write a plugin's parameters.
	 *
	 * @param   string  $folder  The plugin folder.
	 * @param   array   $params  The parameters.
	 *
	 * @return  void
	 * @since   1.2.6
	 */
	private function writePluginParams(string $folder, array $params): void
	{
		$this->db()->query(
			"UPDATE #__extensions SET params = ? WHERE type = 'plugin' AND folder = ? AND element = 'skeletonkey'",
			[json_encode($params), $folder]
		);
	}

	/**
	 * Delete every account except the installer's Super User.
	 *
	 * @return  void
	 * @since   1.2.6
	 */
	private function deleteAllUsersButTheSuperUser(): void
	{
		$db              = $this->db();
		[$adminUsername] = $this->config->getAdminCredentials();

		$ids = array_map('intval', $db->column('SELECT id FROM #__users WHERE username <> ?', [$adminUsername]));

		if ($ids === [])
		{
			return;
		}

		$in = implode(',', $ids);

		foreach (['#__user_usergroup_map', '#__user_profiles', '#__user_notes', '#__user_mfa'] as $table)
		{
			$db->query(sprintf('DELETE FROM %s WHERE user_id IN (%s)', $table, $in));
		}

		$db->query(sprintf('DELETE FROM #__users WHERE id IN (%s)', $in));
	}

	/**
	 * Write the manifest into the web root.
	 *
	 * @param   array  $manifest  The manifest.
	 *
	 * @return  void
	 * @since   1.2.6
	 */
	private function writeManifest(array $manifest): void
	{
		file_put_contents(
			$this->siteRoot() . '/' . self::MANIFEST,
			json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
		);
	}

	/**
	 * Look a value up in the manifest, failing loudly on a typo.
	 *
	 * @param   string  $section  The manifest section.
	 * @param   string  $key      The key.
	 *
	 * @return  mixed
	 * @since   1.2.6
	 */
	private function lookup(string $section, string $key)
	{
		$manifest = $this->getManifest();

		if (!isset($manifest[$section]) || !array_key_exists($key, $manifest[$section]))
		{
			throw new RuntimeException(
				sprintf(
					'The fixture manifest has no %s entry named "%s". Known: %s',
					$section,
					$key,
					implode(', ', array_keys($manifest[$section] ?? [])) ?: '(none)'
				)
			);
		}

		return $manifest[$section][$key];
	}

	/**
	 * The site database.
	 *
	 * @return  Database
	 * @since   1.2.6
	 */
	private function db(): Database
	{
		return $this->db ??= new Database($this->config);
	}

	/**
	 * The site root on the host.
	 *
	 * @return  string
	 * @since   1.2.6
	 */
	private function siteRoot(): string
	{
		$root = rtrim($this->config->getSiteRoot(), '/');

		if (!is_dir($root))
		{
			throw new RuntimeException(sprintf('The site root %s does not exist. Run tests/integration/docker/run.sh.', $root));
		}

		return $root;
	}
}
