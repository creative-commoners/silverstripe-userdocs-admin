<?php

namespace SilverStripe\UserDocs\Search;

use FilesystemIterator;
use InvalidArgumentException;
use LogicException;
use PDO;
use Psr\SimpleCache\CacheInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SilverStripe\Core\Injector\Injector;
use SplFileInfo;
use TeamTNT\TNTSearch\Engines\EngineInterface;
use TeamTNT\TNTSearch\Engines\EngineTrait;
use TeamTNT\TNTSearch\Exceptions\IndexNotFoundException;
use TeamTNT\TNTSearch\FileReaders\FileReaderInterface;
use TeamTNT\TNTSearch\FileReaders\TextFileReader;
use TeamTNT\TNTSearch\Stemmer\NoStemmer;
use TeamTNT\TNTSearch\Stemmer\StemmerInterface;
use TeamTNT\TNTSearch\Support\Collection;
use TeamTNT\TNTSearch\Tokenizer\Tokenizer;
use TeamTNT\TNTSearch\Tokenizer\TokenizerInterface;
use Throwable;

/**
 * A TNTSearch engine that keeps each index as PHP arrays in a PSR-16 cache.
 *
 * It needs no PHP extension and no database, so it works on every server.
 * With the default cache config the indexes are files in the temp folder.
 *
 * Like the other engines, each method that changes the index saves it before it returns.
 * Saving the whole index for each change is slow, so make many changes between indexBeginTransaction() and
 * indexEndTransaction() to save them once.
 */
class CacheEngine implements EngineInterface
{
    use EngineTrait;

    /**
     * The name of the Injector service for the cache that holds the indexes.
     */
    public const string CACHE_SERVICE = CacheInterface::class . '.userdocssearchindex';

    public string $indexName;
    public array $config;
    public StemmerInterface $stemmer;
    public string $query;
    public bool $disableOutput = false;
    public string $primaryKey;
    protected bool $excludePrimaryKey = true;
    public bool $decodeHTMLEntities = false;
    public TokenizerInterface $tokenizer;
    public array $stopWords = [];
    public int $steps = 1000;
    public bool $inMemory = true;
    public ?FileReaderInterface $filereader = null;
    public bool $asYouType = false;
    public bool $fuzziness = false;
    public int $fuzzy_prefix_length = 2;
    public int $fuzzy_max_expansions = 50;
    public int $fuzzy_distance = 2;
    public bool $fuzzy_no_limit = false;
    public int $maxDocs = 500;
    public ?PDO $dbh = null;

    /**
     * The selected index. "wordlist" has hits and docs keyed by term,
     * "doclist" has hit counts keyed by term and then document ID,
     * and "filemap" has file paths keyed by document ID for indexes from readDocumentsFromFileSystem().
     */
    private array $data = [];

    private bool $isChanged = false;

    private bool $inTransaction = false;

    public function loadConfig(array $config)
    {
        $this->config = $config;
    }

    /**
     * Create an empty index and save it, which replaces any index with the same name.
     *
     * @throws LogicException if a transaction is open
     */
    public function createIndex(string $indexName)
    {
        if ($this->inTransaction) {
            throw new LogicException('The index cannot be changed while a transaction is open');
        }
        $this->indexName = $indexName;
        $this->runInTransaction(function () {
            $this->data = [
                'info' => [
                    'total_documents' => 0,
                    'stemmer' => NoStemmer::class,
                    'tokenizer' => Tokenizer::class,
                ],
                'wordlist' => [],
                'doclist' => [],
                'filemap' => [],
            ];
            $this->isChanged = true;
            if (isset($this->config['stemmer'])) {
                $this->setStemmer(new $this->config['stemmer']());
            }
            if (isset($this->config['tokenizer'])) {
                $this->setTokenizer(new $this->config['tokenizer']());
            }
        });
        return $this;
    }

    /**
     * @throws IndexNotFoundException if the index isn't in the cache
     * @throws LogicException if a transaction is open for a different index
     */
    public function selectIndex(string $indexName)
    {
        if (isset($this->indexName) && $this->indexName === $indexName && !empty($this->data)) {
            return;
        }
        if ($this->inTransaction) {
            throw new LogicException('The index cannot be changed while a transaction is open');
        }
        $data = $this->getCache()->get($this->getCacheKey($indexName));
        if (!is_array($data)) {
            throw new IndexNotFoundException("Index $indexName does not exist", 1);
        }
        $this->indexName = $indexName;
        $this->data = $data;
    }

    /**
     * Start a transaction. Changes are kept in memory until indexEndTransaction() saves them.
     *
     * @throws LogicException if a transaction is already open
     */
    public function indexBeginTransaction(): void
    {
        if ($this->inTransaction) {
            throw new LogicException('A transaction is already open');
        }
        $this->inTransaction = true;
    }

    /**
     * Save the changes made since indexBeginTransaction(), and end the transaction.
     * If the changes can't be saved, the transaction stays open so that it can be rolled back.
     *
     * @throws LogicException if no transaction is open
     * @throws RuntimeException if the cache can't store the index
     */
    public function indexEndTransaction(): void
    {
        if (!$this->inTransaction) {
            throw new LogicException('No transaction is open');
        }
        if ($this->isChanged && isset($this->indexName)) {
            if (!$this->getCache()->set($this->getCacheKey($this->indexName), $this->data)) {
                throw new RuntimeException("Could not save the search index {$this->indexName} to the cache");
            }
            $this->isChanged = false;
        }
        $this->inTransaction = false;
    }

    /**
     * Discard the changes made since indexBeginTransaction(), and end the transaction.
     *
     * @throws LogicException if no transaction is open
     */
    public function indexRollBackTransaction(): void
    {
        if (!$this->inTransaction) {
            throw new LogicException('No transaction is open');
        }
        $data = isset($this->indexName) ? $this->getCache()->get($this->getCacheKey($this->indexName)) : null;
        $this->data = is_array($data) ? $data : [];
        $this->isChanged = false;
        $this->inTransaction = false;
    }

    public function flushIndex(string $indexName)
    {
        $this->getCache()->delete($this->getCacheKey($indexName));
        if (isset($this->indexName) && $this->indexName === $indexName) {
            $this->data = [];
            $this->isChanged = false;
        }
    }

    public function updateInfoTable(string $key, $value)
    {
        $this->runInTransaction(function () use ($key, $value) {
            $this->data['info'][$key] = $value;
            $this->isChanged = true;
        });
    }

    public function getValueFromInfoTable(string $value)
    {
        return $this->data['info'][$value] ?? null;
    }

    /**
     * Index the rows from the query, or the files if the "driver" config is "filesystem".
     */
    public function run()
    {
        if (($this->config['driver'] ?? '') === 'filesystem') {
            $this->readDocumentsFromFileSystem();
            return;
        }
        if ($this->dbh === null) {
            $dbh = $this->createConnector($this->config)->connect($this->config);
            if (!$dbh instanceof PDO) {
                throw new LogicException('The configured driver does not give a database connection');
            }
            $this->dbh = $dbh;
        }
        $result = $this->dbh->query($this->query);
        $this->runInTransaction(function () use ($result) {
            $counter = 0;
            while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                $counter++;
                $this->processDocument(new Collection($row));
                if ($counter % $this->steps === 0) {
                    $this->info("Processed {$counter} rows");
                }
            }
            if ($counter % $this->steps !== 0) {
                $this->info("Processed {$counter} rows");
            }
            $this->updateInfoTable('total_documents', $counter);
            $this->info("Total rows {$counter}");
        });
    }

    /**
     * Index the files in the directory from the "location" config.
     * The "extension" config sets which files to include, and the "exclude" config has paths to leave out.
     *
     * @throws InvalidArgumentException if the location isn't a directory on the local filesystem
     */
    public function readDocumentsFromFileSystem()
    {
        $location = $this->config['location'] ?? '';
        // realpath() returns false for stream wrappers such as ftp://, so only local directories are indexed.
        // Check for an empty location first, because realpath('') returns the current directory.
        $path = $location === '' ? false : realpath($location);
        if ($path === false || !is_dir($path)) {
            throw new InvalidArgumentException('The location config must be a directory on the local filesystem');
        }
        $this->runInTransaction(fn () => $this->indexFiles($path));
    }

    private function indexFiles(string $path): void
    {
        $extension = $this->config['extension'];
        $exclude = $this->config['exclude'] ?? [];
        $fileReader = $this->filereader ?? new TextFileReader();
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        $counter = 0;
        /** @var SplFileInfo $file */
        foreach ($files as $filePath => $file) {
            if (!$file->isFile()) {
                continue;
            }
            // Use "/" on all platforms, so the same "exclude" config works on Windows
            $name = str_replace(DIRECTORY_SEPARATOR, '/', substr($filePath, strlen($path) + 1));
            if (is_callable($extension)) {
                $includeFile = $extension($file);
            } elseif (is_array($extension)) {
                $includeFile = in_array($file->getExtension(), $extension);
            } else {
                $includeFile = str_ends_with($name, $extension);
            }
            if (!$includeFile || in_array($name, $exclude)) {
                continue;
            }
            $counter++;
            $document = new Collection([
                'id' => $counter,
                'name' => $name,
                'content' => $fileReader->read($file),
            ]);
            if (property_exists($fileReader, 'fileFilterCallback') && is_callable($fileReader->fileFilterCallback)) {
                $document = $document->filter($fileReader->fileFilterCallback);
            }
            if (property_exists($fileReader, 'fileMapCallback') && is_callable($fileReader->fileMapCallback)) {
                $document = $document->map($fileReader->fileMapCallback);
            }
            $this->processDocument($document);
            $this->data['filemap'][$counter] = $filePath;
            $this->info("Processed {$counter} {$filePath}");
        }
        $this->updateInfoTable('total_documents', $counter);
        $this->updateInfoTable('driver', 'filesystem');
        $this->info("Total rows {$counter}");
    }

    /**
     * Get the file for each document ID from an index made by readDocumentsFromFileSystem().
     * Each value is an array with "id" and "path" keys, or null if the document has no file.
     */
    public function filesystemMapIdsToPaths(Collection $docs): Collection
    {
        return $docs->map(function ($docId) {
            $path = $this->data['filemap'][$docId] ?? null;
            return $path === null ? null : ['id' => $docId, 'path' => $path];
        });
    }

    /**
     * Write each term to a file, most hits first, one term per line.
     *
     * @param int $count The maximum number of terms. Use -1 for all terms.
     * @param bool $hits If true, add the number of hits after each term.
     * @param bool $docs If true, add the number of documents after each term.
     * @throws RuntimeException if the file can't be written
     */
    public function buildDictionary($filename, $count = -1, $hits = true, $docs = false)
    {
        $wordlist = $this->data['wordlist'];
        uasort($wordlist, fn($a, $b) => $b['num_hits'] <=> $a['num_hits']);
        $dictionary = '';
        $counter = 0;
        foreach ($wordlist as $term => $counts) {
            $dictionary .= $term;
            if ($hits) {
                $dictionary .= "\t" . $counts['num_hits'];
            }
            if ($docs) {
                $dictionary .= "\t" . $counts['num_docs'];
            }
            $counter++;
            // Like the other engines, the last line has no line break only if the count stops the loop
            if ($count > 0 && $counter >= $count) {
                break;
            }
            $dictionary .= "\n";
        }
        if (file_put_contents($filename, $dictionary, LOCK_EX) === false) {
            throw new RuntimeException("Could not write the dictionary to $filename");
        }
    }

    public function processDocument(Collection $row)
    {
        $documentId = $row->get($this->getPrimaryKey());
        if ($this->excludePrimaryKey) {
            $row->forget($this->getPrimaryKey());
        }
        $stems = $row->map(function ($columnContent) {
            if (trim((string) $columnContent) === '') {
                return [];
            }
            return $this->stemText((string) $columnContent);
        });
        $this->saveToIndex($stems, $documentId);
    }

    public function excludePrimaryKey()
    {
        $this->excludePrimaryKey = true;
    }

    public function setStopWords(array $stopWords)
    {
        $this->stopWords = $stopWords;
    }

    public function insert(array $document)
    {
        $this->runInTransaction(function () use ($document) {
            $this->processDocument(new Collection($document));
            $this->updateInfoTable('total_documents', $this->totalDocumentsInCollection() + 1);
        });
    }

    public function update(int $id, array $document)
    {
        $this->runInTransaction(function () use ($id, $document) {
            $this->delete($id);
            $this->insert($document);
        });
    }

    public function saveToIndex(Collection $stems, int $docId)
    {
        $this->runInTransaction(function () use ($stems, $docId) {
            $terms = $this->saveWordlist($stems);
            $this->saveDoclist($terms, $docId);
        });
    }

    /**
     * Add the terms of one document to the wordlist.
     * Returns the number of hits in the document keyed by term.
     */
    public function saveWordlist(Collection $stems)
    {
        $terms = [];
        foreach ($stems as $column) {
            foreach ($column as $term) {
                $term = mb_strtolower((string) $term);
                $terms[$term] = ($terms[$term] ?? 0) + 1;
            }
        }
        $this->runInTransaction(function () use ($terms) {
            foreach ($terms as $term => $hits) {
                $this->data['wordlist'][$term]['num_hits'] = ($this->data['wordlist'][$term]['num_hits'] ?? 0) + $hits;
                $this->data['wordlist'][$term]['num_docs'] = ($this->data['wordlist'][$term]['num_docs'] ?? 0) + 1;
            }
            $this->isChanged = true;
        });
        return $terms;
    }

    public function saveDoclist(array $terms, int $docId)
    {
        $this->runInTransaction(function () use ($terms, $docId) {
            foreach ($terms as $term => $hits) {
                $this->data['doclist'][$term][$docId] = $hits;
            }
            $this->isChanged = true;
        });
    }

    public function saveHitList(array $stems, int $docId, array $termsList)
    {
        // Hit positions aren't used for searching, so they aren't stored.
    }

    public function delete(int $documentId)
    {
        $this->runInTransaction(function () use ($documentId) {
            $found = false;
            foreach ($this->data['doclist'] as $term => $docs) {
                if (!isset($docs[$documentId])) {
                    continue;
                }
                $found = true;
                $this->data['wordlist'][$term]['num_hits'] -= $docs[$documentId];
                $this->data['wordlist'][$term]['num_docs']--;
                unset($this->data['doclist'][$term][$documentId]);
                if ($this->data['wordlist'][$term]['num_docs'] <= 0) {
                    unset($this->data['wordlist'][$term], $this->data['doclist'][$term]);
                }
            }
            if (isset($this->data['filemap'][$documentId])) {
                $found = true;
                unset($this->data['filemap'][$documentId]);
            }
            if ($found) {
                $this->data['info']['total_documents']--;
                $this->isChanged = true;
            }
        });
    }

    public function totalDocumentsInCollection()
    {
        return $this->getValueFromInfoTable('total_documents') ?? 0;
    }

    public function getWordFromWordList(string $word)
    {
        $word = mb_strtolower($word);
        return isset($this->data['wordlist'][$word]) ? $this->getWordRow($word) : null;
    }

    public function getWordlistByKeyword(string $keyword, bool $isLastWord = false, bool $noLimit = false)
    {
        $keyword = mb_strtolower($keyword);
        $result = [];
        if ($this->asYouType && $isLastWord) {
            $matches = array_filter(
                array_keys($this->data['wordlist']),
                fn($term) => str_starts_with((string) $term, $keyword)
            );
            // Shortest term first, then the most hits
            usort($matches, fn($a, $b) => [mb_strlen((string) $a), $this->data['wordlist'][$b]['num_hits']]
                <=> [mb_strlen((string) $b), $this->data['wordlist'][$a]['num_hits']]);
            if (!empty($matches)) {
                $result[] = $this->getWordRow($matches[0]);
            }
        } elseif (isset($this->data['wordlist'][$keyword])) {
            $result[] = $this->getWordRow($keyword);
        }
        if ($this->fuzziness && (empty($result) || $noLimit)) {
            if (!empty($result)) {
                $result[0]['distance'] = 0;
            }
            foreach ($this->fuzzySearch($keyword) as $match) {
                if (empty($result) || $match['id'] !== $result[0]['id']) {
                    $result[] = $match;
                }
            }
        }
        return $result;
    }

    public function fuzzySearch(string $keyword)
    {
        $prefix = mb_strtolower(mb_substr($keyword, 0, $this->fuzzy_prefix_length));
        $matches = [];
        foreach ($this->data['wordlist'] as $term => $counts) {
            if (str_starts_with((string) $term, $prefix)) {
                $matches[(string) $term] = $counts['num_hits'];
            }
        }
        // Like the other engines, only check the terms with the most hits
        arsort($matches);
        $matches = array_slice($matches, 0, $this->fuzzy_max_expansions, true);
        $result = [];
        foreach (array_keys($matches) as $term) {
            $distance = $this->mbLevenshtein((string) $term, $keyword);
            if ($distance <= $this->fuzzy_distance) {
                $result[] = $this->getWordRow($term) + ['distance' => $distance];
            }
        }
        usort($result, fn($a, $b) => [$a['distance'], $b['num_hits']] <=> [$b['distance'], $a['num_hits']]);
        return $result;
    }

    public function getAllDocumentsForStrictKeyword(array $word, bool $noLimit)
    {
        $docs = $this->data['doclist'][$word[0]['id']] ?? [];
        arsort($docs);
        if (!$noLimit) {
            $docs = array_slice($docs, 0, $this->maxDocs, true);
        }
        return new Collection($this->getDocRows($word[0]['id'], $docs));
    }

    public function getAllDocumentsForFuzzyKeyword(array $words, bool $noLimit)
    {
        $rows = [];
        foreach ($words as $word) {
            $rows = array_merge($rows, $this->getDocRows($word['id'], $this->data['doclist'][$word['id']] ?? []));
        }
        return new Collection($noLimit ? $rows : array_slice($rows, 0, $this->maxDocs));
    }

    public function getAllDocumentsForWhereKeywordNot(string $keyword, bool $noLimit = false)
    {
        $word = $this->getWordlistByKeyword($keyword);
        if (!isset($word[0])) {
            return new Collection([]);
        }
        $excluded = $this->data['doclist'][$word[0]['id']] ?? [];
        // The highest hit count of each document that doesn't have the keyword
        $docs = [];
        foreach ($this->data['doclist'] as $termDocs) {
            foreach ($termDocs as $docId => $hits) {
                if (!isset($excluded[$docId])) {
                    $docs[$docId] = max($docs[$docId] ?? 0, $hits);
                }
            }
        }
        arsort($docs);
        if (!$noLimit) {
            $docs = array_slice($docs, 0, $this->maxDocs, true);
        }
        $rows = [];
        foreach ($docs as $docId => $hits) {
            $rows[] = ['doc_id' => $docId, 'hit_count' => $hits];
        }
        return new Collection($rows);
    }

    private function getWordRow(int|string $term): array
    {
        // PHP turns numeric array keys into integers, so cast the term back to a string
        $term = (string) $term;
        return [
            'id' => $term,
            'term' => $term,
            'num_hits' => $this->data['wordlist'][$term]['num_hits'],
            'num_docs' => $this->data['wordlist'][$term]['num_docs'],
        ];
    }

    private function getDocRows(string $term, array $docs): array
    {
        $rows = [];
        foreach ($docs as $docId => $hits) {
            $rows[] = ['term_id' => $term, 'doc_id' => $docId, 'hit_count' => $hits];
        }
        return $rows;
    }

    /**
     * Run the callback in a transaction, so that its changes are saved together.
     * If a transaction is already open, the changes are saved when that transaction ends.
     */
    private function runInTransaction(callable $callback): mixed
    {
        if ($this->inTransaction) {
            return $callback();
        }
        $this->indexBeginTransaction();
        try {
            $result = $callback();
            $this->indexEndTransaction();
        } catch (Throwable $exception) {
            $this->indexRollBackTransaction();
            throw $exception;
        }
        return $result;
    }

    private function getCacheKey(string $indexName): string
    {
        // Index names can have characters that PSR-16 doesn't allow in keys
        return 'index_' . md5($indexName);
    }

    private function getCache(): CacheInterface
    {
        return Injector::inst()->get(CacheEngine::CACHE_SERVICE);
    }
}
