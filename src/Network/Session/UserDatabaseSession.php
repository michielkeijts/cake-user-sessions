<?php
/**
 * @author	      Michiel Keijts
 * @link          https://github.com/michielkeijts/cake-user-sessions
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

namespace UserSessions\Network\Session;

use SessionHandlerInterface;
use Cake\ORM\TableRegistry;
use Cake\Core\InstanceConfigTrait;
use Cake\Routing\Router;
use Cake\ORM\Entity;
use Cake\Utility\Security;
use Cake\Http\ServerRequest;
use UserSessions\Helper\Detect;

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
	 * @var string
	 */
	protected $_id = '';
	
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
			'config' => 'default',
		],
		'model'		=> 'UserSessions.Session',
		'getUserId' => ''
	];

    /**
     * Constructor. Looks at Session configuration information and
     * sets up the session model.
     *
	 * $config = [
	 *	'handler'	=> [
			'engine' => 'cache',
			'config' => 'default',
		],												// cache or name of session save handler to actually save sessions
		'model'			=> 'UserSessions.Session'		// model class (Entity) Needs to implement UserSessionInterface
	 *  'getUserId'		=> ''							// string with userdef. functon which returns the user_id. Default empty to use builtin function
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
		
        $tableLocator = isset($config['tableLocator']) ? $config['tableLocator'] : TableRegistry::getTableLocator();

        if (empty($config['model'])) {
            $config = $tableLocator->exists('Sessions') ? [] : ['table' => 'sessions'];
            $this->_table = $tableLocator->get('Sessions', $config);
        } else {
            $this->_table = $tableLocator->get($config['model']);
        }

        $this->_timeout = ini_get('session.gc_maxlifetime');
	}
	
	/**
	 * Try and find the user id for the current request.
	 * Works also if no user id can be found
	 * @return string|id User Id
	 */
	protected function getUserId () {
		$userFn = $this->getConfig('getUserId', '');
		if (!empty($userFn)) {
			return $userFn();
		}
		
		// use identity object from Authentication plugin
		$identity = Router::getRequest(true)->getAttribute('identity', FALSE);
		if ($identity && method_exists($identity, 'getIdentifier'))
			return $identity->getIdentifier();
	
		// use identity object from Auth component
		$identity = Router::getRequest(true)->getSession('Auth.User', FALSE);
		if ($identity)
			return $identity['id']?:$identity->id;
		
		return;
	}
	
	/**
	 * The delegate Saveto delegate al
	 */
	public function setSaveHandler() : SessionHandlerInterface
	{
		$handler = $this->getConfig('handler');
		switch ($handler) {
			case "cache":
				return $this->engine("CacheSession", $this->getConfig('handler.options'));
				break;
			case "database":
				return $this->engine("DatabaseSession", $this->getConfig('handler.options'));
			case "files":
				$this->engine("UserSessions.FilesSession", $this->getConfig('handler.options'));
				break;
			
			default:
				return $this->engine($handler, $this->getConfig('handler.options'));
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

        $className = App::className($class, 'Network/Session');
        if (!$className) {
            throw new InvalidArgumentException(
                sprintf('The class "%s" does not exist and cannot be used as a session engine', $class)
            );
        }
		
		$handler = new $className($options);
		$this->_engine = $handler;
	}
	
	/**
	 * Gets the Session Id From Database and saves
	 * @param type $id
	 * @return string
	 */
	private function initialize($id)
	{
		$this->initialized = TRUE;
		
		$field = $this->_table->getSessionIdField();
		$result = $this->_table
            ->find('all')
            ->select([$field])
            ->where([$this->_table->getPrimaryKey() => $id])
            ->enableHydration(false)
            ->first();

        if (!empty($result) && is_string($result[$field])) {
            return $this->_session_id = $result[$field];
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
     * Method called on close of a session.
     *
     * @return bool Success
     */
    public function close()
    {
        return true;
    }

    /**
     * Method used to read from a database session.
     *
     * @param string|int $id ID that uniquely identifies session in database.
     * @return string Session data or empty string if it does not exist.
     */
    public function read(string $id)
    {
		if (!$this->initialized) {
			$this->initialize($id);
		}
		
		if (empty($this->_session_id)) {
			return '';
		}
		
		return $this->getSaveHandler()->read($this->session_id);
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


        return (bool)$result && $this->getSaveHandler()->write($this->_session_id, $session_data);
    }

    /**
     * Method called on the destruction of a database session.
     *
     * @param string|int $id ID that uniquely identifies session in database.
     * @return bool True for successful delete, false otherwise.
     */
    public function destroy($id)
    {
        $this->_table->delete(new Entity(
            [$this->_table->getPrimaryKey() => $id],
            ['markNew' => false]
        ));

        return $this->getSaveHandler()->destroy($this->_session_id);
    }

    /**
     * Helper function called on gc for database sessions.
     *
     * @param int $maxlifetime Sessions that have not updated for the last maxlifetime seconds will be removed.
     * @return bool True on success, false on failure.
     */
    public function gc($maxlifetime)
    {
        $this->_table->deleteAll(['expires <' => time() - $maxlifetime]);

        return $this->getSaveHandler()->gc($maxlifetime);
    }
	
	/**
	 * Update the current database session with the $newId. For example
	 * when a session_regenerate_id() is called in the application
	 * @return bool
	 */
	protected function regenerateSessionId(string $newId) : bool
	{
		if (empty ($this->_id) || $this->_id == $newId)
			return true;
		
		if (empty($this->_session_id)) {
			$this->_session_id = $this->getRandomString(64);
			$id = $this->writeToDatabase(Router::getRequest(true), TRUE);
		} else {
			$id = $this->writeToDatabase(Router::getRequest(true));
		}
	}
	
	/**
	 * Saves the session Id to the Database. 
	 * @param type $request
	 * @param bool $isNew
	 * @return boolean
	 */
	protected function saveSessionIdToDatabase(ServerRequest $request, bool $isNew = FALSE)
	{
		if (empty($this->_session_id))
			return FALSE;
		
		$expires = time() + $this->_timeout;
        $record = compact($this->_table->getSessionIdKey(), $this->_table->getExpiresKey());
        $record[$this->_table->getPrimaryKey()] = $this->_id;
		$session = new Entity($record);
		
		if (!$isNew) {
			$session->isNew(FALSE);
		} else {
			$session->set($this->_table->getIpField());
			$session->set($this->_table->getRelatedUserField(), $this->getUserId());
			$session->set($this->_table->getUseragentField(), $request->getHeaderLine('user-agent'));
			$session->set($this->_table->getDisplayField(), $this->getNameFromRequest($request));
		}
		
        $result = $this->_table->save($session);
		
		return $result->get($this->_table->getPrimaryKey());
	}
	
	/**
	 * Gets a nice formatted name for this session
	 * @param ServerRequest $request
	 * @return type
	 */
	protected function getNameFromRequest(ServerRequest $request)
	{
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
}
