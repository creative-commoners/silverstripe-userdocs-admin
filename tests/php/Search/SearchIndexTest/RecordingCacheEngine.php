<?php

namespace SilverStripe\UserDocs\Tests\Search\SearchIndexTest;

use SilverStripe\Dev\TestOnly;
use SilverStripe\UserDocs\Search\CacheEngine;

/**
 * A CacheEngine that records the inserts and transactions made after the last index was created.
 */
class RecordingCacheEngine extends CacheEngine implements TestOnly
{
    public static array $calls = [];

    public function createIndex(string $indexName)
    {
        $result = parent::createIndex($indexName);
        // Leave out the calls from creating the index, which are internal to CacheEngine
        RecordingCacheEngine::$calls = ['create'];
        return $result;
    }

    public function insert(array $document)
    {
        RecordingCacheEngine::$calls[] = 'insert';
        parent::insert($document);
    }

    public function indexBeginTransaction(): void
    {
        RecordingCacheEngine::$calls[] = 'begin';
        parent::indexBeginTransaction();
    }

    public function indexEndTransaction(): void
    {
        RecordingCacheEngine::$calls[] = 'end';
        parent::indexEndTransaction();
    }
}
