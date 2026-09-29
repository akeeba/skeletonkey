<?php
/*
 * @package   Skeletonkey
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos
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

	/** Language keys for the reasons a key request may be refused. */
	private const REFUSAL_KEYS = [
		'token'       => 'PLG_ACTIONLOG_SKELETONKEY_LOG_REFUSED_TOKEN',
		'requester'   => 'PLG_ACTIONLOG_SKELETONKEY_LOG_REFUSED_REQUESTER',
		'unavailable' => 'PLG_ACTIONLOG_SKELETONKEY_LOG_REFUSED_UNAVAILABLE',
		'notfound'    => 'PLG_ACTIONLOG_SKELETONKEY_LOG_REFUSED_NO_USER',
		'target'      => 'PLG_ACTIONLOG_SKELETONKEY_LOG_REFUSED_TARGET',
	];

	/** @inheritdoc */
	public static function getSubscribedEvents(): array
	{
		return [
			'onSkeletonKeyRequestLogin' => 'onSkeletonKeyRequestLogin',
			'onSkeletonKeyRedeemLogin'  => 'onSkeletonKeyRedeemLogin',
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
		 * @var  User      $currentUser   User asking to log in as another user
		 * @var  User|null $user          The user to be logged in as, if it exists
		 * @var  int       $userId        The ID of the user asked for
		 * @var  bool      $createdCookie Was the login cookie created?
		 * @var  bool      $mfaBypass     Was Multi-factor Authentication bypassed for this login?
		 * @var  string    $refusal       Why the request was refused; empty if it was not
		 */
		$currentUser   = $event->getArgument('controlUser');
		$user          = $event->getArgument('targetUser');
		$userId        = (int) $event->getArgument('targetUserId', $user ? $user->id : 0);
		$createdCookie = $event->getArgument('createdCookie');
		$mfaBypass     = $event->getArgument('mfaBypass', false);
		$refusal       = (string) $event->getArgument('refusal', '');

		// The data to store for this record
		$data = [
			'asking_username'    => $currentUser->username,
			'asking_link'        => 'index.php?option=com_users&task=user.edit&id=' . $currentUser->id,
			'requested_id'       => $userId,
			'requested_username' => $user ? $user->username : '',
			'requested_link'     => 'index.php?option=com_users&task=user.edit&id=' . $userId,
		];

		if ($refusal !== '')
		{
			$languageKey = self::REFUSAL_KEYS[$refusal] ?? 'PLG_ACTIONLOG_SKELETONKEY_LOG_REFUSED_REQUESTER';
		}
		elseif (!$createdCookie)
		{
			$languageKey = 'PLG_ACTIONLOG_SKELETONKEY_LOG_REQUEST_FAIL';
		}
		elseif ($mfaBypass)
		{
			$languageKey = 'PLG_ACTIONLOG_SKELETONKEY_LOG_REQUEST_SUCCESS_MFABYPASS';
		}
		else
		{
			$languageKey = 'PLG_ACTIONLOG_SKELETONKEY_LOG_REQUEST_SUCCESS';
		}

		// The [$data] is not a typo; that's how Joomla! expects us to log user actions: an array of arrays.
		$this->addLog([$data], $languageKey, 'plg_system_skeletonkey', $currentUser->id);
	}

	/**
	 * Handles the onSkeletonKeyRedeemLogin event fired by our authentication plugin when a key is used.
	 *
	 * @param   Event  $event  The event being handled
	 *
	 * @return  void
	 * @since   1.2.6
	 * @see     \Joomla\Plugin\Authentication\Skeletonkey\Extension\Skeletonkey::onUserAuthenticate()
	 */
	public function onSkeletonKeyRedeemLogin(Event $event)
	{
		/** @var User $user The user who was logged in */
		$user = $event->getArgument('targetUser');

		$data = [
			'requested_username' => $user->username,
			'requested_link'     => 'index.php?option=com_users&task=user.edit&id=' . $user->id,
		];

		// The session belongs to the impersonated user, and that is who the entry is attributed to.
		$this->addLog([$data], 'PLG_ACTIONLOG_SKELETONKEY_LOG_REDEEMED', 'plg_system_skeletonkey', $user->id);
	}
}
