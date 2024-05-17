<?php

declare(strict_types=1);

namespace Amne\Psr16Layers;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * This class will serve as a layered cache that fetches a value
 * from the first layer that has it. Each layer is a Psr16 cache
 * implementation.
 *
 * This can have an arbitrary number of layers.
 * Please use common sense.
 * Each layer has its own latency and concurrency characteristics
 * that are mostly ignored in this implementation for simplicity.
 *
 * An interesting usecase would be if you have precomputed data in a
 * file (flysystem?) or database and want to "lift" it to a faster storage,
 * like redis, memcached or APCu.
 *
 * @author    Cornel Cruceru <cornel@cruceru.cc>
 * @copyright Copyright (c) 2024, Cornel Cruceru. All rights reserved
 * @license   LICENSE MIT
 */
class LayerCache implements CacheInterface 
{
    /**
     * simpleCacheLayer in order of use.
     *
     * @var CacheInterface[]
     */
    protected array $simpleCacheLayers = [];

    /**
     * For layer you can configure a max TTL 
     *
     * @var int[]
     */
    private array $maxTTLs = [];

    /**
     * Layered layer takes a list of KeyValue layers and
     * a corresponding list of max lifetimes for each layer
     *
     * @param CacheInterface[] $simpleCacheLayers
     * @param int[]           $maxLifetimes
     * @param array<int,mixed> $maxTTLs
     */
    public function __construct(array $simpleCacheLayers, array $maxTTLs = [])
    {
        foreach ($simpleCacheLayers as $i => $simpleCacheLayer) {
            if ( !($simpleCacheLayer instanceof CacheInterface) ) {
                throw new \RuntimeException('Cache layer must implement PSR-16 Psr\SimpleCache\CacheInterface');
            }

            $this->simpleCacheLayers[] = $simpleCacheLayer;
            $this->maxTTLs[] = $maxTTLs[$i] ?? PHP_INT_MAX;
        }
    }

    private function _key_meta(mixed $key): string
    {
        if (!is_scalar($key)) {
            throw new \Exception('key is not scalar');
        }
        return $key . '-kvls-meta';
    }

    /**
     * Iterate through all layers and return a value if one is found
     * A token is generated by hashing the value to emulate cas
     *
     * {@inheritdoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // Start with the first simpleCacheLayer
        $value = false;
        
        $result = $this->getMultiple([$key], $default);
        $value = $result[$key] ?? $default;

        return $value;
    }


    public function has(string $key): bool
    {
        $result = true;
        if (empty($this->simpleCacheLayers)) {
            return false;
        }

        foreach($this->simpleCacheLayers as $simpleCacheLayer) {
            $result = $result && $simpleCacheLayer->has($key);
        }

        return $result;
    }

    /**
     * Get multiple keys at once
     *
     * This will sync back missing keys. Meta keys will provide the original TTL
     *
     * {@inheritdoc}
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable 
    {
        $simpleCacheLayerIndex = 0;
        $simpleCacheLayerValues = [];
        // start with no values and all keys marked as "missing"
        $wrappedValues = [];
        $missing = $keys;
        $missingMetas = array_map(fn($k) => $this->_key_meta($k), $keys);
        // keep track of keys we need to sync back up for each simpleCacheLayer
        $syncback = [];
        // stop when we've found all the values or we're out of simpleCacheLayers
        while (!empty($missing) && $simpleCacheLayerIndex < count($this->simpleCacheLayers)) {
            // array_merge because we want to keep all the values
            $simpleCacheLayerValues[$simpleCacheLayerIndex] = array_filter(
                $this->simpleCacheLayers[$simpleCacheLayerIndex]->getMultiple(array_merge($missing, $missingMetas)),
                fn($v) => null !== $v
            );
            // $simpleCacheLayerValues[$simpleCacheLayerIndex] = array_filter($simpleCacheLayerValues[$simpleCacheLayerIndex],);
            // gather all the values. we'll filter later
            // + operator is needed here to avoid renumbering integer keys (https://www.php.net/manual/en/function.array-merge.php)
            $wrappedValues = $wrappedValues + $simpleCacheLayerValues[$simpleCacheLayerIndex];
            $missing = array_diff($missing, array_keys($simpleCacheLayerValues[$simpleCacheLayerIndex]));
            $missingMetas = array_diff($missingMetas, array_keys($simpleCacheLayerValues[$simpleCacheLayerIndex]));

            $syncback[$simpleCacheLayerIndex] = $missing;
            if (!empty($missing)) {
                $simpleCacheLayerIndex++;
            }
        }

        $ttls = [];
        $values = [];
        foreach ($wrappedValues as $key => $value) {
            if (in_array($key, $keys)) {
                $values[$key] = $value;
                // let's replace NULL with PHP_INT_MAX which should get clamped to the layer's max TTL
                $ttls[$key] = $wrappedValues[$this->_key_meta($key)][1] ?? PHP_INT_MAX;
            }
        }

        // walk back and sync missing values
        while ($simpleCacheLayerIndex > 0) {
            $simpleCacheLayerIndex--;
            // this is best effort by design. no error handling
            // if sets fail then the simpleCacheLayers will not have the
            // keys next time either so it's not a big deal
            $syncvalues = array_filter($values, fn($v, $k) => in_array($k, $syncback[$simpleCacheLayerIndex]), ARRAY_FILTER_USE_BOTH);
            if (!count($syncvalues)) {
                continue;
            }
            foreach ($syncvalues as $key => $value) {
                $this->simpleCacheLayers[$simpleCacheLayerIndex]->set(
                    $key,
                    $value, 
                    $this->_getLayerMaxTTL($simpleCacheLayerIndex, $ttls[$key])
                );
            }
        }

        foreach ($keys as $key) {
            if (!isset($values[$key])) {
                $values[$key] = $default;
            }
        }

        return $values;
    }

    /**
     * Clamp it to max lifetime for the simpleCacheLayer
     *
     * If ttl is DateInterval it gets turned into seconds.
     *
     * TODO: If TTL is 0 the key should be deleted actually
     */
    private function _getLayerMaxTTL(int $simpleCacheLayerIndex, null|int|\DateInterval $ttl = null): ?int
    {
        if (null === $ttl) {
            return $this->maxTTLs[$simpleCacheLayerIndex] ?? null;
        }

        if ($ttl instanceof \DateInterval) {
            $now = new \DateTimeImmutable('now');
            $later = $now->add($ttl);
            $ttl = $later->getTimestamp() - $now->getTimestamp();
        }

        return $ttl = $ttl === 0 ? $this->maxTTLs[$simpleCacheLayerIndex] : min($ttl, $this->maxTTLs[$simpleCacheLayerIndex] ?? PHP_INT_MAX);
    }

    /**
     * Set a key in all kv-layers.
     */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $result = $this->setMultiple([$key => $value], $ttl);

        return $result[$key] ?? false;
    }

    /**
     * Set multiple keys at once in all simpleCacheLayers
     *
     * Starts with the last simpleCacheLayer and walks back up.
     * This method tries to rollback if any simpleCacheLayers fails
     * to set all the keys.
     */
    public function setMultiple(iterable $items, null|int|\DateInterval $ttl = null): bool 
    {
        $simpleCacheLayerIndex = count($this->simpleCacheLayers);
        $results = array_fill_keys(array_keys($items), true); 
        $result = true;
        $keyMetas = array_fill_keys(array_map(fn($k) => $this->_key_meta($k), array_keys($items)), [time(), $ttl]);
        while ($result && $simpleCacheLayerIndex > 0) {
            $simpleCacheLayerIndex--;
            $result = $result && $this->simpleCacheLayers[$simpleCacheLayerIndex]->setMultiple(
                $items + $keyMetas,
                $this->_getLayerMaxTTL($simpleCacheLayerIndex, $ttl)
            );
        }

        return $result;
    }

    /**
     * Delete a key from all layers.
     *
     * Delete is done with best effort and no error handling
     * The result bool is an "and" operation on all simpleCacheLayer results
     * It does not stop on failure which means the result may be false
     * but keys could be gone from all simpleCacheLayers except one in the middle
     * It could also mean it's gone from all the simpleCacheLayers but some did
     * not have the key anymore.
     */
    public function delete(string $key): bool
    {
        $simpleCacheLayerIndex = count($this->simpleCacheLayers);
        $result = true;
        while ($simpleCacheLayerIndex > 0) {
            $simpleCacheLayerIndex--;
            $result = $result && $this->simpleCacheLayers[$simpleCacheLayerIndex]->delete($key);
        }

        return $result;
    }


    /**
     * Delete multiple keys from all the layers
     */
    public function deleteMultiple(iterable $keys): bool 
    {
        $simpleCacheLayerIndex = count($this->simpleCacheLayers);
        $result = true;
        while ($simpleCacheLayerIndex > 0) {
            $simpleCacheLayerIndex--;
            $result = $result && $this->simpleCacheLayers[$simpleCacheLayerIndex]->deleteMultiple($keys);
        }

        return $result;
    }

    /**
     * Clear all layers.
     *
     * First it flushes collection without error handling 
     *
     * Starts the clear from the last layer.
     * If upper layers fail to clear then keys may be returned
     * from those layers.
     */
    public function clear(): bool
    {
        $simpleCacheLayerIndex = count($this->simpleCacheLayers);
        $result = true;
        while ($simpleCacheLayerIndex > 0) {
            $simpleCacheLayerIndex--;
            $result = $result && $this->simpleCacheLayers[$simpleCacheLayerIndex]->clear();
        }

        return $result;
    }

}

