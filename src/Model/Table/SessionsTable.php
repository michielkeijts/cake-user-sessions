<?php

namespace UserSessions\Model\Entity;

use UserSessions\Model\Entity\UserSessionInterface;
/**
 * Class to define the UserSessonInterface hanlder
 *
 * @author michiel
 */
class SessionsTable extends Table implements UserSessionInterface {
	
	/**
	 * {@inheritDoc}
	 */
	public function getRelatedUserField():string
	{
		return 'user_id';
	}
	
	/**
	 * {@inheritDoc}
	 */
	public function getExpiresField():string
	{
		return 'expires';
	}
	
	/**
	 * {@inheritDoc}
	 */
	public function getUseragentField():string
	{
		return 'useragent';
	}
	
	/**
	 * {@inheritDoc}
	 */
	public function getIpField():string
	{
		return 'ip';
	}
}
