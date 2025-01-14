<?php

namespace Laminas\Cache\Storage;

use ArrayObject;
use Laminas\Cache\Exception;
use Laminas\EventManager\EventsCapableInterface;
use stdClass;

use function array_diff;
use function array_keys;
use function in_array;
use function is_string;
use function strtolower;

class Capabilities
{
    public const UNKNOWN_KEY_LENGTH   = -1;
    public const UNLIMITED_KEY_LENGTH = 0;

    /**
     * "lock-on-expire" support in seconds.
     *
     *      0 = Expired items will never be retrieved
     *     >0 = Time in seconds an expired item could be retrieved
     *     -1 = Expired items could be retrieved forever
     *
     * If it's NULL the capability isn't set and the getter
     * returns the base capability or the default value.
     *
     * @var null|bool
     */
    protected $lockOnExpire;

    /**
     * Max. key length
     *
     * If it's NULL the capability isn't set and the getter
     * returns the base capability or the default value.
     *
     * @var null|int
     */
    protected $maxKeyLength;

    /**
     * Min. TTL (0 means items never expire)
     *
     * If it's NULL the capability isn't set and the getter
     * returns the base capability or the default value.
     *
     * @var null|int
     */
    protected $minTtl;

    /**
     * Max. TTL (0 means infinite)
     *
     * If it's NULL the capability isn't set and the getter
     * returns the base capability or the default value.
     *
     * @var null|int
     */
    protected $maxTtl;

    /**
     * Namespace is prefix
     *
     * If it's NULL the capability isn't set and the getter
     * returns the base capability or the default value.
     *
     * @var null|bool
     */
    protected $namespaceIsPrefix;

    /**
     * Namespace separator
     *
     * If it's NULL the capability isn't set and the getter
     * returns the base capability or the default value.
     *
     * @var null|string
     */
    protected $namespaceSeparator;

    /**
     * Static ttl
     *
     * If it's NULL the capability isn't set and the getter
     * returns the base capability or the default value.
     *
     * @var null|bool
     */
    protected $staticTtl;

    /**
     * Supported datatypes
     *
     * If it's NULL the capability isn't set and the getter
     * returns the base capability or the default value.
     *
     * @var null|array
     */
    protected $supportedDatatypes;

    /**
     * TTL precision
     *
     * If it's NULL the capability isn't set and the getter
     * returns the base capability or the default value.
     *
     * @var null|int
     */
    protected $ttlPrecision;

    /**
     * Use request time
     *
     * If it's NULL the capability isn't set and the getter
     * returns the base capability or the default value.
     *
     * @var null|bool
     */
    protected $useRequestTime;

    /**
     * Constructor
     */
    public function __construct(
        protected StorageInterface $storage,
        /**
         * A marker to set/change capabilities
         */
        protected stdClass $marker,
        array $capabilities = [],
        protected ?Capabilities $baseCapabilities = null
    ) {
        foreach ($capabilities as $name => $value) {
            $this->setCapability($marker, $name, $value);
        }
    }

    /**
     * Get the storage adapter
     *
     * @return StorageInterface
     */
    public function getAdapter()
    {
        return $this->storage;
    }

    /**
     * Get supported datatypes
     *
     * @return array
     */
    public function getSupportedDatatypes()
    {
        return $this->getCapability('supportedDatatypes', [
            'NULL'     => false,
            'boolean'  => false,
            'integer'  => false,
            'double'   => false,
            'string'   => true,
            'array'    => false,
            'object'   => false,
            'resource' => false,
        ]);
    }

    /**
     * Set supported datatypes
     *
     * @throws Exception\InvalidArgumentException
     * @return Capabilities Fluent interface
     */
    public function setSupportedDatatypes(stdClass $marker, array $datatypes)
    {
        $allTypes = [
            'array',
            'boolean',
            'double',
            'integer',
            'NULL',
            'object',
            'resource',
            'string',
        ];

        // check/normalize datatype values
        foreach ($datatypes as $type => &$toType) {
            if (! in_array($type, $allTypes)) {
                throw new Exception\InvalidArgumentException("Unknown datatype '{$type}'");
            }

            if (is_string($toType)) {
                $toType = strtolower($toType);
                if (! in_array($toType, $allTypes)) {
                    throw new Exception\InvalidArgumentException("Unknown datatype '{$toType}'");
                }
            } else {
                $toType = (bool) $toType;
            }
        }

        // add missing datatypes as not supported
        $missingTypes = array_diff($allTypes, array_keys($datatypes));
        foreach ($missingTypes as $type) {
            $datatypes[$type] = false;
        }

        return $this->setCapability($marker, 'supportedDatatypes', $datatypes);
    }

    /**
     * Get minimum supported time-to-live
     *
     * @return int 0 means items never expire
     */
    public function getMinTtl()
    {
        return $this->getCapability('minTtl', 0);
    }

    /**
     * Set minimum supported time-to-live
     *
     * @param  int $minTtl
     * @throws Exception\InvalidArgumentException
     * @return Capabilities Fluent interface
     */
    public function setMinTtl(stdClass $marker, $minTtl)
    {
        $minTtl = (int) $minTtl;
        if ($minTtl < 0) {
            throw new Exception\InvalidArgumentException('$minTtl must be greater or equal 0');
        }
        return $this->setCapability($marker, 'minTtl', $minTtl);
    }

    /**
     * Get maximum supported time-to-live
     *
     * @return int 0 means infinite
     */
    public function getMaxTtl()
    {
        return $this->getCapability('maxTtl', 0);
    }

    /**
     * Set maximum supported time-to-live
     *
     * @param  int $maxTtl
     * @throws Exception\InvalidArgumentException
     * @return Capabilities Fluent interface
     */
    public function setMaxTtl(stdClass $marker, $maxTtl)
    {
        $maxTtl = (int) $maxTtl;
        if ($maxTtl < 0) {
            throw new Exception\InvalidArgumentException('$maxTtl must be greater or equal 0');
        }
        return $this->setCapability($marker, 'maxTtl', $maxTtl);
    }

    /**
     * Is the time-to-live handled static (on write)
     * or dynamic (on read)
     *
     * @return bool
     */
    public function getStaticTtl()
    {
        return $this->getCapability('staticTtl', false);
    }

    /**
     * Set if the time-to-live handled static (on write) or dynamic (on read)
     *
     * @param  bool $flag
     * @return Capabilities Fluent interface
     */
    public function setStaticTtl(stdClass $marker, $flag)
    {
        return $this->setCapability($marker, 'staticTtl', (bool) $flag);
    }

    /**
     * Get time-to-live precision
     *
     * @return float
     */
    public function getTtlPrecision()
    {
        return $this->getCapability('ttlPrecision', 1);
    }

    /**
     * Set time-to-live precision
     *
     * @param  float $ttlPrecision
     * @throws Exception\InvalidArgumentException
     * @return Capabilities Fluent interface
     */
    public function setTtlPrecision(stdClass $marker, $ttlPrecision)
    {
        $ttlPrecision = (float) $ttlPrecision;
        if ($ttlPrecision <= 0) {
            throw new Exception\InvalidArgumentException('$ttlPrecision must be greater than 0');
        }
        return $this->setCapability($marker, 'ttlPrecision', $ttlPrecision);
    }

    /**
     * Get use request time
     *
     * @return bool
     */
    public function getUseRequestTime()
    {
        return $this->getCapability('useRequestTime', false);
    }

    /**
     * Set use request time
     *
     * @param  bool $flag
     * @return Capabilities Fluent interface
     */
    public function setUseRequestTime(stdClass $marker, $flag)
    {
        return $this->setCapability($marker, 'useRequestTime', (bool) $flag);
    }

    /**
     * Get "lock-on-expire" support in seconds.
     *
     * @return int 0  = Expired items will never be retrieved
     *             >0 = Time in seconds an expired item could be retrieved
     *             -1 = Expired items could be retrieved forever
     */
    public function getLockOnExpire()
    {
        return $this->getCapability('lockOnExpire', 0);
    }

    /**
     * Set "lock-on-expire" support in seconds.
     *
     * @param  int      $timeout
     * @return Capabilities Fluent interface
     */
    public function setLockOnExpire(stdClass $marker, $timeout)
    {
        return $this->setCapability($marker, 'lockOnExpire', (int) $timeout);
    }

    /**
     * Get maximum key length
     *
     * @return int -1 means unknown, 0 means infinite
     */
    public function getMaxKeyLength()
    {
        return $this->getCapability('maxKeyLength', self::UNKNOWN_KEY_LENGTH);
    }

    /**
     * Set maximum key length
     *
     * @param  int $maxKeyLength
     * @throws Exception\InvalidArgumentException
     * @return Capabilities Fluent interface
     */
    public function setMaxKeyLength(stdClass $marker, $maxKeyLength)
    {
        $maxKeyLength = (int) $maxKeyLength;
        if ($maxKeyLength < -1) {
            throw new Exception\InvalidArgumentException('$maxKeyLength must be greater or equal than -1');
        }
        return $this->setCapability($marker, 'maxKeyLength', $maxKeyLength);
    }

    /**
     * Get if namespace support is implemented as prefix
     *
     * @return bool
     */
    public function getNamespaceIsPrefix()
    {
        return $this->getCapability('namespaceIsPrefix', true);
    }

    /**
     * Set if namespace support is implemented as prefix
     *
     * @param  bool $flag
     * @return Capabilities Fluent interface
     */
    public function setNamespaceIsPrefix(stdClass $marker, $flag)
    {
        return $this->setCapability($marker, 'namespaceIsPrefix', (bool) $flag);
    }

    /**
     * Get namespace separator if namespace is implemented as prefix
     *
     * @return string
     */
    public function getNamespaceSeparator()
    {
        return $this->getCapability('namespaceSeparator', '');
    }

    /**
     * Set the namespace separator if namespace is implemented as prefix
     *
     * @param  string $separator
     * @return Capabilities Fluent interface
     */
    public function setNamespaceSeparator(stdClass $marker, $separator)
    {
        return $this->setCapability($marker, 'namespaceSeparator', (string) $separator);
    }

    /**
     * Get a capability
     *
     * @param  string $property
     * @return mixed
     */
    protected function getCapability($property, mixed $default = null)
    {
        if ($this->$property !== null) {
            return $this->$property;
        } elseif ($this->baseCapabilities) {
            $getMethod = 'get' . $property;
            return $this->baseCapabilities->$getMethod();
        }
        return $default;
    }

    /**
     * Change a capability
     *
     * @param  string $property
     * @return Capabilities Fluent interface
     * @throws Exception\InvalidArgumentException
     */
    protected function setCapability(stdClass $marker, $property, mixed $value)
    {
        if ($this->marker !== $marker) {
            throw new Exception\InvalidArgumentException('Invalid marker');
        }

        if ($this->$property !== $value) {
            $this->$property = $value;

            // trigger event
            if ($this->storage instanceof EventsCapableInterface) {
                $this->storage->getEventManager()->trigger('capability', $this->storage, new ArrayObject([
                    $property => $value,
                ]));
            }
        }

        return $this;
    }
}
