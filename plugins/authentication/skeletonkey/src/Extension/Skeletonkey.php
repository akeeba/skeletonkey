<?php
/*
 * @package   Skeletonkey
 * @copyright Copyright © 2022-2022 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Joomla\Plugin\Authentication\Skeletonkey\Extension;

defined('_JEXEC') || die;

use Joomla\Application\ApplicationInterface;
use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\CMS\User\UserHelper;
use Joomla\Database\DatabaseDriver;
use Joomla\Filter\InputFilter;
use RuntimeException;

class Skeletonkey extends CMSPlugin
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
	 * @var   DatabaseDriver
	 * @since 1.0.0
	 */
	protected $db;

	/**
	 * Destroy any possible leftover cookie on logout
	 *
	 * @param   array  $options  Array holding options (length, timeToExpiration)
	 *
	 * @return  boolean  True on success
	 *
	 * @noinspection PhpUnused
	 * @since        1.0.0
	 */
	public function onUserAfterLogout(array $options): bool
	{
		$this->destroyCookie();

		return true;
	}

	/**
	 * Handles authentication with Skeleton Key
	 *
	 * @param   array    $credentials  Array holding the user credentials
	 * @param   array    $options      Array of extra options
	 * @param   object  &$response     Authentication response object
	 *
	 * @return  bool  True on successful authentication
	 * @since   1.0.0
	 *
	 * @noinspection PhpUnused
	 */
	public function onUserAuthenticate(array $credentials, array $options, object &$response): bool
	{
		// Skeleton key only works in the frontend
		if (!$this->app->isClient('site'))
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
		$cookieValue = $this->app->input->cookie->get($cookieName);

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

		// We are faking this to prevent TFA from kicking in.
		$response->type = 'Cookie';

		// Filter series since we're going to use it in the query
		$filter = new InputFilter();
		$series = $filter->clean($cookieArray[1], 'ALNUM');
		$now    = time();

		// Remove expired tokens
		$query = $this->db->getQuery(true)
		                  ->delete($this->db->quoteName('#__user_keys'))
		                  ->where($this->db->quoteName('time') . ' < :now')
		                  ->bind(':now', $now);

		try
		{
			$this->db->setQuery($query)->execute();
		}
		catch (RuntimeException $e)
		{
			// We aren't concerned with errors from this query, carry on
		}

		// Find the matching record if it exists.
		$query = $this->db->getQuery(true)
		                  ->select($this->db->quoteName(['user_id', 'token', 'series', 'time']))
		                  ->from($this->db->quoteName('#__user_keys'))
		                  ->where($this->db->quoteName('series') . ' = :series')
		                  ->where($this->db->quoteName('uastring') . ' = :uastring')
		                  ->order($this->db->quoteName('time') . ' DESC')
		                  ->bind(':series', $series)
		                  ->bind(':uastring', $cookieName);

		try
		{
			$results = $this->db->setQuery($query)->loadObjectList();
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
			$query = $this->db->getQuery(true)
			                  ->delete($this->db->quoteName('#__user_keys'))
			                  ->where($this->db->quoteName('user_id') . ' = :userid')
			                  ->bind(':userid', $results[0]->user_id);

			try
			{
				$this->db->setQuery($query)->execute();
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
			Log::add(sprintf('Skeleton Key login failed for user %u.', $results[0]->user_id), Log::WARNING, 'security');

			$this->destroyCookie();

			$response->status = Authentication::STATUS_FAILURE;

			return false;
		}

		// Make sure there really is a user with this name and get the data for the session.
		$query = $this->db->getQuery(true)
		                  ->select($this->db->quoteName(['id', 'username', 'password']))
		                  ->from($this->db->quoteName('#__users'))
		                  ->where($this->db->quoteName('username') . ' = :userid')
		                  ->where($this->db->quoteName('requireReset') . ' = 0')
		                  ->bind(':userid', $results[0]->user_id);

		try
		{
			$result = $this->db->setQuery($query)->loadObject();
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

		return true;
	}

	/**
	 * Destroy the Skeleton Key cookie
	 *
	 * @since   1.0.0
	 */
	private function destroyCookie()
	{
		// Skeleton key only works in the frontend
		if (!$this->app->isClient('site'))
		{
			return;
		}

		$cookieName  = self::COOKIE_PREFIX . $this->getHashedUserAgent();
		$cookieValue = $this->app->input->cookie->get($cookieName);

		// There are no cookies to delete.
		if (!$cookieValue)
		{
			return;
		}

		$cookieArray = explode('.', $cookieValue);

		// Filter series since we're going to use it in the query
		$filter = new InputFilter();
		$series = $filter->clean($cookieArray[1], 'ALNUM');

		// Remove the record from the database
		$query = $this->db->getQuery(true)
		                  ->delete($this->db->quoteName('#__user_keys'))
		                  ->where($this->db->quoteName('series') . ' = :series')
		                  ->bind(':series', $series);

		try
		{
			$this->db->setQuery($query)->execute();
		}
		catch (RuntimeException $e)
		{
			// We aren't concerned with errors from this query, carry on
		}

		// Destroy the cookie
		$this->app->input->cookie->set($cookieName, '', 1, $this->app->get('cookie_path', '/'), $this->app->get('cookie_domain', ''));
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

}