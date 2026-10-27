<?php

namespace SilverStripe\UserDocs\Search;

use BadMethodCallException;
use Error;
use InvalidArgumentException;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\Embed\Embed;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalink;
use League\CommonMark\Extension\TableOfContents\Node\TableOfContents;
use League\CommonMark\Extension\TableOfContents\Node\TableOfContentsPlaceholder;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\RawMarkupContainerInterface;
use League\CommonMark\Node\StringContainerInterface;
use League\CommonMark\Parser\MarkdownParser;
use Override;
use PDO;
use Predis\Client as PredisClient;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\UserDocs\Admin\UserDocsManifest;
use Symfony\Component\Filesystem\Filesystem;
use TeamTNT\TNTSearch\Engines\MysqlEngine;
use TeamTNT\TNTSearch\Engines\RedisEngine;
use TeamTNT\TNTSearch\Engines\SqliteEngine;
use TeamTNT\TNTSearch\TNTSearch;
use Throwable;

/**
 * Builds and searches full text indexes of the user documentation.
 *
 * Each locale has its own index for the body text of its pages, plus one index for each weighted zone
 * (see the zone_weights configuration). Page data such as titles and file paths comes from UserDocsManifest.
 *
 * Searches use the locale priority from UserDocsManifest::getLocalePriority(). A page is only found
 * in its effective locale, which is the first locale in that priority that has a version of the page.
 */
class SearchIndex implements Flushable
{
    use Extensible;
    use Configurable;
    use Injectable;

    protected const CACHE_KEY = 'indexmap';

    /**
     * Score weights for matches in specific zones of a page.
     *
     * A zone is a part of the page's text that is indexed and scored separately from the body text,
     * for example the title or the text of all level 2 headings. Text in a weighted zone does not count
     * as body text. A match in a zone adds its score multiplied by the zone's weight to the body text score.
     * Body text has a weight of 1, so a weight above 1 makes a zone more important than body text,
     * and a weight below 1 makes it less important.
     *
     * Valid zones are "title" and heading levels "h1" to "h6".
     * Set a zone to null to count its text as body text.
     * If "title" is null, filtering by title matches against the page titles directly.
     */
    private static array $zone_weights = [
        'title' => 5,
        'h1' => 4,
        'h2' => 2,
        'h3' => 1,
    ];

    /**
     * Classes of markdown nodes to remove before indexing, so their text doesn't count towards any index.
     * Subclasses of these classes are also removed, as are any nodes nested as children of these nodes.
     */
    private static array $excluded_node_classes = [
        Embed::class,
        HeadingPermalink::class,
        TableOfContents::class,
        TableOfContentsPlaceholder::class,
    ];

    /**
     * If true, disables the default max results limit in the underlying search engine.
     * Setting this to false will limit the number of results returned and can affect
     * page relevancy rankings.
     *
     * Only set this to false if you have explicit performance limitations that aren't
     * easily resolved any other way (which is very unlikely).
     */
    private static bool $disable_search_limit = true;

    /**
     * The length of the text returned by createSummary()
     */
    private static int $summary_length = 300;

    /**
     * @internal
     */
    private static ?CacheInterface $cache = null;

    private ?TNTSearch $tntSearch = null;

    /**
     * Details about the indexes that have been built.
     * "locales" are the locales that have indexes, and "checkedLocales" are all locales that init() has
     * processed, including locales with no content to index.
     */
    private array $indexMap = [
        'locales' => [],
        'checkedLocales' => [],
        'zones' => [],
    ];

    /**
     * Page slugs keyed by page ID, built from the manifest when first needed.
     */
    private array $slugsById = [];

    /**
     * @inheritDoc
     */
    #[Override]
    public static function flush(): void
    {
        static::singleton()->clearIndex();
    }

    /**
     * Check if this server has the dependencies that the configured tntsearch engine needs.
     * Engines other than SqliteEngine, MysqlEngine, and RedisEngine are assumed to be supported.
     */
    public function isSupported(): bool
    {
        try {
            $engine = $this->getTntSearch()->engine;
        } catch (Error $error) {
            // RedisEngine creates a Predis client when it loads its config, which fails if the class is missing.
            $missingPredis = sprintf('Class "%s" not found', PredisClient::class);
            if ($error->getMessage() === $missingPredis && !$this->hasPredisClient()) {
                return false;
            }
            throw $error;
        }
        // MysqlEngine is a subclass of SqliteEngine, so check it first.
        $isSupported = match (true) {
            $engine instanceof MysqlEngine => in_array('mysql', $this->getAvailablePdoDrivers()),
            $engine instanceof SqliteEngine => in_array('sqlite', $this->getAvailablePdoDrivers()),
            $engine instanceof RedisEngine => $this->hasPredisClient(),
            // We can't check engines we aren't aware of so we must assume they're supported.
            // Custom implementations can be handled in an extension.
            default => true,
        };
        $this->extend('updateIsSupported', $isSupported, $engine);
        return $isSupported;
    }

    public function init(): void
    {
        $manifest = UserDocsManifest::singleton();
        $indexMap = $this->getIndexMap();
        foreach (UserDocsManifest::getLocalePriority() as $locale) {
            if (in_array($locale, $indexMap['checkedLocales'])) {
                // Skip locales we've already indexed or found to have no content
                continue;
            }
            $this->indexMap['checkedLocales'][] = $locale;
            // Note we explicitly don't use locale cascading here - each index should have ONLY
            // the content for the given locale (if any). We cascade during search.
            $docData = $manifest->getDocData($locale);
            if (empty($docData)) {
                // If there's no data for this locale, just skip it.
                continue;
            }

            // // Set locale-specific stemmers here if applicable @TODO consider this
            // if (str_starts_with($locale, 'en')) {
            //     $indexer->setStemmer(PorterStemmer::class);
            // }
            // @TODO Also consider $indexer->setLanguage();
            // If either of the above are used, consider also swapping to the relevant main index
            // in searchManifestTitles and then using the index's etemmer on both query and title.

            $weightedZones = array_keys($this->getZoneWeights());
            $contentRows = [];
            $zoneRows = array_fill_keys($weightedZones, []);
            foreach ($docData as $docDatum) {
                if (!$this->isIndexable($docDatum)) {
                    // Just silently skip any with no content or missing file.
                    // Warnings etc for these are handled elsewhere.
                    continue;
                }
                $id = $this->getPageId($docDatum['slug']);
                $document = $this->parseDocument($docDatum['filePath']);
                // Get zone text first, because this removes the zone nodes from the document.
                $textByZone = $this->extractTextForZones($weightedZones, $docDatum['title'], $document);
                $bodyText = $this->getNodeText($document);
                if (!array_key_exists('title', $textByZone)) {
                    $bodyText = $docDatum['title'] . "\n" . $bodyText;
                }
                $contentRows[] = [
                    'id' => $id,
                    'text' => $bodyText,
                ];
                foreach ($textByZone as $zone => $text) {
                    // Add every page to every zone index, even with no text. This keeps the document
                    // count the same in all indexes which is important for tntsearch's relevancy calculations
                    $zoneRows[$zone][] = [
                        'id' => $id,
                        'text' => $text,
                    ];
                }
            }
            if (empty($contentRows)) {
                continue;
            }
            // Build indexes here instead of above to avoid switching index files constantly
            $this->indexMap['locales'][] = $locale;
            $this->buildIndex("{$locale}.index", $contentRows);
            foreach ($zoneRows as $zone => $rows) {
                $this->buildIndex("{$locale}.{$zone}.index", $rows);
            }
            $this->indexMap['zones'] = $weightedZones;
        }
        // Cache the index map so we can skip re-indexing in future runs
        Injector::inst()->get(CacheInterface::class . '.userdocssearch')->set(static::CACHE_KEY, $this->indexMap);
    }

    /**
     * Check if init() has built the indexes for all locales in the current locale priority.
     */
    public function isBuilt(): bool
    {
        $checkedLocales = $this->getIndexMap()['checkedLocales'];
        return empty(array_diff(UserDocsManifest::getLocalePriority(), $checkedLocales));
    }

    /**
     * Search the documentation, highest score first.
     *
     * @param array $query Search terms keyed by form field(s) from `SearchForm`.
     * Terms from the default field will search across all indexes.
     * Terms from other fields in that form will filter results by relevant indexes.
     *
     * @throws BadMethodCallException if the indexes for the current locales have not been built with init()
     */
    public function search(array $query): array
    {
        if (!$this->isBuilt()) {
            throw new BadMethodCallException('The search indexes for the current locales have not been built yet');
        }
        $terms = trim($query[SearchForm::DEFAULT_SEARCH_FIELD] ?? '');
        $title = trim($query['title'] ?? '');
        $results = [];
        if ($title !== '') {
            $results = in_array('title', $this->getIndexMap()['zones'])
                ? $this->searchLocales($title, ['title'], false)
                : $this->searchManifestTitles($title);
        }
        if ($terms !== '') {
            $termResults = $this->searchLocales($terms);
            $results = $title !== ''
                ? array_intersect_key($termResults, $results)
                : $termResults;
        }
        // Sort final merged results by score
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        return $results;
    }

    /**
     * Get an HTML summary of the page with the given ID, showing where its text matches the query.
     * Returns an empty string if the page or its file can't be found.
     */
    public function createSummary(string $query, int $id, bool $highlight = true): string
    {
        $pageId = (int) $id;
        $locale = $this->getEffectiveLocale($pageId, UserDocsManifest::getLocalePriority());
        $pageData = $locale ? $this->getPageData($pageId, $locale) : null;
        if (!$pageData) {
            return '';
        }
        // Use all text except excluded nodes, so matches in headings are included in the summary.
        $text = $this->getNodeText($this->parseDocument($pageData['filePath']));
        // Prevcount uses 1/6 ratio of snippet length as recommended by the tntsearch docs
        $snippetLength = static::config()->get('summary_length');
        $prevCount = (int) ($snippetLength / 6);
        // We need to suppress warnings because the snippet method often results in a deprecation warning.
        // See https://github.com/teamtnt/tntsearch/issues/370
        $summary = @$this->getTntSearch()->snippet($query, $text, $snippetLength, $prevCount);
        // The text can contain HTML characters from code and entities, so escape it before adding highlight tags.
        $summary = htmlspecialchars($summary, ENT_NOQUOTES);
        if ($highlight) {
            $summary = $this->getTntSearch()->highlight($summary, $query);
        }
        return $summary;
    }

    /**
     * Clear all records out of the index.
     */
    public function clearIndex(): void
    {
        // Clear indexes first
        $tnt = $this->getTntSearch();
        $indexMap = $this->getIndexMap();
        foreach ($indexMap['locales'] as $locale) {
            $tnt->engine->flushIndex("{$locale}.index");
            foreach ($indexMap['zones'] as $zone) {
                $tnt->engine->flushIndex("{$locale}.{$zone}.index");
            }
        }
        // Clear cached index map
        Injector::inst()->get(CacheInterface::class . '.userdocssearch')->clear();
        $this->indexMap = [
            'locales' => [],
            'checkedLocales' => [],
            'zones' => [],
        ];
        $this->slugsById = [];
    }

    /**
     * Resolves which locale should serve this page based on the fallback chain.
     */
    protected function getEffectiveLocale(int $pageId, array $chain): ?string
    {
        foreach ($chain as $locale) {
            if ($this->getPageData($pageId, $locale)) {
                return $locale;
            }
        }
        return null;
    }

    /**
     * Get the ID of the page with the given slug.
     * tntsearch needs int IDs, and the ID must be the same in all locales for the same slug.
     */
    private function getPageId(string $slug): int
    {
        return crc32($slug);
    }

    /**
     * Get the manifest data for the page with the given ID in the given locale.
     * Returns null if that locale has no indexable version of the page.
     */
    private function getPageData(int $pageId, string $locale): ?array
    {
        if (empty($this->slugsById)) {
            foreach (UserDocsManifest::singleton()->getDocData() as $localeData) {
                foreach (array_keys($localeData) as $slug) {
                    $this->slugsById[$this->getPageId($slug)] = $slug;
                }
            }
        }
        $slug = $this->slugsById[$pageId] ?? null;
        $docDatum = $slug !== null ? UserDocsManifest::singleton()->getDocData($locale)[$slug] ?? null : null;
        if (!$docDatum || !$this->isIndexable($docDatum)) {
            return null;
        }
        return $docDatum;
    }

    private function isIndexable(array $docDatum): bool
    {
        return $docDatum['hasContent'] && file_exists($docDatum['filePath']);
    }

    private function getIndexMap(): array
    {
        if (empty($this->indexMap['locales'])) {
            $this->indexMap = Injector::inst()->get(CacheInterface::class . '.userdocssearch')->get(static::CACHE_KEY, [
                'locales' => [],
                'checkedLocales' => [],
                'zones' => [],
            ]);
        }
        return $this->indexMap;
    }

    /**
     * Get the configured zone weights, without zones that count as body text.
     */
    private function getZoneWeights(): array
    {
        return array_filter(static::config()->get('zone_weights') ?? []);
    }

    /**
     * Parse the markdown file and remove excluded nodes from the document.
     */
    private function parseDocument(string $filePath): Document
    {
        /** @var MarkdownConverter $converter */
        $converter = Injector::inst()->create(MarkdownConverter::class . '.userdocs');
        $parser = Injector::inst()->create(MarkdownParser::class, $converter->getEnvironment());
        $document = $parser->parse(file_get_contents($filePath));
        $this->removeExcludedNodes($document);
        return $document;
    }

    /**
     * Remove nodes of the classes in the excluded_node_classes configuration from the document.
     */
    private function removeExcludedNodes(Document $document): void
    {
        $excludedClasses = static::config()->get('excluded_node_classes') ?? [];
        $excludedNodes = [];
        foreach ($document->iterator() as $node) {
            foreach ($excludedClasses as $class) {
                if ($node instanceof $class) {
                    $excludedNodes[] = $node;
                    break;
                }
            }
        }
        foreach ($excludedNodes as $node) {
            $node->detach();
        }
    }

    /**
     * Get the text of each of the given zones, keyed by zone name, and remove the zone nodes from the document.
     * A zone with no text in the page has an empty string.
     */
    private function extractTextForZones(array $zones, string $title, Document $document): array
    {
        $textByZone = array_fill_keys($zones, '');
        if (array_key_exists('title', $textByZone)) {
            $textByZone['title'] = $title;
        }
        $zoneNodes = [];
        foreach ($document->iterator() as $node) {
            if (!$node instanceof Heading) {
                continue;
            }
            $zone = 'h' . $node->getLevel();
            if (array_key_exists($zone, $textByZone)) {
                $textByZone[$zone] = ltrim($textByZone[$zone] . "\n" . $this->getNodeText($node));
                $zoneNodes[] = $node;
            }
        }
        foreach ($zoneNodes as $node) {
            $node->detach();
        }
        return $textByZone;
    }

    /**
     * Get the plain text of a node and its children.
     * Adds a space between blocks and lines so that words don't merge, and strips tags from raw HTML.
     */
    private function getNodeText(Node $node): string
    {
        $text = '';
        foreach ($node->iterator() as $child) {
            if ($child instanceof AbstractBlock || $child instanceof Newline) {
                // Ensure blocks of text don't run into each other
                $text .= ' ';
            }
            if ($child instanceof RawMarkupContainerInterface) {
                // Strip raw HTML from the markdown content
                $text .= strip_tags($child->getLiteral());
            } elseif ($child instanceof StringContainerInterface) {
                $text .= $child->getLiteral();
            }
        }
        return trim($text);
    }

    private function buildIndex(string $indexName, array $rows): void
    {
        $tnt = $this->getTntSearch();
        $indexer = $tnt->createIndex($indexName);
        // Insert all rows in one transaction where possible.
        // Otherwise each row is committed separately, which is much slower.
        $pdo = $tnt->engine instanceof SqliteEngine ? $tnt->engine->index : null;
        $pdo?->beginTransaction();
        try {
            foreach ($rows as $row) {
                $indexer->insert($row);
            }
            $pdo?->commit();
        } catch (Throwable $exception) {
            if ($pdo?->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Search the indexes for all locales in the locale priority, keyed by page ID.
     * Each page is only found in its effective locale.
     * See searchLocale() for the $zones and $includeBody parameters.
     */
    private function searchLocales(string $query, ?array $zones = null, bool $includeBody = true): array
    {
        $results = [];
        $locales = UserDocsManifest::getLocalePriority();
        $indexedLocales = $this->getIndexMap()['locales'];
        foreach ($locales as $priorityIndex => $locale) {
            // Skip if this locale has no index
            if (!in_array($locale, $indexedLocales)) {
                continue;
            }
            foreach ($this->searchLocale($query, $locale, $zones, $includeBody) as $pageId => $score) {
                // 1. Skip if already captured by a higher-priority locale in this search
                if (isset($results[$pageId])) {
                    continue;
                }

                // 2. CRUCIAL: Result Shadowing Check
                // If this page was found in 'en', but has a 'fr' version,
                // its effective locale is 'fr'. It MUST NOT appear as an English result.
                // @TODO this doesn't take extensions into account (future feature)
                if ($this->getEffectiveLocale($pageId, $locales) !== $locale) {
                    continue;
                }

                $results[$pageId] = $this->createResult($pageId, $locale, $priorityIndex, $score);
            }
        }
        return $results;
    }

    /**
     * Search the indexes for the given locale.
     * Returns the score of each matching page keyed by page ID, highest first.
     * Matches in weighted zones add to the body text score.
     *
     * @param null|array $zones The specific zone(s) to search. If null, all zones will
     * be searched. If empty, only the main index will be searched.
     * @param bool $includeBody If false, the main index is not searched.
     */
    private function searchLocale(string $query, string $locale, ?array $zones = null, bool $includeBody = true): array
    {
        if (!$includeBody && $zones === []) {
            throw new InvalidArgumentException('At least one zone or the main index must be searched');
        }
        $tnt = $this->getTntSearch();
        $tnt->selectIndex("{$locale}.index");
        $scores = [];
        // Search in body index
        if ($includeBody) {
            $scores = $tnt->search($query)['docScores'];
        }
        $zoneWeights = $this->getZoneWeights();
        // Search in zone indexes
        foreach ($this->getIndexMap()['zones'] as $zone) {
            if ($zones !== null && !in_array($zone, $zones)) {
                continue;
            }
            $tnt->selectIndex("{$locale}.{$zone}.index");
            foreach ($tnt->search($query)['docScores'] as $pageId => $score) {
                $scores[$pageId] = ($scores[$pageId] ?? 0) + $score * ($zoneWeights[$zone] ?? 1);
            }
        }
        arsort($scores);
        return $scores;
    }

    /**
     * Get the pages whose title contains any word in the query, keyed by page ID.
     * Each page is only found in its effective locale, and its score is the number of words that match.
     * Use this to filter by title when titles are not indexed separately.
     */
    private function searchManifestTitles(string $query): array
    {
        $tokens = $this->getTntSearch()->breakIntoTokens($query);
        $results = [];
        $locales = UserDocsManifest::getLocalePriority();
        foreach ($locales as $priorityIndex => $locale) {
            foreach (UserDocsManifest::singleton()->getDocData($locale) as $docDatum) {
                $pageId = $this->getPageId($docDatum['slug']);
                if (isset($results[$pageId]) || $this->getEffectiveLocale($pageId, $locales) !== $locale) {
                    continue;
                }
                $titleParts = $this->getTntSearch()->breakIntoTokens($docDatum['title']);
                $score = count(array_filter($tokens, fn($token) => in_array($token, $titleParts)));
                if ($score > 0) {
                    $results[$pageId] = $this->createResult($pageId, $locale, $priorityIndex, $score);
                }
            }
        }
        return $results;
    }

    /**
     * Create a search result for the page with the given ID in its effective locale.
     *
     * @param int $priorityIndex The position of the locale in the locale priority
     */
    private function createResult(int $pageId, string $locale, int $priorityIndex, float $score): array
    {
        $pageMeta = $this->getPageData($pageId, $locale);
        return [
            'id' => $pageId,
            'title' => $pageMeta['title'],
            'slug' => $pageMeta['slug'],
            'matched_locale' => $locale,
            'is_fallback' => $priorityIndex > 0,
            // Score with a small priority discount for fallback locales
            'score' => $score * (1 - ($priorityIndex * 0.15)),
        ];
    }

    /**
     * @internal Used by tests to simulate missing PDO drivers
     */
    protected function getAvailablePdoDrivers(): array
    {
        return PDO::getAvailableDrivers();
    }

    /**
     * Check if the Redis client that RedisEngine uses is available.
     * tntsearch requires it at the moment, but that could change in a future version.
     *
     * @internal Used by tests to simulate a missing Redis client
     */
    protected function hasPredisClient(): bool
    {
        return class_exists(PredisClient::class);
    }

    private function getTntSearch(): TNTSearch
    {
        if (!$this->tntSearch) {
            $this->tntSearch = Injector::inst()->get(TNTSearch::class . '.userdocs');
            $this->tntSearch->setFuzzyNoLimit(!static::config()->get('disable_search_limit'));
        }
        return $this->tntSearch;
    }
}
