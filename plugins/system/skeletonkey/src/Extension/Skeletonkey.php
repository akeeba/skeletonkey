<?php
/*
 * @package   Skeletonkey
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Joomla\Plugin\System\Skeletonkey\Extension;

defined('_JEXEC') || die;

use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Event\View\DisplayEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\CMS\User\UserHelper;
use Joomla\Component\Users\Administrator\View\Users\HtmlView as UsersHtmlView;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Plugin\System\Skeletonkey\Helper\DbQuery;
use Joomla\Utilities\ArrayHelper;
use RuntimeException;
use Throwable;

class Skeletonkey extends CMSPlugin implements SubscriberInterface, DatabaseAwareInterface
{
	use DatabaseAwareTrait;

	private const COOKIE_PREFIX = "skeletonkey_";

	/**
	 * Affects constructor behavior. If true, language files will be loaded automatically.
	 *
	 * @var    boolean
	 * @since  1.0.3
	 */
	protected $autoloadLanguage = false;

	/**
	 * Groups allowed to login as another user
	 *
	 * @var   array|null
	 * @since 1.0.0
	 */
	private $allowedControlGroups = null;

	/**
	 * Groups allowed to be logged into
	 *
	 * @var   array|null
	 * @since 1.0.0
	 */
	private $allowedTargetGroups = null;

	/**
	 * Groups disallowed to be logged into
	 *
	 * @var   array|null
	 * @since 1.0.0
	 */
	private $disallowedTargetGroups = null;

	/**
	 * @inheritDoc
	 */
	public function __construct($config = [])
	{
		parent::__construct($config);

		$this->populateOptions();
	}


	/**
	 * @inheritDoc
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onAfterInitialise' => 'onAfterInitialise',
			'onBeforeDisplay'   => 'onBeforeDisplay',
			'onAjaxSkeletonkey' => 'onAjaxSkeletonkey',
		];
	}

	/**
	 * Adds login buttons to the com_users backend users list page
	 *
	 * @param   Event  $event
	 *
	 * @since        1.0.0
	 * @noinspection PhpUnused
	 */
	public function onBeforeDisplay(Event $event)
	{
		// Make sure this is the backend.
		if (!($this->getApplication() instanceof CMSApplication) || !$this->getApplication()->isClient('administrator'))
		{
			return;
		}

		// Make sure the current user is allowed to log into the site as another user.
		$currentUser = $this->getApplication()->getIdentity();

		if (!($currentUser instanceof User) || empty(array_intersect($currentUser->getAuthorisedGroups(), $this->allowedControlGroups)))
		{
			return;
		}

		// Make sure this is a valid event
		if (!($event instanceof DisplayEvent))
		{
			return;
		}

		/**
		 * Make sure this is the Users view
		 *
		 * @var DisplayEvent  $event
		 * @var UsersHtmlView $view
		 */
		$view = $event->getArgument('subject');

		if (!($view instanceof UsersHtmlView))
		{
			return;
		}

		// Make sure the authentication plugin is enabled. If not, warn the user.
		if (!PluginHelper::isEnabled('authentication', 'skeletonkey'))
		{
			$this->loadLanguage();

			$this->getApplication()->enqueueMessage(Text::_('PLG_SYSTEM_SKELETONKEY_LBL_NOAUTHPLUGIN'), CMSApplication::MSG_ERROR);

			return;
		}

		// Find the displayed users and tell the frontend JS which users should get login buttons
		$refObject = new \ReflectionObject($view);
		$refProp    = $refObject->getProperty('items');
		$items      = $refProp->getValue($view);
		$loginUsers = [];

		/** @var \stdClass $item */
		foreach ($items as $item)
		{
			/** @var User $user */
			$user           = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($item->id);
			$allowedUser    = !empty(array_intersect($user->getAuthorisedGroups(), $this->allowedTargetGroups));
			$disallowedUser = !empty(array_intersect($user->getAuthorisedGroups(), $this->disallowedTargetGroups));

			if ($allowedUser && !$disallowedUser)
			{
				$loginUsers[] = $user->id;
			}
		}

		// Add our custom JavaScript
		$document = $this->getApplication()->getDocument();
		$wam      = $document->getWebAssetManager();
		$wam->getRegistry()->addExtensionRegistryFile('plg_system_skeletonkey');
		$document->addScriptOptions('plg_system_skeletonkey', [
			'loginUsers' => ArrayHelper::toInteger($loginUsers),
		]);
		$wam->useScript('plg_system_skeletonkey.backend');

		$this->loadLanguage();
		Text::script('PLG_SYSTEM_SKELETONKEY_BTN_LABEL');
		Text::script('PLG_SYSTEM_SKELETONKEY_ERR_LOGINFAILED');
		Text::script('PLG_SYSTEM_SKELETONKEY_ERR_LOGINFAILED_AJAX');
		Text::script('PLG_SYSTEM_SKELETONKEY_ERR_BLOCKED');
		Text::script('PLG_SYSTEM_SKELETONKEY_ERR_MUSTRESET');
	}

	/**
	 * Automatically logs in the user in the frontend if the cookie is present
	 *
	 * @param   Event  $event
	 *
	 * @since        1.0.0
	 * @noinspection PhpUnused
	 */
	public function onAfterInitialise(Event $event)
	{
		// Skeleton key only works in the frontend
		if (!$this->getApplication()->isClient('site'))
		{
			return;
		}

		// Make sure the authentication plugin is enabled. If not, quit,
		if (!PluginHelper::isEnabled('authentication', 'skeletonkey'))
		{
			return;
		}

		// If the cookie is set try to log in the user using it
		$cookieName = self::COOKIE_PREFIX . $this->getHashedUserAgent();

		if (!$this->getApplication()->getInput()->cookie->get($cookieName))
		{
			return;
		}

		$loginResult = $this->getApplication()->login(['username' => ''], ['silent' => true]);

		// Should I bypass Joomla's Multi-factor Authentication for this impersonated session?
		if ($this->params->get('bypass_mfa', 0) != 1)
		{
			return;
		}

		// Only bypass MFA if the login we just performed actually succeeded.
		if ($loginResult !== true)
		{
			return;
		}

		$identity = $this->getApplication()->getIdentity();

		if (!($identity instanceof User) || $identity->guest || $identity->id <= 0)
		{
			return;
		}

		/**
		 * Tell Joomla's MFA gate that this session has already satisfied MFA.
		 *
		 * `mfa_checked` skips the captive MFA page. `mandatory_mfa_setup` must ALSO be cleared: Joomla
		 * never resets it on login, so a stale value left in this browser's frontend session would
		 * defeat `mfa_checked` in needsMultiFactorAuthenticationRedirection().
		 */
		$session = $this->getApplication()->getSession();
		$session->set('com_users.mfa_checked', 1);
		$session->set('com_users.mandatory_mfa_setup', 0);
	}

	/**
	 * Handle the AJAX request to create a Skeleton Key
	 *
	 * @param   Event  $event
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	public function onAjaxSkeletonkey(Event $event)
	{
		$currentUser = $this->getApplication()->getIdentity();
		$userId      = $this->getApplication()->getInput()->post->getInt('user_id');

		// Anti-CSRF token check. The request is a POST so that neither the token nor the user ID ends up in a URL.
		if (!Session::checkToken('post'))
		{
			return $this->refuse($event, $currentUser, 'token', null, $userId);
		}

		// Make sure this is the backend.
		if (!($this->getApplication() instanceof CMSApplication) || !$this->getApplication()->isClient('administrator'))
		{
			return $this->refuse($event, $currentUser, 'requester', null, $userId);
		}

		// Make sure the current user is allowed to log into the site as another user.
		if (!($currentUser instanceof User) || empty(array_intersect($currentUser->getAuthorisedGroups(), $this->allowedControlGroups)))
		{
			return $this->refuse($event, $currentUser, 'requester', null, $userId);
		}

		// Make sure the authentication plugin is enabled.
		if (!PluginHelper::isEnabled('authentication', 'skeletonkey'))
		{
			return $this->refuse($event, $currentUser, 'unavailable', null, $userId);
		}

		// Make sure the requested user exists
		/** @var User $user */
		$user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($userId);

		if ($user->id <= 0 || $user->id != $userId)
		{
			return $this->refuse($event, $currentUser, 'notfound', null, $userId);
		}

		// Make sure the requested user is allowed to be accessed via a Skeleton Key
		$allowedUser    = !empty(array_intersect($user->getAuthorisedGroups(), $this->allowedTargetGroups));
		$disallowedUser = !empty(array_intersect($user->getAuthorisedGroups(), $this->disallowedTargetGroups));

		if (!$allowedUser || $disallowedUser)
		{
			return $this->refuse($event, $currentUser, 'target', $user, $userId);
		}

		// Blocked users, and users who must reset their password, can never be logged in by a key. Say so now,
		// instead of issuing a key that leads the operator to a blank guest page. The answer carries the reason.
		if ((int) $user->block === 1)
		{
			return $this->refuse($event, $currentUser, 'blocked', $user, $userId, 'blocked');
		}

		if ((int) $user->requireReset === 1)
		{
			return $this->refuse($event, $currentUser, 'mustreset', $user, $userId, 'mustreset');
		}

		/**
		 * Audit first, issue second. If logging fails (or throws) no key exists yet, so an impersonation can
		 * never happen without an audit entry. Should issuing the key then fail, that is logged as well.
		 */
		$this->logRequest($currentUser, $user, true);

		$createdCookie = $this->createCookie($userId);

		if (!$createdCookie)
		{
			$this->logRequest($currentUser, $user, false);
		}

		// Return the event result back to com_ajax
		$this->addEventResult($event, $createdCookie);
	}

	/**
	 * Refuse a key request: audit it (when an authenticated user made it) and answer "no" to com_ajax.
	 *
	 * Requests from guests are not audited: anyone on the Internet can reach this endpoint, and the audit log is not
	 * a place for unauthenticated noise.
	 *
	 * @param   Event                $event        The onAjaxSkeletonkey event
	 * @param   User|mixed           $controlUser  The user making the request
	 * @param   string               $reason       One of token, requester, unavailable, notfound, target, blocked, mustreset
	 * @param   User|null            $targetUser   The requested user, if it exists
	 * @param   int                  $targetId     The requested user ID
	 * @param   bool|string          $answer       What to answer com_ajax: false, or a reason code the operator may be told
	 *
	 * @return  void
	 * @since   1.2.6
	 */
	private function refuse(Event $event, $controlUser, string $reason, ?User $targetUser = null, int $targetId = 0, $answer = false): void
	{
		if ($controlUser instanceof User && !$controlUser->guest && $controlUser->id > 0)
		{
			$this->logRequest($controlUser, $targetUser, false, $reason, $targetId);
		}

		$this->addEventResult($event, $answer);
	}

	/**
	 * Triggers the Action Log plugin for a Skeleton Key request
	 *
	 * @param   User       $controlUser    The user asking to log in as another user
	 * @param   User|null  $targetUser     The user to be logged in as, if known
	 * @param   bool       $createdCookie  Whether the key is (being) issued
	 * @param   string     $refusal        Why the request was refused; empty when it was not
	 * @param   int        $targetUserId   The requested user ID
	 *
	 * @return  void
	 * @since   1.2.6
	 */
	private function logRequest(
		User $controlUser, ?User $targetUser, bool $createdCookie, string $refusal = '', int $targetUserId = 0
	): void
	{
		// Joomla 6.1+ no longer injects a dispatcher into subscriber plugins, so go through the application.
		$this->getApplication()->getDispatcher()->dispatch(
			'onSkeletonKeyRequestLogin',
			new Event('onSkeletonKeyRequestLogin', [
				'controlUser'   => $controlUser,
				'targetUser'    => $targetUser,
				'targetUserId'  => $targetUser ? $targetUser->id : $targetUserId,
				'createdCookie' => $createdCookie,
				'refusal'       => $refusal,
				'mfaBypass'     => (bool) ($this->params->get('bypass_mfa', 0) == 1),
			])
		);
	}

	/**
	 * Creates a cookie for logging in the specified user ID
	 *
	 * @param   int  $userId  The user ID for which a Skeleton Key cookie will be created
	 *
	 * @return  bool
	 * @since   1.0.0
	 */
	private function createCookie(int $userId): bool
	{
		// Make sure the user exists
		/** @var User $user */
		$user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($userId);

		if ($user->id != $userId)
		{
			return false;
		}

		// Get the cookie name
		$cookieName = self::COOKIE_PREFIX . $this->getHashedUserAgent();

		// Create a unique series
		$unique     = false;
		$errorCount = 0;

		$db = $this->getDatabase();

		do
		{
			$series = UserHelper::genRandomPassword(20);
			$query  = DbQuery::create(
				$db
			)
				->select(
					$db->quoteName('series'))
				->from(
					$db->quoteName('#__user_keys'))
				->where(
					$db->quoteName('series') . ' = :series')
				->bind(':series', $series);

			try
			{
				$results = $db->setQuery($query)->loadResult();

				if ($results === null)
				{
					$unique = true;
				}
			}
			catch (RuntimeException $e)
			{
				$errorCount++;

				// We'll let this query fail up to 5 times before giving up, there's probably a bigger issue at this point
				if ($errorCount === 5)
				{
					return false;
				}
			}
		} while ($unique === false);

		// Get the parameter values
		$lifetime = $this->params->get('cookie_lifetime', 10);
		$length   = $this->params->get('key_length', 32);

		// Generate new cookie
		$token       = UserHelper::genRandomPassword($length);
		$cookieValue = $token . '.' . $series;
		$hashedToken = UserHelper::hashPassword($token);

		// Create new record
		try
		{
			$future = (time() + $lifetime);
			$query  = DbQuery::create(
				$db
			);
			$query
				->insert(
					$db->quoteName('#__user_keys'))
				->set(
					$db->quoteName('user_id') . ' = :userid')
				->set(
					$db->quoteName('series') . ' = :series')
				->set(
					$db->quoteName('uastring') . ' = :uastring')
				->set(
					$db->quoteName('time') . ' = :time')
				->set(
					$db->quoteName('token') . ' = :token')
				->bind(':userid', $user->username)
				->bind(':series', $series)
				->bind(':uastring', $cookieName)
				->bind(':time', $future, ParameterType::INTEGER)
				->bind(':token', $hashedToken);
			$db->setQuery($query)->execute();
		}
		catch (RuntimeException $e)
		{
			return false;
		}

		// Set the cookie. The options-array form of Cookie::set() is available since before Joomla 5.4.
		$this->getApplication()->getInput()->cookie->set(
			$cookieName,
			$cookieValue,
			[
				'expires'  => $future,
				'path'     => $this->getApplication()->get('cookie_path', '/') ?: '/',
				'domain'   => $this->getApplication()->get('cookie_domain', ''),
				'secure'   => $this->isFrontendHttpsForced(),
				'httponly' => true,
				'samesite' => 'Strict',
			]
		);

		return true;
	}

	/**
	 * Parse the plugin options and populate the private variables
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	private function populateOptions(): void
	{
		$this->allowedControlGroups = $this->allowedControlGroups
			?? $this->params->get('allowedControlGroups', null)
			?? [8];

		if (is_string($this->allowedControlGroups))
		{
			$this->allowedControlGroups = array_map('intval', explode(',', $this->allowedControlGroups));
		}

		$this->allowedTargetGroups = $this->allowedTargetGroups
			?? $this->params->get('allowedTargetGroups', null)
			?? [2];

		if (is_string($this->allowedTargetGroups))
		{
			$this->allowedTargetGroups = array_map('intval', explode(',', $this->allowedTargetGroups));
		}

		$this->disallowedTargetGroups = $this->disallowedTargetGroups
			?? $this->params->get('disallowedTargetGroups', null)
			?? [7, 8];

		if (is_string($this->disallowedTargetGroups))
		{
			$this->disallowedTargetGroups = array_map('intval', explode(',', $this->disallowedTargetGroups));
		}
	}

	/**
	 * Get a hash of the user agent
	 *
	 * @return  string
	 *
	 * @since   1.0.0
	 */
	private function getHashedUserAgent(): string
	{
		// Scheme-neutral: with "Force HTTPS: Administrator only" the key is issued from an https:// back-end and looked
		// for on an http:// front-end, and both must derive the same name.
		return ApplicationHelper::getHash(preg_replace('#^https?:#i', '', Uri::root()) . $this->getApplication()->client->userAgent);
	}

	/**
	 * Is the FRONT-END served over HTTPS only?
	 *
	 * The key is issued from the back-end but used on the front-end, so the cookie's Secure flag depends on the
	 * front-end's scheme. isHttpsForced() answers for the back-end, where "Force HTTPS: Administrator only" is already
	 * enough to say yes; a Secure cookie would then never reach an HTTP front-end.
	 *
	 * @return  bool
	 * @since   1.2.6
	 */
	private function isFrontendHttpsForced(): bool
	{
		return (int) $this->getApplication()->get('force_ssl') === 2;
	}

	/**
	 * Add a result value to the event
	 *
	 * @param   Event  $event
	 * @param   mixed  $result
	 *
	 * @since   1.0.0
	 */
	private function addEventResult(Event $event, $result)
	{
		$values   = $event->getArgument('result', []) ?: [];
		$values[] = $result;

		$event->setArgument('result', $values);
	}
}