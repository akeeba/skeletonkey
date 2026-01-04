<?php
/*
 * @package   Skeletonkey
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Joomla\Plugin\System\Skeletonkey\Extension;

defined('_JEXEC') || die;

use Joomla\Application\ApplicationInterface;
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
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Utilities\ArrayHelper;
use RuntimeException;
use Throwable;

class Skeletonkey extends CMSPlugin implements SubscriberInterface
{
	private const COOKIE_PREFIX = "skeletonkey_";

	/**
	 * The application I am running under
	 *
	 * @var   ApplicationInterface|CMSApplication
	 * @since 1.0.0
	 */
	protected $app;

	/**
	 * @var    DatabaseDriver
	 * @since  1.0.0
	 */
	protected $db;

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
	public function __construct(&$subject, $config = [])
	{
		parent::__construct($subject, $config);

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
		if (!($this->app instanceof CMSApplication) || !$this->app->isClient('administrator'))
		{
			return;
		}

		// Make sure the current user is allowed to log into the site as another user.
		$currentUser = $this->app->getIdentity();

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

			$this->app->enqueueMessage(Text::_('PLG_SYSTEM_SKELETONKEY_LBL_NOAUTHPLUGIN'), CMSApplication::MSG_ERROR);

			return;
		}

		// Find the displayed users and tell the frontend JS which users should get login buttons
		$refObject = new \ReflectionObject($view);
		$refProp   = $refObject->getProperty('items');

		if (version_compare(PHP_VERSION, '8.1.0', 'lt'))
		{
			$refProp->setAccessible(true);
		}

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
		$document = $this->app->getDocument();
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
		if (!$this->app->isClient('site'))
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

		if ($this->app->getInput()->cookie->get($cookieName))
		{

			$this->app->login(['username' => ''], ['silent' => true]);
		}
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
		// Anti-CSRF token check
		if (!Session::checkToken('get'))
		{
			$this->addEventResult($event, false);

			return;
		}

		// Make sure this is the backend.
		if (!($this->app instanceof CMSApplication) || !$this->app->isClient('administrator'))
		{
			$this->addEventResult($event, false);

			return;
		}

		// Make sure the current user is allowed to log into the site as another user.
		$currentUser = $this->app->getIdentity();

		if (!($currentUser instanceof User) || empty(array_intersect($currentUser->getAuthorisedGroups(), $this->allowedControlGroups)))
		{
			$this->addEventResult($event, false);

			return;
		}

		// Make sure the authentication plugin is enabled.
		if (!PluginHelper::isEnabled('authentication', 'skeletonkey'))
		{
			$this->addEventResult($event, false);

			return;
		}

		// Make sure the requested user exists
		/** @var User $user */
		$userId = $this->app->getInput()->get->getInt('user_id');
		$user   = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($userId);

		if ($user->id <= 0 || $user->id != $userId)
		{
			$this->addEventResult($event, false);

			return;
		}

		// Make sure the requested user is allowed to be accessed via a Skeleton Key
		$allowedUser    = !empty(array_intersect($user->getAuthorisedGroups(), $this->allowedTargetGroups));
		$disallowedUser = !empty(array_intersect($user->getAuthorisedGroups(), $this->disallowedTargetGroups));

		if (!$allowedUser || $disallowedUser)
		{
			$this->addEventResult($event, false);

			return;
		}

		// Create the cookie
		$createdCookie = $this->createCookie($userId);

		// Trigger the Action Log plugin
		$this->getDispatcher()->dispatch(
			'onSkeletonKeyRequestLogin',
			new Event('onSkeletonKeyRequestLogin', [$currentUser, $user, $createdCookie])
		);

		// Return the event result back to com_ajax
		$this->addEventResult($event, $createdCookie);
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

		do
		{
			$series = UserHelper::genRandomPassword(20);
			$query  = (method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true))
				->select($this->db->quoteName('series'))
				->from($this->db->quoteName('#__user_keys'))
				->where($this->db->quoteName('series') . ' = :series')
				->bind(':series', $series);

			try
			{
				$results = $this->db->setQuery($query)->loadResult();

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
			$query  = (method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true));
			$query
				->insert($this->db->quoteName('#__user_keys'))
				->set($this->db->quoteName('user_id') . ' = :userid')
				->set($this->db->quoteName('series') . ' = :series')
				->set($this->db->quoteName('uastring') . ' = :uastring')
				->set($this->db->quoteName('time') . ' = :time')
				->set($this->db->quoteName('token') . ' = :token')
				->bind(':userid', $user->username)
				->bind(':series', $series)
				->bind(':uastring', $cookieName)
				->bind(':time', $future, ParameterType::INTEGER)
				->bind(':token', $hashedToken);
			$this->db->setQuery($query)->execute();
		}
		catch (RuntimeException $e)
		{
			return false;
		}

		// Set the cookie
		$this->app->getInput()->cookie->set(
			$cookieName,
			$cookieValue,
			$future,
			$this->app->get('cookie_path', '/'),
			$this->app->get('cookie_domain', ''),
			$this->app->isHttpsForced(),
			true
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
		return ApplicationHelper::getHash(Uri::root() . $this->app->client->userAgent);
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