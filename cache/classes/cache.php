<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace core_cache;

use core\exception\coding_exception;
use stdClass;

/**
 * The main cache class.
 *
 * This class if the first class that any end developer will interact with.
 * In order to create an instance of a cache that they can work with they must call one of the static make methods belonging
 * to this class.
 *
 * @package    core_cache
 * @category   cache
 * @copyright  2012 Sam Hemelryk
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cache implements loader_interface {
    /**
     * @var int Constant for cache entries that do not have a version number
     */
    const VERSION_NONE = -1;

    /**
     * We need a timestamp to use within the cache API.
     * This stamp needs to be used for all ttl and time based operations to ensure that we don't end up with
     * timing issues.
     * @var int
     */
    protected static $now;

    /**
     * A purge token used to distinguish between multiple cache purges in the same second.
     * This is in the format <microtime>-<random string>.
     *
     * @var string
     */
    protected static $purgetoken;

    /**
     * The definition used when loading this cache if there was one.
     * @var definition
     */
    private $definition = false;

    /**
     * The cache store that this loader will make use of.
     * @var store
     */
    private $store;

    /**
     * The next cache loader in the chain if there is one.
     * If a cache request misses for the store belonging to this loader then the loader
     * stored here will be checked next.
     * If there is a loader here then $datasource must be false.
     * @var loader_interface|false
     */
    private $loader = false;

    /**
     * The data source to use if we need to load data (because if doesn't exist in the cache store).
     * If there is a data source here then $loader above must be false.
     * @var data_source_interface|false
     */
    private $datasource = false;

    /**
     * Used to quickly check if the store supports key awareness.
     * This is set when the cache is initialised and is used to speed up processing.
     * @var bool
     */
    private $supportskeyawareness = null;

    /**
     * Used to quickly check if the store supports ttl natively.
     * This is set when the cache is initialised and is used to speed up processing.
     * @var bool
     */
    private $supportsnativettl = null;

    /**
     * Gets set to true if the cache is going to be using a static array for acceleration.
     * The array statically caches items used during the lifetime of the request. This greatly speeds up interaction
     * with the cache in areas where it will be repetitively hit for the same information such as with strings.
     * There are several other variables to control how this static acceleration array works.
     * @var bool
     */
    private $staticacceleration = false;

    /**
     * The static acceleration array.
     * Items will be stored in this cache as they were provided. This ensure there is no unnecessary processing taking place.
     * @var array
     */
    private $staticaccelerationarray = [];

    /**
     * The number of items in the static acceleration array. Avoids count calls like you wouldn't believe.
     * @var int
     */
    private $staticaccelerationcount = 0;

    /**
     * An array containing just the keys being used in the static acceleration array.
     * This seems redundant perhaps but is used when managing the size of the static acceleration array.
     * Items are added to the end of the array and the when we need to reduce the size of the cache we use the
     * key that is first on this array.
     * @var array
     */
    private $staticaccelerationkeys = [];

    /**
     * The maximum size of the static acceleration array.
     *
     * If set to false there is no max size.
     * Caches that make use of static acceleration should seriously consider setting this to something reasonably small, but
     * still large enough to offset repetitive calls.
     *
     * @var int|false
     */
    private $staticaccelerationsize = false;

    /**
     * Gets set to true during initialisation if the definition is making use of a ttl.
     * Used to speed up processing.
     * @var bool
     */
    private $hasattl = false;

    /**
     * Gets set to the class name of the store during initialisation. This is used several times in the cache class internally
     * and having it here helps speed up processing.
     * @var strubg
     */
    protected $storetype = 'unknown';

    /**
     * Gets set to true if we want to collect performance information about the cache API.
     * @var bool
     */
    protected $perfdebug = false;

    /**
     * Determines if this loader is a sub loader, not the top of the chain.
     * @var bool
     */
    protected $subloader = false;

    /**
     * Gets set to true if the cache writes (set|delete) must have a manual lock created first.
     * @var bool
     */
    protected $requirelockingbeforewrite = false;

    /**
     * Gets set to true if the cache's primary store natively supports locking.
     * If it does then we use that, otherwise we need to instantiate a second store to use for locking.
     * @var store|null
     */
    protected $nativelocking = null;

    /**
     * Creates a new cache instance for a pre-defined definition.
     *
     * @param string $component The component for the definition
     * @param string $area The area for the definition
     * @param array $identifiers Any additional identifiers that should be provided to the definition.
     * @param string $unused Used to be datasourceaggregate but that was removed and this is now unused.
     * @return application_cache|session_cache|store
     */
    public static function make($component, $area, array $identifiers = [], $unused = null) {
        $factory = factory::instance();
        return $factory->create_cache_from_definition($component, $area, $identifiers);
    }

    /**
     * Creates a new cache instance based upon the given params.
     *
     * @param int $mode One of store::MODE_*
     * @param string $component The component this cache relates to.
     * @param string $area The area this cache relates to.
     * @param array $identifiers Any additional identifiers that should be provided to the definition.
     * @param array $options An array of options, available options are:
     *   - simplekeys : Set to true if the keys you will use are a-zA-Z0-9_
     *   - simpledata : Set to true if the type of the data you are going to store is scalar, or an array of scalar vars
     *   - staticacceleration : If set to true the cache will hold onto data passing through it.
     *   - staticaccelerationsize : The max size for the static acceleration array.
     * @return application_cache|session_cache|request_cache
     */
    public static function make_from_params($mode, $component, $area, array $identifiers = [], array $options = []) {
        $factory = factory::instance();
        return $factory->create_cache_from_params($mode, $component, $area, $identifiers, $options);
    }

    /**
     * Constructs a new cache instance.
     *
     * You should not call this method from your code, instead you should use the cache::make methods.
     *
     * This method is public so that the factory is able to instantiate cache instances.
     * Ideally we would make this method protected and expose its construction to the factory method internally somehow.
     * The factory class is responsible for this in order to centralise the storage of instances once created. This way if needed
     * we can force a reset of the cache API (used during unit testing).
     *
     * @param definition $definition The definition for the cache instance.
     * @param store $store The store that cache should use.
     * @param loader_interface|data_source_interface $loader The next loader in the chain or the data source if there is one
     *                                                       and there are no other loader_interfaces in the chain.
     */
    public function __construct(definition $definition, store $store, $loader = null) {
        global $CFG;
        $this->definition = $definition;
        $this->store = $store;
        $this->storetype = get_class($store);
        $this->perfdebug = (!empty($CFG->perfdebug) and $CFG->perfdebug > 7);
        if ($loader instanceof loader_interface) {
            $this->set_loader($loader);
        } else if ($loader instanceof data_source_interface) {
            $this->set_data_source($loader);
        }
        $this->definition->generate_definition_hash();
        $this->staticacceleration = $this->definition->use_static_acceleration();
        if ($this->staticacceleration) {
            $this->staticaccelerationsize = $this->definition->get_static_acceleration_size();
        }
        $this->hasattl = ($this->definition->get_ttl() > 0);
    }

    /**
     * Set the loader for this cache.
     *
     * @param   loader_interface $loader
     */
    protected function set_loader(loader_interface $loader): void {
        $this->loader = $loader;

        // Mark the loader as a sub (chained) loader.
        $this->loader->set_is_sub_loader(true);
    }

    /**
     * Set the data source for this cache.
     *
     * @param   data_source_interface $datasource
     */
    protected function set_data_source(data_source_interface $datasource): void {
        $this->datasource = $datasource;
    }

    /**
     * Used to inform the loader of its state as a sub loader, or as the top of the chain.
     *
     * This is important as it ensures that we do not have more than one loader keeping static acceleration data.
     * Subloaders need to be "pure" loaders in the sense that they are used to store and retrieve information from stores or the
     * next loader/data source in the chain.
     * Nothing fancy, nothing flash.
     *
     * @param bool $setting
     */
    protected function set_is_sub_loader($setting = true) {
        if ($setting) {
            $this->subloader = true;
            // Subloaders should not keep static acceleration data.
            $this->staticacceleration = false;
            $this->staticaccelerationsize = false;
        } else {
            $this->subloader = true;
            $this->staticacceleration = $this->definition->use_static_acceleration();
            if ($this->staticacceleration) {
                $this->staticaccelerationsize = $this->definition->get_static_acceleration_size();
            }
        }
    }

    /**
     * Alters the identifiers that have been provided to the definition.
     *
     * This is an advanced method and should not be used unless really needed.
     * It allows the developer to slightly alter the definition without having to re-establish the cache.
     * It will cause more processing as the definition will need to clear and reprepare some of its properties.
     *
     * @param array $identifiers
     */
    public function set_identifiers(array $identifiers) {
        if ($this->definition->set_identifiers($identifiers)) {
            // As static acceleration uses input keys and not parsed keys
            // it much be cleared when the identifier set is changed.
            $this->staticaccelerationarray = [];
            if ($this->staticaccelerationsize !== false) {
                $this->staticaccelerationkeys = [];
                $this->staticaccelerationcount = 0;
            }
        }
    }

    /**
     * Process any outstanding invalidation events for the cache we are registering,
     *
     * Identifiers and event invalidation are not compatible with each other at this time.
     * As a result the cache does not need to consider identifiers when working out what to invalidate.
     */
    protected function handle_invalidation_events() {
        if (!$this->definition->has_invalidation_events()) {
            return;
        }

        // Each cache stores the current 'lastinvalidation' value within the cache itself.
        $lastinvalidation = $this->get('lastinvalidation');
        if ($lastinvalidation === false) {
            // There is currently no  value for the lastinvalidation token, therefore the token is not set, and there
            // can be nothing to invalidate.
            // Set the lastinvalidation value to the current purge token and return early.
            $this->set('lastinvalidation', self::get_purge_token());
            return;
        } else if ($lastinvalidation == self::get_purge_token()) {
            // The current purge request has already been fully handled by this cache.
            return;
        }

        /*
         * Now that the whole cache check is complete, we check the meaning of any specific cache invalidation events.
         * These are stored in the core/eventinvalidation cache as an multi-dimensinoal array in the form:
         *  [
         *      eventname => [
         *          keyname => purgetoken,
         *      ]
         *  ]
         *
         * The 'keyname' value is used to delete a specific key in the cache.
         * If the keyname is set to the special value 'purged', then the whole cache is purged instead.
         *
         * The 'purgetoken' is the token that this key was last purged.
         * a) If the purgetoken matches the last invalidation, then the key/cache is not purged.
         * b) If the purgetoken is newer than the last invalidation, then the key/cache is not purged.
         * c) If the purge token is older than the last invalidation, or it has a different token component, then the
         *    cache is purged.
         *
         * Option b should not happen under normal operation, but may happen in race condition whereby a long-running
         * request's cache is cleared in another process during that request, and prior to that long-running request
         * creating the cache. In such a condition, it would be incorrect to clear that cache.
         */
        $cache = self::make('core', 'eventinvalidation');
        $events = $cache->get_many($this->definition->get_invalidation_events());
        $todelete = [];
        $purgeall = false;

        // Iterate the returned data for the events.
        foreach ($events as $event => $keys) {
            if ($keys === false) {
                // No data to be invalidated yet.
                continue;
            }

            // Look at each key and check the timestamp.
            foreach ($keys as $key => $purgetoken) {
                // If the timestamp of the event is more than or equal to the last invalidation (happened between the last
                // invalidation and now), then we need to invaliate the key.
                if (self::compare_purge_tokens($purgetoken, $lastinvalidation) > 0) {
                    if ($key === 'purged') {
                        $purgeall = true;
                        break;
                    } else {
                        $todelete[] = $key;
                    }
                }
            }
        }
        if ($purgeall) {
            $this->purge();
        } else if (!empty($todelete)) {
            $todelete = array_unique($todelete);
            $this->delete_many($todelete);
        }
        // Set the time of the last invalidation.
        if ($purgeall || !empty($todelete)) {
            $this->set('lastinvalidation', self::get_purge_token(true));
        }
    }

    /**
     * Retrieves the value for the given key from the cache.
     *
     * @param string|int $key The key for the data being requested.
     *      It can be any structure although using a scalar string or int is recommended in the interests of performance.
     *      In advanced cases an array may be useful such as in situations requiring the multi-key functionality.
     * @param int $strictness One of IGNORE_MISSING | MUST_EXIST
     * @return mixed|false The data from the cache or false if the key did not exist within the cache.
     * @throws coding_exception
     */
    public function get($key, $strictness = IGNORE_MISSING) {
        return $this->get_implementation($key, self::VERSION_NONE, $strictness);
    }

    /**
     * Retrieves the value and actual version for the given key, with at least the required version.
     *
     * If there is no value for the key, or there is a value but it doesn't have the required
     * version, then this function will return null (or throw an exception if you set strictness
     * to MUST_EXIST).
     *
     * This function can be used to make it easier to support localisable caches (where the cache
     * could be stored on a local server as well as a shared cache). Specifying the version means
     * that it will automatically retrieve the correct version if available, either from the local
     * server or [if that has an older version] from the shared server.
     *
     * If the cached version is newer than specified version, it will be returned regardless. For
     * example, if you request version 4, but the locally cached version is 5, it will be returned.
     * If you request version 6, and the locally cached version is 5, then the system will look in
     * higher-level caches (if any); if there still isn't a version 6 or greater, it will return
     * null.
     *
     * You must use this function if you use set_versioned.
     *
     * @param string|int $key The key for the data being requested.
     * @param int $requiredversion Minimum required version of the data
     * @param int $strictness One of IGNORE_MISSING or MUST_EXIST.
     * @param mixed $actualversion If specified, will be set to the actual version number retrieved
     * @return mixed Data from the cache, or false if the key did not exist or was too old
     * @throws \coding_exception If you call get_versioned on a non-versioned cache key
     */
    public function get_versioned($key, int $requiredversion, int $strictness = IGNORE_MISSING, &$actualversion = null) {
        return $this->get_implementation($key, $requiredversion, $strictness, $actualversion);
    }

    /**
     * Checks returned data to see if it matches the specified version number.
     *
     * For versioned data, this returns the version_wrapper object (or false). For other
     * data, it returns the actual data (or false).
     *
     * @param mixed $result Result data
     * @param int $requiredversion Required version number or VERSION_NONE if there must be no version
     * @return bool True if version is current, false if not (or if result is false)
     * @throws \coding_exception If unexpected type of data (versioned vs non-versioned) is found
     */
    protected static function check_version($result, int $requiredversion): bool {
        if ($requiredversion === self::VERSION_NONE) {
            if ($result instanceof \core_cache\version_wrapper) {
                throw new \coding_exception('Unexpectedly found versioned cache entry');
            } else {
                // No version checks, so version is always correct.
                return true;
            }
        } else {
            // If there's no result, obviously it doesn't meet the required version.
            if (!helper::result_found($result)) {
                return false;
            }
            if (!($result instanceof \core_cache\version_wrapper)) {
                throw new \coding_exception('Unexpectedly found non-versioned cache entry');
            }
            // If the result doesn't match the required version tag, return false.
            if ($result->version < $requiredversion) {
                return false;
            }
            // The version meets the requirement.
            return true;
        }
    }

    /**
     * Retrieves the value for the given key from the cache.
     *
     * @param string|int $key The key for the data being requested.
     *      It can be any structure although using a scalar string or int is recommended in the interests of performance.
     *      In advanced cases an array may be useful such as in situations requiring the multi-key functionality.
     * @param int $requiredversion Minimum required version of the data or cache::VERSION_NONE
     * @param int $strictness One of IGNORE_MISSING | MUST_EXIST
     * @param mixed $actualversion If specified, will be set to the actual version number retrieved
     * @return mixed|false The data from the cache or false if the key did not exist within the cache.
     * @throws coding_exception
     */
    protected function get_implementation($key, int $requiredversion, int $strictness, &$actualversion = null) {
        // 1. Get it from the static acceleration array if we can (only when it is enabled and it has already been requested/set).
        $usesstaticacceleration = $this->use_static_acceleration();

        if ($usesstaticacceleration) {
            $result = $this->static_acceleration_get($key);
            if (helper::result_found($result) && self::check_version($result, $requiredversion)) {
                if ($requiredversion === self::VERSION_NONE) {
                    return $result;
                } else {
                    $actualversion = $result->version;
                    return $result->data;
                }
            }
        }

        // 2. Parse the key.
        $parsedkey = $this->parse_key($key);

        // 3. Get it from the store. Obviously wasn't in the static acceleration array.
        $result = $this->store->get($parsedkey);
        if (helper::result_found($result)) {
            // Check the result has at least the required version.
            try {
                $validversion = self::check_version($result, $requiredversion);
            } catch (\coding_exception $e) {
                // In certain circumstances this could happen before users are taken to the upgrade
                // screen when upgrading from an earlier Moodle version that didn't use a versioned
                // cache for this item, so redirect instead of showing error if that's the case.
                redirect_if_major_upgrade_required();

                // If we still get an exception because there is incorrect data in the cache (not
                // versioned when it ought to be), delete it so this exception goes away next time.
                // The exception should only happen if there is a code bug (which is why we still
                // throw it) but there are unusual scenarios in development where it might happen
                // and that would be annoying if it doesn't fix itself.
                $this->store->delete($parsedkey);
                throw $e;
            }

            if (!$validversion) {
                // If the result was too old, don't use it.
                $result = false;

                // Also delete it immediately. This improves performance in the
                // case when the cache item is large and there may be multiple clients simultaneously
                // requesting it - they won't all have to do a megabyte of IO just in order to find
                // that it's out of date.
                $this->store->delete($parsedkey);
            }
        }
        if (helper::result_found($result)) {
            // Look to see if there's a TTL wrapper. It might be inside a version wrapper.
            if ($requiredversion !== self::VERSION_NONE) {
                $ttlconsider = $result->data;
            } else {
                $ttlconsider = $result;
            }
            if ($ttlconsider instanceof ttl_wrapper) {
                if ($ttlconsider->has_expired()) {
                    $this->store->delete($parsedkey);
                    $result = false;
                } else if ($requiredversion === self::VERSION_NONE) {
                    // Use the data inside the TTL wrapper as the result.
                    $result = $ttlconsider->data;
                } else {
                    // Put the data from the TTL wrapper directly inside the version wrapper.
                    $result->data = $ttlconsider->data;
                }
            }
            if ($usesstaticacceleration) {
                $this->static_acceleration_set($key, $result);
            }
            // Remove version wrapper if necessary.
            if ($requiredversion !== self::VERSION_NONE) {
                $actualversion = $result->version;
                $result = $result->data;
            }
            if ($result instanceof cached_object) {
                $result = $result->restore_object();
            }
        }

        // 4. Load if from the loader/datasource if we don't already have it.
        $setaftervalidation = false;
        if (!helper::result_found($result)) {
            if ($this->perfdebug) {
                helper::record_cache_miss($this->store, $this->definition);
            }
            if ($this->loader !== false) {
                // We must pass the original (unparsed) key to the next loader in the chain.
                // The next loader will parse the key as it sees fit. It may be parsed differently
                // depending upon the capabilities of the store associated with the loader.
                if ($requiredversion === self::VERSION_NONE) {
                    $result = $this->loader->get($key);
                } else {
                    $result = $this->loader->get_versioned($key, $requiredversion, IGNORE_MISSING, $actualversion);
                }
            } else if ($this->datasource !== false) {
                if ($requiredversion === self::VERSION_NONE) {
                    $result = $this->datasource->load_for_cache($key);
                } else {
                    if (!$this->datasource instanceof versionable_data_source_interface) {
                        throw new \coding_exception('Data source is not versionable');
                    }
                    $result = $this->datasource->load_for_cache_versioned($key, $requiredversion, $actualversion);
                    if ($result && $actualversion < $requiredversion) {
                        throw new \coding_exception('Data source returned outdated version');
                    }
                }
            }
            $setaftervalidation = (helper::result_found($result));
        } else if ($this->perfdebug) {
            $readbytes = $this->store->get_last_io_bytes();
            helper::record_cache_hit($this->store, $this->definition, 1, $readbytes);
        }
        // 5. Validate strictness.
        if ($strictness === MUST_EXIST && !helper::result_found($result)) {
            throw new coding_exception('Requested key did not exist in any cache stores and could not be loaded.');
        }
        // 6. Set it to the store if we got it from the loader/datasource. Only set to this direct
        // store; parent method will have set it to all stores if needed.
        if ($setaftervalidation) {
            $lock = false;
            try {
                // Only try to acquire a lock for this cache if we do not already have one.
                if (!empty($this->requirelockingbeforewrite) && !$this->check_lock_state($key)) {
                    $this->acquire_lock($key);
                    $lock = true;
                }
                if ($requiredversion === self::VERSION_NONE) {
                    $this->set_implementation($key, self::VERSION_NONE, $result, false);
                } else {
                    $this->set_implementation($key, $actualversion, $result, false);
                }
            } finally {
                if ($lock) {
                    $this->release_lock($key);
                }
            }
        }
        // 7. Make sure we don't pass back anything that could be a reference.
        // We don't want people modifying the data in the cache.
        if (!$this->store->supports_dereferencing_objects() && !is_scalar($result)) {
            // If data is an object it will be a reference.
            // If data is an array if may contain references.
            // We want to break references so that the cache cannot be modified outside of itself.
            // Call the function to unreference it (in the best way possible).
            $result = $this->unref($result);
        }
        return $result;
    }

    /**
     * Retrieves an array of values for an array of keys.
     *
     * Using this function comes with potential performance implications.
     * Not all cache stores will support get_many/set_many operations and in order to replicate this functionality will call
     * the equivalent singular method for each item provided.
     * This should not deter you from using this function as there is a performance benefit in situations where the cache store
     * does support it, but you should be aware of this fact.
     *
     * @param array $keys The keys of the data being requested.
     *      Each key can be any structure although using a scalar string or int is recommended in the interests of performance.
     *      In advanced cases an array may be useful such as in situations requiring the multi-key functionality.
     * @param int $strictness One of IGNORE_MISSING or MUST_EXIST.
     * @return array An array of key value pairs for the items that could be retrieved from the cache.
     *      If MUST_EXIST was used and not all keys existed within the cache then an exception will be thrown.
     *      Otherwise any key that did not exist will have a data value of false within the results.
     * @throws coding_exception
     */
    public function get_many(array $keys, $strictness = IGNORE_MISSING) {

        $keysparsed = [];
        $parsedkeys = [];
        $resultpersist = [];
        $resultstore = [];
        $keystofind = [];
        $readbytes = store::IO_BYTES_NOT_SUPPORTED;

        // First up check the persist cache for each key.
        $isusingpersist = $this->use_static_acceleration();
        foreach ($keys as $key) {
            $pkey = $this->parse_key($key);
            if (is_array($pkey)) {
                $pkey = $pkey['key'];
            }
            $keysparsed[$key] = $pkey;
            $parsedkeys[$pkey] = $key;
            $keystofind[$pkey] = $key;
            if ($isusingpersist) {
                $value = $this->static_acceleration_get($key);
                if ($value !== false) {
                    $resultpersist[$pkey] = $value;
                    unset($keystofind[$pkey]);
                }
            }
        }

        // Next assuming we didn't find all of the keys in the persist cache try loading them from the store.
        if (count($keystofind)) {
            $resultstore = $this->store->get_many(array_keys($keystofind));
            if ($this->perfdebug) {
                $readbytes = $this->store->get_last_io_bytes();
            }
            // Process each item in the result to "unwrap" it.
            foreach ($resultstore as $key => $value) {
                if ($value instanceof ttl_wrapper) {
                    if ($value->has_expired()) {
                        $value = false;
                    } else {
                        $value = $value->data;
                    }
                }
                if ($value !== false && $this->use_static_acceleration()) {
                    $this->static_acceleration_set($keystofind[$key], $value);
                }
                if ($value instanceof cached_object) {
                    $value = $value->restore_object();
                }
                $resultstore[$key] = $value;
            }
        }

        // Merge the result from the persis cache with the results from the store load.
        $result = $resultpersist + $resultstore;
        unset($resultpersist);
        unset($resultstore);

        // Next we need to find any missing values and load them from the loader/datasource next in the chain.
        $usingloader = ($this->loader !== false);
        $usingsource = (!$usingloader && ($this->datasource !== false));
        if ($usingloader || $usingsource) {
            $missingkeys = [];
            foreach ($result as $key => $value) {
                if ($value === false) {
                    $missingkeys[] = $parsedkeys[$key];
                }
            }
            if (!empty($missingkeys)) {
                if ($usingloader) {
                    $resultmissing = $this->loader->get_many($missingkeys);
                } else {
                    $resultmissing = $this->datasource->load_many_for_cache($missingkeys);
                }
                foreach ($resultmissing as $key => $value) {
                    $result[$keysparsed[$key]] = $value;
                    $lock = false;
                    try {
                        if (!empty($this->requirelockingbeforewrite)) {
                            $this->acquire_lock($key);
                            $lock = true;
                        }
                        if ($value !== false) {
                            $this->set($key, $value);
                        }
                    } finally {
                        if ($lock) {
                            $this->release_lock($key);
                        }
                    }
                }
                unset($resultmissing);
            }
            unset($missingkeys);
        }

        // Create an array with the original keys and the found values. This will be what we return.
        $fullresult = [];
        foreach ($result as $key => $value) {
            if (!is_scalar($value)) {
                // If data is an object it will be a reference.
                // If data is an array if may contain references.
                // We want to break references so that the cache cannot be modified outside of itself.
                // Call the function to unreference it (in the best way possible).
                $value = $this->unref($value);
            }
            $fullresult[$parsedkeys[$key]] = $value;
        }
        unset($result);

        // Final step is to check strictness.
        if ($strictness === MUST_EXIST) {
            foreach ($keys as $key) {
                if (!array_key_exists($key, $fullresult)) {
                    throw new coding_exception('Not all the requested keys existed within the cache stores.');
                }
            }
        }

        if ($this->perfdebug) {
            $hits = 0;
            $misses = 0;
            foreach ($fullresult as $value) {
                if ($value === false) {
                    $misses++;
                } else {
                    $hits++;
                }
            }
            helper::record_cache_hit($this->store, $this->definition, $hits, $readbytes);
            helper::record_cache_miss($this->store, $this->definition, $misses);
        }

        // Return the result. Phew!
        return $fullresult;
    }

    /**
     * Sends a key => value pair to the cache.
     *
     * <code>
     * // This code will add four entries to the cache, one for each url.
     * $cache->set('main', 'http://moodle.org');
     * $cache->set('docs', 'http://docs.moodle.org');
     * $cache->set('tracker', 'http://tracker.moodle.org');
     * $cache->set('qa', 'http://qa.moodle.net');
     * </code>
     *
     * @param string|int $key The key for the data being requested.
     *      It can be any structure although using a scalar string or int is recommended in the interests of performance.
     *      In advanced cases an array may be useful such as in situations requiring the multi-key functionality.
     * @param mixed $data The data to set against the key.
     * @return bool True on success, false otherwise.
     */
    public function set($key, $data) {
        return $this->set_implementation($key, self::VERSION_NONE, $data);
    }

    /**
     * Sets the value for the given key with the given version.
     *
     * The cache does not store multiple versions - any existing version will be overwritten with
     * this one. This function should only be used if there is a known 'current version' (e.g.
     * stored in a database table). It only ensures that the cache does not return outdated data.
     *
     * This function can be used to help implement localisable caches (where the cache could be
     * stored on a local server as well as a shared cache). The version will be recorded alongside
     * the item and get_versioned will always return the correct version.
     *
     * The version number must be an integer that always increases. This could be based on the
     * current time, or a stored value that increases by 1 each time it changes, etc.
     *
     * If you use this function you must use get_versioned to retrieve the data.
     *
     * @param string|int $key The key for the data being set.
     * @param int $version Integer for the version of the data
     * @param mixed $data The data to set against the key.
     * @return bool True on success, false otherwise.
     */
    public function set_versioned($key, int $version, $data): bool {
        return $this->set_implementation($key, $version, $data);
    }

    /**
     * Sets the value for the given key, optionally with a version tag.
     *
     * @param string|int $key The key for the data being set.
     * @param int $version Version number for the data or cache::VERSION_NONE if none
     * @param mixed $data The data to set against the key.
     * @param bool $setparents If true, sets all parent loaders, otherwise only this one
     * @return bool True on success, false otherwise.
     */
    protected function set_implementation($key, int $version, $data, bool $setparents = true): bool {
        if ($this->loader !== false && $setparents) {
            // We have a loader available set it there as well.
            // We have to let the loader do its own parsing of data as it may be unique.
            if ($version === self::VERSION_NONE) {
                $this->loader->set($key, $data);
            } else {
                $this->loader->set_versioned($key, $version, $data);
            }
        }
        $usestaticacceleration = $this->use_static_acceleration();

        if (is_object($data) && $data instanceof cacheable_object_interface) {
            $data = new cached_object($data);
        } else if (!$this->store->supports_dereferencing_objects() && !is_scalar($data)) {
            // If data is an object it will be a reference.
            // If data is an array if may contain references.
            // We want to break references so that the cache cannot be modified outside of itself.
            // Call the function to unreference it (in the best way possible).
            $data = $this->unref($data);
        }

        if ($usestaticacceleration) {
            // Static acceleration cache should include the cache version wrapper, but not TTL.
            if ($version === self::VERSION_NONE) {
                $this->static_acceleration_set($key, $data);
            } else {
                $this->static_acceleration_set($key, new \core_cache\version_wrapper($data, $version));
            }
        }

        if ($this->has_a_ttl() && !$this->store_supports_native_ttl()) {
            $data = new ttl_wrapper($data, $this->definition->get_ttl());
        }
        $parsedkey = $this->parse_key($key);

        if ($version !== self::VERSION_NONE) {
            $data = new \core_cache\version_wrapper($data, $version);
        }

        $success = $this->store->set($parsedkey, $data);
        if ($this->perfdebug) {
            helper::record_cache_set(
                $this->store,
                $this->definition,
                1,
                $this->store->get_last_io_bytes()
            );
        }
        return $success;
    }

    /**
     * Removes references where required.
     *
     * @param stdClass|array $data
     * @return mixed What ever was put in but without any references.
     */
    protected function unref($data) {
        if ($this->definition->uses_simple_data()) {
            return $data;
        }
        // Check if it requires serialisation in order to produce a reference free copy.
        if ($this->requires_serialisation($data)) {
            // Damn, its going to have to be serialise.
            $data = serialize($data);
            // We unserialise immediately so that we don't have to do it every time on get.
            $data = unserialize($data);
        } else if (!is_scalar($data)) {
            // Its safe to clone, lets do it, its going to beat the pants of serialisation.
            $data = $this->deep_clone($data);
        }
        return $data;
    }

    /**
     * Checks to see if a var requires serialisation.
     *
     * @param mixed $value The value to check.
     * @param int $depth Used to ensure we don't enter an endless loop (think recursion).
     * @return bool Returns true if the value is going to require serialisation in order to ensure a reference free copy
     *      or false if its safe to clone.
     */
    protected function requires_serialisation($value, $depth = 1) {
        if (is_scalar($value)) {
            return false;
        } else if (is_array($value) || $value instanceof stdClass || $value instanceof Traversable) {
            if ($depth > 5) {
                // Skrew it, mega-deep object, developer you suck, we're just going to serialise.
                return true;
            }
            foreach ($value as $key => $subvalue) {
                if ($this->requires_serialisation($subvalue, $depth++)) {
                    return true;
                }
            }
        }
        // Its not scalar, array, or stdClass so we'll need to serialise.
        return true;
    }

    /**
     * Creates a reference free clone of the given value.
     *
     * @param mixed $value
     * @return mixed
     */
    protected function deep_clone($value) {
        if (is_object($value)) {
            // Objects must be cloned to begin with.
            $value = clone $value;
        }
        if (is_array($value) || is_object($value)) {
            foreach ($value as $key => $subvalue) {
                $value[$key] = $this->deep_clone($subvalue);
            }
        }
        return $value;
    }

    /**
     * Sends several key => value pairs to the cache.
     *
     * Using this function comes with potential performance implications.
     * Not all cache stores will support get_many/set_many operations and in order to replicate this functionality will call
     * the equivalent singular method for each item provided.
     * This should not deter you from using this function as there is a performance benefit in situations where the cache store
     * does support it, but you should be aware of this fact.
     *
     * <code>
     * // This code will add four entries to the cache, one for each url.
     * $cache->set_many(array(
     *     'main' => 'http://moodle.org',
     *     'docs' => 'http://docs.moodle.org',
     *     'tracker' => 'http://tracker.moodle.org',
     *     'qa' => ''http://qa.moodle.net'
     * ));
     * </code>
     *
     * @param array $keyvaluearray An array of key => value pairs to send to the cache.
     * @return int The number of items successfully set. It is up to the developer to check this matches the number of items.
     *      ... if they care that is.
     */
    public function set_many(array $keyvaluearray) {
        if ($this->loader !== false) {
            // We have a loader available set it there as well.
            // We have to let the loader do its own parsing of data as it may be unique.
            $this->loader->set_many($keyvaluearray);
        }
        $data = array();
        $simulatettl = $this->has_a_ttl() && !$this->store_supports_native_ttl();
        $usestaticaccelerationarray = $this->use_static_acceleration();
        $needsdereferencing = !$this->store->supports_dereferencing_objects();
        foreach ($keyvaluearray as $key => $value) {
            if (is_object($value) && $value instanceof cacheable_object_interface) {
                $value = new cached_object($value);
            } else if ($needsdereferencing && !is_scalar($value)) {
                // If data is an object it will be a reference.
                // If data is an array if may contain references.
                // We want to break references so that the cache cannot be modified outside of itself.
                // Call the function to unreference it (in the best way possible).
                $value = $this->unref($value);
            }
            if ($usestaticaccelerationarray) {
                $this->static_acceleration_set($key, $value);
            }
            if ($simulatettl) {
                $value = new ttl_wrapper($value, $this->definition->get_ttl());
            }
            $data[$key] = [
                'key' => $this->parse_key($key),
                'value' => $value,
            ];
        }
        $successfullyset = $this->store->set_many($data);
        if ($this->perfdebug && $successfullyset) {
            helper::record_cache_set(
                $this->store,
                $this->definition,
                $successfullyset,
                $this->store->get_last_io_bytes()
            );
        }
        return $successfullyset;
    }

    /**
     * Test is a cache has a key.
     *
     * The use of the has methods is strongly discouraged. In a high load environment the cache may well change between the
     * test and any subsequent action (get, set, delete etc).
     * Instead it is recommended to write your code in such a way they it performs the following steps:
     * <ol>
     * <li>Attempt to retrieve the information.</li>
     * <li>Generate the information.</li>
     * <li>Attempt to set the information</li>
     * </ol>
     *
     * Its also worth mentioning that not all stores support key tests.
     * For stores that don't support key tests this functionality is mimicked by using the equivalent get method.
     * Just one more reason you should not use these methods unless you have a very good reason to do so.
     *
     * @param string|int $key
     * @param bool $tryloadifpossible If set to true, the cache doesn't contain the key, and there is another cache loader or
     *      data source then the code will try load the key value from the next item in the chain.
     * @return bool True if the cache has the requested key, false otherwise.
     */
    public function has($key, $tryloadifpossible = false) {
        if ($this->static_acceleration_has($key)) {
            // Hoorah, that was easy. It exists in the static acceleration array so we definitely have it.
            return true;
        }
        $parsedkey = $this->parse_key($key);

        if ($this->has_a_ttl() && !$this->store_supports_native_ttl()) {
            // The data has a TTL and the store doesn't support it natively.
            // We must fetch the data and expect a ttl wrapper.
            $data = $this->store->get($parsedkey);
            $has = ($data instanceof ttl_wrapper && !$data->has_expired());
        } else if (!$this->store_supports_key_awareness()) {
            // The store doesn't support key awareness, get the data and check it manually... puke.
            // Either no TTL is set of the store supports its handling natively.
            $data = $this->store->get($parsedkey);
            $has = ($data !== false);
        } else {
            // The store supports key awareness, this is easy!
            // Either no TTL is set of the store supports its handling natively.
            $has = $this->store->has($parsedkey);
        }
        if (!$has && $tryloadifpossible) {
            if ($this->loader !== false) {
                $result = $this->loader->get($parsedkey);
            } else if ($this->datasource !== null) {
                $result = $this->datasource->load_for_cache($key);
            }
            $has = ($result !== null);
            if ($has) {
                $this->set($key, $result);
            }
        }
        return $has;
    }

    /**
     * Test is a cache has all of the given keys.
     *
     * It is strongly recommended to avoid the use of this function if not absolutely required.
     * In a high load environment the cache may well change between the test and any subsequent action (get, set, delete etc).
     *
     * Its also worth mentioning that not all stores support key tests.
     * For stores that don't support key tests this functionality is mimicked by using the equivalent get method.
     * Just one more reason you should not use these methods unless you have a very good reason to do so.
     *
     * @param array $keys
     * @return bool True if the cache has all of the given keys, false otherwise.
     */
    public function has_all(array $keys) {
        if (($this->has_a_ttl() && !$this->store_supports_native_ttl()) || !$this->store_supports_key_awareness()) {
            foreach ($keys as $key) {
                if (!$this->has($key)) {
                    return false;
                }
            }
            return true;
        }
        $parsedkeys = array_map([$this, 'parse_key'], $keys);
        return $this->store->has_all($parsedkeys);
    }

    /**
     * Test if a cache has at least one of the given keys.
     *
     * It is strongly recommended to avoid the use of this function if not absolutely required.
     * In a high load environment the cache may well change between the test and any subsequent action (get, set, delete etc).
     *
     * Its also worth mentioning that not all stores support key tests.
     * For stores that don't support key tests this functionality is mimicked by using the equivalent get method.
     * Just one more reason you should not use these methods unless you have a very good reason to do so.
     *
     * @param array $keys
     * @return bool True if the cache has at least one of the given keys
     */
    public function has_any(array $keys) {
        if (($this->has_a_ttl() && !$this->store_supports_native_ttl()) || !$this->store_supports_key_awareness()) {
            foreach ($keys as $key) {
                if ($this->has($key)) {
                    return true;
                }
            }
            return false;
        }

        if ($this->use_static_acceleration()) {
            foreach ($keys as $id => $key) {
                if ($this->static_acceleration_has($key)) {
                    return true;
                }
            }
        }
        $parsedkeys = array_map([$this, 'parse_key'], $keys);
        return $this->store->has_any($parsedkeys);
    }

    /**
     * Delete the given key from the cache.
     *
     * @param string|int $key The key to delete.
     * @param bool $recurse When set to true the key will also be deleted from all stacked cache loaders and their stores.
     *     This happens by default and ensure that all the caches are consistent. It is NOT recommended to change this.
     * @return bool True of success, false otherwise.
     */
    public function delete($key, $recurse = true) {
        $this->static_acceleration_delete($key);
        if ($recurse && $this->loader !== false) {
            // Delete from the bottom of the stack first.
            $this->loader->delete($key, $recurse);
        }
        $parsedkey = $this->parse_key($key);
        return $this->store->delete($parsedkey);
    }

    /**
     * Delete all of the given keys from the cache.
     *
     * @param array $keys The key to delete.
     * @param bool $recurse When set to true the key will also be deleted from all stacked cache loaders and their stores.
     *     This happens by default and ensure that all the caches are consistent. It is NOT recommended to change this.
     * @return int The number of items successfully deleted.
     */
    public function delete_many(array $keys, $recurse = true) {
        if ($this->use_static_acceleration()) {
            foreach ($keys as $key) {
                $this->static_acceleration_delete($key);
            }
        }
        if ($recurse && $this->loader !== false) {
            // Delete from the bottom of the stack first.
            $this->loader->delete_many($keys, $recurse);
        }
        $parsedkeys = array_map([$this, 'parse_key'], $keys);
        return $this->store->delete_many($parsedkeys);
    }

    /**
     * Purges the cache store, and loader if there is one.
     *
     * @return bool True on success, false otherwise
     */
    public function purge() {
        // 1. Purge the static acceleration array.
        $this->static_acceleration_purge();
        // 2. Purge the store.
        $this->store->purge();
        // 3. Optionally pruge any stacked loaders.
        if ($this->loader) {
            $this->loader->purge();
        }
        return true;
    }

    /**
     * Parses the key turning it into a string (or array is required) suitable to be passed to the cache store.
     *
     * @param string|int $key As passed to get|set|delete etc.
     * @return string|array String unless the store supports multi-identifiers in which case an array if returned.
     */
    protected function parse_key($key) {
        // First up if the store supports multiple keys we'll go with that.
        if ($this->store->supports_multiple_identifiers()) {
            $result = $this->definition->generate_multi_key_parts();
            $result['key'] = $key;
            return $result;
        }
        // If not we need to generate a hash and to for that we use the helper.
        return helper::hash_key($key, $this->definition);
    }

    /**
     * Returns true if the cache is making use of a ttl.
     * @return bool
     */
    protected function has_a_ttl() {
        return $this->hasattl;
    }

    /**
     * Returns true if the cache store supports native ttl.
     * @return bool
     */
    protected function store_supports_native_ttl() {
        if ($this->supportsnativettl === null) {
            $this->supportsnativettl = ($this->store->supports_native_ttl());
        }
        return $this->supportsnativettl;
    }

    /**
     * Returns the cache definition.
     *
     * @return definition
     */
    protected function get_definition() {
        return $this->definition;
    }

    /**
     * Returns the cache store
     *
     * @return store
     */
    protected function get_store() {
        return $this->store;
    }

    /**
     * Returns the loader associated with this instance.
     *
     * @since Moodle 2.4.4
     * @return cache|false
     */
    protected function get_loader() {
        return $this->loader;
    }

    /**
     * Returns the data source associated with this cache.
     *
     * @since Moodle 2.4.4
     * @return data_source_interface|false
     */
    protected function get_datasource() {
        return $this->datasource;
    }

    /**
     * Returns true if the store supports key awareness.
     *
     * @return bool
     */
    protected function store_supports_key_awareness() {
        if ($this->supportskeyawareness === null) {
            $this->supportskeyawareness = ($this->store instanceof key_aware_cache_interface);
        }
        return $this->supportskeyawareness;
    }

    /**
     * Returns true if the store natively supports locking.
     *
     * @return bool
     */
    protected function store_supports_native_locking() {
        if ($this->nativelocking === null) {
            $this->nativelocking = ($this->store instanceof lockable_cache_interface);
        }
        return $this->nativelocking;
    }

    /**
     * Returns true if this cache is making use of the static acceleration array.
     *
     * @return bool
     */
    protected function use_static_acceleration() {
        return $this->staticacceleration;
    }

    /**
     * Returns true if the requested key exists within the static acceleration array.
     *
     * @param string $key The parsed key
     * @return bool
     */
    protected function static_acceleration_has($key) {
        // This could be written as a single line, however it has been split because the ttl check is faster than the instanceof
        // and has_expired calls.
        if (!$this->staticacceleration || !isset($this->staticaccelerationarray[$key])) {
            return false;
        }
        return true;
    }

    /**
     * Returns the item from the static acceleration array if it exists there.
     *
     * @param string $key The parsed key
     * @return mixed|false Dereferenced data from the static acceleration array or false if it wasn't there.
     */
    protected function static_acceleration_get($key) {
        if (!$this->staticacceleration || !isset($this->staticaccelerationarray[$key])) {
            $result = false;
        } else {
            $data = $this->staticaccelerationarray[$key]['data'];

            if ($data instanceof cached_object) {
                $result = $data->restore_object();
            } else if ($this->staticaccelerationarray[$key]['serialized']) {
                $result = unserialize($data);
            } else {
                $result = $data;
            }
        }
        if (helper::result_found($result)) {
            if ($this->perfdebug) {
                helper::record_cache_hit(store::STATIC_ACCEL, $this->definition);
            }
            if ($this->staticaccelerationsize > 1 && $this->staticaccelerationcount > 1) {
                // Check to see if this is the last item on the static acceleration keys array.
                if (end($this->staticaccelerationkeys) !== $key) {
                    // It isn't the last item.
                    // Move the item to the end of the array so that it is last to be removed.
                    unset($this->staticaccelerationkeys[$key]);
                    $this->staticaccelerationkeys[$key] = $key;
                }
            }
            return $result;
        } else {
            if ($this->perfdebug) {
                helper::record_cache_miss(store::STATIC_ACCEL, $this->definition);
            }
            return false;
        }
    }

    /**
     * Sets a key value pair into the static acceleration array.
     *
     * @param string $key The parsed key
     * @param mixed $data
     * @return bool
     */
    protected function static_acceleration_set($key, $data) {
        if ($this->staticaccelerationsize !== false && isset($this->staticaccelerationkeys[$key])) {
            $this->staticaccelerationcount--;
            unset($this->staticaccelerationkeys[$key]);
        }

        // We serialize anything that's not;
        // 1. A known scalar safe value.
        // 2. A definition that says it's simpledata.  We trust it that it doesn't contain dangerous references.
        // 3. An object that handles dereferencing by itself.
        if (
            is_scalar($data) || $this->definition->uses_simple_data()
                || $data instanceof cached_object
        ) {
            $this->staticaccelerationarray[$key]['data'] = $data;
            $this->staticaccelerationarray[$key]['serialized'] = false;
        } else {
            $this->staticaccelerationarray[$key]['data'] = serialize($data);
            $this->staticaccelerationarray[$key]['serialized'] = true;
        }
        if ($this->staticaccelerationsize !== false) {
            $this->staticaccelerationcount++;
            $this->staticaccelerationkeys[$key] = $key;
            if ($this->staticaccelerationcount > $this->staticaccelerationsize) {
                $dropkey = array_shift($this->staticaccelerationkeys);
                unset($this->staticaccelerationarray[$dropkey]);
                $this->staticaccelerationcount--;
            }
        }
        return true;
    }

    /**
     * Deletes an item from the static acceleration array.
     *
     * @param string|int $key As given to get|set|delete
     * @return bool True on success, false otherwise.
     */
    protected function static_acceleration_delete($key) {
        unset($this->staticaccelerationarray[$key]);
        if ($this->staticaccelerationsize !== false && isset($this->staticaccelerationkeys[$key])) {
            unset($this->staticaccelerationkeys[$key]);
            $this->staticaccelerationcount--;
        }
        return true;
    }

    /**
     * Purge the static acceleration cache.
     */
    protected function static_acceleration_purge() {
        $this->staticaccelerationarray = [];
        if ($this->staticaccelerationsize !== false) {
            $this->staticaccelerationkeys = [];
            $this->staticaccelerationcount = 0;
        }
    }

    /**
     * Returns the timestamp from the first request for the time from the cache API.
     *
     * This stamp needs to be used for all ttl and time based operations to ensure that we don't end up with
     * timing issues.
     *
     * @param   bool    $float Whether to use floating precision accuracy.
     * @return  int|float
     */
    public static function now($float = false) {
        if (self::$now === null) {
            self::$now = microtime(true);
        }

        if ($float) {
            return self::$now;
        } else {
            return (int) self::$now;
        }
    }

    /**
     * Get a 'purge' token used for cache invalidation handling.
     *
     * Note: This function is intended for use from within the Cache API only and not by plugins, or cache stores.
     *
     * @param   bool    $reset  Whether to reset the token and generate a new one.
     * @return  string
     */
    public static function get_purge_token($reset = false) {
        if (self::$purgetoken === null || $reset) {
            self::$now = null;
            self::$purgetoken = self::now(true) . '-' . uniqid('', true);
        }

        return self::$purgetoken;
    }

    /**
     * Compare a pair of purge tokens.
     *
     * If the two tokens are identical, then the return value is 0.
     * If the time component of token A is newer than token B, then a positive value is returned.
     * If the time component of token B is newer than token A, then a negative value is returned.
     *
     * Note: This function is intended for use from within the Cache API only and not by plugins, or cache stores.
     *
     * @param   string  $tokena
     * @param   string  $tokenb
     * @return  int
     */
    public static function compare_purge_tokens($tokena, $tokenb) {
        if ($tokena === $tokenb) {
            // There is an exact match.
            return 0;
        }

        // The token for when the cache was last invalidated.
        [$atime] = explode('-', "{$tokena}-", 2);

        // The token for this cache.
        [$btime] = explode('-', "{$tokenb}-", 2);

        if ($atime >= $btime) {
            // Token A is newer.
            return 1;
        } else {
            // Token A is older.
            return -1;
        }
    }

    /**
     * Subclasses may support purging cache of all data belonging to the
     * current user.
     */
    public function purge_current_user() {
    }
}

// Alias this class to the old name.
// This file will be autoloaded by the legacyclasses autoload system.
// In future all uses of this class will be corrected and the legacy references will be removed.
class_alias(cache::class, \cache::class);
