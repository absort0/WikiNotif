<?php

/**
 * Extension to provide customisable email notification
 * on new user creation and page editing or creation
 *
 * @file
 * @ingroup Extensions
 * @author Igor Absorto <igor [at] absorto.dev>
 * (Based on code from Rob Church's extension
 * NewUserNotif https://www.mediawiki.org/wiki/Extension:New_User_Email_Notification)
 */

use MediaWiki\MediaWikiServices;

class WikiNotif {

	/** @var string */
	private $sender;

	/** @var User */
	private $user;

	/** @var Page */
	private $page;

	public function __construct() {
		global $wgWikiNotifSender;

		$this->sender = $wgWikiNotifSender;
	}

	/**
	 * Send all email notifications
	 *
	 * @param User $user User newly created or the author of the new rev
	 * @param string $type type of the notification, 'new-rev' or 'new-user'
	 * @param WikiPage $page Page that was edited or created, if applicable
	 */
	public function execute( $user, $type, $page = false ) {
		$this->user   = $user;
		$this->page   = $page ?: '';

		# Get notification targets based on the type of the action
		$targets = [];
		$groups  = $this->getGroups();
		if ( $type == 'new-user' ) {
			$targets = $groups['sysop'];
		} elseif ( $type == 'new-rev' ) {
			$targets = array_merge( $groups['sysop'], $groups['editor'] );
		}

		$this->sendExternalMails( $targets );
		$this->sendInternalMails( $targets );
	}

	/**
	 * Get groups of users
	 * user not part of the sysop or editor groups are ignored
	 * @return array $groups
	 */
	private function getGroups() {
		global $wgWikiNotifTargets;

		$groups = [];
		foreach ( $wgWikiNotifTargets as $target ) {
			$user = $this->makeUser( $target );
			if ( $user != null ) {
				if ( in_array( 'sysop', $user->getGroups() ) ) {
					$groups['sysop'][] = $target;
				} elseif ( in_array( 'editor', $user->getGroups() ) ) {
					$groups['editor'][] = $target;
				}
			}
		}
		wfDebugLog( __METHOD__, 'groups are ' . var_export( $groups, true ) );
		return $groups;
	}

	/**
	 * Send email to external addresses
	 */
	private function sendExternalMails( $targets ) {
		foreach ( $targets as $target ) {
			UserMailer::send(
				new MailAddress( $target ),
				new MailAddress( $this->sender ),
				$this->makeSubject( $target, $this->user, $this->page ),
				$this->makeMessage( $target, $this->user, $this->page )
			);
		}
	}

	/**
	 * Send email to users
	 */
	private function sendInternalMails( $targets ) {
		foreach ( $targets as $target ) {
			$user = $this->makeUser( $target );
			if ( $user instanceof User && $user->isEmailConfirmed() ) {
				$user->sendMail(
					$this->makeSubject( $user->getName(), $this->user, $this->page ),
					$this->makeMessage( $user->getName(), $this->user, $this->page ),
					$this->sender
				);
			}
		}
	}

	/**
	 * Initialise a user from an identifier or a username
	 *
	 * @param mixed $spec User identifier or name
	 * @return User|null
	 */
	private function makeUser( $spec ) {
		$name = is_int( $spec ) ? User::whoIs( $spec ) : $spec;
		$user = User::newFromName( $name );

		if ( $user instanceof User && $user->getId() > 0 ) {
			return $user;
		}

		return null;
	}

	/**
	 * Build a notification email subject line
	 *
	 * @param string $recipient Name of the recipient
	 * @param User $user Either the user that was created or the user who edited/created the page
	 * @param WikiPage $page Page that was edited/created
	 * @return string
	 */
	private function makeSubject( $recipient, $user, $page ) {
		global $wgSitename;

		if ( $this->page ) {
			return wfMessage( 'wikinotif-newedit-subj', $wgSitename )
				->inContentLanguage()->text();
		}
		return wfMessage( 'wikinotif-newuser-subj', $wgSitename )
			->inContentLanguage()->text();
	}

	/**
	 * Build a notification email message body
	 *
	 * @param string $recipient Name of the recipient
	 * @param User $user User that was created or the that edited/created page
	 * @param WikiPage $page WikiPage that was edited/created
	 * @return string
	 */
	private function makeMessage( $recipient, $user, $page ) {
		global $wgSitename;

		$contentLanguage = MediaWikiServices::getInstance()->getContentLanguage();

		if ( $this->page ) {
			return wfMessage(
				'wikinotif-newedit-body',
				$recipient,
				$page->getTitle()->getFullUrl(),
				$user->getName(),
				$wgSitename,
				$contentLanguage->timeAndDate( wfTimestampNow() ),
				$contentLanguage->date( wfTimestampNow() ),
				$contentLanguage->time( wfTimestampNow() )
			)->inContentLanguage()->text();
		}
		return wfMessage(
			'wikinotif-newuser-body',
			$recipient,
			$user->getName(),
			$wgSitename,
			$contentLanguage->timeAndDate( wfTimestampNow() ),
			$contentLanguage->date( wfTimestampNow() ),
			$contentLanguage->time( wfTimestampNow() )
		)->inContentLanguage()->text();
	}

	public static function onRegistration() {
		global $wgWikiNotifSender, $wgPasswordSender;

		/**
		 * Email address to use as the sender
		 */
		$wgWikiNotifSender = $wgPasswordSender;
	}

	/**
	 * Hook account creation
	 *
	 * @param User $user User that was created
	 * @param bool $autocreated Whether this was an auto-created account
	 */
	public static function onLocalUserCreated( $user, $autocreated ) {
		$type = 'new-user';
		$notifier = new self();
		$notifier->execute( $user, $type );
	}

	/**
	 * Hook page creation or editing
	 *
	 * @param WikiPage $wikiPage WikiPage edited
	 * @param RevisionRecord $rev New revision
	 * @param int|bool $originalRevId
	 * @param UserIdentity $user Editing user
	 * @param string[] &$tags Tags to apply to the edit and recent change.
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public static function onRevisionFromEditComplete(
		$wikiPage,
		$rev,
		$originalRevId,
		$user,
		&$tags
	) {
		$type = 'new-rev';
		$notifier = new self();
		$notifier->execute( $user, $type, $wikiPage );
	}
}
