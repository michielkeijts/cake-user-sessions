<?php
/**
 * @author	      Michiel Keijts
 * @link          https://github.com/michielkeijts/cake-user-sessions
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

namespace UserSessions\Network\Session;

use SessionHandlerInterface;
use Cake\ORM\TableRegistry;

/**
 * UserDatabaseSession is a custom session save handler to relate user_id
 * to active sessions. It honors all the defined CakePHP session configurations
 *
 */
class UserDatabaseSession implements SessionHandlerInterface
{
	/**
     * Reference to the table handling the session data
     *
     * @var \Cake\ORM\Table
     */
    protected $_table;

    /**
     * Number of seconds to mark the session as expired
     *
     * @var int
     */
    protected $_timeout;
	
	/**
	 * Default configuration, see __construct() 
	 * @var array
	 */
	protected $_defaultConfig = [
		'saveHandler'	=> 'cache',
		'model'			=> 'UserSessions.Session',
		''
	];

    /**
     * Constructor. Looks at Session configuration information and
     * sets up the session model.
     *
	 * $config = [
	 *	'saveHandler'	=> 'cache',						// cache or name of session save handler to actually save sessions
		'model'			=> 'UserSessions.Session'		// model class (Entity) Needs to implement UserSessionInterface
	 * ];
	 * 
	 * 
     * @param array $config The configuration for this engine. It requires the 'model'
     * key to be present corresponding to the Table to use for managing the sessions.
	 * 
	 * 
	 * 
     */
    public function __construct(array $config = [])
    {
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
     * Method called on open of a database session.
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
     * Method called on close of a database session.
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
    public function read($id)
    {
        $result = $this->_table
            ->find('all')
            ->select([$this->_table->getSessionIdKey()])
            ->where([$this->_table->getPrimaryKey() => $id])
            ->enableHydration(false)
            ->first();

        if (empty($result)) {
            return '';
        }

        if (is_string($result['data'])) {
            return $result['data'];
        }

        $session = stream_get_contents($result['data']);

        if ($session === false) {
            return '';
        }

        return $session;
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
        $expires = time() + $this->_timeout;
        $record = compact($this->_table->getSessionIdKey(), $this->_table->getExpiresKey());
        $record[$this->_table->getPrimaryKey()] = $id;
        $result = $this->_table->save(new Entity($record));

        return (bool)$result;
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

        return true;
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

        return true;
    }
}
