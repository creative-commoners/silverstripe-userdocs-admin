<?php

namespace SilverStripe\UserDocs\Tests\Search\SearchIndexTest;

use SilverStripe\Dev\TestOnly;
use SilverStripe\UserDocs\Admin\UserDocsManifest;

/**
 * Provides doc data for the markdown files in this directory without scanning the project.
 */
class StubUserDocsManifest extends UserDocsManifest implements TestOnly
{
    /**
     * @param array $extraDocData Doc data for more pages, keyed by locale and then by slug
     */
    public function __construct(private array $extraDocData = [])
    {
    }

    public function getDocData(?string $locale = null): array
    {
        $titles = [
            'formatting' => 'Formatting',
            'loops' => 'Loops',
            'other' => 'Miscellaneous',
            'template_syntax' => 'Template syntax',
        ];
        $docData = [];
        foreach ($titles as $slug => $title) {
            $docData[$slug] = [
                'title' => $title,
                'hasContent' => true,
                'filePath' => __DIR__ . "/$slug.md",
                'slug' => $slug,
            ];
        }
        $docData = array_replace_recursive(['en' => $docData], $this->extraDocData);
        if ($locale !== null) {
            return $docData[$locale] ?? [];
        }
        return $docData;
    }
}
