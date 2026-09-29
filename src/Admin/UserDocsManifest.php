<?php

namespace SilverStripe\UserDocs\Admin;

use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Manifest\ManifestFileFinder;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use SilverStripe\Core\ArrayLib;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\Module;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Core\Path;
use SilverStripe\i18n\i18n;

use function Embed\isEmpty;

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
     */
    protected ?CacheInterface $cache = null;

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

        // Check if cache is safe to use
        if ($this->cache
            && ($data = $this->cache->get(static::CACHE_KEY))
            && $this->loadState($data)
        ) {
            return;
        }

        // Build
        $this->regenerate($validPaths, $includeTests);
    }

    /**
     * Completely regenerates the manifest file.
     */
    public function regenerate(array $validPaths, bool $includeTests)
    {
        // Reset the manifest so stale info doesn't cause errors.
        $this->loadState([]);

        $data = [];

        $finder = new ManifestFileFinder();
        $finder->setOptions([
            'name_regex' => '/.*\\.md$/',
            'ignore_tests' => !$includeTests,
            'file_callback' => function ($fileName, $filePath) use ($includeTests, $validPaths, &$data) {
                $basePath = $this->findBasePath($filePath, $validPaths); // @TODO see if we can make the finder ignore invalid paths in the first place
                if ($basePath) {
                    $this->handleFile($data, $fileName, $filePath, $basePath, $includeTests);
                }
            },
        ]);
        $finder->find(BASE_PATH);
        $data = $this->mergeModuleData($data);
        $data = $this->sortDocData($data);
        $this->loadState($data); // @TODO rename this now that we have multiple "state" and I cbf doing it the way class manifest does
        if ($this->cache) {
            // @TODO we need to store the root and tree data too
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
                        return $a['isIndex'] ? -1 : 1; // @TODO there's some conundrums around when to deal with index vs order...
                    }

                // If files are in different directories use absolute paths to determine order
                // @TODO this is probably a bad idea, see note about building the tree in handleFile()
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
        $module = ModuleLoader::inst()->getManifest()->getModuleByPath($filePath)?->getName();
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
        /*
            @TODO We also will need to build a tree so something about sorting out the child hierarchy needs to happen too.
                  Maybe we should be storing these as nested slug segments?
                  e.g: general-features
                               |------- Feature 1
                               |------- Feature 2
                                            |------ Sub-feature
                       something-else
                               |------- more stuff
                   instead of as a flat general-features/feature1, general-features/feature2, general-features/feature2/sub-feature etc
                That would make the module merge part harder, so maybe that happens before/after/during sorting??
        */
    }

    /**
     * Load current state into an array of data.
     */
    public function getState(?string $locale = null): array
    {
        return $this->docData;
    }

    /**
     * Load current state into an array of data.
     * Data will be returned specifically
     * for the current locale, with fallbacks in the shortened locale and then in the default locale.
     * For example pt_BR will fallback to pt which will then fallback to en_US which falls back to en.
     */
    public function getLocalisedState(): array
    {
        $locale = i18n::get_locale();
        // @TODO handle this better, e.g. fall back to en for en_US, etc.
        return $this->docData[$locale] ?? $this->docData[i18n::config()->uninherited('default_locale')] ?? $this->docData['en'];
    }

    public function getLocalisedTreeData(): array
    {
        $locale = i18n::get_locale();
        // @TODO handle this better, e.g. fall back to en for en_US, etc.
        return $this->treeData[$locale] ?? $this->treeData[i18n::config()->uninherited('default_locale')] ?? $this->treeData['en'];
    }

    /**
     * Reload state from given cache data
     *
     * @return bool True if cache was valid and successfully loaded
     */
    protected function loadState(array $data): bool
    {
        //@TODO delete this if we don't get more complicated.
        // BUT if we do get more complicated check out the method by the same name in ClassManifest.
        $this->docData = $data;
        if (isEmpty($data)) {
            return true;
        }

        // Find tree roots
        $roots = [];
        foreach ($data as $locale => $localeData) {
            foreach ($localeData as $slug => $docData) {
                $root = explode('/', $slug)[0];
                if (!in_array($root, $roots[$locale] ?? [])) {
                    $roots[$locale][] = $root;
                }
            }
        }

        $toCheck = $roots;
        $this->treeData = [];
        foreach ($data as $locale => $localeData) {
            foreach (ArrayLib::iterateVolatile($toCheck[$locale]) as $slugToCheck) {
                foreach ($localeData as $slug => $docData) {
                    if ($slugToCheck === '.') continue; // @TODO temporary hack
                    // Roots need to be captured specifically
                    if (!str_contains($slugToCheck, '/') && $slug === $slugToCheck) {
                        $this->treeData[$locale][$slugToCheck]['slug'] = $slug; // @TODO not all roots wil have a slug!!
                        $this->treeData[$locale][$slugToCheck]['title'] = $docData['title'];
                    }
                    // Capture children
                    if (str_contains($slug, '/') && $docData['parentSlug'] === $slugToCheck) {
                        $slugParts = explode('/', $slug);
                        $this->storeTreeData($locale, $slugParts, [
                            'slug' => $slug,
                            'title' => $docData['title'],
                        ]);
                        // $this->treeData[$locale][$slugToCheck]['children'][array_pop($slugParts)] =
                        $toCheck[$locale][] = $slug; // @TODO or maybe we add it whether it's a child or not? We might get a state here where there's a doc with no parent maybe idk
                    }
                }
            }
        }

        return true;
    }

    private function storeTreeData(string $locale, array $slugParts, array $data): void
    {
        $current = &$this->treeData[$locale];
        $lastIndex = count($slugParts) - 1;

        foreach ($slugParts as $index => $slugPart) {
            // Ensure the current slug key exists as an array
            if (!isset($current[$slugPart]) || !is_array($current[$slugPart])) {
                $current[$slugPart] = [];
            }

            // If this is the final slug, write the value
            if ($index === $lastIndex) {
                $current[$slugPart] = $data;
                return;
            }

            // Ensure the 'children' key exists before navigating deeper
            if (!isset($current[$slugPart]['children']) || !is_array($current[$slugPart]['children'])) {
                $current[$slugPart]['children'] = [];
            }

            // Move the reference pointer down into 'children'
            $current = &$current[$slugPart]['children'];
        }
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
        // @TODO for now this generates the full slug path, not just the slug segment for THIS file. We may want to change that.
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
