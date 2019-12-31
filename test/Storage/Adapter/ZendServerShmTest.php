<?php

/**
 * @see       https://github.com/laminas/laminas-cache for the canonical source repository
 * @copyright https://github.com/laminas/laminas-cache/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-cache/blob/master/LICENSE.md New BSD License
 */

namespace LaminasTest\Cache\Storage\Adapter;

use Laminas\Cache;
use Laminas\Cache\Exception;

/**
 * @category   Laminas
 * @package    Laminas_Cache
 * @subpackage UnitTests
 * @group      Laminas_Cache
 */
class ZendServerShmTest extends CommonAdapterTest
{

    public function setUp()
    {
        if (!defined('TESTS_LAMINAS_CACHE_ZEND_SERVER_ENABLED') || !TESTS_LAMINAS_CACHE_ZEND_SERVER_ENABLED) {
            $this->markTestSkipped("Skipped by TestConfiguration (TESTS_LAMINAS_CACHE_ZEND_SERVER_ENABLED)");
        }

        if (strtolower(PHP_SAPI) == 'cli') {
            $this->markTestSkipped('Zend Server SHM does not work in CLI environment');
            return;
        }

        if (!function_exists('zend_shm_cache_store')) {
            try {
                new Cache\Storage\Adapter\ZendServerShm();
                $this->fail("Missing expected ExtensionNotLoadedException");
            } catch (Exception\ExtensionNotLoadedException $e) {
                $this->markTestSkipped($e->getMessage());
            }
        }

        $this->_options = new Cache\Storage\Adapter\AdapterOptions();
        $this->_storage = new Cache\Storage\Adapter\ZendServerShm($this->_options);
        parent::setUp();
    }

    public function tearDown()
    {
        if (function_exists('zend_shm_cache_clear')) {
            zend_shm_cache_clear();
        }

        parent::tearDown();
    }
}
