<?php

namespace Laminas\Cache\Storage\Adapter;

use ArrayObject;
use Laminas\Cache\Exception;
use Laminas\Cache\Storage\Capabilities;
use Laminas\Cache\Storage\Event;
use Laminas\Cache\Storage\ExceptionEvent;
use Laminas\Cache\Storage\Plugin;
use Laminas\Cache\Storage\PluginAwareInterface;
use Laminas\Cache\Storage\PostEvent;
use Laminas\Cache\Storage\StorageInterface;
use Laminas\EventManager\EventManager;
use Laminas\EventManager\EventManagerInterface;
use Laminas\EventManager\ResponseCollection;
use SplObjectStorage;
use stdClass;
use Traversable;

use function array_keys;
use function array_unique;
use function array_values;
use function array_walk;
use function func_num_args;
use function preg_match;
use function sprintf;

abstract class AbstractAdapter implements StorageInterface, PluginAwareInterface
{
    /**
     * The used EventManager if any
     *
     * @var null|EventManagerInterface
     */
    protected $events;

    /**
     * Event handles of this adapter
     *
     * @var array
     */
    protected $eventHandles = [];

    /**
     * The plugin registry
     *
     * @var SplObjectStorage|null Registered plugins
     */
    protected $pluginRegistry;

    /**
     * Capabilities of this adapter
     *
     * @var null|Capabilities
     */
    protected $capabilities;

    /**
     * Marker to change capabilities
     *
     * @var null|object
     */
    protected $capabilityMarker;

    /**
     * options
     *
     * @var mixed
     */
    protected $options;

    /**
     * Constructor
     *
     * @param  null|array|Traversable|AdapterOptions $options
     * @throws Exception\ExceptionInterface
     */
    public function __construct($options = null)
    {
        if ($options) {
            $this->setOptions($options);
        }
    }

    /**
     * Destructor
     *
     * detach all registered plugins to free
     * event handles of event manager
     *
     * @return void
     */
    public function __destruct()
    {
        foreach ($this->getPluginRegistry() as $plugin) {
            $this->removePlugin($plugin);
        }

        if ($this->eventHandles) {
            $events = $this->getEventManager();
            foreach ($this->eventHandles as $handle) {
                $events->detach($handle);
            }
        }
    }

    /* configuration */

    /**
     * Set options.
     *
     * @see    getOptions()
     *
     * @param  array|Traversable|AdapterOptions $options
     * @return AbstractAdapter Provides a fluent interface
     */
    public function setOptions($options)
    {
        if ($this->options !== $options) {
            if (! $options instanceof AdapterOptions) {
                $options = new AdapterOptions($options);
            }

            if ($this->options) {
                $this->options->setAdapter(null);
            }
            $options->setAdapter($this);
            $this->options = $options;

            $event = new Event('option', $this, new ArrayObject($options->toArray()));

            $this->getEventManager()->triggerEvent($event);
        }
        return $this;
    }

    /**
     * Get options.
     *
     * @see setOptions()
     *
     * @return AdapterOptions
     */
    public function getOptions()
    {
        if (! $this->options) {
            $this->setOptions(new AdapterOptions());
        }
        return $this->options;
    }

    /**
     * Enable/Disable caching.
     *
     * Alias of setWritable and setReadable.
     *
     * @see    setWritable()
     * @see    setReadable()
     *
     * @param  bool $flag
     * @return AbstractAdapter Provides a fluent interface
     */
    public function setCaching($flag)
    {
        $flag    = (bool) $flag;
        $options = $this->getOptions();
        $options->setWritable($flag);
        $options->setReadable($flag);
        return $this;
    }

    /**
     * Get caching enabled.
     *
     * Alias of getWritable and getReadable.
     *
     * @see    getWritable()
     * @see    getReadable()
     *
     * @return bool
     */
    public function getCaching()
    {
        $options = $this->getOptions();
        return $options->getWritable() && $options->getReadable();
    }

    /* Event/Plugin handling */

    /**
     * Get the event manager
     *
     * @return EventManagerInterface
     */
    public function getEventManager()
    {
        if ($this->events === null) {
            $this->events = new EventManager();
            $this->events->setIdentifiers([self::class, static::class]);
        }
        return $this->events;
    }

    /**
     * Trigger a pre event and return the event response collection
     *
     * @param  string $eventName
     * @return ResponseCollection All handler return values
     */
    protected function triggerPre($eventName, ArrayObject $args)
    {
        return $this->getEventManager()->triggerEvent(new Event($eventName . '.pre', $this, $args));
    }

    /**
     * Triggers the PostEvent and return the result value.
     *
     * @param  string      $eventName
     * @return mixed
     */
    protected function triggerPost($eventName, ArrayObject $args, mixed &$result)
    {
        $postEvent = new PostEvent($eventName . '.post', $this, $args, $result);
        $eventRs   = $this->getEventManager()->triggerEvent($postEvent);

        return $eventRs->stopped()
            ? $eventRs->last()
            : $postEvent->getResult();
    }

    /**
     * Trigger an exception event
     *
     * If the ExceptionEvent has the flag "throwException" enabled throw the
     * exception after trigger else return the result.
     *
     * @param  string      $eventName
     * @throws Exception\ExceptionInterface
     * @return mixed
     */
    protected function triggerException($eventName, ArrayObject $args, mixed &$result, \Exception $exception)
    {
        $exceptionEvent = new ExceptionEvent($eventName . '.exception', $this, $args, $result, $exception);
        $eventRs        = $this->getEventManager()->triggerEvent($exceptionEvent);

        if ($exceptionEvent->getThrowException()) {
            throw $exceptionEvent->getException();
        }

        return $eventRs->stopped()
            ? $eventRs->last()
            : $exceptionEvent->getResult();
    }

    /**
     * {@inheritdoc}
     */
    public function hasPlugin(Plugin\PluginInterface $plugin)
    {
        $registry = $this->getPluginRegistry();
        return $registry->contains($plugin);
    }

    /**
     * {@inheritdoc}
     */
    public function addPlugin(Plugin\PluginInterface $plugin, $priority = 1)
    {
        $registry = $this->getPluginRegistry();
        if ($registry->contains($plugin)) {
            throw new Exception\LogicException(sprintf(
                'Plugin of type "%s" already registered',
                $plugin::class
            ));
        }

        $plugin->attach($this->getEventManager(), $priority);
        $registry->attach($plugin);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function removePlugin(Plugin\PluginInterface $plugin)
    {
        $registry = $this->getPluginRegistry();
        if ($registry->contains($plugin)) {
            $plugin->detach($this->getEventManager());
            $registry->detach($plugin);
        }
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getPluginRegistry()
    {
        if (! $this->pluginRegistry instanceof SplObjectStorage) {
            $this->pluginRegistry = new SplObjectStorage();
        }
        return $this->pluginRegistry;
    }

    /* reading */

    /**
     * Get an item.
     *
     * @param  string  $key
     * @param  bool $success
     * @param  mixed   $casToken
     * @return mixed Data on success, null on failure
     * @throws Exception\ExceptionInterface
     * @triggers getItem.pre(PreEvent)
     * @triggers getItem.post(PostEvent)
     * @triggers getItem.exception(ExceptionEvent)
     */
    public function getItem($key, &$success = null, &$casToken = null)
    {
        if (! $this->getOptions()->getReadable()) {
            $success = false;
            return;
        }

        $this->normalizeKey($key);

        $argn = func_num_args();
        $args = [
            'key' => &$key,
        ];
        if ($argn > 1) {
            $args['success'] = &$success;
        }
        if ($argn > 2) {
            $args['casToken'] = &$casToken;
        }
        $args = new ArrayObject($args);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            if ($eventRs->stopped()) {
                $result = $eventRs->last();
            } elseif ($args->offsetExists('success') && $args->offsetExists('casToken')) {
                $result = $this->internalGetItem($args['key'], $args['success'], $args['casToken']);
            } elseif ($args->offsetExists('success')) {
                $result = $this->internalGetItem($args['key'], $args['success']);
            } else {
                $result = $this->internalGetItem($args['key']);
            }

            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            $result  = null;
            $success = false;
            return $this->triggerException(__FUNCTION__, $args, $result, $e);
        }
    }

    /**
     * Internal method to get an item.
     *
     * @param  string  $normalizedKey
     * @param  bool $success
     * @return mixed Data on success, null on failure
     * @throws Exception\ExceptionInterface
     */
    abstract protected function internalGetItem(&$normalizedKey, &$success = null, mixed &$casToken = null);

    /**
     * Get multiple items.
     *
     * @param  array $keys
     * @return array Associative array of keys and values
     * @throws Exception\ExceptionInterface
     * @triggers getItems.pre(PreEvent)
     * @triggers getItems.post(PostEvent)
     * @triggers getItems.exception(ExceptionEvent)
     */
    public function getItems(array $keys)
    {
        if (! $this->getOptions()->getReadable()) {
            return [];
        }

        $this->normalizeKeys($keys);
        $args = new ArrayObject([
            'keys' => &$keys,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalGetItems($args['keys']);

            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            $result = [];
            return $this->triggerException(__FUNCTION__, $args, $result, $e);
        }
    }

    /**
     * Internal method to get multiple items.
     *
     * @return array Associative array of keys and values
     * @throws Exception\ExceptionInterface
     */
    protected function internalGetItems(array &$normalizedKeys)
    {
        $success = null;
        $result  = [];
        foreach ($normalizedKeys as $normalizedKey) {
            $value = $this->internalGetItem($normalizedKey, $success);
            if ($success) {
                $result[$normalizedKey] = $value;
            }
        }

        return $result;
    }

    /**
     * Test if an item exists.
     *
     * @param  string $key
     * @return bool
     * @throws Exception\ExceptionInterface
     * @triggers hasItem.pre(PreEvent)
     * @triggers hasItem.post(PostEvent)
     * @triggers hasItem.exception(ExceptionEvent)
     */
    public function hasItem($key)
    {
        if (! $this->getOptions()->getReadable()) {
            return false;
        }

        $this->normalizeKey($key);
        $args = new ArrayObject([
            'key' => &$key,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalHasItem($args['key']);

            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            $result = false;
            return $this->triggerException(__FUNCTION__, $args, $result, $e);
        }
    }

    /**
     * Internal method to test if an item exists.
     *
     * @param  string $normalizedKey
     * @return bool
     * @throws Exception\ExceptionInterface
     */
    protected function internalHasItem(&$normalizedKey)
    {
        $success = null;
        $this->internalGetItem($normalizedKey, $success);
        return $success;
    }

    /**
     * Test multiple items.
     *
     * @param  array $keys
     * @return array Array of found keys
     * @throws Exception\ExceptionInterface
     * @triggers hasItems.pre(PreEvent)
     * @triggers hasItems.post(PostEvent)
     * @triggers hasItems.exception(ExceptionEvent)
     */
    public function hasItems(array $keys)
    {
        if (! $this->getOptions()->getReadable()) {
            return [];
        }

        $this->normalizeKeys($keys);
        $args = new ArrayObject([
            'keys' => &$keys,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalHasItems($args['keys']);

            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            $result = [];
            return $this->triggerException(__FUNCTION__, $args, $result, $e);
        }
    }

    /**
     * Internal method to test multiple items.
     *
     * @return array Array of found keys
     * @throws Exception\ExceptionInterface
     */
    protected function internalHasItems(array &$normalizedKeys)
    {
        $result = [];
        foreach ($normalizedKeys as $normalizedKey) {
            if ($this->internalHasItem($normalizedKey)) {
                $result[] = $normalizedKey;
            }
        }
        return $result;
    }

    /* writing */

    /**
     * Store an item.
     *
     * @param  string $key
     * @param  mixed  $value
     * @return bool
     * @throws Exception\ExceptionInterface
     * @triggers setItem.pre(PreEvent)
     * @triggers setItem.post(PostEvent)
     * @triggers setItem.exception(ExceptionEvent)
     */
    public function setItem($key, $value)
    {
        if (! $this->getOptions()->getWritable()) {
            return false;
        }

        $this->normalizeKey($key);
        $args = new ArrayObject([
            'key'   => &$key,
            'value' => &$value,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalSetItem($args['key'], $args['value']);

            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            $result = false;
            return $this->triggerException(__FUNCTION__, $args, $result, $e);
        }
    }

    /**
     * Internal method to store an item.
     *
     * @param  string $normalizedKey
     * @return bool
     * @throws Exception\ExceptionInterface
     */
    abstract protected function internalSetItem(&$normalizedKey, mixed &$value);

    /**
     * Store multiple items.
     *
     * @param  array $keyValuePairs
     * @return array Array of not stored keys
     * @throws Exception\ExceptionInterface
     * @triggers setItems.pre(PreEvent)
     * @triggers setItems.post(PostEvent)
     * @triggers setItems.exception(ExceptionEvent)
     */
    public function setItems(array $keyValuePairs)
    {
        if (! $this->getOptions()->getWritable()) {
            return array_keys($keyValuePairs);
        }

        $this->normalizeKeyValuePairs($keyValuePairs);
        $args = new ArrayObject([
            'keyValuePairs' => &$keyValuePairs,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalSetItems($args['keyValuePairs']);

            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            $result = array_keys($keyValuePairs);
            return $this->triggerException(__FUNCTION__, $args, $result, $e);
        }
    }

    /**
     * Internal method to store multiple items.
     *
     * @return array Array of not stored keys
     * @throws Exception\ExceptionInterface
     */
    protected function internalSetItems(array &$normalizedKeyValuePairs)
    {
        $failedKeys = [];
        foreach ($normalizedKeyValuePairs as $normalizedKey => $value) {
            if (! $this->internalSetItem($normalizedKey, $value)) {
                $failedKeys[] = $normalizedKey;
            }
        }
        return $failedKeys;
    }

    /**
     * Add an item.
     *
     * @param  string $key
     * @param  mixed  $value
     * @return bool
     * @throws Exception\ExceptionInterface
     * @triggers addItem.pre(PreEvent)
     * @triggers addItem.post(PostEvent)
     * @triggers addItem.exception(ExceptionEvent)
     */
    public function addItem($key, $value)
    {
        if (! $this->getOptions()->getWritable()) {
            return false;
        }

        $this->normalizeKey($key);
        $args = new ArrayObject([
            'key'   => &$key,
            'value' => &$value,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalAddItem($args['key'], $args['value']);

            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            $result = false;
            return $this->triggerException(__FUNCTION__, $args, $result, $e);
        }
    }

    /**
     * Internal method to add an item.
     *
     * @param  string $normalizedKey
     * @return bool
     * @throws Exception\ExceptionInterface
     */
    protected function internalAddItem(&$normalizedKey, mixed &$value)
    {
        if ($this->internalHasItem($normalizedKey)) {
            return false;
        }
        return $this->internalSetItem($normalizedKey, $value);
    }

    /**
     * Add multiple items.
     *
     * @param  array $keyValuePairs
     * @return array Array of not stored keys
     * @throws Exception\ExceptionInterface
     * @triggers addItems.pre(PreEvent)
     * @triggers addItems.post(PostEvent)
     * @triggers addItems.exception(ExceptionEvent)
     */
    public function addItems(array $keyValuePairs)
    {
        if (! $this->getOptions()->getWritable()) {
            return array_keys($keyValuePairs);
        }

        $this->normalizeKeyValuePairs($keyValuePairs);
        $args = new ArrayObject([
            'keyValuePairs' => &$keyValuePairs,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalAddItems($args['keyValuePairs']);

            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            $result = array_keys($keyValuePairs);
            return $this->triggerException(__FUNCTION__, $args, $result, $e);
        }
    }

    /**
     * Internal method to add multiple items.
     *
     * @return array Array of not stored keys
     * @throws Exception\ExceptionInterface
     */
    protected function internalAddItems(array &$normalizedKeyValuePairs)
    {
        $result = [];
        foreach ($normalizedKeyValuePairs as $normalizedKey => $value) {
            if (! $this->internalAddItem($normalizedKey, $value)) {
                $result[] = $normalizedKey;
            }
        }
        return $result;
    }

    /**
     * Replace an existing item.
     *
     * @param  string $key
     * @param  mixed  $value
     * @return bool
     * @throws Exception\ExceptionInterface
     * @triggers replaceItem.pre(PreEvent)
     * @triggers replaceItem.post(PostEvent)
     * @triggers replaceItem.exception(ExceptionEvent)
     */
    public function replaceItem($key, $value)
    {
        if (! $this->getOptions()->getWritable()) {
            return false;
        }

        $this->normalizeKey($key);
        $args = new ArrayObject([
            'key'   => &$key,
            'value' => &$value,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalReplaceItem($args['key'], $args['value']);

            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            $result = false;
            return $this->triggerException(__FUNCTION__, $args, $result, $e);
        }
    }

    /**
     * Internal method to replace an existing item.
     *
     * @param  string $normalizedKey
     * @return bool
     * @throws Exception\ExceptionInterface
     */
    protected function internalReplaceItem(&$normalizedKey, mixed &$value)
    {
        if (! $this->internalhasItem($normalizedKey)) {
            return false;
        }

        return $this->internalSetItem($normalizedKey, $value);
    }

    /**
     * Replace multiple existing items.
     *
     * @param  array $keyValuePairs
     * @return array Array of not stored keys
     * @throws Exception\ExceptionInterface
     * @triggers replaceItems.pre(PreEvent)
     * @triggers replaceItems.post(PostEvent)
     * @triggers replaceItems.exception(ExceptionEvent)
     */
    public function replaceItems(array $keyValuePairs)
    {
        if (! $this->getOptions()->getWritable()) {
            return array_keys($keyValuePairs);
        }

        $this->normalizeKeyValuePairs($keyValuePairs);
        $args = new ArrayObject([
            'keyValuePairs' => &$keyValuePairs,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalReplaceItems($args['keyValuePairs']);

            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            $result = array_keys($keyValuePairs);
            return $this->triggerException(__FUNCTION__, $args, $result, $e);
        }
    }

    /**
     * Internal method to replace multiple existing items.
     *
     * @return array Array of not stored keys
     * @throws Exception\ExceptionInterface
     */
    protected function internalReplaceItems(array &$normalizedKeyValuePairs)
    {
        $result = [];
        foreach ($normalizedKeyValuePairs as $normalizedKey => $value) {
            if (! $this->internalReplaceItem($normalizedKey, $value)) {
                $result[] = $normalizedKey;
            }
        }
        return $result;
    }

    /**
     * Set an item only if token matches
     *
     * It uses the token received from getItem() to check if the item has
     * changed before overwriting it.
     *
     * @see    getItem()
     * @see    setItem()
     *
     * @param  mixed  $token
     * @param  string $key
     * @param  mixed  $value
     * @return bool
     * @throws Exception\ExceptionInterface
     */
    public function checkAndSetItem($token, $key, $value)
    {
        if (! $this->getOptions()->getWritable()) {
            return false;
        }

        $this->normalizeKey($key);
        $args = new ArrayObject([
            'token' => &$token,
            'key'   => &$key,
            'value' => &$value,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalCheckAndSetItem($args['token'], $args['key'], $args['value']);

            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            $result = false;
            return $this->triggerException(__FUNCTION__, $args, $result, $e);
        }
    }

    /**
     * Internal method to set an item only if token matches
     *
     * @see    getItem()
     * @see    setItem()
     *
     * @param  string $normalizedKey
     * @return bool
     * @throws Exception\ExceptionInterface
     */
    protected function internalCheckAndSetItem(mixed &$token, &$normalizedKey, mixed &$value)
    {
        $oldValue = $this->internalGetItem($normalizedKey);
        if ($oldValue !== $token) {
            return false;
        }

        return $this->internalSetItem($normalizedKey, $value);
    }

    /**
     * Reset lifetime of an item
     *
     * @param  string $key
     * @return bool
     * @throws Exception\ExceptionInterface
     * @triggers touchItem.pre(PreEvent)
     * @triggers touchItem.post(PostEvent)
     * @triggers touchItem.exception(ExceptionEvent)
     */
    public function touchItem($key)
    {
        if (! $this->getOptions()->getWritable()) {
            return false;
        }

        $this->normalizeKey($key);
        $args = new ArrayObject([
            'key' => &$key,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalTouchItem($args['key']);

            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            $result = false;
            return $this->triggerException(__FUNCTION__, $args, $result, $e);
        }
    }

    /**
     * Internal method to reset lifetime of an item
     *
     * @param  string $normalizedKey
     * @return bool
     * @throws Exception\ExceptionInterface
     */
    protected function internalTouchItem(&$normalizedKey)
    {
        $success = null;
        $value   = $this->internalGetItem($normalizedKey, $success);
        if (! $success) {
            return false;
        }

        return $this->internalReplaceItem($normalizedKey, $value);
    }

    /**
     * Reset lifetime of multiple items.
     *
     * @param  array $keys
     * @return array Array of not updated keys
     * @throws Exception\ExceptionInterface
     * @triggers touchItems.pre(PreEvent)
     * @triggers touchItems.post(PostEvent)
     * @triggers touchItems.exception(ExceptionEvent)
     */
    public function touchItems(array $keys)
    {
        if (! $this->getOptions()->getWritable()) {
            return $keys;
        }

        $this->normalizeKeys($keys);
        $args = new ArrayObject([
            'keys' => &$keys,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalTouchItems($args['keys']);

            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            return $this->triggerException(__FUNCTION__, $args, $keys, $e);
        }
    }

    /**
     * Internal method to reset lifetime of multiple items.
     *
     * @return array Array of not updated keys
     * @throws Exception\ExceptionInterface
     */
    protected function internalTouchItems(array &$normalizedKeys)
    {
        $result = [];
        foreach ($normalizedKeys as $normalizedKey) {
            if (! $this->internalTouchItem($normalizedKey)) {
                $result[] = $normalizedKey;
            }
        }
        return $result;
    }

    /**
     * Remove an item.
     *
     * @param  string $key
     * @return bool
     * @throws Exception\ExceptionInterface
     * @triggers removeItem.pre(PreEvent)
     * @triggers removeItem.post(PostEvent)
     * @triggers removeItem.exception(ExceptionEvent)
     */
    public function removeItem($key)
    {
        if (! $this->getOptions()->getWritable()) {
            return false;
        }

        $this->normalizeKey($key);
        $args = new ArrayObject([
            'key' => &$key,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalRemoveItem($args['key']);

            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            $result = false;
            return $this->triggerException(__FUNCTION__, $args, $result, $e);
        }
    }

    /**
     * Internal method to remove an item.
     *
     * @param  string $normalizedKey
     * @return bool
     * @throws Exception\ExceptionInterface
     */
    abstract protected function internalRemoveItem(&$normalizedKey);

    /**
     * Remove multiple items.
     *
     * @param  array $keys
     * @return array Array of not removed keys
     * @throws Exception\ExceptionInterface
     * @triggers removeItems.pre(PreEvent)
     * @triggers removeItems.post(PostEvent)
     * @triggers removeItems.exception(ExceptionEvent)
     */
    public function removeItems(array $keys)
    {
        if (! $this->getOptions()->getWritable()) {
            return $keys;
        }

        $this->normalizeKeys($keys);
        $args = new ArrayObject([
            'keys' => &$keys,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalRemoveItems($args['keys']);

            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            return $this->triggerException(__FUNCTION__, $args, $keys, $e);
        }
    }

    /**
     * Internal method to remove multiple items.
     *
     * @return array Array of not removed keys
     * @throws Exception\ExceptionInterface
     */
    protected function internalRemoveItems(array &$normalizedKeys)
    {
        $result = [];
        foreach ($normalizedKeys as $normalizedKey) {
            if (! $this->internalRemoveItem($normalizedKey)) {
                $result[] = $normalizedKey;
            }
        }
        return $result;
    }

    /**
     * Increment an item.
     *
     * @param  string $key
     * @param  int    $value
     * @return int|bool The new value on success, false on failure
     * @throws Exception\ExceptionInterface
     * @triggers incrementItem.pre(PreEvent)
     * @triggers incrementItem.post(PostEvent)
     * @triggers incrementItem.exception(ExceptionEvent)
     */
    public function incrementItem($key, $value)
    {
        if (! $this->getOptions()->getWritable()) {
            return false;
        }

        $this->normalizeKey($key);
        $args = new ArrayObject([
            'key'   => &$key,
            'value' => &$value,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalIncrementItem($args['key'], $args['value']);

            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            $result = false;
            return $this->triggerException(__FUNCTION__, $args, $result, $e);
        }
    }

    /**
     * Internal method to increment an item.
     *
     * @param  string $normalizedKey
     * @param  int    $value
     * @return int|bool The new value on success, false on failure
     * @throws Exception\ExceptionInterface
     */
    protected function internalIncrementItem(&$normalizedKey, &$value)
    {
        $success  = null;
        $value    = (int) $value;
        $get      = (int) $this->internalGetItem($normalizedKey, $success);
        $newValue = $get + $value;

        if ($success) {
            $this->internalReplaceItem($normalizedKey, $newValue);
        } else {
            $this->internalAddItem($normalizedKey, $newValue);
        }

        return $newValue;
    }

    /**
     * Increment multiple items.
     *
     * @param  array $keyValuePairs
     * @return array Associative array of keys and new values
     * @throws Exception\ExceptionInterface
     * @triggers incrementItems.pre(PreEvent)
     * @triggers incrementItems.post(PostEvent)
     * @triggers incrementItems.exception(ExceptionEvent)
     */
    public function incrementItems(array $keyValuePairs)
    {
        if (! $this->getOptions()->getWritable()) {
            return [];
        }

        $this->normalizeKeyValuePairs($keyValuePairs);
        $args = new ArrayObject([
            'keyValuePairs' => &$keyValuePairs,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalIncrementItems($args['keyValuePairs']);

            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            $result = [];
            return $this->triggerException(__FUNCTION__, $args, $result, $e);
        }
    }

    /**
     * Internal method to increment multiple items.
     *
     * @return array Associative array of keys and new values
     * @throws Exception\ExceptionInterface
     */
    protected function internalIncrementItems(array &$normalizedKeyValuePairs)
    {
        $result = [];
        foreach ($normalizedKeyValuePairs as $normalizedKey => $value) {
            $newValue = $this->internalIncrementItem($normalizedKey, $value);
            if ($newValue !== false) {
                $result[$normalizedKey] = $newValue;
            }
        }
        return $result;
    }

    /**
     * Decrement an item.
     *
     * @param  string $key
     * @param  int    $value
     * @return int|bool The new value on success, false on failure
     * @throws Exception\ExceptionInterface
     * @triggers decrementItem.pre(PreEvent)
     * @triggers decrementItem.post(PostEvent)
     * @triggers decrementItem.exception(ExceptionEvent)
     */
    public function decrementItem($key, $value)
    {
        if (! $this->getOptions()->getWritable()) {
            return false;
        }

        $this->normalizeKey($key);
        $args = new ArrayObject([
            'key'   => &$key,
            'value' => &$value,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalDecrementItem($args['key'], $args['value']);

            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            $result = false;
            return $this->triggerException(__FUNCTION__, $args, $result, $e);
        }
    }

    /**
     * Internal method to decrement an item.
     *
     * @param  string $normalizedKey
     * @param  int    $value
     * @return int|bool The new value on success, false on failure
     * @throws Exception\ExceptionInterface
     */
    protected function internalDecrementItem(&$normalizedKey, &$value)
    {
        $success  = null;
        $value    = (int) $value;
        $get      = (int) $this->internalGetItem($normalizedKey, $success);
        $newValue = $get - $value;

        if ($success) {
            $this->internalReplaceItem($normalizedKey, $newValue);
        } else {
            $this->internalAddItem($normalizedKey, $newValue);
        }

        return $newValue;
    }

    /**
     * Decrement multiple items.
     *
     * @param  array $keyValuePairs
     * @return array Associative array of keys and new values
     * @throws Exception\ExceptionInterface
     * @triggers incrementItems.pre(PreEvent)
     * @triggers incrementItems.post(PostEvent)
     * @triggers incrementItems.exception(ExceptionEvent)
     */
    public function decrementItems(array $keyValuePairs)
    {
        if (! $this->getOptions()->getWritable()) {
            return [];
        }

        $this->normalizeKeyValuePairs($keyValuePairs);
        $args = new ArrayObject([
            'keyValuePairs' => &$keyValuePairs,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalDecrementItems($args['keyValuePairs']);

            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            $result = [];
            return $this->triggerException(__FUNCTION__, $args, $result, $e);
        }
    }

    /**
     * Internal method to decrement multiple items.
     *
     * @return array Associative array of keys and new values
     * @throws Exception\ExceptionInterface
     */
    protected function internalDecrementItems(array &$normalizedKeyValuePairs)
    {
        $result = [];
        foreach ($normalizedKeyValuePairs as $normalizedKey => $value) {
            $newValue = $this->internalDecrementItem($normalizedKey, $value);
            if ($newValue !== false) {
                $result[$normalizedKey] = $newValue;
            }
        }
        return $result;
    }

    /* status */

    /**
     * Get capabilities of this adapter
     *
     * @return Capabilities
     * @triggers getCapabilities.pre(PreEvent)
     * @triggers getCapabilities.post(PostEvent)
     * @triggers getCapabilities.exception(ExceptionEvent)
     */
    public function getCapabilities()
    {
        $args = new ArrayObject();

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalGetCapabilities();

            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            $result = false;
            return $this->triggerException(__FUNCTION__, $args, $result, $e);
        }
    }

    /**
     * Internal method to get capabilities of this adapter
     *
     * @return Capabilities
     */
    protected function internalGetCapabilities()
    {
        if ($this->capabilities === null) {
            $this->capabilityMarker = new stdClass();
            $this->capabilities     = new Capabilities($this, $this->capabilityMarker);
        }
        return $this->capabilities;
    }

    /* internal */

    /**
     * Validates and normalizes a key
     *
     * @param  string $key
     * @return void
     * @throws Exception\InvalidArgumentException On an invalid key.
     */
    protected function normalizeKey(&$key)
    {
        $key = (string) $key;

        if ($key === '') {
            throw new Exception\InvalidArgumentException(
                "An empty key isn't allowed"
            );
        } elseif (($p = $this->getOptions()->getKeyPattern()) && ! preg_match($p, $key)) {
            throw new Exception\InvalidArgumentException(
                "The key '{$key}' doesn't match against pattern '{$p}'"
            );
        }
    }

    /**
     * Validates and normalizes multiple keys
     *
     * @return void
     * @throws Exception\InvalidArgumentException On an invalid key.
     */
    protected function normalizeKeys(array &$keys)
    {
        if (! $keys) {
            throw new Exception\InvalidArgumentException(
                "An empty list of keys isn't allowed"
            );
        }

        array_walk($keys, [$this, 'normalizeKey']);
        $keys = array_values(array_unique($keys));
    }

    /**
     * Validates and normalizes an array of key-value pairs
     *
     * @return void
     * @throws Exception\InvalidArgumentException On an invalid key.
     */
    protected function normalizeKeyValuePairs(array &$keyValuePairs)
    {
        $normalizedKeyValuePairs = [];
        foreach ($keyValuePairs as $key => $value) {
            $this->normalizeKey($key);
            $normalizedKeyValuePairs[$key] = $value;
        }
        $keyValuePairs = $normalizedKeyValuePairs;
    }
}
