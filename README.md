# What?

This is a PSR-16 implementation of a layered cache that is full of optimism. It assumes the cache is being used for speed and not for important things like throttling, sessions or things like that.

# How?

Layers are also PSR-16 implementations. This means every method of the layered cache is delegated to all the layers depending on the desired outcome:
* `get()` and `getMultiple()` will return a value from the first layer that has it. When a value is found in a "lower" layer it gets synced back up to all the "higher" layers
* `set()` and `setMultiple()` will store a value in all the layers, starting from the "lowest" layer
* a companion key suffixed with `-layer-meta` is created for all stored keys to keep track of creation time and first TTL. This information is used during sync back.
* full of optimism: when things fail there is no rollback logic. If you set a key and it fails in a mid layer it just stops. This should be fine because `set()` starts with the lowest layer and the next `get()` will trigger a sync back

# Why?

In a distributed context it can become a bottleneck to go out on the network to read a centralized cache all the time. Storing some information locally (in APC or even filesystem) with a short TTL should keep things going almost as fast as having everything cached only locally but still keep the control that a centralized cache like redis/memcache/couchbase gives you.
