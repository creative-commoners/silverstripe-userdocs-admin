<?php

namespace SilverStripe\UserDocs\Tests\Search;

use InvalidArgumentException;
use LogicException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Path;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\UserDocs\Search\CacheEngine;
use SplFileInfo;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Filesystem\Filesystem;
use TeamTNT\TNTSearch\Exceptions\IndexNotFoundException;
use TeamTNT\TNTSearch\FileReaders\TextFileReader;
use TeamTNT\TNTSearch\Stemmer\NoStemmer;
use TeamTNT\TNTSearch\Stemmer\PorterStemmer;
use TeamTNT\TNTSearch\Support\Collection;
use TeamTNT\TNTSearch\TNTSearch;
use TeamTNT\TNTSearch\Tokenizer\Tokenizer;

class CacheEngineTest extends SapphireTest
{
    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempPath = Path::join(TEMP_PATH, 'userdocs-cache-engine-test');
        Injector::inst()->registerService(new Psr16Cache(new ArrayAdapter()), CacheEngine::CACHE_SERVICE);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tempPath);
        parent::tearDown();
    }

    private function createTntSearch(array $config = []): TNTSearch
    {
        $tnt = new TNTSearch();
        $tnt->loadConfig(['engine' => CacheEngine::class] + $config);
        return $tnt;
    }

    /**
     * Build an index with the given text, keyed by document ID.
     */
    private function buildIndex(TNTSearch $tnt, array $texts, string $indexName = 'test'): CacheEngine
    {
        $indexer = $tnt->createIndex($indexName);
        foreach ($texts as $id => $text) {
            $indexer->insert(['id' => $id, 'text' => $text]);
        }
        /** @var CacheEngine $engine */
        $engine = $tnt->engine;
        return $engine;
    }

    /**
     * Select the index with a new TNTSearch instance, so only the index in the cache is used.
     */
    private function selectSavedIndex(string $indexName = 'test'): CacheEngine
    {
        $tnt = $this->createTntSearch();
        $tnt->selectIndex($indexName);
        /** @var CacheEngine $engine */
        $engine = $tnt->engine;
        return $engine;
    }

    private function getFixturePath(): string
    {
        return Path::join(__DIR__, 'CacheEngineTest', 'files');
    }

    /**
     * Get the paths of the indexed files relative to the fixture directory, in the order of their IDs.
     */
    private function getIndexedFiles(CacheEngine $engine): array
    {
        $ids = range(1, $engine->totalDocumentsInCollection());
        $files = [];
        foreach ($engine->filesystemMapIdsToPaths(new Collection($ids)) as $file) {
            $relativePath = substr($file['path'], strlen($this->getFixturePath()) + 1);
            $files[] = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
        }
        return $files;
    }

    /**
     * Register a cache that reads from the real cache but fails to save.
     */
    private function registerFailingCache(): void
    {
        $savedCache = Injector::inst()->get(CacheEngine::CACHE_SERVICE);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(fn (string $key) => $savedCache->get($key));
        $cache->method('set')->willReturn(false);
        Injector::inst()->registerService($cache, CacheEngine::CACHE_SERVICE);
    }

    public function testCreateIndex(): void
    {
        $engine = $this->createTntSearch(['stemmer' => PorterStemmer::class])->createIndex('test');

        $this->assertSame(0, $engine->totalDocumentsInCollection());
        $this->assertSame(PorterStemmer::class, $engine->getValueFromInfoTable('stemmer'));
        $this->assertSame(Tokenizer::class, $engine->getValueFromInfoTable('tokenizer'));
    }

    public function testCreateIndexSavesEmptyIndex(): void
    {
        $this->buildIndex($this->createTntSearch(), [1 => 'apple']);

        $this->createTntSearch()->createIndex('test');

        $engine = $this->selectSavedIndex();
        $this->assertSame(0, $engine->totalDocumentsInCollection());
        $this->assertNull($engine->getWordFromWordList('apple'));
    }

    public function testCreateIndexInTransaction(): void
    {
        $engine = $this->buildIndex($this->createTntSearch(), [], 'one');
        $engine->indexBeginTransaction();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The index cannot be changed while a transaction is open');
        $engine->createIndex('two');
    }

    public function testSelectIndex(): void
    {
        $this->buildIndex($this->createTntSearch(), [1 => 'apple banana', 2 => 'banana', 3 => 'apple apple cherry']);
        $tnt = $this->createTntSearch();

        $tnt->selectIndex('test');

        $this->assertSame([3, 1], $tnt->search('apple')['ids']);
        $this->assertSame(NoStemmer::class, $tnt->engine->getValueFromInfoTable('stemmer'));
    }

    public function testSelectIndexMissing(): void
    {
        $this->expectException(IndexNotFoundException::class);
        $this->createTntSearch()->selectIndex('missing');
    }

    public function testSelectIndexInTransaction(): void
    {
        $tnt = $this->createTntSearch();
        $this->buildIndex($tnt, [], 'one');
        $engine = $this->buildIndex($tnt, [1 => 'apple'], 'two');
        $engine->indexBeginTransaction();
        // Selecting the index that the transaction is for is allowed
        $engine->selectIndex('two');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The index cannot be changed while a transaction is open');
        $engine->selectIndex('one');
    }

    public function testIndexBeginTransaction(): void
    {
        $engine = $this->buildIndex($this->createTntSearch(), [1 => 'apple']);

        $engine->indexBeginTransaction();
        $engine->insert(['id' => 2, 'text' => 'banana']);

        $this->assertNotNull($engine->getWordFromWordList('banana'));
        $savedEngine = $this->selectSavedIndex();
        $this->assertSame(1, $savedEngine->totalDocumentsInCollection());
        $this->assertNull($savedEngine->getWordFromWordList('banana'));
    }

    public function testIndexBeginTransactionAlreadyOpen(): void
    {
        $engine = $this->buildIndex($this->createTntSearch(), []);
        $engine->indexBeginTransaction();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A transaction is already open');
        $engine->indexBeginTransaction();
    }

    public function testIndexEndTransaction(): void
    {
        $engine = $this->buildIndex($this->createTntSearch(), [1 => 'apple']);
        $engine->indexBeginTransaction();
        $engine->insert(['id' => 2, 'text' => 'banana']);
        $engine->delete(1);
        $savedCache = Injector::inst()->get(CacheEngine::CACHE_SERVICE);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(fn (string $key) => $savedCache->get($key));
        $cache->expects($this->once())->method('set')->willReturnCallback(
            fn (string $key, mixed $value) => $savedCache->set($key, $value)
        );
        Injector::inst()->registerService($cache, CacheEngine::CACHE_SERVICE);

        $engine->indexEndTransaction();

        $savedEngine = $this->selectSavedIndex();
        $this->assertSame(1, $savedEngine->totalDocumentsInCollection());
        $this->assertNull($savedEngine->getWordFromWordList('apple'));
        $this->assertNotNull($savedEngine->getWordFromWordList('banana'));
    }

    public function testIndexEndTransactionUnchanged(): void
    {
        $engine = $this->buildIndex($this->createTntSearch(), [1 => 'apple']);
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->never())->method('set');
        Injector::inst()->registerService($cache, CacheEngine::CACHE_SERVICE);
        $engine->indexBeginTransaction();

        $engine->indexEndTransaction();
    }

    public function testIndexEndTransactionNotOpen(): void
    {
        $engine = $this->buildIndex($this->createTntSearch(), []);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No transaction is open');
        $engine->indexEndTransaction();
    }

    public function testIndexEndTransactionCacheFailure(): void
    {
        $engine = $this->buildIndex($this->createTntSearch(), [1 => 'apple']);
        $engine->indexBeginTransaction();
        $engine->insert(['id' => 2, 'text' => 'banana']);
        $this->registerFailingCache();
        try {
            $engine->indexEndTransaction();
            $this->fail('indexEndTransaction() should throw an exception when the cache fails');
        } catch (RuntimeException $exception) {
            $this->assertSame('Could not save the search index test to the cache', $exception->getMessage());
        }

        // The transaction is still open, so it can be rolled back
        $engine->indexRollBackTransaction();

        $this->assertNull($engine->getWordFromWordList('banana'));
    }

    public function testIndexRollBackTransaction(): void
    {
        $engine = $this->buildIndex($this->createTntSearch(), [1 => 'apple']);
        $engine->indexBeginTransaction();
        $engine->insert(['id' => 2, 'text' => 'banana']);
        $engine->delete(1);

        $engine->indexRollBackTransaction();
        // A later change must not save the discarded changes
        $engine->updateInfoTable('custom', 'value');

        $this->assertSame(1, $engine->totalDocumentsInCollection());
        $this->assertNotNull($engine->getWordFromWordList('apple'));
        $this->assertNull($engine->getWordFromWordList('banana'));
        $savedEngine = $this->selectSavedIndex();
        $this->assertSame('value', $savedEngine->getValueFromInfoTable('custom'));
        $this->assertNull($savedEngine->getWordFromWordList('banana'));
    }

    public function testIndexRollBackTransactionNotOpen(): void
    {
        $engine = $this->buildIndex($this->createTntSearch(), []);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No transaction is open');
        $engine->indexRollBackTransaction();
    }

    public function testFlushIndex(): void
    {
        $engine = $this->buildIndex($this->createTntSearch(), [1 => 'apple']);

        $engine->flushIndex('test');

        $this->expectException(IndexNotFoundException::class);
        $engine->selectIndex('test');
    }

    public function testFlushIndexOtherIndex(): void
    {
        $tnt = $this->createTntSearch();
        $this->buildIndex($tnt, [1 => 'apple'], 'one');
        $engine = $this->buildIndex($tnt, [1 => 'banana'], 'two');

        $engine->flushIndex('one');

        $this->assertNotNull($engine->getWordFromWordList('banana'));
        $this->assertNotNull($this->selectSavedIndex('two')->getWordFromWordList('banana'));
        $this->expectException(IndexNotFoundException::class);
        $this->selectSavedIndex('one');
    }

    public function testUpdateInfoTable(): void
    {
        $engine = $this->buildIndex($this->createTntSearch(), []);

        $engine->updateInfoTable('custom', 'value');

        $this->assertSame('value', $this->selectSavedIndex()->getValueFromInfoTable('custom'));
        $this->assertNull($engine->getValueFromInfoTable('missing'));
    }

    public function testRun(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetch')->willReturnOnConsecutiveCalls(
            ['id' => 1, 'text' => 'apple'],
            ['id' => 2, 'text' => 'apple banana'],
            false
        );
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('query')->with('SELECT id, text FROM docs')->willReturn($statement);
        $tnt = $this->createTntSearch();
        $indexer = $tnt->createIndex('test', true);
        $tnt->engine->setDatabaseHandle($pdo);
        $indexer->query('SELECT id, text FROM docs');

        $indexer->run();

        $engine = $this->selectSavedIndex();
        $this->assertSame(2, $engine->totalDocumentsInCollection());
        $this->assertSame(2, $engine->getWordFromWordList('apple')['num_docs']);
        $this->assertSame(1, $engine->getWordFromWordList('banana')['num_docs']);
    }

    public function testRunFailure(): void
    {
        $rows = [['id' => 1, 'text' => 'apple']];
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetch')->willReturnCallback(function () use (&$rows) {
            if (empty($rows)) {
                throw new RuntimeException('Query failed');
            }
            return array_shift($rows);
        });
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn($statement);
        $tnt = $this->createTntSearch();
        $indexer = $tnt->createIndex('test', true);
        $tnt->engine->setDatabaseHandle($pdo);
        $indexer->query('SELECT id, text FROM docs');
        try {
            $indexer->run();
            $this->fail('run() should rethrow the query error');
        } catch (RuntimeException $exception) {
            $this->assertSame('Query failed', $exception->getMessage());
        }

        // The rows indexed before the error are discarded
        $this->assertNull($tnt->engine->getWordFromWordList('apple'));
        $this->assertNull($this->selectSavedIndex()->getWordFromWordList('apple'));
    }

    public function testRunFilesystemDriver(): void
    {
        $tnt = $this->createTntSearch([
            'driver' => 'filesystem',
            'location' => $this->getFixturePath(),
            'extension' => 'md',
        ]);
        $indexer = $tnt->createIndex('test', true);

        $indexer->run();

        $engine = $this->selectSavedIndex();
        $this->assertSame('filesystem', $engine->getValueFromInfoTable('driver'));
        $this->assertSame(['banana.md'], $this->getIndexedFiles($engine));
    }

    public static function provideReadDocumentsFromFileSystem(): array
    {
        return [
            'extension string' => [
                'extension' => 'txt',
                'exclude' => [],
                'expected' => ['apple.txt', 'excluded.txt', 'nested/cherry.txt'],
            ],
            'extension array' => [
                'extension' => ['md'],
                'exclude' => [],
                'expected' => ['banana.md'],
            ],
            'extension callable' => [
                'extension' => fn (SplFileInfo $file) => str_starts_with($file->getFilename(), 'ch'),
                'exclude' => [],
                'expected' => ['nested/cherry.txt'],
            ],
            'excluded files' => [
                'extension' => 'txt',
                'exclude' => ['excluded.txt', 'nested/cherry.txt'],
                'expected' => ['apple.txt'],
            ],
        ];
    }

    #[DataProvider('provideReadDocumentsFromFileSystem')]
    public function testReadDocumentsFromFileSystem(
        string|array|callable $extension,
        array $exclude,
        array $expected
    ): void {
        $tnt = $this->createTntSearch([
            'location' => $this->getFixturePath(),
            'extension' => $extension,
            'exclude' => $exclude,
        ]);
        $tnt->createIndex('test', true);

        $tnt->engine->readDocumentsFromFileSystem();

        $engine = $this->selectSavedIndex();
        $files = $this->getIndexedFiles($engine);
        $this->assertEqualsCanonicalizing($expected, $files);
        $this->assertSame(count($expected), $engine->totalDocumentsInCollection());
        $this->assertSame('filesystem', $engine->getValueFromInfoTable('driver'));
    }

    public function testReadDocumentsFromFileSystemIndexesContent(): void
    {
        $tnt = $this->createTntSearch(['location' => $this->getFixturePath(), 'extension' => 'txt']);
        $tnt->createIndex('test', true);

        $tnt->engine->readDocumentsFromFileSystem();

        $engine = $this->selectSavedIndex();
        $docs = $engine->getAllDocumentsForStrictKeyword($engine->getWordlistByKeyword('cherry'), true);
        $paths = $engine->filesystemMapIdsToPaths(new Collection(array_column($docs->toArray(), 'doc_id')));
        $this->assertSame([Path::join($this->getFixturePath(), 'nested', 'cherry.txt')], array_column(
            $paths->toArray(),
            'path'
        ));
    }

    public function testReadDocumentsFromFileSystemFileReaderCallbacks(): void
    {
        $fileReader = new TextFileReader();
        $fileReader->fileFilterCallback = fn ($value, $key) => $key !== 'name';
        $fileReader->fileMapCallback = fn ($value, $key) => $key === 'content' ? $value . ' mapped' : $value;
        $tnt = $this->createTntSearch(['location' => $this->getFixturePath(), 'extension' => 'md']);
        $tnt->createIndex('test', true);
        $tnt->engine->filereader = $fileReader;

        $tnt->engine->readDocumentsFromFileSystem();

        $engine = $this->selectSavedIndex();
        $this->assertNotNull($engine->getWordFromWordList('mapped'));
        // The file name is filtered out, so its extension isn't indexed
        $this->assertNull($engine->getWordFromWordList('md'));
    }

    public static function provideReadDocumentsFromFileSystemInvalidLocation(): array
    {
        return [
            'empty' => [
                'location' => '',
            ],
            'missing directory' => [
                'location' => 'userdocs-missing-directory',
            ],
            'file' => [
                'location' => 'apple.txt',
            ],
            'ftp stream wrapper' => [
                'location' => 'ftp://example.com/docs',
            ],
            'file stream wrapper' => [
                'location' => 'file://{fixtures}',
            ],
        ];
    }

    #[DataProvider('provideReadDocumentsFromFileSystemInvalidLocation')]
    public function testReadDocumentsFromFileSystemInvalidLocation(string $location): void
    {
        if ($location === 'apple.txt') {
            $location = Path::join($this->getFixturePath(), $location);
        }
        $location = str_replace('{fixtures}', $this->getFixturePath(), $location);
        $tnt = $this->createTntSearch(['location' => $location, 'extension' => 'txt']);
        $tnt->createIndex('test', true);

        $this->expectException(InvalidArgumentException::class);
        $tnt->engine->readDocumentsFromFileSystem();
    }

    public function testFilesystemMapIdsToPathsUnknownId(): void
    {
        $tnt = $this->createTntSearch(['location' => $this->getFixturePath(), 'extension' => 'md']);
        $tnt->createIndex('test', true);
        $tnt->engine->readDocumentsFromFileSystem();

        $paths = $tnt->engine->filesystemMapIdsToPaths(new Collection([99]));

        $this->assertSame([null], $paths->toArray());
    }

    public static function provideBuildDictionary(): array
    {
        return [
            'all terms with hits' => [
                'count' => -1,
                'hits' => true,
                'docs' => false,
                'expected' => "apple\t3\nbanana\t2\ncherry\t1\n",
            ],
            'limited count' => [
                'count' => 2,
                'hits' => true,
                'docs' => false,
                'expected' => "apple\t3\nbanana\t2",
            ],
            'docs without hits' => [
                'count' => -1,
                'hits' => false,
                'docs' => true,
                'expected' => "apple\t2\nbanana\t2\ncherry\t1\n",
            ],
        ];
    }

    #[DataProvider('provideBuildDictionary')]
    public function testBuildDictionary(int $count, bool $hits, bool $docs, string $expected): void
    {
        $engine = $this->buildIndex($this->createTntSearch(), [1 => 'cherry banana apple apple', 2 => 'banana apple']);
        (new Filesystem())->mkdir($this->tempPath);
        $filename = Path::join($this->tempPath, 'dictionary.txt');

        $engine->buildDictionary($filename, $count, $hits, $docs);

        $this->assertSame($expected, file_get_contents($filename));
    }

    public function testBuildDictionaryWriteFailure(): void
    {
        $engine = $this->buildIndex($this->createTntSearch(), [1 => 'apple']);
        $filename = Path::join($this->tempPath, 'missing-directory', 'dictionary.txt');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not write the dictionary');
        @$engine->buildDictionary($filename);
    }

    public static function provideProcessDocument(): array
    {
        return [
            'exclude primary key' => [
                'includePrimaryKey' => false,
                'expected' => false,
            ],
            'include primary key' => [
                'includePrimaryKey' => true,
                'expected' => true,
            ],
        ];
    }

    #[DataProvider('provideProcessDocument')]
    public function testProcessDocument(bool $includePrimaryKey, bool $expected): void
    {
        $tnt = $this->createTntSearch();
        $indexer = $tnt->createIndex('test');
        if ($includePrimaryKey) {
            $indexer->includePrimaryKey();
        }

        $tnt->engine->processDocument(new Collection(['id' => 42, 'text' => 'Apple apple', 'other' => ' ']));

        $this->assertSame($expected, $tnt->engine->getWordFromWordList('42') !== null);
        $this->assertSame(2, $tnt->engine->getWordFromWordList('apple')['num_hits']);
    }

    public function testInsert(): void
    {
        $this->buildIndex($this->createTntSearch(), [1 => 'apple apple banana', 2 => 'apple']);

        $engine = $this->selectSavedIndex();
        $this->assertSame(2, $engine->totalDocumentsInCollection());
        $this->assertSame(
            ['id' => 'apple', 'term' => 'apple', 'num_hits' => 3, 'num_docs' => 2],
            $engine->getWordFromWordList('apple')
        );
        $this->assertSame(
            ['id' => 'banana', 'term' => 'banana', 'num_hits' => 1, 'num_docs' => 1],
            $engine->getWordFromWordList('banana')
        );
    }

    public function testInsertCacheFailure(): void
    {
        $engine = $this->buildIndex($this->createTntSearch(), [1 => 'apple']);
        $this->registerFailingCache();
        try {
            $engine->insert(['id' => 2, 'text' => 'banana']);
            $this->fail('insert() should throw an exception when the cache fails');
        } catch (RuntimeException $exception) {
            $this->assertSame('Could not save the search index test to the cache', $exception->getMessage());
        }

        $this->assertSame(1, $engine->totalDocumentsInCollection());
        $this->assertNull($engine->getWordFromWordList('banana'));
        // No transaction is left open, so a new one can start
        $engine->indexBeginTransaction();
    }

    public function testUpdate(): void
    {
        $engine = $this->buildIndex($this->createTntSearch(), [1 => 'apple', 2 => 'cherry']);

        $engine->update(1, ['id' => 1, 'text' => 'banana']);

        $savedEngine = $this->selectSavedIndex();
        $this->assertSame(2, $savedEngine->totalDocumentsInCollection());
        $this->assertNull($savedEngine->getWordFromWordList('apple'));
        $docs = $savedEngine->getAllDocumentsForStrictKeyword([['id' => 'banana']], true);
        $this->assertSame([1], array_column($docs->toArray(), 'doc_id'));
    }

    public function testSaveWordlist(): void
    {
        $engine = $this->buildIndex($this->createTntSearch(), []);

        $terms = $engine->saveWordlist(new Collection([['Apple', 'apple'], ['banana']]));

        $this->assertSame(['apple' => 2, 'banana' => 1], $terms);
        $this->assertSame(
            ['id' => 'apple', 'term' => 'apple', 'num_hits' => 2, 'num_docs' => 1],
            $this->selectSavedIndex()->getWordFromWordList('apple')
        );
    }

    public function testSaveDoclist(): void
    {
        $engine = $this->buildIndex($this->createTntSearch(), []);

        $engine->saveDoclist(['apple' => 3], 7);

        $docs = $this->selectSavedIndex()->getAllDocumentsForStrictKeyword([['id' => 'apple']], true);
        $this->assertSame([7 => 3], array_column($docs->toArray(), 'hit_count', 'doc_id'));
    }

    public function testDelete(): void
    {
        $this->buildIndex($this->createTntSearch(), [1 => 'apple banana', 2 => 'apple apple'])->delete(1);

        $engine = $this->selectSavedIndex();
        $this->assertSame(1, $engine->totalDocumentsInCollection());
        $this->assertSame(
            ['id' => 'apple', 'term' => 'apple', 'num_hits' => 2, 'num_docs' => 1],
            $engine->getWordFromWordList('apple')
        );
        $this->assertNull($engine->getWordFromWordList('banana'));
        $docs = $engine->getAllDocumentsForStrictKeyword([['id' => 'apple']], true);
        $this->assertSame([2], array_column($docs->toArray(), 'doc_id'));
    }

    public function testDeleteFile(): void
    {
        $tnt = $this->createTntSearch(['location' => $this->getFixturePath(), 'extension' => 'md']);
        $tnt->createIndex('test', true);
        $tnt->engine->readDocumentsFromFileSystem();

        $tnt->engine->delete(1);

        $this->assertSame(0, $tnt->engine->totalDocumentsInCollection());
        $this->assertSame([null], $tnt->engine->filesystemMapIdsToPaths(new Collection([1]))->toArray());
    }

    public function testDeleteFileWithoutTerms(): void
    {
        (new Filesystem())->dumpFile(Path::join($this->tempPath, 'empty.txt'), '');
        $tnt = $this->createTntSearch(['location' => $this->tempPath, 'extension' => 'txt']);
        $tnt->createIndex('test', true);
        // Only the content is indexed, so the file has no terms
        $tnt->engine->filereader = new TextFileReader();
        $tnt->engine->filereader->fileFilterCallback = fn ($value, $key) => $key !== 'name';
        $tnt->engine->readDocumentsFromFileSystem();

        $tnt->engine->delete(1);

        $this->assertSame(0, $tnt->engine->totalDocumentsInCollection());
        $this->assertSame([null], $tnt->engine->filesystemMapIdsToPaths(new Collection([1]))->toArray());
    }

    public function testDeleteUnknownDocument(): void
    {
        $engine = $this->buildIndex($this->createTntSearch(), [1 => 'apple']);

        $engine->delete(99);

        $this->assertSame(1, $engine->totalDocumentsInCollection());
        $this->assertSame(1, $engine->getWordFromWordList('apple')['num_docs']);
    }

    public static function provideGetWordFromWordList(): array
    {
        return [
            'different case' => [
                'word' => 'APPLE',
                'expected' => ['id' => 'apple', 'term' => 'apple', 'num_hits' => 2, 'num_docs' => 2],
            ],
            'numeric term' => [
                'word' => '2024',
                'expected' => ['id' => '2024', 'term' => '2024', 'num_hits' => 1, 'num_docs' => 1],
            ],
            'term with underscore' => [
                'word' => 'has_many',
                'expected' => ['id' => 'has_many', 'term' => 'has_many', 'num_hits' => 1, 'num_docs' => 1],
            ],
            'underscore is not a wildcard' => [
                'word' => 'has-many',
                'expected' => null,
            ],
            'missing term' => [
                'word' => 'missing',
                'expected' => null,
            ],
        ];
    }

    #[DataProvider('provideGetWordFromWordList')]
    public function testGetWordFromWordList(string $word, ?array $expected): void
    {
        $engine = $this->buildIndex($this->createTntSearch(), [1 => 'Apple has_many 2024', 2 => 'apple']);

        $this->assertSame($expected, $engine->getWordFromWordList($word));
    }

    public static function provideGetWordlistByKeyword(): array
    {
        return [
            'exact match' => [
                'keyword' => 'Apple',
                'isLastWord' => false,
                'asYouType' => false,
                'expected' => ['apple'],
            ],
            'no exact match' => [
                'keyword' => 'appl',
                'isLastWord' => true,
                'asYouType' => false,
                'expected' => [],
            ],
            'as you type uses most hits for terms of the same length' => [
                'keyword' => 'appl',
                'isLastWord' => true,
                'asYouType' => true,
                'expected' => ['apply'],
            ],
            'as you type uses shortest term' => [
                'keyword' => 'apples',
                'isLastWord' => true,
                'asYouType' => true,
                'expected' => ['apples'],
            ],
            'as you type only for last word' => [
                'keyword' => 'appl',
                'isLastWord' => false,
                'asYouType' => true,
                'expected' => [],
            ],
            'as you type with no match' => [
                'keyword' => 'banana',
                'isLastWord' => true,
                'asYouType' => true,
                'expected' => [],
            ],
        ];
    }

    #[DataProvider('provideGetWordlistByKeyword')]
    public function testGetWordlistByKeyword(string $keyword, bool $isLastWord, bool $asYouType, array $expected): void
    {
        $engine = $this->buildIndex(
            $this->createTntSearch(),
            [1 => 'apple apply apply apples applesauce applesauce applesauce']
        );
        $engine->asYouType = $asYouType;

        $result = $engine->getWordlistByKeyword($keyword, $isLastWord);

        $this->assertSame($expected, array_column($result, 'term'));
    }

    public static function provideGetWordlistByKeywordFuzzy(): array
    {
        return [
            'fuzzy matches when there is no exact match' => [
                'keyword' => 'aple',
                'noLimit' => false,
                'expected' => ['apple' => 1, 'apply' => 2, 'apples' => 2],
            ],
            'exact match only' => [
                'keyword' => 'apply',
                'noLimit' => false,
                'expected' => ['apply' => null],
            ],
            'exact match first, then fuzzy matches' => [
                'keyword' => 'apply',
                'noLimit' => true,
                'expected' => ['apply' => 0, 'apple' => 1, 'apples' => 2],
            ],
        ];
    }

    #[DataProvider('provideGetWordlistByKeywordFuzzy')]
    public function testGetWordlistByKeywordFuzzy(string $keyword, bool $noLimit, array $expected): void
    {
        $engine = $this->buildIndex($this->createTntSearch(), [1 => 'apple apple apple apply apply apples']);
        $engine->fuzziness = true;

        $result = $engine->getWordlistByKeyword($keyword, false, $noLimit);

        $distances = [];
        foreach ($result as $row) {
            $distances[$row['term']] = $row['distance'] ?? null;
        }
        $this->assertSame($expected, $distances);
    }

    public static function provideFuzzySearch(): array
    {
        return [
            'sorted by distance then hits' => [
                'keyword' => 'aple',
                'maxExpansions' => 50,
                'distance' => 2,
                'expected' => ['apple' => 1, 'apply' => 2, 'apples' => 2],
            ],
            'only terms with the most hits are checked' => [
                'keyword' => 'aple',
                'maxExpansions' => 2,
                'distance' => 2,
                'expected' => ['apple' => 1, 'apply' => 2],
            ],
            'max distance' => [
                'keyword' => 'aple',
                'maxExpansions' => 50,
                'distance' => 1,
                'expected' => ['apple' => 1],
            ],
            'terms must have the same prefix' => [
                'keyword' => 'bple',
                'maxExpansions' => 50,
                'distance' => 2,
                'expected' => [],
            ],
        ];
    }

    #[DataProvider('provideFuzzySearch')]
    public function testFuzzySearch(string $keyword, int $maxExpansions, int $distance, array $expected): void
    {
        $engine = $this->buildIndex($this->createTntSearch(), [1 => 'apple apple apple apply apply apples banana']);
        $engine->fuzzy_max_expansions = $maxExpansions;
        $engine->fuzzy_distance = $distance;

        $result = $engine->fuzzySearch($keyword);

        $this->assertSame($expected, array_column($result, 'distance', 'term'));
    }

    public static function provideGetAllDocumentsForStrictKeyword(): array
    {
        return [
            'limited' => [
                'noLimit' => false,
                'expected' => [2 => 3, 3 => 2],
            ],
            'not limited' => [
                'noLimit' => true,
                'expected' => [2 => 3, 3 => 2, 1 => 1],
            ],
        ];
    }

    #[DataProvider('provideGetAllDocumentsForStrictKeyword')]
    public function testGetAllDocumentsForStrictKeyword(bool $noLimit, array $expected): void
    {
        $engine = $this->buildIndex(
            $this->createTntSearch(),
            [1 => 'apple', 2 => 'apple apple apple', 3 => 'apple apple']
        );
        $engine->maxDocs = 2;

        $result = $engine->getAllDocumentsForStrictKeyword([['id' => 'apple']], $noLimit)->toArray();

        $this->assertSame($expected, array_column($result, 'hit_count', 'doc_id'));
        $this->assertSame(['apple'], array_unique(array_column($result, 'term_id')));
    }

    public function testGetAllDocumentsForStrictKeywordMissingTerm(): void
    {
        $engine = $this->buildIndex($this->createTntSearch(), [1 => 'apple']);

        $this->assertSame([], $engine->getAllDocumentsForStrictKeyword([['id' => 'missing']], true)->toArray());
    }

    public static function provideGetAllDocumentsForFuzzyKeyword(): array
    {
        return [
            'limited' => [
                'noLimit' => false,
                'expected' => [['apple', 1], ['apple', 2]],
            ],
            'not limited' => [
                'noLimit' => true,
                'expected' => [['apple', 1], ['apple', 2], ['apply', 1]],
            ],
        ];
    }

    #[DataProvider('provideGetAllDocumentsForFuzzyKeyword')]
    public function testGetAllDocumentsForFuzzyKeyword(bool $noLimit, array $expected): void
    {
        $engine = $this->buildIndex($this->createTntSearch(), [1 => 'apple apply', 2 => 'apple']);
        $engine->maxDocs = 2;

        $result = $engine->getAllDocumentsForFuzzyKeyword([['id' => 'apple'], ['id' => 'apply']], $noLimit);

        $actual = [];
        foreach ($result as $row) {
            $actual[] = [$row['term_id'], $row['doc_id']];
        }
        $this->assertSame($expected, $actual);
    }

    public static function provideGetAllDocumentsForWhereKeywordNot(): array
    {
        return [
            'limited' => [
                'keyword' => 'apple',
                'noLimit' => false,
                'expected' => [2 => 2],
            ],
            'not limited' => [
                'keyword' => 'apple',
                'noLimit' => true,
                'expected' => [2 => 2, 3 => 1],
            ],
            'unknown keyword' => [
                'keyword' => 'missing',
                'noLimit' => true,
                'expected' => [],
            ],
        ];
    }

    #[DataProvider('provideGetAllDocumentsForWhereKeywordNot')]
    public function testGetAllDocumentsForWhereKeywordNot(string $keyword, bool $noLimit, array $expected): void
    {
        $engine = $this->buildIndex(
            $this->createTntSearch(),
            [1 => 'apple banana', 2 => 'banana banana cherry', 3 => 'cherry']
        );
        $engine->maxDocs = 1;

        $result = $engine->getAllDocumentsForWhereKeywordNot($keyword, $noLimit)->toArray();

        $this->assertSame($expected, array_column($result, 'hit_count', 'doc_id'));
    }
}
