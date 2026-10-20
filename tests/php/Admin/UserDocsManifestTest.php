<?php

namespace SilverStripe\UserDocs\Tests\Admin;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\Module;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Core\Manifest\ModuleManifest;
use SilverStripe\Core\Path;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\UserDocs\Admin\UserDocsManifest;
use SilverStripe\UserDocs\Tests\ExtensionModulesTrait;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

class UserDocsManifestTest extends SapphireTest
{
    use ExtensionModulesTrait;

    /**
     * Manifest built from the main fixture root. Building it scans the whole project,
     * so it's only done once and shared by tests that don't change how it's built.
     */
    private static ?UserDocsManifest $docsManifest = null;

    private static ?UserDocsManifest $localisationManifest = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Keep the real site's manifest cache out of it
        Injector::inst()->registerService(
            new Psr16Cache(new ArrayAdapter()),
            CacheInterface::class . '.userdocsmanifest'
        );
        i18n::config()->set('default_locale', 'en_US');
        i18n::set_locale('en_US');
    }

    protected function tearDown(): void
    {
        $this->popExtensionModules();
        parent::tearDown();
    }

    public function testHandleFile(): void
    {
        $docData = $this->getDocsManifest()->getState()['en'];
        $fixturePath = $this->fixturePath('docs/en/01_getting_started');

        $this->assertSame([
            'order' => 1,
            'frontMatter' => ['title' => 'Start here'],
            'title' => 'Start here',
            'isExtension' => false,
            'extensionKey' => null,
            'isIndex' => true,
            'hasContent' => true,
            'fileName' => 'index.md',
            'filePath' => "$fixturePath/index.md",
            'module' => 'silverstripe/userdocs-admin',
            'locale' => 'en',
            'slug' => 'getting_started',
            'parentSlug' => '.',
            'rootPathInModule' => 'tests/php/fixtures/docs',
            'pathFromLocale' => '01_getting_started/index.md',
            'extensions' => [],
        ], $docData['getting_started']);

        $this->assertSame([
            'order' => 1,
            'frontMatter' => null,
            'title' => 'First page',
            'isExtension' => false,
            'extensionKey' => null,
            'isIndex' => false,
            'hasContent' => true,
            'fileName' => '01_first_page.md',
            'filePath' => "$fixturePath/01_first_page.md",
            'module' => 'silverstripe/userdocs-admin',
            'locale' => 'en',
            'slug' => 'getting_started/first_page',
            'parentSlug' => 'getting_started',
            'rootPathInModule' => 'tests/php/fixtures/docs',
            'pathFromLocale' => '01_getting_started/01_first_page.md',
            'extensions' => [],
        ], $docData['getting_started/first_page']);

        $this->assertSame([
            'order' => 3,
            'frontMatter' => null,
            'title' => 'Nested section',
            'isExtension' => false,
            'extensionKey' => null,
            'isIndex' => true,
            'hasContent' => true,
            'fileName' => 'index.md',
            'filePath' => "$fixturePath/03_nested_section/index.md",
            'module' => 'silverstripe/userdocs-admin',
            'locale' => 'en',
            'slug' => 'getting_started/nested_section',
            'parentSlug' => 'getting_started',
            'rootPathInModule' => 'tests/php/fixtures/docs',
            'pathFromLocale' => '01_getting_started/03_nested_section/index.md',
            'extensions' => [],
        ], $docData['getting_started/nested_section']);
    }

    public function testHandleFileWithRootPathNamedLikeLocale(): void
    {
        $data = [];
        (new UserDocsManifest())->handleFile(
            $data,
            '01_page.md',
            $this->fixturePath('locale_name_in_root/fr/userhelp/en/01_section/01_page.md'),
            $this->fixturePath('locale_name_in_root/fr/userhelp')
        );

        $docDatum = $data['silverstripe/userdocs-admin']['en']['section/page'];
        $this->assertSame('tests/php/fixtures/locale_name_in_root/fr/userhelp', $docDatum['rootPathInModule']);
        $this->assertSame('01_section/01_page.md', $docDatum['pathFromLocale']);
    }

    public function testHandleFileInModuleWithDifferentPath(): void
    {
        // Like a module that is the project root, which is how CI runs this module's tests
        $manifest = new ModuleManifest(BASE_PATH);
        $manifest->addModule($this->fixturePath('renamed_module'));
        ModuleLoader::inst()->pushManifest($manifest);
        $data = [];
        try {
            (new UserDocsManifest())->handleFile(
                $data,
                '01_page.md',
                $this->fixturePath('renamed_module/docs/en/01_section/01_page.md'),
                $this->fixturePath('renamed_module/docs')
            );
        } finally {
            ModuleLoader::inst()->popManifest();
        }

        $docDatum = $data['test/renamed-module']['en']['section/page'];
        $this->assertSame('docs', $docDatum['rootPathInModule']);
        $this->assertSame('01_section/01_page.md', $docDatum['pathFromLocale']);
    }

    public static function provideHandleFileExtends(): array
    {
        return [
            'boolean true uses the module as the key' => [
                'fileName' => 'bool_true.md',
                'expectedIsExtension' => true,
                'expectedKey' => 'silverstripe/userdocs-admin',
            ],
            'boolean false' => [
                'fileName' => 'bool_false.md',
                'expectedIsExtension' => false,
                'expectedKey' => null,
            ],
            'no extends key' => [
                'fileName' => 'missing.md',
                'expectedIsExtension' => false,
                'expectedKey' => null,
            ],
            'module name' => [
                'fileName' => 'module_name.md',
                'expectedIsExtension' => true,
                'expectedKey' => 'test/module-two',
            ],
            // Strings are keys, even if they look like booleans.
            // Keys that aren't installed modules are dropped when the manifest is built.
            'string "true"' => [
                'fileName' => 'string_true.md',
                'expectedIsExtension' => true,
                'expectedKey' => 'true',
            ],
            'empty string' => [
                'fileName' => 'empty_string.md',
                'expectedIsExtension' => true,
                'expectedKey' => '',
            ],
            'number' => [
                'fileName' => 'number.md',
                'expectedIsExtension' => true,
                'expectedKey' => '1',
            ],
            'not a number' => [
                'fileName' => 'not_a_number.md',
                'expectedIsExtension' => true,
                'expectedKey' => 'NAN',
            ],
            // The frontmatter parser reads "yes" as a boolean
            'yes' => [
                'fileName' => 'yes.md',
                'expectedIsExtension' => true,
                'expectedKey' => 'silverstripe/userdocs-admin',
            ],
        ];
    }

    #[DataProvider('provideHandleFileExtends')]
    public function testHandleFileExtends(string $fileName, bool $expectedIsExtension, ?string $expectedKey): void
    {
        $data = [];
        UserDocsManifest::create()->handleFile(
            $data,
            $fileName,
            $this->fixturePath("extends_values/en/01_section/$fileName"),
            $this->fixturePath('extends_values')
        );

        $docDatum = $data['silverstripe/userdocs-admin']['en']['section/' . basename($fileName, '.md')];
        $this->assertSame($expectedIsExtension, $docDatum['isExtension']);
        $this->assertSame($expectedKey, $docDatum['extensionKey']);
    }

    public function testHandleFileWithFrontMatterOnly(): void
    {
        $docData = $this->getDocsManifest()->getState()['en'];

        $this->assertSame('Title only', $docData['title_only']['title']);
        $this->assertFalse($docData['title_only']['hasContent']);
    }

    public function testFileNameToTitle(): void
    {
        $titles = array_column($this->getDocsManifest()->getState()['fr'], 'title', 'fileName');

        $this->assertSame('Étapes suivantes', $titles['03_étapes_suivantes.md']);
        $this->assertSame('Été indien', $titles['04_ÉTÉ_INDIEN.md']);
    }

    public static function provideGetSlugForPath(): array
    {
        return [
            'file' => [
                'path' => '01_section/02_page.md',
                'isRootDir' => false,
                'expected' => 'section/page',
            ],
            'index file' => [
                'path' => '01_section/03_sub_section/index.md',
                'isRootDir' => false,
                'expected' => 'section/sub_section',
            ],
            'file without numeric prefixes' => [
                'path' => 'section/page.md',
                'isRootDir' => false,
                'expected' => 'section/page',
            ],
            'non-ASCII capitals are lowercased' => [
                'path' => '01_getting_started/04_ÉTÉ_INDIEN.md',
                'isRootDir' => false,
                'expected' => 'getting_started/été_indien',
            ],
            'file without a directory' => [
                'path' => '02_page.md',
                'isRootDir' => false,
                'expected' => 'page',
            ],
            'root directory' => [
                'path' => '01_section',
                'isRootDir' => true,
                'expected' => 'section',
            ],
        ];
    }

    #[DataProvider('provideGetSlugForPath')]
    public function testGetSlugForPath(string $path, bool $isRootDir, string $expected): void
    {
        $this->assertSame($expected, UserDocsManifest::create()->getSlugForPath($path, $isRootDir));
    }

    public function testRegenerateScansOnlyRegisteredRoot(): void
    {
        // fixtures/docs_sibling shares a prefix with fixtures/docs but isn't registered,
        // so its files must not show up (e.g. as a bogus "_sibling" locale)
        $this->assertEqualsCanonicalizing(['en', 'fr', 'fr_CA'], array_keys($this->getDocsManifest()->getState()));
    }

    public function testGetLocalisedTreeDataRootOrder(): void
    {
        $tree = $this->getDocsManifest()->getLocalisedTreeData();

        $this->assertSame(['getting_started', 'no_index', 'title_only'], array_keys($tree));
    }

    public function testGetLocalisedTreeData(): void
    {
        $tree = $this->getDocsManifest()->getLocalisedTreeData();

        // Numeric prefixes first (folders before files when equal), then unprefixed by title
        $this->assertSame([
            'slug' => 'getting_started',
            'title' => 'Start here',
            'isIndex' => true,
            'hasContent' => true,
            'children' => [
                'first_page' => [
                    'slug' => 'getting_started/first_page',
                    'title' => 'First page',
                    'isIndex' => false,
                    'hasContent' => true,
                ],
                'second_page' => [
                    'slug' => 'getting_started/second_page',
                    'title' => 'Second page',
                    'isIndex' => false,
                    'hasContent' => true,
                ],
                'nested_section' => [
                    'slug' => 'getting_started/nested_section',
                    'title' => 'Nested section',
                    'isIndex' => true,
                    'hasContent' => true,
                    'children' => [
                        'child_page' => [
                            'slug' => 'getting_started/nested_section/child_page',
                            'title' => 'Child page',
                            'isIndex' => false,
                            'hasContent' => true,
                        ],
                    ],
                ],
                'zebra' => [
                    'slug' => 'getting_started/zebra',
                    'title' => 'Aardvark',
                    'isIndex' => false,
                    'hasContent' => true,
                ],
                'apple' => [
                    'slug' => 'getting_started/apple',
                    'title' => 'Apple',
                    'isIndex' => false,
                    'hasContent' => true,
                ],
            ],
        ], $tree['getting_started']);
    }

    public static function provideGetLocalisedTreeDataWithExtensionTitle(): array
    {
        return [
            'extension in higher priority module' => [
                'moduleNames' => ['two', 'one'],
                'expectedTitle' => 'Extended page',
            ],
            'extension in lower priority module' => [
                'moduleNames' => ['one', 'two'],
                'expectedTitle' => 'Page',
            ],
        ];
    }

    #[DataProvider('provideGetLocalisedTreeDataWithExtensionTitle')]
    public function testGetLocalisedTreeDataWithExtensionTitle(array $moduleNames, string $expectedTitle): void
    {
        $tree = $this->regenerateWithExtensionModules($moduleNames)->getLocalisedTreeData();

        $this->assertSame($expectedTitle, $tree['section']['children']['page']['title']);
    }

    public function testGetLocalisedTreeDataWithTranslatedExtensionTitle(): void
    {
        i18n::set_locale('fr_FR');

        $tree = $this->regenerateWithExtensionModules(['two', 'one'])->getLocalisedTreeData();

        // Module two's French extension comes after module one's page, so its title is used
        $this->assertSame('Page étendue', $tree['section']['children']['page']['title']);
    }

    public function testGetLocalisedTreeDataRootWithoutIndex(): void
    {
        $root = $this->getDocsManifest()->getLocalisedTreeData()['no_index'];

        $this->assertSame('no_index', $root['slug'] ?? null);
        $this->assertSame('No index', $root['title'] ?? null);
        $this->assertFalse($root['hasContent'] ?? null);
        $this->assertSame(['some_page'], array_keys($root['children']));
    }

    public static function provideGetLocalisedDocData(): array
    {
        return [
            'default locale' => [
                'locale' => 'en_US',
                'defaultLocale' => 'en_US',
                'expectedRootTitle' => 'Start here',
                'expectedSecondPageTitle' => 'Second page',
            ],
            'falls back to primary language' => [
                'locale' => 'fr_FR',
                'defaultLocale' => 'en_US',
                'expectedRootTitle' => 'Commencer',
                'expectedSecondPageTitle' => 'Deuxième page',
            ],
            'full locale overrides primary language' => [
                'locale' => 'fr_CA',
                'defaultLocale' => 'en_US',
                'expectedRootTitle' => 'Commencer (Canada)',
                'expectedSecondPageTitle' => 'Deuxième page',
            ],
            'falls back to default locale language' => [
                'locale' => 'de_DE',
                'defaultLocale' => 'fr_FR',
                'expectedRootTitle' => 'Commencer',
                'expectedSecondPageTitle' => 'Deuxième page',
            ],
            'falls back to full default locale' => [
                'locale' => 'de_DE',
                'defaultLocale' => 'fr_CA',
                'expectedRootTitle' => 'Commencer (Canada)',
                'expectedSecondPageTitle' => 'Deuxième page',
            ],
            'english user locale overrides non-english default locale' => [
                'locale' => 'en_NZ',
                'defaultLocale' => 'fr_FR',
                'expectedRootTitle' => 'Start here',
                'expectedSecondPageTitle' => 'Second page',
            ],
            'user locale language overrides full default locale' => [
                'locale' => 'fr_FR',
                'defaultLocale' => 'fr_CA',
                'expectedRootTitle' => 'Commencer',
                'expectedSecondPageTitle' => 'Deuxième page',
            ],
        ];
    }

    #[DataProvider('provideGetLocalisedDocData')]
    public function testGetLocalisedDocData(
        string $locale,
        string $defaultLocale,
        string $expectedRootTitle,
        string $expectedSecondPageTitle
    ): void {
        i18n::config()->set('default_locale', $defaultLocale);
        i18n::set_locale($locale);

        $docData = $this->getDocsManifest()->getLocalisedDocData();

        $this->assertSame($expectedRootTitle, $docData['getting_started']['title']);
        $this->assertSame($expectedSecondPageTitle, $docData['getting_started/second_page']['title']);
        // Pages with no translation always fall back to English
        $this->assertSame('First page', $docData['getting_started/first_page']['title']);
    }

    public function testGetLocalisedDocDataWithoutDocs(): void
    {
        // No documentation roots is the default configuration
        $manifest = new UserDocsManifest();
        $manifest->regenerate([], false);

        $this->assertSame([], $manifest->getLocalisedDocData());
        $this->assertSame([], $manifest->getLocalisedTreeData());
    }

    public function testGetLocalisedDocDataWithTranslationWithoutRootIndex(): void
    {
        i18n::set_locale('fr_FR');
        $manifest = $this->getLocalisationManifest();

        // fr/01_section has pages but no index.md, so only en/01_section/index.md describes the root
        $docData = $manifest->getLocalisedDocData()['section'];
        $this->assertSame('Section overview', $docData['title']);
        $this->assertTrue($docData['hasContent']);
        $treeNode = $manifest->getLocalisedTreeData()['section'];
        $this->assertSame('Section overview', $treeNode['title']);
        $this->assertTrue($treeNode['hasContent']);
    }

    public static function provideRegenerateWithExtension(): array
    {
        return [
            'extension in higher priority module' => [
                'moduleNames' => ['two', 'one'],
            ],
            'extension in lower priority module' => [
                'moduleNames' => ['one', 'two'],
            ],
        ];
    }

    #[DataProvider('provideRegenerateWithExtension')]
    public function testRegenerateWithExtension(array $moduleNames): void
    {
        $docDatum = $this->regenerateWithExtensionModules($moduleNames)->getState()['en']['section/page'];

        // The original stays the main doc whichever module has the higher priority
        $this->assertSame($this->extensionDocPath('one', '01_page.md'), $docDatum['filePath']);
        $this->assertSame(
            [$this->extensionDocPath('two', '01_page.md')],
            array_column($docDatum['extensions'], 'filePath')
        );
    }

    public function testRegenerateWithMultipleExtensions(): void
    {
        $docDatum = $this->regenerateWithExtensionModules(['two', 'three', 'one'])->getState()['en']['section/page'];

        // Extensions are in the reverse of the ModuleManifest order, i.e. lowest priority first
        $extensionModules = ['test/module-three', 'test/module-two'];
        $manifestOrder = array_map(
            fn (Module $module) => $module->getName(),
            array_values(ModuleLoader::inst()->getManifest()->getModules())
        );
        $expectedOrder = array_values(array_reverse(array_intersect($manifestOrder, $extensionModules)));
        $this->assertSame($expectedOrder, array_column($docDatum['extensions'], 'module'));
    }

    public function testRegenerateWithExtensionWithoutOriginal(): void
    {
        $docDatum = $this->regenerateWithExtensionModules(['two', 'one'])->getState()['en']['section/extension_only'];

        // The extension is shown as the page, and is listed once
        $this->assertSame($this->extensionDocPath('two', '03_extension_only.md'), $docDatum['filePath']);
        $this->assertSame(
            [$this->extensionDocPath('two', '03_extension_only.md')],
            array_column($docDatum['extensions'], 'filePath')
        );
    }

    public function testRegenerateWithOverrideAfterExtension(): void
    {
        $docDatum = $this->regenerateWithExtensionModules(['four', 'two', 'one'])->getState()['en']['section/page'];

        // Module four replaces the original, but extensions from other modules are kept
        $this->assertSame($this->extensionDocPath('four', '01_page.md'), $docDatum['filePath']);
        $this->assertSame(
            [$this->extensionDocPath('two', '01_page.md')],
            array_column($docDatum['extensions'], 'filePath')
        );
    }

    public function testRegenerateWithOverriddenExtension(): void
    {
        $docDatum = $this->regenerateWithExtensionModules(['three', 'two', 'one'])
            ->getState()['en']['section/overridden'];

        // Module three's file replaces module two's extension
        $this->assertSame(['test/module-two'], array_keys($docDatum['extensions']));
        $this->assertSame(
            $this->extensionDocPath('three', '06_overridden.md'),
            $docDatum['extensions']['test/module-two']['filePath']
        );
    }

    public function testRegenerateWithOverriddenExtensionFromLowerPriorityModule(): void
    {
        $docDatum = $this->regenerateWithExtensionModules(['two', 'three', 'one'])
            ->getState()['en']['section/overridden'];

        // Module three has a lower priority than module two, so it can't replace module two's extension
        $this->assertSame(
            $this->extensionDocPath('two', '06_overridden.md'),
            $docDatum['extensions']['test/module-two']['filePath']
        );
    }

    public function testRegenerateWithRemovedExtension(): void
    {
        $docDatum = $this->regenerateWithExtensionModules(['three', 'two', 'one'])->getState()['en']['section/removed'];

        // An override without content takes the place of the extension, so there's nothing to show
        $extension = $docDatum['extensions']['test/module-two'];
        $this->assertSame($this->extensionDocPath('three', '07_removed.md'), $extension['filePath']);
        $this->assertFalse($extension['hasContent']);
    }

    public function testRegenerateWithExtensionKeyOrder(): void
    {
        $docDatum = $this->regenerateWithExtensionModules(['three', 'four', 'two', 'one'])
            ->getState()['en']['section/overridden'];

        // Module three's override is ordered as module two's extension, so it comes before module four's
        $this->assertSame(['test/module-two', 'test/module-four'], array_keys($docDatum['extensions']));
    }

    public static function provideRegenerateWithUnusedExtensionKey(): array
    {
        return [
            'module that isn\'t installed' => [
                'slug' => 'section/uninstalled',
                'fileName' => '09_uninstalled.md',
                'expectWarning' => false,
                'expectedTitle' => 'Uninstalled',
            ],
            'not a module name' => [
                'slug' => 'section/invalid_key',
                'fileName' => '08_invalid_key.md',
                'expectWarning' => true,
                'expectedTitle' => 'Invalid key',
            ],
        ];
    }

    #[DataProvider('provideRegenerateWithUnusedExtensionKey')]
    public function testRegenerateWithUnusedExtensionKey(
        string $slug,
        string $fileName,
        bool $expectWarning,
        string $expectedTitle
    ): void {
        $warnings = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message) use (&$warnings) {
            $warnings[] = $message;
        });
        Injector::inst()->registerService($logger, LoggerInterface::class);

        $docDatum = $this->regenerateWithExtensionModules(['two', 'one'])->getState()['en'][$slug];

        // The extension isn't used, but only a value that isn't a module name is reported
        $this->assertSame([], $docDatum['extensions']);
        $this->assertSame($expectedTitle, $docDatum['title']);
        $filePath = $this->extensionDocPath('two', $fileName);
        $this->assertSame(
            $expectWarning,
            in_array(
                "Documentation at $filePath has \"extends: my custom key\", which isn't a module name",
                $warnings
            )
        );
    }

    public function testRegenerateWithUnusedExtensionOnly(): void
    {
        $state = $this->regenerateWithExtensionModules(['two', 'one'])->getState();

        $this->assertArrayNotHasKey('section/unused_extension_only', $state['en']);
    }

    public function testGetLocalisedDocDataWithTranslatedExtension(): void
    {
        i18n::set_locale('fr_FR');

        $docDatum = $this->regenerateWithExtensionModules(['three', 'two', 'one'])
            ->getLocalisedDocData()['section/page'];

        // The page and module two's extension are translated, but module three's extension falls back to English
        $this->assertSame($this->extensionDocPath('one', '01_page.md', 'fr'), $docDatum['filePath']);
        $this->assertSame(
            [
                'test/module-two' => $this->extensionDocPath('two', '01_page.md', 'fr'),
                'test/module-three' => $this->extensionDocPath('three', '01_page.md'),
            ],
            array_map(fn (array $extension) => $extension['filePath'], $docDatum['extensions'])
        );
    }

    public function testGetLocalisedDocDataWithExtensionTranslatedByAnotherModule(): void
    {
        i18n::set_locale('fr_FR');

        $docDatum = $this->regenerateWithExtensionModules(['four', 'three', 'two', 'one'])
            ->getLocalisedDocData()['section/page'];

        // Module four has a French translation of module three's extension
        $this->assertSame(
            $this->extensionDocPath('four', '01_page.md', 'fr'),
            $docDatum['extensions']['test/module-three']['filePath']
        );
    }

    public function testRegenerateWithFileDirectlyUnderLocale(): void
    {
        $manifest = new UserDocsManifest();
        $fixturePath = $this->fixturePath('invalid');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'User documentation files must have at least one directory between the locale and file.'
        );

        $manifest->regenerate([$fixturePath], true);
    }

    public function testRegenerateWithPageWithoutContent(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Documentation at ' . $this->fixturePath('no_content/en/section/empty.md') . ' has no content');
        Injector::inst()->registerService($logger, LoggerInterface::class);

        (new UserDocsManifest())->regenerate([$this->fixturePath('no_content')], true);
    }

    public function testInitReusesCache(): void
    {
        (new UserDocsManifest())->init([$this->fixturePath('docs_sibling')], true);

        // The cached manifest is used even though different paths are passed in
        $manifest = new UserDocsManifest();
        $manifest->init([$this->fixturePath('docs')], true);

        $this->assertSame(['en'], array_keys($manifest->getState()));
        $this->assertSame(['sibling_section', 'sibling_section/page'], array_keys($manifest->getState()['en']));
    }

    public function testFlush(): void
    {
        (new UserDocsManifest())->init([$this->fixturePath('docs_sibling')], true);

        UserDocsManifest::flush();
        $manifest = new UserDocsManifest();
        $manifest->init([$this->fixturePath('docs')], true);

        $this->assertArrayHasKey('getting_started', $manifest->getState()['en']);
    }

    public function testInitWithInvalidCache(): void
    {
        Injector::inst()->get(CacheInterface::class . '.userdocsmanifest')->set('manifest', ['docData' => 'invalid']);

        $manifest = new UserDocsManifest();
        $manifest->init([$this->fixturePath('docs')], true);

        $this->assertArrayHasKey('getting_started', $manifest->getState()['en']);
    }

    private function getDocsManifest(): UserDocsManifest
    {
        if (!static::$docsManifest) {
            static::$docsManifest = new UserDocsManifest();
            static::$docsManifest->regenerate([$this->fixturePath('docs')], true);
        }
        return static::$docsManifest;
    }

    private function getLocalisationManifest(): UserDocsManifest
    {
        if (!static::$localisationManifest) {
            static::$localisationManifest = new UserDocsManifest();
            static::$localisationManifest->regenerate([$this->fixturePath('localisation')], true);
        }
        return static::$localisationManifest;
    }

    /**
     * Build a manifest from the docs in the fixture modules, in priority order (highest first)
     */
    private function regenerateWithExtensionModules(array $moduleNames): UserDocsManifest
    {
        $this->pushExtensionModules($moduleNames);
        $manifest = UserDocsManifest::create();
        $manifest->regenerate(
            array_map(fn (string $moduleName) => $this->getExtensionModulePath($moduleName) . '/docs', $moduleNames),
            true
        );
        return $manifest;
    }

    private function extensionDocPath(string $moduleName, string $fileName, string $locale = 'en'): string
    {
        return $this->getExtensionModulePath($moduleName) . "/docs/$locale/01_section/$fileName";
    }

    private function fixturePath(string $path): string
    {
        return Path::join(dirname(__DIR__), 'fixtures', $path);
    }
}
