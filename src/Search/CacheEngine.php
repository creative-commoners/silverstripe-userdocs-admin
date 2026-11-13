<?php

namespace SilverStripe\UserDocs\Search;

use BadMethodCallException;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Injector\Injector;
use TeamTNT\TNTSearch\Engines\EngineInterface;
use TeamTNT\TNTSearch\Engines\EngineTrait;
use TeamTNT\TNTSearch\Exceptions\IndexNotFoundException;
use TeamTNT\TNTSearch\FileReaders\FileReaderInterface;
use TeamTNT\TNTSearch\Stemmer\NoStemmer;
use TeamTNT\TNTSearch\Stemmer\StemmerInterface;
use TeamTNT\TNTSearch\Support\Collection;
use TeamTNT\TNTSearch\Tokenizer\Tokenizer;
use TeamTNT\TNTSearch\Tokenizer\TokenizerInterface;

/**
 * A TNTSearch engine that keeps each index as PHP arrays in a PSR-16 cache.
 *
 * It needs no PHP extension and no database, so it works on every server.
 * With the default cache config the indexes are files in the temp folder.
 *
 * Changes to an index are kept in memory until save() is called.
 * Only the inserts that the documentation search uses are supported: the index can't be built from a database
 * query or from the file system.
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

    /**
     * The selected index. "wordlist" has hits and docs keyed by term,
     * and "doclist" has hit counts keyed by term and then document ID.
     */
    private array $data = [];

    private bool $isChanged = false;

    public function loadConfig(array $config)
    {
        $this->config = $config;
    }

    public function createIndex(string $indexName)
    {
        $this->indexName = $indexName;
        $this->data = [
            'info' => [
                'total_documents' => 0,
                'stemmer' => NoStemmer::class,
                'tokenizer' => Tokenizer::class,
            ],
            'wordlist' => [],
            'doclist' => [],
        ];
        $this->isChanged = true;
        if (isset($this->config['stemmer'])) {
            $this->setStemmer(new $this->config['stemmer']());
        }
        if (isset($this->config['tokenizer'])) {
            $this->setTokenizer(new $this->config['tokenizer']());
        }
        return $this;
    }

    /**
     * @throws IndexNotFoundException if the index isn't in the cache
     */
    public function selectIndex(string $indexName)
    {
        if (isset($this->indexName) && $this->indexName === $indexName && !empty($this->data)) {
            return;
        }
        $this->save();
        $data = $this->getCache()->get($this->getCacheKey($indexName));
        if (!is_array($data)) {
            throw new IndexNotFoundException("Index $indexName does not exist", 1);
        }
        $this->indexName = $indexName;
        $this->data = $data;
    }

    /**
     * Save the selected index to the cache if it has changed.
     */
    public function save(): void
    {
        if (!$this->isChanged || !isset($this->indexName)) {
            return;
        }
        $this->getCache()->set($this->getCacheKey($this->indexName), $this->data);
        $this->isChanged = false;
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
        $this->data['info'][$key] = $value;
        $this->isChanged = true;
    }

    public function getValueFromInfoTable(string $value)
    {
        return $this->data['info'][$value] ?? null;
    }

    public function run()
    {
        throw new BadMethodCallException(static::class . ' can only index documents with insert()');
    }

    public function readDocumentsFromFileSystem()
    {
        throw new BadMethodCallException(static::class . ' can only index documents with insert()');
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

    public function saveToIndex(Collection $stems, int $docId)
    {
        $terms = $this->saveWordlist($stems);
        $this->saveDoclist($terms, $docId);
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
        foreach ($terms as $term => $hits) {
            $this->data['wordlist'][$term]['num_hits'] = ($this->data['wordlist'][$term]['num_hits'] ?? 0) + $hits;
            $this->data['wordlist'][$term]['num_docs'] = ($this->data['wordlist'][$term]['num_docs'] ?? 0) + 1;
        }
        $this->isChanged = true;
        return $terms;
    }

    public function saveDoclist(array $terms, int $docId)
    {
        foreach ($terms as $term => $hits) {
            $this->data['doclist'][$term][$docId] = $hits;
        }
        $this->isChanged = true;
    }

    public function saveHitList(array $stems, int $docId, array $termsList)
    {
        // Hit positions aren't used for searching, so they aren't stored.
    }

    public function delete(int $documentId)
    {
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
        if ($found) {
            $this->data['info']['total_documents']--;
            $this->isChanged = true;
        }
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
