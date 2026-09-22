<?php

namespace SilverStripe\UserDocs\Admin;

use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Manifest\ManifestFileFinder;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
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
class UserDocsManifest
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

        $finder = new ManifestFileFinder();
        $finder->setOptions([
            'name_regex' => '/.*\\.md$/',
            'ignore_tests' => !$includeTests,
            'file_callback' => function ($fileName, $filePath) use ($includeTests, $validPaths) {
                $basePath = $this->findBasePath($filePath, $validPaths); // @TODO wee if we can make the finder ignore invalid paths in the first place
                if ($basePath) {
                    $this->handleFile($fileName, $filePath, $basePath, $includeTests);
                }
            },
        ]);
        $finder->find(BASE_PATH); // @TODO find in each path from UserDocsAdmin::config()->get('documentation_roots') which is an array that will be passed in on construction

        // @TODO Does sorting go here? See https://github.com/silverstripe/doc.silverstripe.org/blob/2a8072f8dc2a1efea1a5e1707c4954d6c338a82c/src/lib/content/sort-files.ts for how it's done in the docs site - see also ModuleManifest::sort()
        // @TODO see https://github.com/silverstripe/doc.silverstripe.org/blob/2a8072f8dc2a1efea1a5e1707c4954d6c338a82c/src/lib/content/slug-generator.ts for slug generation
        if ($this->cache) {
            $data = $this->getState();
            $this->cache->set(static::CACHE_KEY, $data);
        }
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
     * Visit a file to inspect for user help documentation
     */
    public function handleFile(string $fileName, string $filePath, string $basePath, bool $includeTests): void
    {
        // ONLY parse the frontmatter at this stage
        $frontMatterExtension = new FrontMatterExtension();
        $result = $frontMatterExtension->getFrontMatterParser()->parse(file_get_contents($filePath));
        $frontMatter = $result->getFrontMatter();
        $pathFromBase = ltrim(substr($filePath, strlen($basePath)), '/\\');
        $locale = strtok($pathFromBase, '/\\');
        $pathFromLocale = ltrim(substr($pathFromBase, strlen($locale)), '/\\');
        $slug = $this->getSlugForPath($pathFromLocale);

        $data = [
            'frontMatter' => $frontMatter,
            'title' => $frontMatter['title'] ?? $this->filenameToTitle($fileName, $filePath),
            'isIndex' => $fileName === 'index.md',
            'fileName' => $fileName,
            'filePath' => $filePath,
            'module' => ModuleLoader::inst()->getManifest()->getModuleByPath($filePath)?->getName(),
            'locale' => $locale,
            'slug' => $slug,
        ];
        /*
            @TODO We also will need to build a tree so something about sorting out the child hierarchy needs to happen too
            @TODO Using the slug in the key here is stupid because it will cause overrides arbitrarily since the file finder
                  doesn't care about module priority. We need to either plant it somewhere temporary first, or else plant
                  it in a way that we can deal with priority in a separate step.
        */
        $this->docData[$locale][$slug] = $data;
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

    /**
     * Reload state from given cache data
     *
     * @return bool True if cache was valid and successfully loaded
     */
    protected function loadState(array $data): bool // @TODO
    {
        //@TODO delete this if we don't get more complicated.
        // BUT if we do get more complicated check out the method by the same name in ClassManifest.
        $this->docData = $data;
        return true;
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
            $name = dirname($filePath);
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
    { // @TODO remove "index"
        // Strip file extension and validate there's a root
        $dir = pathinfo($path, PATHINFO_DIRNAME);
        $file = pathinfo($path, PATHINFO_FILENAME);
        if ($dir === '.' && $dir === '/' && $dir === '\\') {
            // @TODO throw a wobbly if there's no parent, because we MUST have a root!
            $path = $file;
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
