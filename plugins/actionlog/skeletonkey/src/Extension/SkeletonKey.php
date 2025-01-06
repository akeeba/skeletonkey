<?php
/*
 * @package   Skeletonkey
 * @copyright Copyright (c)2022-2025 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\Plugin\ActionLog\SkeletonKey\Extension;

defined('_JEXEC') || die;

use Joomla\CMS\User\User;
use Joomla\Component\Actionlogs\Administrator\Plugin\ActionLogPlugin;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

/**
 * Plugin to handle the User Action Log entries when using Skeleton Key
 *
 * @since  1.0.0
 */
class SkeletonKey extends ActionLogPlugin implements SubscriberInterface
{
	/** @inheritdoc */
	protected $autoloadLanguage = true;

	/** @inheritdoc */
	public static function getSubscribedEvents(): array
	{
		return [
			'onSkeletonKeyRequestLogin' => 'onSkeletonKeyRequestLogin',
		];
	}

	/**
	 * Handles the onSkeletonKeyRequestLogin event fired by our system plugin.
	 *
	 * @param   Event  $event  The event being handled
	 *
	 * @return  void
	 * @since   1.0.0
	 * @see     \Joomla\Plugin\System\Skeletonkey\Extension\Skeletonkey::onAjaxSkeletonkey()
	 */
	public function onSkeletonKeyRequestLogin(Event $event)
	{
		/**
		 * @var  User $currentUser   User asking to log in as another user
		 * @var  User $user          The user to be logged in as
		 * @var  bool $createdCookie Was the login cookie created?
		 */
		[$currentUser, $user, $createdCookie] = array_values($event->getArguments());

		// The data to store for this record
		$data = [
			'asking_username'    => $currentUser->username,
			'asking_link'        => 'index.php?option=com_users&task=user.edit&id=' . $currentUser->id,
			'requested_username' => $user->username,
			'requested_link'     => 'index.php?option=com_users&task=user.edit&id=' . $user->id,
		];

		$languageKey = $createdCookie
			? 'PLG_ACTIONLOG_SKELETONKEY_LOG_REQUEST_SUCCESS'
			: 'PLG_ACTIONLOG_SKELETONKEY_LOG_REQUEST_FAIL';

		// The [$data] is not a typo; that's how Joomla! expects us to log user actions: an array of arrays.
		$this->addLog([$data], $languageKey, 'plg_system_skeletonkey', $currentUser->id);
	}
}