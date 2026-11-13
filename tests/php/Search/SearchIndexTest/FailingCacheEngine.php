<?php

namespace SilverStripe\UserDocs\Tests\Search\SearchIndexTest;

use RuntimeException;
use SilverStripe\Dev\TestOnly;
use SilverStripe\UserDocs\Search\CacheEngine;
use TeamTNT\TNTSearch\Support\Collection;

/**
 * A CacheEngine that fails after it indexes the first document, so the index is only partly built.
 */
class FailingCacheEngine extends CacheEngine implements TestOnly
{
    public function processDocument(Collection $row)
    {
        parent::processDocument($row);
        throw new RuntimeException('Indexing failed');
    }
}
