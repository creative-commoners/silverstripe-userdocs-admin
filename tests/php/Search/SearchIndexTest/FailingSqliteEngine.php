<?php

namespace SilverStripe\UserDocs\Tests\Search\SearchIndexTest;

use RuntimeException;
use SilverStripe\Dev\TestOnly;
use TeamTNT\TNTSearch\Engines\SqliteEngine;
use TeamTNT\TNTSearch\Support\Collection;

/**
 * A SqliteEngine that fails after it indexes the first document, so the index is only partly built.
 */
class FailingSqliteEngine extends SqliteEngine implements TestOnly
{
    public function processDocument(Collection $row)
    {
        parent::processDocument($row);
        throw new RuntimeException('Indexing failed');
    }
}
