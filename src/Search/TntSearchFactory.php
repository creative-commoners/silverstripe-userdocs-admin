<?php

namespace SilverStripe\UserDocs\Search;

use InvalidArgumentException;
use Predis\Client as PredisClient;
use Predis\Connection\ConnectionInterface;
use Predis\Connection\NodeConnectionInterface;
use SilverStripe\Core\Injector\Factory;
use SilverStripe\Core\Path;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Filesystem\Path as SymfonyPath;
use TeamTNT\TNTSearch\Engines\MysqlEngine;
use TeamTNT\TNTSearch\Engines\RedisEngine;
use TeamTNT\TNTSearch\Engines\SqliteEngine;
use TeamTNT\TNTSearch\TNTSearch;

/**
 * Creates a TNTSearch instance with appropriate configuration
 *
 * If the config has no "driver" key, it is set to "filesystem". If the config has no "storage" key and the engine is
 * SqliteEngine or a subclass of it, it is set to "userdocs-admin/indexes/". Relative storage paths are relative to
 * the temporary folder.
 *
 * In addition to the TNTSearch configuration, the config can have these keys:
 * - redis_dsn: A DSN for RedisEngine, in any format Symfony's RedisAdapter supports.
 *   When set, the redis_options key is used. The redis_scheme, redis_host, and redis_port keys are set from the DSN
 *   if it is for a single Redis server over TCP or TLS, and removed if not. The other redis_* keys are removed.
 * - mysql_dsn: A DSN for MysqlEngine in the format mysql://user:password@host:port/database.
 *   When set, it replaces the host and database keys, and the username and password keys if the DSN has them.
 */
class TntSearchFactory implements Factory
{
    private const array MYSQL_DSN_SCHEMES = ['mysql', 'mariadb'];

    private const string DEFAULT_STORAGE = 'userdocs-admin/indexes/';

    /**
     * Create a TNTSearch object with appropriate configuration
     *
     * @param array $params An array of TNTSearch configuration in the "config" key, if any config is set
     */
    public function create(string $service, array $params = []): TNTSearch
    {
        $config = $this->applyDefaults($params['config'] ?? []);
        $redisDsn = $this->getDsn($config, 'redis_dsn', RedisEngine::class);
        $mysqlDsn = $this->getDsn($config, 'mysql_dsn', MysqlEngine::class);
        unset($config['redis_dsn'], $config['mysql_dsn']);
        $redisConnection = null;
        if ($redisDsn !== null) {
            $redisConnection = $this->createRedisConnection($redisDsn);
            $config = $this->applyRedisConnection($config, $redisConnection);
        }
        if ($mysqlDsn !== null) {
            $config = array_merge($config, $this->parseMysqlDsn($mysqlDsn));
        }
        $tnt = new TNTSearch();
        $tnt->loadConfig($config);
        if ($redisConnection !== null) {
            $this->replaceRedisClient($tnt, $redisConnection);
        }
        return $tnt;
    }

    /**
     * Set the default engine, driver, and storage, and make a relative storage path relative to the temporary folder.
     */
    private function applyDefaults(array $config): array
    {
        // Use CacheEngine as a default if none is specified
        if (empty($config['engine'])) {
            $config['engine'] = CacheEngine::class;
        }
        // The engines create a connector from the driver when they create an index, even if it isn't used
        $config['driver'] ??= 'filesystem';
        // SqliteEngine reads the storage path when it loads its config.
        if (is_a($config['engine'], SqliteEngine::class, true)) {
            $config['storage'] ??= TntSearchFactory::DEFAULT_STORAGE;
        }
        // Relative storage paths are relative to the cache dir
        if (!empty($config['storage']) && SymfonyPath::isRelative($config['storage'])) {
            // Use framework's Path class here to avoid path traversal
            $config['storage'] = Path::join(TEMP_PATH, $config['storage']);
        }
        return $config;
    }

    /**
     * Replace the Redis connection keys in the config with the keys for the connection made from the DSN.
     */
    private function applyRedisConnection(array $config, ?ConnectionInterface $connection): array
    {
        // The DSN replaces these keys. Remove them so the config doesn't have values that aren't used.
        unset($config['redis_scheme'], $config['redis_password'], $config['redis_ssl_options']);
        $nodeConfig = $connection === null ? null : $this->getRedisNodeConfig($connection);
        // RedisEngine needs a host and port to load its config. Use placeholders if the DSN has no single host.
        // RedisEngine's own client never connects, because the client made from the DSN replaces it.
        return array_merge($config, $nodeConfig ?? ['redis_host' => '127.0.0.1', 'redis_port' => 6379]);
    }

    /**
     * Replace the Redis client that RedisEngine made with a client for the connection made from the DSN.
     */
    private function replaceRedisClient(TNTSearch $tnt, ConnectionInterface $connection): void
    {
        $tnt->engine->redis = new PredisClient($connection, $tnt->config['redis_options'] ?? null);
        if ($this->getRedisNodeConfig($connection) === null) {
            // Remove the placeholders, so nothing reads an incorrect host and port.
            unset($tnt->config['redis_host'], $tnt->config['redis_port']);
            unset($tnt->engine->config['redis_host'], $tnt->engine->config['redis_port']);
        }
    }

    /**
     * Create a Predis connection from a DSN in any format Symfony's RedisAdapter supports.
     * Returns null if Predis isn't installed, so that RedisEngine throws the error that
     * SearchIndex::isSupported() checks for.
     */
    private function createRedisConnection(string $dsn): ?ConnectionInterface
    {
        if (!class_exists(PredisClient::class)) {
            return null;
        }
        // RedisEngine::$redis must be a Predis client, so don't let Symfony select the redis extension.
        // Symfony's client does not throw exceptions for Redis errors, and it does not use redis_options.
        // Only its connection is used, in a client that has the same options as the one RedisEngine makes.
        return RedisAdapter::createConnection($dsn, ['class' => PredisClient::class])->getConnection();
    }

    /**
     * Get the RedisEngine config keys for a connection to a single Redis server over TCP or TLS.
     * Returns null for other connections, such as clusters and unix sockets, which have no single host and port.
     */
    private function getRedisNodeConfig(ConnectionInterface $connection): ?array
    {
        if (!$connection instanceof NodeConnectionInterface) {
            return null;
        }
        $parameters = $connection->getParameters();
        if (!in_array($parameters->scheme, ['tcp', 'tls'], true)) {
            return null;
        }
        return [
            'redis_scheme' => $parameters->scheme,
            'redis_host' => $parameters->host,
            'redis_port' => $parameters->port,
        ];
    }

    /**
     * Get a DSN from the config, and validate that it applies to the configured engine.
     * Returns null if the DSN is not set.
     */
    private function getDsn(array $config, string $key, string $engineClass): ?string
    {
        $dsn = $config[$key] ?? null;
        if ($dsn === null) {
            return null;
        }
        if (!is_a($config['engine'], $engineClass, true)) {
            throw new InvalidArgumentException(
                "The $key config value cannot be used with the {$config['engine']} engine."
            );
        }
        return $dsn;
    }

    /**
     * Parse a MySQL DSN into the config keys that MysqlEngine uses.
     * Error messages do not include the DSN, because it can contain a password.
     */
    private function parseMysqlDsn(string $dsn): array
    {
        $parts = parse_url($dsn);
        if ($parts === false) {
            throw new InvalidArgumentException('The mysql_dsn config value is not a valid DSN.');
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, TntSearchFactory::MYSQL_DSN_SCHEMES, true)) {
            throw new InvalidArgumentException(sprintf(
                'The mysql_dsn config value must use one of these schemes: %s.',
                implode(', ', TntSearchFactory::MYSQL_DSN_SCHEMES)
            ));
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException(
                'The mysql_dsn config value cannot have a query string or fragment. MysqlEngine cannot use them.'
            );
        }
        $host = $this->validateMysqlHost($parts['host'] ?? '');
        $database = rawurldecode(substr($parts['path'] ?? '', 1));
        if ($database === '' || preg_match('#[;=/\\\\\x00-\x1F\x7F]#', $database)) {
            throw new InvalidArgumentException('The mysql_dsn config value must have a valid database name.');
        }
        if (isset($parts['port'])) {
            if ($parts['port'] < 1 || $parts['port'] > 65535) {
                throw new InvalidArgumentException('The mysql_dsn config value has an invalid port.');
            }
            // MysqlEngine::selectIndex() makes its PDO DSN as "mysql:dbname=<database>;host=<host>"
            // and does not use a port. Adding ";port=<port>" to the host puts the port in that PDO DSN.
            // This is safe only because the host is validated and cannot contain ";" or "=".
            $host .= ';port=' . $parts['port'];
        }
        $result = [
            'host' => $host,
            'database' => $database,
        ];
        // Keep the username and password from the config if the DSN doesn't have them
        if (isset($parts['user'])) {
            $result['username'] = rawurldecode($parts['user']);
        }
        if (isset($parts['pass'])) {
            $result['password'] = rawurldecode($parts['pass']);
        }
        return $result;
    }

    /**
     * Validate a host from a MySQL DSN so it is safe to add to a PDO DSN.
     * Returns IPv6 addresses without the square brackets.
     */
    private function validateMysqlHost(string $host): string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $ipv6 = substr($host, 1, -1);
            if (filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                return $ipv6;
            }
        } elseif ($host !== '' && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false) {
            return $host;
        }
        throw new InvalidArgumentException('The mysql_dsn config value must have a valid host.');
    }
}
