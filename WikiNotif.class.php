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

class WikiNotifier {

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
	 * @param User $user User that was created
	 * @param WikiPage $page Page that was edited or created, if applicable
	 */
	public function execute( $user, $page = false ) {
		$this->user = $user;
		$this->page = $page ?: '';
		$this->sendExternalMails();
		$this->sendInternalMails();
	}

	/**
	 * Send email to external addresses
	 */
	private function sendExternalMails() {
		global $wgWikiNotifEmailTargets;

		foreach ( $wgWikiNotifEmailTargets as $target ) {
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
	private function sendInternalMails() {
		global $wgWikiNotifTargets;

		foreach ( $wgWikiNotifTargets as $userSpec ) {
			$user = $this->makeUser( $userSpec );

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
			return wfMessage( 'wikinotif-newedit-subj', $wgSitename )->inContentLanguage()->text();
		}
		return wfMessage( 'wikinotif-newuser-subj', $wgSitename )->inContentLanguage()->text();
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
				$page->getTitle()->getText(),
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
		$notifier = new self();
		$notifier->execute( $user );
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
		$notifier = new self();
		$notifier->execute( $user, $wikiPage );
	}
}
