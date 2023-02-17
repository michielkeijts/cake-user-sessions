<?php

namespace UserSessions\Model\Table;

use Cake\Http\ServerRequest;
use Cake\ORM\Query;
use Cake\Routing\Router;
use Cake\Utility\Security;
use UserSessions\Helper\Detect;
use UserSessions\Model\Table\UserSessionInterface;
use Cake\ORM\Table;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use ArrayObject;

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
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->addBehavior('Timestamp');
	}


	/**
	 * {@inheritDoc}
     * {
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

    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options)
    {
        if ($entity->isNew()) {
            $request = $this->getRequest();
            $entity->id = $this->getRandomString(128);
            $entity->set($this->getIpField(), $request->clientIp());
            $entity->set($this->getUseragentField(), $request->getHeaderLine('user-agent'));
            $entity->set($this->getDisplayField(), $this->getNameFromRequest($request));
        }
    }

    /**
     * Gets a nice formatted name for this session
     * @param ServerRequest $request
     * @return type
     */
    protected function getNameFromRequest(ServerRequest $request)
    {
        Detect::init();
        return sprintf("%s on %s (%s %s)",
            Detect::browser(),
            Detect::os(),
            Detect::brand(),
            Detect::deviceType()
        );
    }

    /**
     * Get dummy Server request
     * @return ServerRequest
     */
    public function getRequest() : ServerRequest
    {
        if (Router::getRequest() instanceof ServerRequest)
            return Router::getRequest();

        return new ServerRequest(['environment'=>$_SERVER + $_ENV]);
    }

    /**
     * Return a random string
     * @param int $length
     * @return string
     */
    protected function getRandomString($length) : string
    {
        return substr(bin2hex(Security::randomBytes($length)), 0, $length);
    }

    /**
     * Return active sessions for a user ($options['user_id'])
     * @param Query $query
     * @param array $options
     * @return Query
     */
    public function findActiveSessions(Query $query, array $options) : Query
    {
        $user_id = $options['user_id'] ?? -1;
        $field = $this->getRelatedUserField();
        return $query->where([$field => $user_id])->orderDesc('accessed');
    }
}
