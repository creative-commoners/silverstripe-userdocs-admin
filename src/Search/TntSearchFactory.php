<?php

namespace SilverStripe\UserDocs\Search;

use SilverStripe\Core\Injector\Factory;
use SilverStripe\Core\Path;
use Symfony\Component\Filesystem\Path as SymfonyPath;
use TeamTNT\TNTSearch\TNTSearch;

/**
 * Creates a TNTSearch instance with appropriate configuration
 */
class TntSearchFactory implements Factory
{
    /**
     * Create a TNTSearch object with appropriate configuration
     *
     * @param array $params Must have a "config" key with an array of TnTsearch configuration
     */
    public function create(string $service, array $params = []): TNTSearch
    {
        $config = $params['config'];
        // Relative storage paths are relative to the cache dir
        if (!empty($config['storage']) && SymfonyPath::isRelative($config['storage'])) {
            // Use framework's Path class here to avoid path traversal
            $config['storage'] = Path::join(TEMP_PATH, $config['storage']);
        }
        // @TODO helpers like supporting DSN for redis and mysql connections
        $tnt = new TNTSearch();
        $tnt->loadConfig($config);
        return $tnt;
    }
}
