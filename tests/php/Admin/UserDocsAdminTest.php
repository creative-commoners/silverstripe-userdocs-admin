<?php

namespace SilverStripe\UserDocs\Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Security\Security;
use SilverStripe\UserDocs\Admin\UserDocsAdmin;
use SilverStripe\UserDocs\Admin\UserDocsManifest;
use SilverStripe\UserDocs\Tests\Admin\UserDocsAdminTest\RecordingUserDocsManifest;
use SilverStripe\UserDocs\Tests\ExtensionModulesTrait;
use Symfony\Component\Filesystem\Path;

class UserDocsAdminTest extends FunctionalTest
{
    use ExtensionModulesTrait;

    protected $usesDatabase = true;

    private RecordingUserDocsManifest $manifest;

    protected function setUp(): void
    {
        parent::setUp();
        UserDocsAdmin::config()->set('documentation_roots', [
            'silverstripe/userdocs-admin:tests/php/fixtures/docs' => true,
        ]);
        $this->manifest = new RecordingUserDocsManifest();
        Injector::inst()->registerService($this->manifest, UserDocsManifest::class);
        $this->logInWithPermission('ADMIN');
    }

    protected function tearDown(): void
    {
        $this->popExtensionModules();
        parent::tearDown();
    }

    public function testGetDocRoots(): void
    {
        // The module can be installed in vendor/ or be the project root, so fixture paths can't be hardcoded
        $fixturesPath = dirname(__DIR__) . '/fixtures';
        UserDocsAdmin::config()->set('documentation_roots', [
            // module:path format
            'silverstripe/userdocs-admin:tests/php/fixtures/docs' => true,
            // explicitly excluded
            'silverstripe/userdocs-admin:tests/php/fixtures/no_content' => false,
            // list item with a path relative to the project root
            Path::makeRelative("$fixturesPath/docs_sibling", BASE_PATH),
        ]);

        $this->get('admin/user-docs');

        $this->assertSame([
            "$fixturesPath/docs",
            "$fixturesPath/docs_sibling",
        ], $this->manifest->getValidPaths());
    }

    public function testDocs(): void
    {
        $response = $this->get('admin/user-docs/docs/getting_started/first_page');

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('<h1 id="first-page">First page', $body);
        // Links are rewritten to point at other docs pages within the admin
        $this->assertStringContainsString(
            '<a href="admin/user-docs/docs/getting_started/first_page/../second_page">second page</a>',
            $body
        );
    }

    public static function provideDocsMissingDoc(): array
    {
        return [
            'unknown slug' => [
                'url' => 'admin/user-docs/docs/getting_started/does_not_exist',
            ],
            'page without content' => [
                'url' => 'admin/user-docs/docs/title_only',
            ],
        ];
    }

    #[DataProvider('provideDocsMissingDoc')]
    public function testDocsMissingDoc(string $url): void
    {
        $response = $this->get($url);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDocsWithExtension(): void
    {
        $this->useExtensionModules(['one', 'two']);

        $response = $this->get('admin/user-docs/docs/section/page');

        $this->assertSame(200, $response->getStatusCode());
        $docsHtml = $this->getDocsHtml($response->getBody());
        // Extension content is added after the original content
        $originalPosition = strpos($docsHtml, 'Original content');
        $this->assertNotFalse($originalPosition);
        $this->assertGreaterThan($originalPosition, strpos($docsHtml, 'Module two content'));
        // Markers and the extension's frontmatter aren't rendered
        $this->assertStringNotContainsString('UserDocsAdmin__userdocs-extension', $response->getBody());
        $this->assertStringNotContainsString('extends: true', $docsHtml);
        $this->assertStringNotContainsString('<hr', $docsHtml);
        // Images in the extension are relative to the extension's file
        $this->assertStringContainsString(
            'src="' . ModuleResourceLoader::resourceURL('test/module-two:docs/en/01_section/images/extension.png'),
            $docsHtml
        );
    }

    public function testDocsWithExtensionHeadings(): void
    {
        $this->useExtensionModules(['one', 'two']);

        $docsHtml = $this->getDocsHtml($this->get('admin/user-docs/docs/section/page')->getBody());

        // There is one table of contents
        $this->assertSame(1, substr_count($docsHtml, 'class="toc-container"'));
        preg_match('#<nav class="toc-container".*?</nav>#s', $docsHtml, $tableOfContents);
        // The original and the extension both have a "Notes" heading, but each needs its own ID
        $contentHtml = str_replace($tableOfContents[0], '', $docsHtml);
        preg_match_all('#<h2 id="([^"]+)"#', $contentHtml, $matches);
        $headingIds = $matches[1];
        $this->assertCount(2, $headingIds);
        $this->assertSame($headingIds, array_values(array_unique($headingIds)));
        // The table of contents includes the headings from the extension
        foreach ($headingIds as $headingId) {
            $this->assertStringContainsString("#$headingId\"", $tableOfContents[0]);
        }
    }

    public static function provideDocsWithExtensionKey(): array
    {
        return [
            'overridden extension' => [
                'slug' => 'section/overridden',
                'expectedContent' => ['Original content.', 'Module three override.'],
                'unexpectedContent' => ['Module two extension.'],
            ],
            'removed extension' => [
                'slug' => 'section/removed',
                'expectedContent' => ['Original content.'],
                'unexpectedContent' => ['Module two removed content.'],
            ],
            'key that isn\'t a module name' => [
                'slug' => 'section/invalid_key',
                'expectedContent' => ['Original content.'],
                'unexpectedContent' => ['Invalid key content.'],
            ],
            'extension of a module that isn\'t installed' => [
                'slug' => 'section/uninstalled',
                'expectedContent' => ['Original content.'],
                'unexpectedContent' => ['Uninstalled module content.'],
            ],
        ];
    }

    #[DataProvider('provideDocsWithExtensionKey')]
    public function testDocsWithExtensionKey(string $slug, array $expectedContent, array $unexpectedContent): void
    {
        $this->useExtensionModules(['three', 'two', 'one']);

        $response = $this->get("admin/user-docs/docs/$slug");

        $this->assertSame(200, $response->getStatusCode());
        $docsHtml = $this->getDocsHtml($response->getBody());
        foreach ($expectedContent as $content) {
            $this->assertStringContainsString($content, $docsHtml);
        }
        foreach ($unexpectedContent as $content) {
            $this->assertStringNotContainsString($content, $docsHtml);
        }
    }

    public function testDocsWithFrontMatterOnlyExtension(): void
    {
        $this->useExtensionModules(['one', 'two']);

        $response = $this->get('admin/user-docs/docs/section/frontmatter_only_extension');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, substr_count($this->getDocsHtml($response->getBody()), 'Original content'));
    }

    public function testDocsWithExtensionWithoutOriginal(): void
    {
        $this->useExtensionModules(['two', 'one']);

        $response = $this->get('admin/user-docs/docs/section/extension_only');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, substr_count($this->getDocsHtml($response->getBody()), 'Extension only content'));
    }

    public static function provideDocsWithExtensionOfMissingDoc(): array
    {
        return [
            'original without content' => [
                'slug' => 'section/empty_original',
            ],
            'extension without content and without an original' => [
                'slug' => 'section/empty_extension_only',
            ],
        ];
    }

    #[DataProvider('provideDocsWithExtensionOfMissingDoc')]
    public function testDocsWithExtensionOfMissingDoc(string $slug): void
    {
        $this->useExtensionModules(['one', 'two']);

        $response = $this->get("admin/user-docs/docs/$slug");

        // The doc exists, but extensions don't replace a main doc that has no content
        $this->assertArrayHasKey($slug, $this->manifest->getLocalisedDocData());
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDocsWithEncodedSlug(): void
    {
        $this->useLocalisationFixtures();

        $response = $this->get('admin/user-docs/docs/section/' . rawurlencode('été_indien'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('>Été indien<', $response->getBody());
    }

    public static function provideGetCurrentDocLang(): array
    {
        return [
            'translated doc' => [
                'url' => 'admin/user-docs/docs/section/first',
                'expectedLang' => 'fr',
            ],
            'doc without a translation' => [
                'url' => 'admin/user-docs/docs/section/second',
                'expectedLang' => 'en',
            ],
        ];
    }

    #[DataProvider('provideGetCurrentDocLang')]
    public function testGetCurrentDocLang(string $url, string $expectedLang): void
    {
        $this->useLocalisationFixtures();
        $member = Security::getCurrentUser();
        $member->Locale = 'fr_FR';
        $member->write();

        $response = $this->get($url);

        // The docs container has the lang attribute, whatever its other classes and attributes
        $this->assertMatchesRegularExpression(
            '#<div\b(?=[^>]*\sclass="(?:[^"]*\s)?docs-shadow-root[\s"])(?=[^>]*\slang="' . $expectedLang . '")[^>]*>#',
            $response->getBody()
        );
    }

    public function testTreeviewWithCurrentPage(): void
    {
        // Slashes in the slug are sent as __SLASH__
        $response = $this->get('admin/user-docs/treeview/getting_started__SLASH__nested_section__SLASH__child_page');

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertSame(1, substr_count($body, 'aria-current="page"'));
        $this->assertMatchesRegularExpression($this->openingTagPattern('a', [
            'href' => 'admin/user-docs/docs/getting_started/nested_section/child_page',
            'aria-current' => 'page',
            'tabindex' => '0',
        ]), $body);
    }

    public function testTreeviewWithoutCurrentPage(): void
    {
        $response = $this->get('admin/user-docs/treeview');

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringNotContainsString('aria-current="page"', $body);
        $this->assertSame(1, substr_count($body, 'tabindex="0"'));
        $this->assertMatchesRegularExpression($this->openingTagPattern('a', [
            'href' => 'admin/user-docs/docs/getting_started',
            'tabindex' => '0',
        ]), $body);
    }

    public function testTreeviewRootWithoutIndex(): void
    {
        $response = $this->get('admin/user-docs/treeview');

        $body = $response->getBody();
        $this->assertMatchesRegularExpression('#<strong\b[^>]*>No index</strong>#', $body);
        $this->assertDoesNotMatchRegularExpression(
            $this->openingTagPattern('a', ['href' => 'admin/user-docs/docs/no_index']),
            $body
        );
    }

    public static function provideGetCurrentDocSlugAsId(): array
    {
        return [
            'no slug' => [
                'currentSlug' => null,
                'slug' => null,
                'expected' => null,
            ],
            'current slug' => [
                'currentSlug' => 'getting_started/nested_section/child_page',
                'slug' => null,
                'expected' => 'getting_started__SLASH__nested_section__SLASH__child_page',
            ],
            'explicit slug overrides current slug' => [
                'currentSlug' => 'getting_started',
                'slug' => 'title_only/page',
                'expected' => 'title_only__SLASH__page',
            ],
        ];
    }

    #[DataProvider('provideGetCurrentDocSlugAsId')]
    public function testGetCurrentDocSlugAsId(?string $currentSlug, ?string $slug, ?string $expected): void
    {
        $admin = (new UserDocsAdmin())->setCurrentDocSlug($currentSlug);

        $this->assertSame($expected, $admin->getCurrentDocSlugAsId($slug));
    }

    private function useLocalisationFixtures(): void
    {
        UserDocsAdmin::config()->set('documentation_roots', [
            'silverstripe/userdocs-admin:tests/php/fixtures/localisation' => true,
        ]);
    }

    /**
     * Get a regex that matches an opening tag with these attribute values.
     * The attributes can be in any order, and the tag can have other attributes.
     */
    private function openingTagPattern(string $tag, array $attributes): string
    {
        $lookaheads = '';
        foreach ($attributes as $name => $value) {
            $lookaheads .= '(?=[^>]*\s' . preg_quote($name, '/') . '="' . preg_quote($value, '/') . '")';
        }
        return '/<' . preg_quote($tag, '/') . '\b' . $lookaheads . '[^>]*>/';
    }

    /**
     * Register the fixture modules in priority order (highest first), and use their docs
     */
    private function useExtensionModules(array $moduleNames): void
    {
        $this->pushExtensionModules($moduleNames);
        $roots = [];
        foreach ($moduleNames as $moduleName) {
            $roots["test/module-$moduleName:docs"] = true;
        }
        UserDocsAdmin::config()->set('documentation_roots', $roots);
    }

    /**
     * Get the rendered docs from inside the shadow DOM template of a docs page
     */
    private function getDocsHtml(string $body): string
    {
        preg_match('#<template shadowrootmode="open">(.*)</template>#s', $body, $matches);
        return $matches[1] ?? '';
    }
}
