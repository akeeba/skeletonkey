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
 * The "Log in as user" buttons on the back-end Users list.
 *
 * The plugin decides server-side which rows get a button and hands the list of user ids to its JavaScript
 * through the script options; the JavaScript only draws them. So the list IS the decision, and that is what
 * is asserted — along with the script and the strings the button needs actually reaching the page.
 *
 * @since 1.2.6
 */
class UsersPageTest extends AbstractE2ETestCase
{
	public function testButtonsAreOfferedForExactlyTheAllowedUsers(): void
	{
		$response = $this->usersPage($this->superUser());

		$this->assertSame(200, $response->code, $response->summary());
		$this->assertSame($this->ids(['alice', 'bob', 'author', 'blocked', 'mustreset', 'mfauser']), $this->loginButtonUserIds($response));
	}

	public function testTheButtonScriptAndItsStringsReachThePage(): void
	{
		$browser  = $this->superUser();
		$response = $this->usersPage($browser);

		$this->assertMatchesRegularExpression(
			'#<script[^>]+src="[^"]*media/plg_system_skeletonkey/js/backend\.min\.js[^"]*"#',
			$response->body,
			'The page does not load the button script.'
		);

		$script = $browser->get('media/plg_system_skeletonkey/js/backend.min.js');

		$this->assertSame(200, $script->code, $script->summary());
		$this->assertStringContainsString('plg_system_skeletonkey', $script->body);

		$strings = $this->scriptOptions($response)['joomla.jtext'] ?? [];

		foreach (['PLG_SYSTEM_SKELETONKEY_BTN_LABEL', 'PLG_SYSTEM_SKELETONKEY_ERR_LOGINFAILED', 'PLG_SYSTEM_SKELETONKEY_ERR_LOGINFAILED_AJAX'] as $key)
		{
			$this->assertArrayHasKey($key, $strings, sprintf('%s was not handed to the JavaScript.', $key));
			$this->assertNotSame($key, $strings[$key], sprintf('%s is untranslated.', $key));
		}
	}

	public function testTheButtonLabelIsCleanText(): void
	{
		$label = $this->scriptOptions($this->usersPage($this->superUser()))['joomla.jtext']['PLG_SYSTEM_SKELETONKEY_BTN_LABEL'] ?? '';

		$this->assertSame('Log in as user', $label);
	}

	public function testNoButtonsForABackendUserOutsideTheControlGroups(): void
	{
		// A Manager cannot even open the Users list by default (403); either way, no buttons.
		$response = $this->usersPage($this->backendAs('manager'));

		$this->assertNotServerError($response);
		$this->assertNull($this->loginButtonUserIds($response));
		$this->assertStringNotContainsString('plg_system_skeletonkey/js/backend', $response->body);
	}

	public function testNoButtonsOnOtherBackendPages(): void
	{
		$response = $this->superUser()->get('administrator/index.php', ['option' => 'com_users', 'view' => 'groups']);

		$this->assertNotServerError($response);
		$this->assertNull($this->loginButtonUserIds($response));
	}

	public function testTheSuperUserIsWarnedWhenTheAuthenticationPluginIsDisabled(): void
	{
		$this->setPluginEnabled('authentication', false);

		$response = $this->usersPage($this->superUser());

		$this->assertNotServerError($response);
		$this->assertNull($this->loginButtonUserIds($response), 'Buttons were offered although they cannot work.');

		// Joomla files CMSApplication::MSG_ERROR under 'danger' in the rendered queue.
		$errors = implode("\n", array_merge($this->queuedMessages($response, 'error'), $this->queuedMessages($response, 'danger')));

		$this->assertStringContainsString('Authentication - Skeleton Key', $errors, 'No warning about the disabled authentication plugin.');
	}

	/**
	 * @param   string[]  $roles  Roles.
	 *
	 * @return  int[]  Their user ids, sorted.
	 */
	private function ids(array $roles): array
	{
		$ids = array_map(fn(string $role): int => static::$fixtures->userId($role), $roles);
		sort($ids);

		return $ids;
	}
}
