<?php

class SessionHandlingTest extends PHPUnit_Framework_TestCase
{
    protected static $missingRequirements = false;
    protected static $connectionTested = false;
    protected static $connStr = '';
    protected static $connOpts = array();
    protected static $db;
    protected static $sessDocName = 'sessions';
    protected static $lockDocName = 'locks';

    /**
     * @var MongoCollection
     */
    protected $currSessDocs;

    /**
     * @var MongoCollection
     */
    protected $currLockDocs;

    /**
     * @var MongoClient|Mongo
     */
    protected $currConn;

    /**
     * @var MongoDB
     */
    protected $currDb;

    protected $currDbName;

    protected static function envOrConst($key)
    {
        $val = getenv($key);
        if ($val) {
            return $val;
        }

        if (defined($key)) {
            return constant($key);
        }

        return null;
    }

    public static function setupBeforeClass()
    {
        parent::setUpBeforeClass();

        if (!extension_loaded('mongo')) {
            self::$missingRequirements = 'Mongo extension is not loaded';
            return;
        }

        self::$connStr = self::envOrConst('MONGO_CONN_STR');
        self::$db =  self::envOrConst('MONGO_CONN_DB');
        $username =  self::envOrConst('MONGO_CONN_USER');
        $password =  self::envOrConst('MONGO_CONN_PASS');

        if (!self::$connStr || !self::$db) {
            self::$missingRequirements = 'You need to set MONGO_CONN_STR and MONGO_CONN_DB_PREFIX in your phpunit.xml';
            return;
        }

        //try the mongo connection
        try {
            if ($username && $password) {
                self::$connOpts = array('username' => $username, 'password' => $password);
            } else {
                self::$connOpts = array();
            }
            $class = class_exists('MongoClient') ? 'MongoClient' : 'Mongo';
            /** @var MongoClient $mongo */
            $mongo = new $class(self::$connStr . '/' . self::$db, self::$connOpts);
            $mongo->connect();
            $mongo->selectDB(self::$db);
            $mongo->dropDB(self::$db);
            self::$connectionTested = true;
        } catch (Exception $e) {
            self::$missingRequirements = sprintf('Mongo failed: %s/%s: %s', self::$connStr, self::$db, $e->getMessage());
        }

    }

    public function setup()
    {
        if (!extension_loaded('mongo')) {
            $this->markTestIncomplete('Missing Mongo extension');
        }

        if (self::$missingRequirements) {
            $this->markTestSkipped(self::$missingRequirements);
            return;
        }

        if (!self::$connectionTested) {
            $this->markTestSkipped("The Mongo connection was not tested.");
        }

        $this->currDbName = self::$db;

        if (class_exists('MongoClient')) {
            $this->currConn = new MongoClient(self::$connStr . '/' . self::$db, self::$connOpts);
        } else {
            $this->currConn = new Mongo(self::$connStr . '/' . self::$db, self::$connOpts);
        }

        $this->currConn->connect();
        $this->currDb = $this->currConn->selectDB($this->currDbName);
        $this->currSessDocs = $this->currDb->selectCollection(self::$sessDocName);
        $this->currLockDocs = $this->currDb->selectCollection(self::$lockDocName);

        parent::setUp();
    }

    public function testNewSessionStartsWithDataWritten()
    {
        $handler = MongoSession::create(array(
            'db' => $this->currDbName,
            'collection' => self::$sessDocName,
            'lockcollection' => self::$lockDocName
        ));

        $tester = new n1_Session_Emulator();
        $tester->setSaveHandler($handler);
        $tester->sessionStart();

        $id = $tester->sessionId();
        $this->assertLockAcquired($id);
        $this->assertNoData($id);

        $tester->set('hello', 'world');
        $this->assertNoData($id);

        $tester->onShutdown();
        $this->assertHasData($id, $tester->serialize());
        $this->assertLockAcquired($id, false);
    }

    public function testNewSessionStartsWithNoDataWritten()
    {
        $handler = MongoSession::create(array(
            'db' => $this->currDbName,
            'collection' => self::$sessDocName,
            'lockcollection' => self::$lockDocName
        ));

        $tester = new n1_Session_Emulator();
        $tester->setSaveHandler($handler);
        $tester->sessionStart();

        $id = $tester->sessionId();
        $this->assertLockAcquired($id);
        $this->assertNoData($id);

        $tester->onShutdown();
        $this->assertHasData($id);
        $this->assertHasData($id, $tester->serialize());
        $this->assertLockAcquired($id, false);
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Could not acquire lock for abc123
     */
    public function testNewSessionTryingToAcquireExistingLock()
    {
        $handler = MongoSession::create(array(
            'db' => $this->currDbName,
            'collection' => self::$sessDocName,
            'lockcollection' => self::$lockDocName,
            'locktimeout' => 0.1,
            'error_handler' => function($msg){
                throw new Exception($msg);
            }
        ));

        $id = 'abc123';
        $tester = new n1_Session_Emulator('PHPSESSID', array(), $id);
        $tester->setSaveHandler($handler);

        //prior to session start we want to make a fake lock
        $this->currLockDocs->insert(array(
            '_id' => $id,
            'created' => new MongoDate()
        ));

        $tester->sessionStart();
    }

    public function testSessionRegeneratedDoesNotLeaveOldLocksOrOldData()
    {
        $handler = MongoSession::create(array(
            'db' => $this->currDbName,
            'collection' => self::$sessDocName,
            'lockcollection' => self::$lockDocName
        ));

        $tester = new n1_Session_Emulator();
        $tester->setSaveHandler($handler);
        $tester->sessionStart();

        $id = $tester->sessionId();
        $this->assertLockAcquired($id);
        $this->assertNoData($id);

        $tester->sessionRegenerateId(true);
        $newId = $tester->sessionId();
        $tester->sessionWriteClose();

        $this->assertLockAcquired($id, false);//uh oh there IS still a lock here wtf
        $this->assertHasData($newId, $tester->serialize());
        $this->assertNoData($id);

        $tester->onShutdown();
    }

    public function testGarbageCollectionRemovesOldSessionData()
    {
        $handler = MongoSession::create(array(
            'db' => $this->currDbName,
            'collection' => self::$sessDocName,
            'lockcollection' => self::$lockDocName,
            'timeout' => 0
        ));

        $tester = new n1_Session_Emulator();
        $tester->setSaveHandler($handler);
        $tester->sessionStart();
        $id = $tester->sessionId();

        $tester->set('a', 1);

        $tester->onShutdown();

        sleep(1);//otherwise gc won't kick in
        $handler->gc();

        $this->assertNoData($id);
    }

    public function assertLockAcquired($id, $which = true)
    {
        $doc = $this->currLockDocs->findOne(array('_id' => $id));

        if (!$which) {
            $this->assertNull($doc, "There should NOT be a lock for $id");
            return;
        }

        $this->assertInternalType('array', $doc);
        $this->assertArrayHasKey('_id', $doc);
        $this->assertArrayHasKey('created', $doc);
        $this->assertSame($id, $doc['_id']);
    }

    public function assertNoData($id)
    {
        $doc = $this->currSessDocs->findOne(array('_id' => $id));
        $this->assertNull($doc);
    }

    public function assertHasData($id, $check = null)
    {
        $doc = $this->currSessDocs->findOne(array('_id' => $id));
        $this->assertNotNull($doc);
        $this->assertInternalType('array', $doc);
        $this->assertArrayHasKey('_id', $doc);
        $this->assertArrayHasKey('started', $doc);
        $this->assertArrayHasKey('last_accessed', $doc);
        $this->assertArrayHasKey('data', $doc);

        $this->assertInstanceOf('MongoBinData', $doc['data']);

        if ($check !== null) {
            /** @var MongoBinData $data */
            $data = $doc['data'];
            $this->assertSame($check, $data->bin, 'Saved session data does not match');
        }
    }

    public function tearDown()
    {
        if ($this->currDb) {
            $this->currDb->drop();
        }

        if ($this->currConn) {
            $this->currConn->close();
        }

        parent::tearDown();
    }
}
