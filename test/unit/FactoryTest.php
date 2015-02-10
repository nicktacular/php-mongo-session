<?php

class FactoryTest extends PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        parent::setUp();

        if (!extension_loaded('mongo')) {
            $this->markTestSkipped('Missing mongo extension.');
        }
    }

    protected function mongoObject()
    {
        if (class_exists('MongoClient')) {
            $m = Mockery::mock('MongoClient');
        } else {
            $m = Mockery::mock('Mongo');
        }

        $db = Mockery::mock('MongoDB');
        $db->shouldReceive('selectCollection')
            ->atLeast(1)
            ->andReturn();

        $m->shouldReceive('connect')
            ->atLeast(1)
            ->andReturn();

        $m->shouldReceive('selectDB')
            ->atLeast(1)
            ->andReturn($db);

        return $m;
    }

    public function testDeprecatedMethodWorksStill()
    {
        MongoSession::config(array('name' => 'test', 'mongo' => $this->mongoObject()));
        MongoSession::init();

        $instance = MongoSession::instance();
        $this->assertSame('test', $instance->getConfig('name'));
    }

    public function testMongoOverride()
    {
        $m = $this->mongoObject();
        $handler = MongoSession::create(array('mongo' => $m));
        $this->assertAttributeSame($m, 'conn', $handler);
    }

    public function testConfigOverride()
    {
        $writeConcern = array('w' => 99, 'j' => 99);
        $handler = MongoSession::create(array('write_concern' => $writeConcern, 'mongo' => $this->mongoObject()));
        $this->assertSame($writeConcern, $handler->getConfig('write_concern'));
    }

    public function testSaveHandlerSetsProperPhpIniSettings()
    {
        $m = $this->mongoObject();
        $handler = MongoSession::create(array(
            'mongo' => $m,
            'lifetime' => $lifetime = '18327382',
            'cache' => $cacheLimiter = 'blahblah',
            'cache_expiry' => $cacheExpiry = '929',
            'cookie_path' => $cookiePath = '/horse/',
            'cookie_domain' => $cookieDomain = '.biz.co',
            'cookie_secure' => $cookieSecure = '1',
            'cookie_httponly' => $cookieHttpOnly = '1'
        ));
        $handler->setSaveHandler();

        $this->assertSame(ini_get('session.gc_maxlifetime'), $lifetime);
        $this->assertSame(ini_get('session.cache_limiter'), $cacheLimiter);
        $this->assertSame(ini_get('session.cache_expire'), $cacheExpiry);
        $this->assertSame(ini_get('session.cookie_path'), $cookiePath);
        $this->assertSame(ini_get('session.cookie_domain'), $cookieDomain);
        $this->assertSame(ini_get('session.cookie_secure'), $cookieSecure);
        $this->assertSame(ini_get('session.cookie_httponly'), $cookieHttpOnly);
    }
}
