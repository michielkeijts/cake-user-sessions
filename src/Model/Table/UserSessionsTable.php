<?php

namespace UserSessions\Model\Table;

use Cake\Http\ServerRequest;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\Query;
use Cake\Routing\Router;
use Cake\Utility\Security;
use UserSessions\Helper\Detect;
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

        $this->setEntityClass('UserSessions.UserSession');
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

    public function afterDelete(EventInterface $event, EntityInterface $entity, ArrayObject $options)
    {
        try {
            $handler = Router::getRequest()->getSession()->engine();
            $handler->destroy($entity->session_id);
        } catch (\Exception $e) {
            Log::error("[Session] Delete " . $e->getMessage() );
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

    public function findNotExpired(Query $query, array $options) : Query
    {
        return $query->where([$this->getExpiresField() => new DateTime()]);
    }

    /**
     * @param int $user_id
     * @return bool
     */
    public function deleteByUserId(int $user_id) : bool
    {
        $user_sessions = $this->findByUserId($user_id);
        foreach ($user_sessions as $user_session) {
            $this->delete($user_session);
        }

        return TRUE;
    }

    /**
     * @param int $user_id
     * @return bool
     */
    public function deleteExpired() : bool
    {
        $user_sessions = $this->find('NotExpired');
        foreach ($user_sessions as $user_session) {
            $this->delete($user_session);
        }

        return TRUE;
    }
}
