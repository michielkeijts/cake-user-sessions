<?php

namespace UserSessions\Model\Table;

use UserSessions\Model\Table\UserSessionInterface;
use Cake\ORM\Table;

/**
 * Class to define the UserSessonInterface hanlder
 *
 * @author michiel
 */
class UserSessionsTable extends Table implements UserSessionInterface {
	
	/**
     * Initialize method
     *
     * @param array $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config)
    {
        parent::initialize($config);
        $this->addBehavior('Timestamp');
	}


	/**
	 * {@inheritDoc}
	 */
	public function getSessionIdField() : string
	{
		return 'session_id';
	}
	
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
	
	/**
	 * {@inheritDoc}
	 */
	public function getAccessedField():string
	{
		return 'accessed';
	}
}
