<?php
/*
 * @package   Skeletonkey
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Joomla\Plugin\Authentication\Skeletonkey\Extension;

defined('_JEXEC') || die;

use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\CMS\User\UserHelper;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Filter\InputFilter;
use Joomla\Registry\Registry;
use Joomla\Plugin\Authentication\Skeletonkey\Helper\DbQuery;
use RuntimeException;

class Skeletonkey extends CMSPlugin implements SubscriberInterface, DatabaseAwareInterface
{
	use DatabaseAwareTrait;

	private const COOKIE_PREFIX = "skeletonkey_";

	/**
	 * @inheritDoc
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onUserAuthenticate' => 'onUserAuthenticate',
			'onUserAfterLogout'  => 'onUserAfterLogout',
		];
	}

	/**
	 * Destroy any possible leftover cookie on logout
	 *
	 * @param   Event  $event  The onUserAfterLogout event
	 *
	 * @return  boolean  True on success
	 *
	 * @noinspection PhpUnused
	 * @since        1.0.0
	 */
	public function onUserAfterLogout(Event $event): bool
	{
		$this->destroyCookie();

		return true;
	}

	/**
	 * Handles authentication with Skeleton Key
	 *
	 * @param   Event  $event  The onUserAuthenticate event
	 *
	 * @return  bool  True on successful authentication
	 * @since   1.0.0
	 *
	 * @noinspection PhpUnused
	 */
	public function onUserAuthenticate(Event $event): bool
	{
		/**
		 * Joomla 7 dispatches a concrete AuthenticationEvent which exposes the authentication response
		 * through a typed getter. Joomla 4, 5 and 6 pass the response object as the event's 'subject'
		 * argument. Either way it is the same object the Authentication helper reads back, so mutating
		 * its properties below propagates the result.
		 */
		if (version_compare(JVERSION, '6.999.999', 'gt'))
		{
			/** @var \Joomla\CMS\Event\User\AuthenticationEvent $event */
			$response = $event->getAuthenticationResponse();
		}
		else
		{
			$response = $event->getArgument('subject');
		}

		// Skeleton key only works in the frontend
		if (!$this->getApplication()->isClient('site'))
		{
			return false;
		}

		// Make sure the system plugin is enabled. If not, abort.
		if (!PluginHelper::isEnabled('system', 'skeletonkey'))
		{
			$this->destroyCookie();

			return false;
		}

		// Get the cookie. If it does not exist, give up.
		$cookieName  = self::COOKIE_PREFIX . $this->getHashedUserAgent();
		$cookieValue = $this->getApplication()->getInput()->cookie->get($cookieName);

		if (!$cookieValue)
		{
			return false;
		}

		$cookieArray = explode('.', $cookieValue);

		// Check for valid cookie value
		if (count($cookieArray) !== 2)
		{
			$this->destroyCookie();

			Log::add('Invalid cookie detected.', Log::WARNING, 'error');

			return false;
		}

		/**
		 * Joomla treats a 'Cookie' login as a silent login and, by default, skips MFA for it. We only want
		 * that when the administrator explicitly enabled "Bypass Multi-factor Authentication"; otherwise use
		 * our own response type so Joomla's MFA gate applies as it would to any other login.
		 */
		$response->type = $this->isMfaBypassEnabled() ? 'Cookie' : 'SkeletonKey';

		// Filter series since we're going to use it in the query
		$filter = new InputFilter();
		$series = $filter->clean($cookieArray[1], 'ALNUM');
		$now    = time();

		// Remove expired tokens
		$db = $this->getDatabase();

		$query = DbQuery::create($db)
		                ->delete($db->quoteName('#__user_keys'))
		                ->where($db->quoteName('time') . ' < :now')
		                ->bind(':now', $now);

		try
		{
			$db->setQuery($query)->execute();
		}
		catch (RuntimeException $e)
		{
			// We aren't concerned with errors from this query, carry on
		}

		// Find the matching record if it exists. Expiry is enforced here too: the purge above may have failed.
		$query = DbQuery::create($db)
		                  ->select($db->quoteName(['user_id', 'token', 'series', 'time']))
		                  ->from($db->quoteName('#__user_keys'))
		                  ->where($db->quoteName('series') . ' = :series')
		                  ->where($db->quoteName('time') . ' >= :notexpired')
		                  ->where($db->quoteName('uastring') . ' = :uastring')
		                  ->order($db->quoteName('time') . ' DESC')
		                  ->bind(':series', $series)
		                  ->bind(':notexpired', $now)
		                  ->bind(':uastring', $cookieName);

		try
		{
			$results = $db->setQuery($query)->loadObjectList();
		}
		catch (RuntimeException $e)
		{
			$this->destroyCookie();

			$response->status = Authentication::STATUS_FAILURE;

			return false;
		}

		if (count($results) !== 1)
		{
			$this->destroyCookie();

			$response->status = Authentication::STATUS_FAILURE;

			return false;
		}

		// We have a user with one cookie with a valid series and a corresponding record in the database.
		if (!UserHelper::verifyPassword($cookieArray[0], $results[0]->token))
		{
			/*
			 * This is a real attack!
			 * Either the series was guessed correctly or a cookie was stolen and used twice (once by attacker and once by victim).
			 * Delete all tokens for this user!
			 */
			$query = DbQuery::create($db)
			                  ->delete($db->quoteName('#__user_keys'))
			                  ->where($db->quoteName('user_id') . ' = :userid')
			                  ->bind(':userid', $results[0]->user_id);

			try
			{
				$db->setQuery($query)->execute();
			}
			catch (RuntimeException $e)
			{
				// Log an alert for the site admin
				Log::add(
					sprintf('Failed to delete cookie token for user %s with the following error: %s', $results[0]->user_id, $e->getMessage()),
					Log::WARNING,
					'security'
				);
			}

			// Issue warning by email to user and/or admin?
			// The key's user_id column holds the username, so look up the numeric ID to name the account fully.
			Log::add(
				sprintf(
					'Skeleton Key login failed for user %s (#%d).',
					preg_replace('/[[:cntrl:]]/', '', (string) $results[0]->user_id),
					(int) UserHelper::getUserId($results[0]->user_id)
				),
				Log::WARNING,
				'security'
			);

			$this->destroyCookie();

			$response->status = Authentication::STATUS_FAILURE;

			return false;
		}

		// Make sure there really is a user with this name and get the data for the session.
		$query = DbQuery::create($db)
		                  ->select($db->quoteName(['id', 'username', 'password']))
		                  ->from($db->quoteName('#__users'))
		                  ->where($db->quoteName('username') . ' = :userid')
		                  ->where($db->quoteName('requireReset') . ' = 0')
		                  ->bind(':userid', $results[0]->user_id);

		try
		{
			$result = $db->setQuery($query)->loadObject();
		}
		catch (RuntimeException $e)
		{
			$this->destroyCookie();

			$response->status = Authentication::STATUS_FAILURE;

			return false;
		}

		if (!$result)
		{
			$this->destroyCookie();

			$response->status        = Authentication::STATUS_FAILURE;
			$response->error_message = Text::_('JGLOBAL_AUTH_NO_USER');

			return false;
		}

		// Bring this in line with the rest of the system
		$user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($result->id);

		// Set response data.
		$response->username = $result->username;
		$response->email    = $user->email;
		$response->fullname = $user->name;
		$response->password = $result->password;
		$response->language = $user->getParam('language');

		// Set response status.
		$response->status        = Authentication::STATUS_SUCCESS;
		$response->error_message = '';

		$this->destroyCookie();

		// Audit the use of the key. The impersonated user is the one who ends up logged in.
		$this->getApplication()->getDispatcher()->dispatch(
			'onSkeletonKeyRedeemLogin',
			new Event('onSkeletonKeyRedeemLogin', ['targetUser' => $user])
		);

		return true;
	}

	/**
	 * Is the "Bypass Multi-factor Authentication" option of the system plugin enabled?
	 *
	 * @return  bool
	 * @since   1.2.6
	 */
	private function isMfaBypassEnabled(): bool
	{
		$plugin = PluginHelper::getPlugin('system', 'skeletonkey');

		if (!is_object($plugin) || empty($plugin->params))
		{
			return false;
		}

		return (new Registry($plugin->params))->get('bypass_mfa', 0) == 1;
	}

	/**
	 * Destroy the Skeleton Key cookie
	 *
	 * @since   1.0.0
	 */
	private function destroyCookie()
	{
		// Skeleton key only works in the frontend
		if (!$this->getApplication()->isClient('site'))
		{
			return;
		}

		$cookieName  = self::COOKIE_PREFIX . $this->getHashedUserAgent();
		$cookieValue = $this->getApplication()->getInput()->cookie->get($cookieName);

		// There are no cookies to delete.
		if (!$cookieValue)
		{
			return;
		}

		$cookieArray = explode('.', $cookieValue);

		// Filter series since we're going to use it in the query
		$filter = new InputFilter();
		$series = $filter->clean($cookieArray[1] ?? '', 'ALNUM');

		/**
		 * Remove the record from the database. Only the record issued to THIS browser (the cookie name is derived
		 * from the user agent) may be removed: someone who has merely seen a series must not be able to void
		 * somebody else's key by presenting it from another browser.
		 */
		if ($series !== '')
		{
			$db    = $this->getDatabase();
			$query = DbQuery::create($db)
			                ->delete($db->quoteName('#__user_keys'))
			                ->where($db->quoteName('series') . ' = :series')
			                ->where($db->quoteName('uastring') . ' = :uastring')
			                ->bind(':series', $series)
			                ->bind(':uastring', $cookieName);

			try
			{
				$db->setQuery($query)->execute();
			}
			catch (RuntimeException $e)
			{
				// We aren't concerned with errors from this query, carry on
			}
		}

		// Destroy the cookie. The options-array form of Cookie::set() is available since before Joomla 5.4.
		$this->getApplication()->getInput()->cookie->set(
			$cookieName,
			'',
			[
				'expires'  => 1,
				'path'     => $this->getApplication()->get('cookie_path', '/') ?: '/',
				'domain'   => $this->getApplication()->get('cookie_domain', ''),
				'secure'   => $this->getApplication()->isHttpsForced(),
				'httponly' => true,
				'samesite' => 'Strict',
			]
		);
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

}