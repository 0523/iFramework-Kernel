<?php

namespace Illuminate\Cache;

use Closure;
use Exception;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\ConnectionInterface;

class ConfigStore implements Store
{
    use RetrievesMultipleKeys;

    /**
     * The database connection instance.
     *
     * @var \Illuminate\Database\ConnectionInterface
     */
    protected $connection;

    /**
     * The name of the cache table.
     *
     * @var string
     */
    protected $table;

    /**
     * Create a new database store.
     *
     * @param  \Illuminate\Database\ConnectionInterface $connection
     * @param  string $table
     * @return void
     */
    public function __construct(ConnectionInterface $connection, $table)
    {
        $this->table = $table;

        $this->connection = $connection;
    }

    /**
     * Retrieve an item from the cache by key.
     *
     * @param  string|array $key
     * @return mixed
     */
    public function get($key)
    {
        $cache = $this->table()->where('key', '=', $key)->first();

        // If we have a cache record we will check the expiration time against current
        // time on the system and see if the record has expired. If it has, we will
        // remove the records from the database table so it isn't returned again.
        if (! is_null($cache)) {
            if (is_array($cache)) {
                $cache = (object)$cache;
            }

            /**
             * 加密方法由 encrypter 改为 json
             */
            return json_decode($cache->value, true);
        }
    }

    /**
     * Store an item in the cache for a given number of minutes.
     *
     * @param  string $key
     * @param  mixed $value
     * @param  int $minutes
     * @return void
     */
    public function put($key, $value, $minutes = 5256000)
    {
        // All of the cached values in the database are encrypted in case this is used
        // as a session data store by the consumer. We'll also calculate the expire
        // time and place that on the table so we will check it on our retrieval.
        /**
         * 加密方法由 encrypter 改为 json
         */
        $value = json_encode($value);

        /**
         * 这种写法导致事物报错退出
         */
        // try {
        //     $this->table()->insert(compact('key', 'value'));
        // } catch (Exception $e) {
        //     $this->table()->where('key', '=', $key)->update(compact('value'));
        // }

        $data = $this->table()->where('key', '=', $key)->first();
        if (! $data) {
            $this->table()->insert(compact('key', 'value'));
        } elseif ($data->value != $value) {
            $this->table()->where('key', '=', $key)->update(compact('value'));
        }
    }

    /**
     * Increment the value of an item in the cache.
     *
     * @param  string $key
     * @param  mixed $value
     * @return int|bool
     */
    public function increment($key, $value = 1)
    {
        return $this->incrementOrDecrement($key, $value, function ($current, $value) {
            return $current + $value;
        });
    }

    /**
     * Increment the value of an item in the cache.
     *
     * @param  string $key
     * @param  mixed $value
     * @return int|bool
     */
    public function decrement($key, $value = 1)
    {
        return $this->incrementOrDecrement($key, $value, function ($current, $value) {
            return $current - $value;
        });
    }

    /**
     * Increment or decrement an item in the cache.
     *
     * @param  string $key
     * @param  mixed $value
     * @param  \Closure $callback
     * @return int|bool
     */
    protected function incrementOrDecrement($key, $value, Closure $callback)
    {
        return $this->connection->transaction(function () use ($key, $value, $callback) {
            $cache = $this->table()->where('key', $key)->lockForUpdate()->first();

            if (is_null($cache)) {
                return false;
            }

            if (is_array($cache)) {
                $cache = (object)$cache;
            }

            /**
             * 加密方法由 encrypter 改为 json
             */
            $current = json_decode($cache->value);
            $new = $callback($current, $value);

            if (! is_numeric($current)) {
                return false;
            }

            /**
             * 加密方法由 encrypter 改为 json
             */
            $this->table()->where('key', $key)->update([
                'value' => json_encode($new),
            ]);

            return $new;
        });
    }

    /**
     * Get the current system time.
     *
     * @return int
     */
    protected function getTime()
    {
        return time();
    }

    /**
     * Store an item in the cache indefinitely.
     *
     * @param  string $key
     * @param  mixed $value
     * @return void
     */
    public function forever($key, $value)
    {
        $this->put($key, $value, 5256000);
    }

    /**
     * Remove an item from the cache.
     *
     * @param  string $key
     * @return bool
     */
    public function forget($key)
    {
        $this->table()->where('key', '=', $key)->delete();

        return true;
    }

    /**
     * Remove all items from the cache.
     *
     * @return void
     */
    public function flush()
    {
        $this->table()->delete();
    }

    /**
     * Get a query builder for the cache table.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function table()
    {
        return $this->connection->table($this->table);
    }

    /**
     * Get the underlying database connection.
     *
     * @return \Illuminate\Database\ConnectionInterface
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Get the cache key prefix.
     *
     * @return string
     */
    public function getPrefix()
    {
        return null;
    }
}
