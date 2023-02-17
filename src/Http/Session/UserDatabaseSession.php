<?php
/**
 * @author	      Normit, Michiel Keijts
 * @link          https://github.com/michielkeijts/cake-user-sessions
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

namespace UserSessions\Http\Session;

use Cake\Datasource\EntityInterface;
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
use Exception;

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
	 * The session. Saved to see if it is renewed.
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
		'getUserId' => '',
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
        $identity = $this->getTable()->getRequest()->getAttribute('identity', FALSE);
        if ($identity && method_exists($identity, 'getIdentifier'))
            return $identity->getIdentifier();

        // use identity object from Auth component
        $identity = $this->getTable()->getRequest()->getSession()->read('Auth.User');
        if ($identity)
            return $identity['id']?:$identity->id;

        return;
    }

    /**
     * Returns the database entity associated with this session
     * @return Entity|NULL
     */
    public function getSession() : ?Entity
    {
        return $this->_session;
    }

    /**
     * Sets the $session variable to the $this->_session
     * @param EntityInterface $session
     * @return Entity $session
     */
    public function setSession(EntityInterface $session) : Entity
    {
        return $this->_session = $session;
    }

    /**
     * Returns the session ID from the $session entity
     * @return string
     */
    public function getSessionId() : string
    {
        if (is_null($this->getSession())) {
            throw new Exception("First initialize the database session");
        }

        return $this->getSession()->get($this->getTable()->getSessionIdField());
    }

    /**
     * The delegate save to delegate all
     */
    public function setSaveHandler() : ?SessionHandlerInterface
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
        }

        return $this->engine($handler, $this->getConfig('handler', []));
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
     * @param string $session_id
     * @return string $session id
     */
    private function initialize($session_id)
    {
        $session = $this->getTable()
            ->find('all')
            ->where([$this->getTable()->getSessionIdField() => $session_id])
            ->first();

        if (empty($session)) {
            $session = $this->createDatabaseSession($session_id);
        }

        $this->setSession($session);

        return $this->getSessionId();
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
    public function open($savePath, $name): bool
    {
        return true;
    }

    /**
     * Method called on close of a session. We update accessed timestamp
	 * as in the open parameter, we do not have the actual id
     *
     * @return bool Success
     */
    public function close(): bool
    {
        // sometimes the session gets destroyed first, it is set to NULL
        $session = $this->getSession();
        if (is_null($session)) {
            return TRUE;
        }

        $this->getSession()->set($this->getTable()->getAccessedField(), time());
        return $this->saveSessionToDatabase();
    }

    /**
     * Method used to read from a database session.
     *
     * @param string|int $session_id ID that uniquely identifies session in database.
     * @return string Session data or empty string if it does not exist.
     */
    public function read($session_id): string
    {
        if (is_null($this->getSession())) {
            $this->initialize($session_id);
        }

        if ($session_id !== $this->getSessionId()) {
            $i=0;
        }

        return $this->getSaveHandler()->read($this->getSessionId());
    }

    /**
     * Helper function called on write for database sessions.
     *
     * @param string|int $session_id ID that uniquely identifies session in database.
     * @param mixed $data The data to be saved.
     * @return bool True for successful write, false otherwise.
     */
    public function write($session_id, $data) : bool
    {
        if (empty($session_id)) {
            return FALSE;
        }

        if (is_null($this->getSession())) {
            $this->initialize($session_id);
        }

        if ($session_id !== $this->getSessionId()) {
            $i=0;
        }

        if (!$this->getSession()->get($this->getTable()->getRelatedUserField()) && !empty($this->getUserId())) {
            $this->getSession()->set($this->getTable()->getRelatedUserField(), $this->getUserId());
        }

        return (bool)$this->getSaveHandler()->write($this->getSessionId(), $data);
    }

    /**
     * Method called on the destruction of a database session.
     *
     * @param string|int $session_id ID that uniquely identifies session in database.
     * @return bool True for successful delete, false otherwise.
     */
    public function destroy($session_id) : bool
    {
        $session = $this->getSession();
        if (is_null($session) || $session_id !== $this->getSessionId()) {
            $session = $this->getTable()->findBySessionId($session_id)->first();
        }

        if (!is_null($this->getSession()) && $this->getSessionId() === $session_id) {
            $this->_session = NULL;
        }

        return $this->getSaveHandler()->destroy($session->get($this->getTable()->getSessionIdField()))
            && $this->getTable()->delete($session);
    }

    /**
     * Helper function called on gc for database sessions.
     *
     * @param int $maxlifetime Sessions that have not updated for the last maxlifetime seconds will be removed.
     * @return bool True on success, false on failure.
     */
    public function gc($maxlifetime): int
    {
        $this->getTable()->deleteAll([$this->getTable()->getExpiresField() . ' <' => time() - $maxlifetime]);

        return $this->getSaveHandler()->gc($maxlifetime);
    }

	/**
	 * Saves the session id to the Database.
	 * @return boolean
	 */
	protected function saveSessionToDatabase() : bool
	{
		$this->getSession()->set($this->getTable()->getExpiresField(), time() + $this->_timeout);

		return $this->getTable()->save($this->getSession()) !== FALSE;
	}

    /**
     * Creates a new session in the Database for session id $session_id
     * @param string $session_id
     * @return Entity
     */
    protected function createDatabaseSession(string $session_id) : Entity
    {
        $session = $this->getTable()->newEmptyEntity();
        $session->set($this->getTable()->getSessionIdField(), $session_id);
        $session->set($this->getTable()->getExpiresField(), time() + $this->_timeout);

        return $this->getTable()->save($session);
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
