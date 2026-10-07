<?php

namespace SilverStripe\UserDocs\Admin;

use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Manifest\ManifestFileFinder;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use Locale;
use SilverStripe\Core\ArrayLib;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\Module;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Core\Path;
use SilverStripe\i18n\i18n;

/**
 * A utility class which builds a manifest of where to find all user documentation and caches it.
 *
 * It finds the following information:
 *   - The canonical location of the file for each documentation path.
 *   - Which pages have which localisations.
 */
class UserDocsManifest implements Flushable
{
    use Injectable;

    protected const CACHE_KEY = 'manifest';

    /**
     * Array of properties to cache
     */
    protected array $cachedProperties = [
        'docData',
        'treeData',
    ];

    private ?CacheInterface $cache = null;

    /**
     * The full set of doc paths.
     * First key is the locale (e.g. "en")
     * Second key is the URL slug (e.g. "userdocs/something")
     * Value is an array with metadata about the file including the full path
     */
    private array $docData = [];

    /**
     * The minimal information required to build a tree of links for documentation.
     */
    private array $treeData = [];

    public static function flush(): void
    {
        // @TODO flush test cache if we add it
        Injector::inst()->get(CacheInterface::class . '.userdocsmanifest')->clear();
    }

    /**
     * Initialise the documentation manifest
     */
    public function init(array $validPaths, bool $includeTests = false): void
    {
        $this->cache = $this->buildCache($includeTests);
        // If cache is safe to use, use it
        if ($this->cache
            && ($data = $this->cache->get(static::CACHE_KEY))
            && $this->loadState($data)
        ) {
            return;
        }
        // Otherwise build from scratch
        $this->regenerate($validPaths, $includeTests);
    }

    /**
     * Completely regenerates the manifest file.
     */
    public function regenerate(array $validPaths, bool $includeTests): void
    {
        // Reset the manifest so stale info doesn't cause errors.
        $this->loadState([]);
        // Get metadata about all user documentation
        $docData = [];
        $finder = new ManifestFileFinder();
        $finder->setOptions([
            'name_regex' => '/.*\\.md$/',
            'ignore_tests' => !$includeTests,
            'file_callback' => function ($fileName, $filePath) use ($includeTests, $validPaths, &$docData) {
                $basePath = $this->findBasePath($filePath, $validPaths);
                if ($basePath) {
                    $this->handleFile($docData, $fileName, $filePath, $basePath, $includeTests);
                }
            },
        ]);
        $finder->find(BASE_PATH);
        $docData = $this->mergeModuleData($docData);
        $docData = $this->sortDocData($docData);
        $treeData = $this->collateTreeData($docData);
        $data = [
            'docData' => $docData,
            'treeData' => $treeData,
        ];
        $this->loadState($data);
        if ($this->cache) {
            $this->cache->set(static::CACHE_KEY, $data);
        }
    }

    /**
     * Merge data from modules, respecting module priority order.
     *
     * The result is that all original documentation is retained for all modules, but any
     * collision where two modules contain docs with the same slug will be resolved by the
     * module with the higher priority retaining its documentation and the lower priority
     * doc being discarded.
     */
    private function mergeModuleData(array $data): array
    {
        // Sort data by module priority order as determined by the module manifest.
        $sortedModules = array_map(
            fn (Module $module) => $module->getName(),
            ModuleLoader::inst()->getManifest()->getModules()
        );
        uksort($data, function(string $moduleA, string $moduleB) use ($sortedModules) {
            $posA = array_search($moduleA, $sortedModules);
            $posB = array_search($moduleB, $sortedModules);

            // If a key isn't found in the order array, push it to the beginning as it is now
            // the least important module.
            // That *shouldn't* ever happen but it doesn't hurt to account for the edge case.
            if ($posA === false) return -1;
            if ($posB === false) return 1;

            // Note that the sorted modules are in priority order where the FIRST module is
            // the most important. We want the reverse order here so that the most important
            // module gets to override everything in the next step.
            return $posB <=> $posA;
        });

        // Merge in and replace data as appropriate.
        // Note we can't just use the built-in array_replace_recursive() because
        // that will merge metadata about documentation for two conflicting associative arrays
        // for doc data rather than outright replacing one with the other.
        $result = [];
        foreach ($data as $datum) {
            foreach ($datum as $locale => $localeData) {
                foreach ($localeData as $slug => $docData) {
                    $result[$locale][$slug] = $docData;
                }
            }
        }
        return $result;
    }

    private function sortDocData(array $data): array
    {
        foreach ($data as $locale => &$localeData) {
            uasort($localeData, function(array $a, array $b) {
                // If both have order, compare by order first (regardless of directory)
                // This ensures siblings with order are sorted together
                if ($a['order'] !== null && $b['order'] !== null) {
                    // If orders are different, sort by order
                    if ($a['order'] !== $b['order']) {
                        return $a['order'] <=> $b['order'];
                    }
                    // Both are folders or both are files, fall through to directory/title comparison
                }

                // If one document is an index file, it comes first
                // Orders are the same - folders come before files
                // isIndex = true means it's a folder (index.md)
                // isIndex = false means it's a regular file
                if ($a['isIndex'] !== $b['isIndex']) {
                    return $a['isIndex'] ? -1 : 1;
                }

                // If files are in different directories use absolute paths to determine order
                $dirA = dirname($a['filePath']);
                $dirB = dirname($b['filePath']);
                if ($dirA !== $dirB) {
                    return $dirA <=> $dirB;
                }

                // If only one has order, it comes first
                if ($a['order'] !== null) {
                    return -1;
                }
                if ($b['order'] !== null) {
                    return 1;
                }

                // Compare alphabetically against file titles
                return $a['title'] <=> $b['title'];
            });
        }
        return $data;
    }

    private function findBasePath(string $filePath, array $validPaths): string|false
    {
        foreach ($validPaths as $candidate) {
            if (str_starts_with($filePath, $candidate)) {
                return $candidate;
            }
        }
        return false;
    }

    /**
     * Visit a file to inspect for user help documentation.
     * Updates the $data array in place, so that further transformations (e.g. sorting) can be done
     * before storing the data as manifest state.
     */
    public function handleFile(array &$data, string $fileName, string $filePath, string $basePath, bool $includeTests): void
    {
        // ONLY parse the frontmatter at this stage
        $frontMatterExtension = new FrontMatterExtension();
        $result = $frontMatterExtension->getFrontMatterParser()->parse(file_get_contents($filePath));
        $frontMatter = $result->getFrontMatter();
        $isIndex = $fileName === 'index.md';
        $module = ModuleLoader::inst()->getManifest()->getModuleByPath($filePath)->getName();
        $pathFromBase = ltrim(substr($filePath, strlen($basePath)), '/\\');
        $locale = strtok($pathFromBase, '/\\');
        $pathFromLocale = ltrim(substr($pathFromBase, strlen($locale)), '/\\');
        $slug = $this->getSlugForPath($pathFromLocale);
        $parentSlug = dirname($slug);

        $data[$module][$locale][$slug] = [
            'order' => $this->extractOrderFromFilename($isIndex ? dirname($filePath) : $fileName),
            'frontMatter' => $frontMatter,
            'title' => $frontMatter['title'] ?? $this->filenameToTitle($fileName, $filePath),
            'isIndex' => $isIndex,
            'fileName' => $fileName,
            'filePath' => $filePath,
            'module' => $module,
            'locale' => $locale,
            'slug' => $slug,
            'parentSlug' => $parentSlug,
        ];
    }

    /**
     * Load current state into an array of data.
     */
    public function getState(?string $locale = null): array
    {
        return $this->docData;
    }

    /**
     * Get all data about documentation for the current locale.
     * Falls back to the shortened locale and then to the default locale.
     * For example pt_BR will fallback to pt which will then fallback to en_US which falls back to en.
     */
    public function getLocalisedDocData(): array
    {
        return $this->getLocalisedData($this->docData);
    }

    /**
     * Undocumented function
     *
     * @return array
     */
    public function getLocalisedTreeData(): array
    {
        return $this->getLocalisedData($this->treeData);
    }

    private function getLocalisedData(array $data): array
    {
        // @TODO This is kinda garbage because it relies on an all-or-nothing localisation.
        //       We need instead to do some sort of locale merging so unlocalised docs are still displayed.
        $locale = i18n::get_locale();
        if (!empty($data[$locale])) {
            return $data[$locale];
        }
        $fallbackLocale = Locale::getPrimaryLanguage($locale);
        if ($locale !== $fallbackLocale && !empty($data[$fallbackLocale])) {
            return $data[$fallbackLocale];
        }

        $defaultLocale = i18n::config()->uninherited('default_locale');
        if (!empty($data[$defaultLocale])) {
            return $data[$defaultLocale];
        }
        $fallbackDefaultLocale = Locale::getPrimaryLanguage($defaultLocale);
        if ($defaultLocale !== $fallbackDefaultLocale && !empty($data[$fallbackDefaultLocale])) {
            return $data[$fallbackDefaultLocale];
        }
        return [];
    }

    /**
     * Reload state from given cache data
     *
     * @return bool True if cache was valid and successfully loaded
     */
    protected function loadState(array $data): bool
    {
        $success = true;
        if (empty($data)) {
            return $success;
        }
        foreach ($this->cachedProperties as $property) {
            if (!isset($data[$property]) || !is_array($data[$property])) {
                $success = false;
                $value = [];
            } else {
                $value = $data[$property];
            }
            $this->$property = $value;
        }
        return $success;
    }

    private function collateTreeData(array $docData): array
    {
        // Find tree roots
        $roots = [];
        foreach ($docData as $locale => $localeData) {
            foreach ($localeData as $slug => $docDatum) {
                $root = explode('/', $slug)[0];
                if (!in_array($root, $roots[$locale] ?? [])) {
                    $roots[$locale][] = $root;
                }
            }
        }

        $treeData = [];
        $toCheck = $roots;
        foreach ($docData as $locale => $localeData) {
            foreach (ArrayLib::iterateVolatile($toCheck[$locale]) as $slugToCheck) {
                foreach ($localeData as $slug => $docDatum) {
                    if ($slugToCheck === '.') continue; // @TODO temporary hack
                    // Roots need to be captured specifically
                    if (!str_contains($slugToCheck, '/') && $slug === $slugToCheck) {
                        $treeData[$locale][$slugToCheck]['slug'] = $slug; // @TODO not all roots wil have a slug!!
                        $treeData[$locale][$slugToCheck]['title'] = $docDatum['title'];
                    }
                    // Capture children
                    if (str_contains($slug, '/') && $docDatum['parentSlug'] === $slugToCheck) {
                        $slugParts = explode('/', $slug);
                        $current = &$treeData[$locale];
                        $lastIndex = count($slugParts) - 1;
                        foreach ($slugParts as $index => $slugPart) {
                            // Ensure the current slug key exists as an array
                            if (!isset($current[$slugPart]) || !is_array($current[$slugPart])) {
                                $current[$slugPart] = [];
                            }

                            // If this is the final slug, write the value
                            if ($index === $lastIndex) {
                                $current[$slugPart] = [
                                    'slug' => $slug,
                                    'title' => $docDatum['title'],
                                ];
                                break;
                            }

                            // Ensure the 'children' key exists before navigating deeper
                            if (!isset($current[$slugPart]['children']) || !is_array($current[$slugPart]['children'])) {
                                $current[$slugPart]['children'] = [];
                            }

                            // Move the reference pointer down into 'children'
                            $current = &$current[$slugPart]['children'];
                        }

                        // Make sure we check for children of this page
                        $toCheck[$locale][] = $slug;
                    }
                }
            }
        }
        return $treeData;
    }

    /**
     * Convert a file name to a human-readable title
     * - Strips numeric prefixes (01_, 02_, etc.)
     * - Converts underscores to spaces
     * - For index files, uses parent directory name
     */
    private function fileNameToTitle(string $fileName, string $filePath): string
    {
        // Remove file extension
        $name = pathinfo($fileName, PATHINFO_FILENAME);
        // Use directory name for index files
        if ($name === 'index') {
            $name = basename(dirname($filePath));
        }
        // Strip numeric prefixes
        $name = preg_replace('/^\d+_/', '', $name);
        // Convert underscores to spaces
        $name = preg_replace('/_/', ' ', $name);
        // Ensure title case
        $name = ucwords(strtolower($name));
        return $name;
    }

    private function getSlugForPath(string $path): string
    {
        // Strip file extension and validate there's a root
        $dir = dirname($path);
        $file = pathinfo($path, PATHINFO_FILENAME);
        if ($dir === '.' || $dir === '/' || $dir === '\\') {
            // @TODO uncomment when we're done messing around with developer docs as a source
            // throw new InvalidArgumentException('User documentation files must have at least one directory between the locale and file.');
        }
        if ($file === 'index') {
            $path = $dir;
        } else {
            $path = Path::join($dir, $file);
        }
        // Make lowercase and remove prefix numbering
        $segments = preg_split('@[\\/]@', $path);
        $parts = array_map(fn (string $segment): string => strtolower(preg_replace('/^\d+_/', '', $segment)), $segments);
        // Join parts into a single slug path
        return Path::join($parts);
    }

    private function extractOrderFromFilename(string $fileName): ?int
    {
        if (!preg_match('/^(\d+)_/', $fileName, $matches)) {
            return null;
        }
        return (int) $matches[1];
    }

    private function buildCache(bool $includeTests = false): ?CacheInterface
    {
        if ($this->cache) {
            return $this->cache;
        }
            // return $this->cacheFactory->create( @TODO if we want test separation we may need to do a thing here.
            //     CacheInterface::class . '.userdocsmanifest',
            //     ['namespace' => 'userdocsmanifest' . ($includeTests ? '_tests' : '')]
            // );
        return Injector::inst()->get(CacheInterface::class . '.userdocsmanifest');
    }
}
