<?php
/**
 * @package   Skeletonkey
 * @copyright Copyright (c)2022-2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\SkeletonKey\IntegrationTest\Tests;

defined('_JEXEC') or die;

use Akeeba\SkeletonKey\IntegrationTest\AbstractE2ETestCase;

/**
 * The audit trail: every impersonation is recorded in Joomla's User Actions Log, and reads correctly there.
 *
 * @since 1.2.6
 */
class ActionLogTest extends AbstractE2ETestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		$this->db()->query("DELETE FROM #__action_logs WHERE extension = 'plg_system_skeletonkey'");
	}

	public function testAnImpersonationIsLogged(): void
	{
		$browser = $this->superUser();
		$this->logInAs($browser, 'alice');

		$logs = $this->actionLogs();

		$this->assertOrKnownIssue(
			$logs !== [],
			1,
			'Nothing is logged: on Joomla 6.1 the request dies with "Dispatcher not set" before onSkeletonKeyRequestLogin is dispatched.'
		);

		$this->assertCount(1, $logs);

		$log     = $logs[0];
		$message = json_decode($log['message'], true);

		$this->assertSame('PLG_ACTIONLOG_SKELETONKEY_LOG_REQUEST_SUCCESS', $log['message_language_key']);
		$this->assertSame(static::$fixtures->userId('admin'), (int) $log['user_id'], 'The entry is not attributed to the Super User who asked.');
		$this->assertSame(static::$fixtures->username('admin'), $message['asking_username']);
		$this->assertSame(static::$fixtures->username('alice'), $message['requested_username']);
		$this->assertStringEndsWith('id=' . static::$fixtures->userId('alice'), $message['requested_link']);
	}

	public function testTheImpersonationIsLoggedBeforeTheKeyIsIssued(): void
	{
		// A trigger refuses to write a key unless the audit entry already exists. If the key were issued first
		// and only then logged, a failure to log could leave a working, unaudited key behind.
		$db = $this->db();
		$db->query('DROP TRIGGER IF EXISTS skeletonkey_e2e_audit_first');
		$db->query(
			"CREATE TRIGGER skeletonkey_e2e_audit_first BEFORE INSERT ON #__user_keys FOR EACH ROW BEGIN "
			. "IF NOT EXISTS (SELECT 1 FROM #__action_logs WHERE extension = 'plg_system_skeletonkey') THEN "
			. "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Key issued before it was audited'; END IF; END"
		);

		try
		{
			$browser  = $this->superUser();
			$response = $this->requestKey($browser, static::$fixtures->userId('alice'));

			$this->assertNotEmpty($this->keyRows('alice'), 'The key was issued before the audit entry was written. ' . $response->summary());
			$this->assertCount(1, $this->actionLogs());
		}
		finally
		{
			$db->query('DROP TRIGGER IF EXISTS skeletonkey_e2e_audit_first');
		}
	}

	public function testAnImpersonationWithTheMfaBypassIsLoggedAsSuch(): void
	{
		$this->setSystemParams(['bypass_mfa' => 1]);

		$this->logInAs($this->superUser(), 'alice');

		$logs = $this->actionLogs();

		$this->assertOrKnownIssue($logs !== [], 1, 'Nothing is logged: see testAnImpersonationIsLogged.');

		$this->assertSame('PLG_ACTIONLOG_SKELETONKEY_LOG_REQUEST_SUCCESS_MFABYPASS', $logs[0]['message_language_key']);
	}

	public function testTheLogEntryReadsCorrectlyInTheUserActionsLog(): void
	{
		$browser = $this->superUser();
		$this->logInAs($browser, 'alice');

		$this->assertOrKnownIssue($this->actionLogs() !== [], 1, 'Nothing is logged: see testAnImpersonationIsLogged.');

		$page = $browser->get('administrator/index.php', ['option' => 'com_actionlogs', 'view' => 'actionlogs']);

		$this->assertNotServerError($page);
		$this->assertStringNotContainsString(
			'PLG_ACTIONLOG_SKELETONKEY_LOG_REQUEST_SUCCESS',
			$page->body,
			'The User Actions Log shows the untranslated language key.'
		);
		$this->assertStringContainsString('was authorised to log in to the frontend as', $page->body);

		// The entry must link to both users' edit pages with well-formed anchors.
		$this->assertOrKnownIssue(
			(bool) preg_match('#<a href="[^"]*task=user\.edit&(amp;)?id=' . static::$fixtures->userId('alice') . '"#', $page->body),
			3,
			'The log message is delimited with typographic quotes, so its links render as href=\\”…\\” — '
			. 'not a quoted attribute — and the text is wrapped in stray ” characters.'
		);
	}

	public function testNothingIsLoggedWhileTheActionLogPluginIsDisabled(): void
	{
		$this->setPluginEnabled('actionlog', false);

		$browser = $this->superUser();
		$this->logInAs($browser, 'alice');

		$this->assertFrontendUser('alice', $browser, 'Disabling the action log plugin must not break the feature.');
		$this->assertSame([], $this->actionLogs());
	}

	public function testARefusedRequestIsAudited(): void
	{
		// A Super User trying to impersonate another Super User, or a back-end user outside the control
		// groups trying at all, is exactly what an audit trail is for.
		$this->requestKey($this->superUser(), static::$fixtures->userId('super2'));
		$this->requestKey($this->backendAs('manager'), static::$fixtures->userId('alice'));

		$this->assertOrKnownIssue(
			count($this->actionLogs()) === 2,
			4,
			'Refused requests leave no trace: the plugin only dispatches onSkeletonKeyRequestLogin once every check has passed.'
		);
	}
}
