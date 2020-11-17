<?php
/**
 * @author	      Michiel Keijts
 * @link          https://github.com/michielkeijts/cake-user-sessions
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

namespace UserSessions\Http\Session;

use SessionHandlerInterface;
use Cake\ORM\TableRegistry;
use Cake\Core\InstanceConfigTrait;
use Cake\Routing\Router;
use Cake\ORM\Entity;
use Cake\Utility\Security;
use Cake\Http\ServerRequest;
use UserSessions\Helper\Detect;
use Cake\Core\App;
use UserSessions\Model\Table\UserSessionsTable;

/**
 * UserDatabaseSession is a custom session save handler to relate user_id
 * to active sessions. It honors all the defined CakePHP session configurations
 *
 */
class UserDatabaseSession implements SessionHandlerInterface
{
	use InstanceConfigTrait;
	
	/**
     * Reference to the table handling the session data
     *
     * @var \UserSessions\Model\Table\SessionsTable
     */
    protected $_table;

    /**
     * Number of seconds to mark the session as expired
     *
     * @var int
     */
    protected $_timeout;
	
	/**
	 * Delegat engine to acutally save sessions
	 * @var SessionHandlerInterface
	 */
	protected $_engine;
	
	/**
	 * The id of the session. Saved to see if it is renewed.
	 * @var Entity
	 */
	protected $_session = NULL;
	
	/**
	 * The actual session id which is saved. Once retrieved from database, save here.
	 * @var string
	 */
	protected $_session_id = FALSE;
	
	/**
	 * If initialized
	 * @var bool
	 */
	protected $initialized = FALSE;
	
	/**
	 * Default configuration, see __construct() 
	 * @var array
	 */
	protected $_defaultConfig = [
		'handler'	=> [
			'engine' => 'cache',
			'options' => [
				'config' => 'default'
			]
		],
		'model'		=> 'UserSessions.UserSessions',
		'getUserId' => ''
	];

    /**
     * Constructor. Looks at Session configuration information and
     * sets up the session model.
     *
	 * $config = [
	 *	'handler'	=> [
			'engine' => 'cache',
			'options' => [
	 			'config' => 'default'
			]
		],												// cache or name of session save handler to actually save sessions
		'model'			=> 'UserSessions.UserSessions'		// model class (Entity) Needs to implement UserSessionInterface
	 *  'getUserId'		=> ''							// string with userdef. functon which returns the user_id. Default empty to use builtin function
	 *  'tableLocator'  => TableLocator					// default  TableRegistry::getTableLocator()
	 * ];
	 * 
	 * 
     * @param array $config The configuration for this engine. It requires the 'model'
     * key to be present corresponding to the Table to use for managing the sessions.
	 *  
     */
    public function __construct(array $config = [])
    {
		$this->setConfig($config);
		
        $tableLocator = $this->getConfig('tableLocator', TableRegistry::getTableLocator());

        if (!$this->getConfig('model', FALSE)) {
            $config = $tableLocator->exists('Sessions') ? [] : ['table' => 'sessions'];
            $this->_table = $tableLocator->get('Sessions', $config);
        } else {
            $this->_table = $tableLocator->get($this->getConfig('model'));
        }

        $this->setTimeout(ini_get('session.gc_maxlifetime'));
		
		$this->setSaveHandler();
	}
	
	/**
	 * Try and find the user id for the current request.
	 * Works also if no user id can be found
	 * @return string|id User Id
	 */
	protected function getUserId () 
	{
		$userFn = $this->getConfig('getUserId', '');
		if (!empty($userFn)) {
			return $userFn();
		}
		
		// use identity object from Authentication plugin
		$identity = $this->getRequest()->getAttribute('identity', FALSE);
		if ($identity && method_exists($identity, 'getIdentifier'))
			return $identity->getIdentifier();
	
		// use identity object from Auth component
		$identity = $this->getRequest()->getSession()->read('Auth.User');
		if ($identity)
			return $identity['id']?:$identity->id;
		
		return;
	}
	
	/**
	 * The delegate Saveto delegate al
	 */
	public function setSaveHandler() : SessionHandlerInterface
	{
		$handler = $this->getConfig('handler.engine');
		switch ($handler) {
			case "cache":
				return $this->engine("CacheSession", $this->getConfig('handler.config'));
				break;
			case "database":
				return $this->engine("DatabaseSession", $this->getConfig('handler.config'));
			case "files":
				$this->engine("UserSessions.FilesSession", $this->getConfig('handler.config'));
				break;
			
			default:
				return $this->engine($handler, $this->getConfig('handler', []));
		}
	}
	
	/**
	 * Returns the delegate saveHandler
	 * @return SessionHandlerInterface
	 */
	public function getSaveHandler(): SessionHandlerInterface
	{
		return $this->_engine;
	}
	
	/**
	 * 
	 * @param SessionHandlerInterface $class
	 * @param array $options
	 * @return SessionHandlerInterface
	 * @throws InvalidArgumentException
	 */
	private function engine ($class = NULL, array $options = []) : SessionHandlerInterface
	{
		if ($class instanceof SessionHandlerInterface) {
            return $this->_engine = $class;
        }

        if ($class === null) {
            return $this->_engine;
        }

        $className = App::className($class, 'Http/Session');
        if (!$className) {
            throw new InvalidArgumentException(
                sprintf('The class "%s" does not exist and cannot be used as a session engine', $class)
            );
        }
		
		$handler = new $className($options);
		return $this->_engine = $handler;
	}
	
	/**
	 * Gets the Session Id From Database and saves
	 * @param type $id
	 * @return string
	 */
	private function initialize($id)
	{
		$this->initialized = TRUE;
		
		$field = $this->getTable()->getSessionIdField();
		$result = $this->getTable()
            ->find('all')
            ->where([$this->getTable()->getPrimaryKey() => $id])
            ->first();

        if (!empty($result)) {
			$this->_session = $result;
            return $this->_session_id = $result->get($field);
        } else {
			return $this->generateSessionId($id);
		}

		return  $this->_session_id = FALSE;
	}

    /**
     * Set the timeout value for sessions.
     *
     * Primarily used in testing.
     *
     * @param int $timeout The timeout duration.
     * @return $this
     */
    public function setTimeout($timeout)
    {
        $this->_timeout = $timeout;

        return $this;
    }

    /**
     * Method called on open of a session. 
	 * 
     * @param string $savePath The path where to store/retrieve the session.
     * @param string $name The session name.
     * @return bool Success
     */
    public function open($savePath, $name)
    {
        return true;
    }

    /**
     * Method called on close of a session. We update accessed timestamp
	 * as in the open parameter, we do not have the actual id
     *
     * @return bool Success
     */
    public function close()
    {
		$this->_session->set($this->getTable()->getAccessedField(), time());
		$this->saveSessionIdToDatabase($this->_session->id, $this->getRequest());
        return true;
    }

    /**
     * Method used to read from a database session.
     *
     * @param string|int $id ID that uniquely identifies session in database.
     * @return string Session data or empty string if it does not exist.
     */
    public function read($id)
    {
		if (!$this->initialized) {
			$this->initialize($id);
		}
		
		if (empty($this->_session_id)) {
			return '';
		}
		
		return $this->getSaveHandler()->read($this->_session_id);
    }

    /**
     * Helper function called on write for database sessions.
     *
     * @param string|int $id ID that uniquely identifies session in database.
     * @param mixed $data The data to be saved.
     * @return bool True for successful write, false otherwise.
     */
    public function write($id, $data)
    {
		if (!$id) {
            return false;
        }
		
		if (!$this->initialized) {
			$this->initialize($id);
		}
		
		if (empty($this->_session_id)) {
			return '';
		}
		
		// renewed ID, change database
		if ($id !== $this->_session->get($this->getTable()->getPrimaryKey())) {
			$this->regenerateSessionId($id);
		}
		
		if (!$this->_session->get($this->getTable()->getRelatedUserField()) && !empty($this->getUserId())) {
			$this->_session->set($this->getTable()->getRelatedUserField(), $this->getUserId());
			$this->saveSessionIdToDatabase($this->_session->get($this->getTable()->getPrimaryKey()), $this->getRequest());
		}

        return (bool)$this->getSaveHandler()->write($this->_session_id, $data);
    }

    /**
     * Method called on the destruction of a database session.
     *
     * @param string|int $id ID that uniquely identifies session in database.
     * @return bool True for successful delete, false otherwise.
     */
    public function destroy($id)
    {
		// more generic to 
		if ($id !== $this->_session->get($this->getTable()->getPrimaryKey())) {
			$session = $this->getTable()->findById($id)->first();
		} else {
			$session = $this->_session;
		}
		
        if (!($session instanceof Entity)) 
            return true;

        $session_id = $session->get($this->getTable()->getSessionIdField());

        $this->getTable()->delete($session);

        return $this->getSaveHandler()->destroy($session_id);
    }

    /**
     * Helper function called on gc for database sessions.
     *
     * @param int $maxlifetime Sessions that have not updated for the last maxlifetime seconds will be removed.
     * @return bool True on success, false on failure.
     */
    public function gc($maxlifetime)
    {
        $this->getTable()->deleteAll([$this->getTable()->getExpiresField() . ' <' => time() - $maxlifetime]);

        return $this->getSaveHandler()->gc($maxlifetime);
    }
	
		
	/**
	 * Creates database record for current session, when no record in the database exists
	 * 
	 * @return bool if successfull inserted
	 */
	protected function generateSessionId(string $id) : bool
	{
		if (empty($this->_session_id)) {
			$this->_session_id = $this->getRandomString(64);
			$insertedId = $this->saveSessionIdToDatabase($id, $this->getRequest())->id;
		}
		
		if ($insertedId === $id) {
			$this->_id = $insertedId;
		}
		
		return $insertedId === $id;
	}
	
	
	
	/**
	 * Update the current database session with the $newId. For example
	 * when a session_regenerate_id() is called in the application.
	 * 
	 * @return bool
	 */
	protected function regenerateSessionId(string $id) : bool
	{
		// only for regeneration
		if ($this->_session->get($this->getTable()->getPrimaryKey()) == $id)
			return true;
		
		if (empty($this->_session_id)) {
			$this->_session_id = $this->getRandomString(64);
		}
		
		$this->saveSessionIdToDatabase($id, $this->getRequest());
		
		return TRUE;
	}
	
	/**
	 * Saves the session id to the Database. 
	 * @param $id The Session identifier
	 * @param ServerRequest $request
	 * @param bool $isNew
	 * @return boolean
	 */
	protected function saveSessionIdToDatabase(string $id, ServerRequest $request)
	{
		if (empty($this->_session_id)) {
			return FALSE;
		}
		
		if (!$this->_session instanceof Entity) {
			$session = new Entity();
		} elseif ($this->_session->get($this->getTable()->getPrimaryKey()) != $id) {
			$session = clone $this->_session;
			$session->setNew(true);
		} else {
			$session = $this->_session;
		}
		
		$session->set($this->getTable()->getPrimaryKey(), $id);
		$session->set($this->getTable()->getSessionIdField(), $this->_session_id);
		$session->set($this->getTable()->getExpiresField(), time() + $this->_timeout);
		
		if ($session->isNew()) {
			$session->set($this->getTable()->getIpField(), $request->clientIp());
			$session->set($this->getTable()->getUseragentField(), $request->getHeaderLine('user-agent'));
			$session->set($this->getTable()->getDisplayField(), $this->getNameFromRequest($request));
		}
		
		return $this->_session = $this->getTable()->save($session);
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
	 * Return a random string
	 * @param int $length
	 * @return string
	 */
	protected function getRandomString($length) : string
	{
		return substr(bin2hex(Security::randomBytes($length)), 0, $length);
	}
	
	/**
	 * Get dummy Serverrequest
	 * @return ServerRequest
	 */
	protected function getRequest() : ServerRequest
	{
		if (Router::getRequest() instanceof ServerRequest)
			return Router::getRequest();
				
		return new ServerRequest(['environment'=>$_SERVER + $_ENV]);
	}
	
	/**
	 * Get the current Table instance
	 * @return Table
	 */
	protected function getTable() : UserSessionsTable
	{
		return $this->_table;
	}
}
