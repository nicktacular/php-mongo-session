<?php
/**
 * @author            Nick Ilyin nick.ilyin@gmail.com
 * @description       Changes PHPs behaviour with sessions by using Mongo as a data
 *                    storage solution for PHP sessions. Safely does things by locking
 *                    sessions while using them. Currently requires 1 database in mongo
 *                    and 2 collections. Please use collections that don't already exist
 *                    since you could affect the indexing performance if you're storing other
 *                    data in the same collections.
 *
 * @example            In your bootstrap file/class/method, configure mongo like:
 *                    <code>
 *                    $handler = MongoSession::create(array(
 *                        'connection' => 'mongodb://localhost:27017',
 *                        'cookie_domain' => $_SERVER['HOST_NAME']
 *                    ));
 *                    $handler->setSaveHandler();
 *                    </code>
 *
 *                    Then you can do beautiful things like session_start() or $_SESSION['coolest'] = 'MongoSession!';
 *
 */
class MongoSession
{
    /**
     * Using singleton pattern, so here's the instance.
     *
     * @deprecated
     *
     * @var MongoSession
     */
    private static $instance;

    /**
     * The default configuration.
     *
     * Note that the 'cache' value needs to be carefully considered.
     * The value private_no_expire is not the default PHP setting so
     * carefully consider what's the most appropriate setting for your
     * application.
     *
     * The 'cookie_domain' should just be set to $_SERVER['HTTP_HOST']
     * unless you have load balancing where a different host is being passed.
     */
    protected static $defaultConfig = array(
        'name'              => 'PHPSESSID',
        'connection'        => 'mongodb://localhost:27017',
        'connection_opts'   => array(),//options to pass to MongoClient
        'write_options'     => array(),
        'db'                => 'mySessDb',
        'collection'        => 'sessions',
        'lockcollection'    => 'sessions_lock',
        'timeout'           => 3600,//seconds
        'cache'             => 'private_no_expire',
        'cache_expiry'      => 10,//minutes
        'cookie_path'       => '/',
        'cookie_domain'     => '.thisdomain.com',
        'cookie_secure'     => false,
        'cookie_httponly'   => false,
        'autostart'         => false,
        'locktimeout'       => 30,//seconds
        'locksleep'         => 100,//milliseconds
        'cleanonclose'      => false,//this is an option for testing purposes
        'error_handler'     => 'trigger_error',
        'logger'            => false,//by default, no logging
        'machine_id'        => false,//identify the machine, if you want for debugs
        'write_concern'     => 1,//by default, MongoClient uses w=1 (Mongo 'safe' mode)
        'write_journal'     => false,//by default, no journaling required before ack
    );

    /**
     * Possible exception codes indicating MongoDB duplicate key error.
     *
     * @var array
     */
    protected static $duplicateKeyCodes = array(11001, 11000, 12582);

    /**
     * The configuration.
     * @var array
     */
    private $config;


    /**
     * MongoDB connection object.
     * @var Mongo|MongoClient
     */
    private $conn;

    /**
     * The database where the data is stored.
     * @var MongoDB
     */
    private $db;

    /**
     * The session collection, the actual name is specified
     * in the configuration.
     * @var MongoCollection
     */
    private $sessions;

    /**
     * The lock collection, actual name found in config.
     * @var MongoCollection
     */
    private $locks;

    /**
     * The session ID which is saved since php doesn't pass the session ID
     * when calling the close method. Also this is used to detect when a
     * session is being regenerated.
     * @var string
     */
    private $sid;

    /**
     * Storing the current session document.
     * @var array
     */
    private $sessionDoc;

    /**
     * Indicates whether this client acquired the lock or not. This
     * is used to determine whether this instance can release a lock
     * or not. Only if the lock was acquired in this instance is this
     * allowed.
     * @var boolean
     */
    private $lockAcquired = false;

    /**
     * Set the configuration.
     * @var        $config        array
     * @deprecated
     * @return null
     */
    public static function config(array $config = array())
    {
        self::$instance = self::create($config);
    }

    /**
     * Get the instance, or set up a new one.
     *
     * @deprecated
     *
     * @return MongoSession
     */
    public static function instance()
    {
        if (self::$instance) {
            return self::$instance;
        }

        //set up a proper instance
        self::$instance = self::create();

        return self::$instance;
    }

    /**
     * This method is deprecated. Please use the create method instead.
     *
     * @param  boolean $dbInit When passing true, it will also call ensureIndex()
     *                         on the appropriate collections so that Mongo isn't
     *                         slow. You should never pass true in a production app.
     *                         It should only be called once, perhaps by an install
     *                         script.
     *
     * @deprecated
     *
     * @return null
     */
    public static function init($dbInit = false)
    {
        $i = self::instance();

        if ($dbInit) {
            $i->dbInit();
        }

        $i->setSaveHandler();

    }

    /**
     * Create a new instance of the MongoSession handler.
     *
     * @param array $configs Configuration.
     *
     * @return MongoSession
     */
    public static function create(array $configs = array())
    {
        // set default configs wherever not present in $configs
        foreach (self::$defaultConfig as $config => $value) {
            if (!array_key_exists($config, $configs)) {
                $configs[$config] = $value;
            }
        }

        if (!isset($configs['mongo'])) {
            $mongo = self::createMongoInstance($configs['connection'], $configs['connection_opts']);
        } else {
            $mongo = $configs['mongo'];
        }

        if (!count($configs['write_options'])) {
            $configs['write_options'] = self::configWriteOptions(
                $mongo,
                $configs['write_concern'],
                $configs['write_journal']
            );
        }

        return new self($mongo, $configs);
    }

    /**
     * Calls the necessary PHP methods to have this instance the session handler.
     */
    public function setSaveHandler()
    {
        //set the cookie settings
        session_set_cookie_params(0, $this->getConfig('cookie_path'), $this->getConfig('cookie_domain'),
            $this->getConfig('cookie_secure'), $this->getConfig('cookie_httponly'));

        //set HTTP cache headers
        session_cache_limiter($this->getConfig('cache'));
        session_cache_expire($this->getConfig('cache_expiry'));

        //we need to ensure that PHP knows about our explicit timeout
        ini_set('session.gc_maxlifetime', $this->getConfig('lifetime'));

        //tell PHP to use this class as the handler
        session_set_save_handler(
            array($this, 'open'),
            array($this, 'close'),
            array($this, 'read'),
            array($this, 'write'),
            array($this, 'destroy'),
            array($this, 'gc')
        );
    }

    /**
     * Creates a Mongo(Client) instance depending on which is available.
     *
     * @param $connStr string A connection string.
     * @param array $config Configs for the constructor.
     *
     * @return Mongo|MongoClient The latest Mongo client class instance.
     */
    protected static function createMongoInstance($connStr, array $config)
    {
        $opts = array();
        foreach ($config as $option => $value) {
            $opts[$option] = $value;
        }

        if (class_exists('MongoClient')) {
            $mongo = new MongoClient($connStr, $opts);
        } else {
            $mongo = new Mongo($connStr, $opts);
        }

        return $mongo;
    }

    /**
     * Configure write options depending on connection type.
     *
     * @param $connection Mongo|MongoClient The connection class.
     * @param $writeConcern int Write concern.
     * @param $writeJournal int Write journal.
     *
     * @return array The appropriate configuration for writes.
     */
    protected static function configWriteOptions($connection, $writeConcern, $writeJournal)
    {
        if ($connection instanceof MongoClient) {
            return array(
                'w' => $writeConcern,
                'j' => $writeJournal
            );
        } else {
            return array(
                'safe' => $writeConcern > 0
            );
        }
    }

    /**
     * Retrieve write options for operation which does not block on waiting for response
     * from MongoDB.
     *
     * @return array Write options.
     */
    protected function getUnsafeWriteOptions()
    {
        if ($this->conn instanceof MongoClient) {
            return array(
                'w' => 0
            );
        }

        return array(
            'safe' => false
        );
    }

    /**
     * @param $connection Mongo|MongoClient The client.
     * @param array $config Configuration.
     */
    public function __construct($connection, array $config)
    {
        $this->conn = $connection;
        $this->config = $config;

        //make the connection explicit
        $this->conn->connect();

        //init some variables for use
        $db = $this->getConfig('db');
        $coll = $this->getConfig('collection');
        $lock = $this->getConfig('lockcollection');

        //connect to the db and select the collections
        $this->db = $this->conn->selectDB($db);
        $this->sessions = $this->db->selectCollection($coll);
        $this->locks = $this->db->selectCollection($lock);
    }

    /**
     * Builds indices on the appropriate collections. No need to call directly.
     */
    public function dbInit()
    {
      $mongo_index = ( (phpversion('mongo') >= '1.5.0') ? ('createIndex') : ('ensureIndex') );
      $this->log("maint: {$mongo_index} on ".$this->getConfig('collection'));
      $this->sessions->$mongo_index(array(
                                          'last_accessed' => 1
                                          ));
      $this->log("maint: {$mongo_index} on ".$this->getConfig('lockcollection'));
      $this->locks->$mongo_index(array(
                                       'created' => 1
                                       ));
    }

    /**
     * Get a configuration item. Will return null if it doesn't exist.
     * @var $key    string        The key of the configuration you're looking.
     * @return mixed
     */
    public function getConfig($key)
    {
        if (!array_key_exists($key, $this->config))
            return null;
        else
            return $this->config[$key];
    }

    /**
     * Acquires a lock on a session, or it waits for a specified amount of time
     * WARNING: This method isn't expected to fail in any realistic application.
     * In the case of a tiny Mongo server with tons of web traffic, it's conceivable
     * that this method could fail. Keep in mind that php will
     * make sure that write() and close() is also called if this fails. There's no
     * specific way to ensure that this never fails since it's dependent on the
     * application design. Overall, one should be extremely careful with making
     * sure that the Mongo database can handle the load you'll be sending its way.
     *
     * @param  string  $sid The session ID to acquire a lock on.
     * @return boolean True if succeeded, false if not.
     */
    private function lock($sid)
    {
        //check if we've already acquired a lock
        if ($this->lockAcquired) {
            if ($sid == $this->sid) {
                return true;
            } else {
                // during session regenerate, there will be a new id, so we need to unlock old
                $this->unlock($this->sid, true);
                $this->lockAcquired = false;
                $this->sid = $sid;
            }
        }

        $timeout = $this->getConfig('locktimeout') * 1000000;//microseconds we want
        $sleep = $this->getConfig('locksleep') * 1000;//we want microseconds
        $start = microtime(true);

        $this->log('Trying to acquire a lock on ' . $sid);

        $waited = false;

        do {
            //check if there is a current lock
            $lock = $this->locks->findOne(array('_id' => $sid));

            if (!$lock) {
                $lock = array();
                $lock['_id'] = $sid;
                $lock['created'] = new MongoDate();

                if ($mid = $this->getConfig('machine_id'))
                    $lock['mid'] = $mid;

                try {
                  $this->locks->insert($lock, $this->getConfig('write_options'));
                } catch (MongoDuplicateKeyException $e) {
                  //duplicate key may occur during lock race
                  continue;
                } catch (MongoCursorException $e) {
                  if (in_array($e->getCode(), self::$duplicateKeyCodes)) {
                    //catch duplicate key if no exception thrown
                    continue;
                  } elseif (preg_match('/replication timed out/i', $e->getMessage())) {
                    //force unlock to prevent lockout from partial write
                    $this->unlock($sid, true);
                  }
                  //log exception and fail lock
                  $this->log('exception: ' . $e->getMessage());
                  break 1;
                }

                $this->lockAcquired = true;

                $this->log('Lock acquired @ ' . date('Y-m-d H:i:s', $lock['created']->sec));

                if ($waited)
                    $this->log('LOCK_WAIT_SECONDS:' . number_format(microtime(true) - $start, 5));

                return true;
            }

            //we need to sleep
            usleep($sleep);
            $waited = true;
            $timeout -= $sleep;
        } while ($timeout > 0);

        //no lock could be acquired, so try to use an error handler for this
        $this->errorHandler('Could not acquire lock for ' . $sid);
    }

    /**
     * Release lock **only** if this instance had acquired it.
     * @param string $sid The session ID that php passes.
     */
    private function unlock($sid, $force=false)
    {
        if ($this->lockAcquired || $force) {
            $this->lockAcquired = false;
            $opts = $force ? $this->getUnsafeWriteOptions() : $this->getConfig('write_options');
            $this->locks->remove(array('_id' => $sid), $opts);
        }
    }

    /**
     * A useless method since this is where file handling would occur, except there's
     * no files to open and the database connection was opened in the constructor,
     * so this just needs to exist but doesn't actually do anythiing.
     * @param  string  $path The storage path that php passes. Not relevant to Mongo.
     * @param  string  $name The name of the session, defaults to PHPSESSID but could be anything.
     * @return boolean Always true.
     */
    public function open($path, $name)
    {
        return true;
    }

    /**
     * Closes the session. Invoked by PHP but doesn't pass the session ID, so we use the session
     * ID that we previously saved in open/write. During testing, one could also invoke garbage
     * collection by setting 'cleanonclose' setting to true. This is only useful to test garbage
     * collection, but on production you shouldn't be doing that on every run.
     * @return boolean true
     */
    public function close()
    {
        //release any locks
        $this->unlock($this->sid);

        //do an explicit gc() if called for
        if ($this->getConfig('cleanonclose')) {
            $this->gc();
        }

        return true;
    }

    /**
     * Read the contents of the session. Get's called once during a request to get entire session contents.
     *
     * @param  string $sid The session ID passed by PHP.
     * @return string Either an empty string if there's nothing in a session of a special session
     *                    serialized string. In this case we're storing in the DB as MongoBinData since
     *                    UTF-8 is harder to enforce than just storing as binary.
     */
    public function read($sid)
    {
        //save the session ID for closing later
        $this->sid = $sid;

        //a lock MUST be acquired, but the complexity is in the lock() method
        $this->lock($sid);

        $this->sessionDoc = $this->sessions->findOne(array('_id' => $sid));

        if (!$this->sessionDoc) {
            return '';
        } else {
            //return the string data (stored as Mongo binary format)
            return $this->sessionDoc['data']->bin;
        }
    }

    /**
     * Save the session data.
     * @param  string  $sid  The session ID that PHP passes.
     * @param  string  $data The session serialized data string.
     * @return boolean True always.
     */
    public function write($sid, $data)
    {
        //update/insert our session data
        if (!$this->sessionDoc) {
            $this->sessionDoc = array();
            $this->sessionDoc['_id'] = $sid;
            $this->sessionDoc['started'] = new MongoDate();
        }

        //there could have been a session regen so we need to be careful with the $sid here and set it anyway
        if ($this->sid != $sid) {
            //need to unlock old sid
            $this->unlock($this->sid);

            //set the new one
            $this->sid = $sid;
            $this->lock($this->sid);

            //and also make sure we're going to write to the correct document
            $this->sessionDoc['_id'] = $sid;
        }

        $this->sessionDoc['last_accessed'] = new MongoDate();
        $this->sessionDoc['data'] = new MongoBinData($data, MongoBinData::BYTE_ARRAY);

        $this->sessions->save($this->sessionDoc, $this->getConfig('write_options'));

        return true;
    }

    /**
     * Tries to invoke the error handler specified in settings.
     */
    private function errorHandler($msg)
    {
        $waited = $this->getConfig('locktimeout');
        $this->log("PANIC! {$this->sid} cannot be acquired after waiting for {$waited}s. ");
        $h = $this->getConfig('error_handler');

        //call and exit
        call_user_func_array($h, array($msg));
        exit(1);
    }

    /**
     * For logging, if we want to.
     */
    private function log($msg)
    {
        $logger = $this->getConfig('logger');
        if (!$logger) return false;
        return call_user_func_array($logger, array($msg));
    }

    /**
     * Destroy the session.
     * @param  string  $sid The session ID to destroy.
     * @return boolean True always.
     */
    public function destroy($sid)
    {
        $this->sessions->remove(array('_id' => $sid), $this->getConfig('write_options'));
        $this->locks->remove(array('_id' => $sid), $this->getConfig('write_options'));

        return true;
    }

    /**
     * The garbage collection function invoked by PHP.
     * @param  int     $lifetime The lifetime param, defaults to 1440 seconds in PHP.
     * @return boolean True always.
     */
    public function gc($lifetime = 0)
    {
        $timeout = $this->getConfig('timeout');

        //find all sessions that are older than $timeout
        $olderThan = time() - $timeout;

        //no ack required
        $this->sessions->remove(
            array('last_accessed' => array(
                '$lt' => new MongoDate($olderThan))
            ),
            $this->getUnsafeWriteOptions()
        );

        return true;
    }
}
