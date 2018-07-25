<?php

namespace UserSessions\Model\Entity;

/**
 * Abstract class to define the UserSessonInterface hanlder
 *
 * @author michiel
 */
interface UserSessionInterface {

	/**
	 * Returns the name of the related user id field (e.g. user_id)
	 * @return string
	 */
	public function getRelatedUserField() : string;	
	
	/**
	 * Returns the name of the field indicating when a session expires
	 * @return string
	 */
	public function getExpiresField() : string;
	
	/**
	 * Returns the name of the field storing useragent info (if any)
	 * Leave empty for none
	 * @return string
	 */
	public function getUseragentField() : string;
	
	/**
	 * Returns the name of the field storing ip address of user info (if any)
	 * Leave empty for none
	 * @return string
	 */
	public function getIpField() : string;
}
