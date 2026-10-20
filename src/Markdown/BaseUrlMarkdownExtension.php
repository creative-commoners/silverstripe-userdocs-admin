<?php

namespace SilverStripe\UserDocs\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Inline\AbstractWebResource;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Renderer\Block\ListBlockRenderer;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalink;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkRenderer;
use League\CommonMark\Extension\TableOfContents\Node\TableOfContents;
use League\CommonMark\Extension\TableOfContents\TableOfContentsExtension;
use League\CommonMark\Extension\TableOfContents\TableOfContentsRenderer;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\RawMarkupContainerInterface;
use League\CommonMark\Node\StringContainerInterface;
use Locale;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\UserDocs\Admin\UserDocsManifest;
use Symfony\Component\Filesystem\Path;

/**
 * CommonMark extension which changes relative links and image paths in a doc
 * so that they resolve correctly in the CMS.
 */
class BaseUrlMarkdownExtension implements ExtensionInterface
{
    use Injectable;

    private string $baseUrl;

    private array $docData;

    private array $extensionData;

    private string $missingImagePath;

    /**
     * Matches the HTML comment from getExtensionMarker()
     */
    private const EXTENSION_MARKER_PATTERN = '/<!-- UserDocsAdmin__userdocs-extension:(?<index>\S+) -->/';

    /**
     * @param string $baseUrl The URL that doc slugs are relative to
     * @param array $docData Metadata for the doc from UserDocsManifest
     * @param array $extensionData Metadata for docs whose content is appended to this doc
     */
    public function __construct(string $baseUrl, array $docData, array $extensionData = [])
    {
        $this->baseUrl = $baseUrl;
        $this->docData = $docData;
        $this->extensionData = $extensionData;
        $this->missingImagePath = ModuleResourceLoader::resourceURL(
            'silverstripe/userdocs-admin:client/images/missing-image-placeholder.png'
        );
    }

    /**
     * Get the HTML comment to put in the markdown before the content of an extension doc.
     */
    public static function getExtensionMarker(string|int $index): string
    {
        return "<!-- UserDocsAdmin__userdocs-extension:$index -->";
    }

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addEventListener(DocumentParsedEvent::class, [$this, 'onDocumentParsed']);
        if (!($environment instanceof Environment)) {
            return;
        }

        $hasTableOfContents = false;
        $hasPermalinkExtension = false;
        $baseUrl = Controller::join_links($this->baseUrl, $this->docData['slug']);
        foreach ($environment->getExtensions() as $extension) {
            if ($extension instanceof TableOfContentsExtension) {
                $hasTableOfContents = true;
            }
            if ($extension instanceof HeadingPermalinkExtension) {
                $hasPermalinkExtension = true;
            }
        }

        if ($hasTableOfContents) {
            // We can't use $environment->getRenderersForClass() to get the existing table of contents renderer
            // because that will initialise the environment which doesn't allow adding new renderers.
            $innerRenderer = new TableOfContentsRenderer(new ListBlockRenderer());
            $environment->addRenderer(
                TableOfContents::class,
                TableOfContentsWrapper::create($innerRenderer, $baseUrl),
                99999
            );
        }

        if ($hasPermalinkExtension) {
            $innerRenderer = new HeadingPermalinkRenderer();
            $environment->addRenderer(
                HeadingPermalink::class,
                HeadingPermalinkWrapper::create($innerRenderer, $baseUrl),
                99999
            );
        }
    }

    /**
     * Update the URLs of all links and images in the parsed document.
     * Content after an extension marker is resolved relative to that extension's file.
     */
    public function onDocumentParsed(DocumentParsedEvent $event): void
    {
        $document = $event->getDocument();
        $currentDocData = $this->docData;
        $markers = [];

        foreach ($document->iterator() as $node) {
            if ($node instanceof HtmlBlock) {
                $extensionIndex = $this->getextensionIndexFromMarker($node);
                if ($extensionIndex !== null) {
                    if (array_key_exists($extensionIndex, $this->extensionData)) {
                        $currentDocData = $this->extensionData[$extensionIndex];
                    } else {
                        Injector::inst()->get(LoggerInterface::class)->warning(
                            "No extension doc data for marker '$extensionIndex', so the content after it"
                            . ' uses the paths of the content before it.'
                        );
                    }
                    $markers[] = $node;
                    continue;
                }
            }
            if ($node instanceof Link || $node instanceof Image) {
                $this->updateUrl($node, $currentDocData);
                continue;
            }
            $this->warnIfMarkerIsInside($node);
        }

        // Markers are only needed to find where each extension starts, so don't render them
        foreach ($markers as $marker) {
            $marker->detach();
        }
    }

    /**
     * Get the extension index if this node is only an extension marker
     */
    private function getextensionIndexFromMarker(HtmlBlock $node): ?string
    {
        if (!preg_match(BaseUrlMarkdownExtension::EXTENSION_MARKER_PATTERN, $node->getLiteral(), $matches)) {
            return null;
        }
        if (trim($node->getLiteral()) !== $matches[0]) {
            return null;
        }
        return $matches['index'];
    }

    /**
     * Log a warning if an extension marker is part of other content.
     * This happens when content before the marker has an unclosed code block or HTML block,
     * so links and images in that extension are resolved relative to the wrong file.
     */
    private function warnIfMarkerIsInside(Node $node): void
    {
        if (!($node instanceof StringContainerInterface) && !($node instanceof RawMarkupContainerInterface)) {
            return;
        }
        if (!preg_match_all(BaseUrlMarkdownExtension::EXTENSION_MARKER_PATTERN, $node->getLiteral(), $matches)) {
            return;
        }
        foreach ($matches['index'] as $extensionIndex) {
            $filePath = $this->extensionData[$extensionIndex]['filePath'] ?? $extensionIndex;
            Injector::inst()->get(LoggerInterface::class)->warning(
                "Could not find where the content from '$filePath' starts, so its links and images may not work."
                . ' Check the content before it for an unclosed code block or HTML block.'
            );
        }
    }

    private function updateUrl(AbstractWebResource $node, array $docData): void
    {
        $url = $node->getUrl();

        // Skip empty URLs or URLs with a protocol (http://, https://, ftp://, //, mailto:)
        if (empty($url) || preg_match('/^(https?:|ftp:)?\/\/|mailto:/i', $url)) {
            return;
        }

        if ($node instanceof Image) {
            $missingResourcePath = null;
            // Make sure localised docs can use images from fallback locales
            foreach (UserDocsManifest::getLocalePriority() as $locale) {
                $moduleResourcePath = $this->getModuleResourcePath($docData, $locale, $url);
                $imagePath = ModuleResourceLoader::resourcePath($moduleResourcePath);
                if ($imagePath && file_exists(Path::join(BASE_PATH, $imagePath))) {
                    $node->setUrl(ModuleResourceLoader::resourceURL($moduleResourcePath));
                    return;
                }
                if ($locale === $docData['locale']) {
                    $missingResourcePath = $moduleResourcePath;
                }
            }
            $node->setUrl($this->missingImagePath);
            if (!$missingResourcePath) {
                $missingResourcePath = $this->getModuleResourcePath($docData, $docData['locale'], $url);
            }
            Injector::inst()->get(LoggerInterface::class)->warning("Could not find image '$missingResourcePath'");
            return;
        }

        $urlParts = [$this->baseUrl];
        if (!str_starts_with($url, '/')) {
            $urlParts[] = $docData['slug'];
            if (!str_starts_with($url, '#') && !$docData['isIndex']) {
                $urlParts[] = '..';
            }
        }
        $urlParts[] = $url;

        $node->setUrl(Controller::join_links(...$urlParts));
    }

    /**
     * Get the module resource path for a URL relative to the current doc,
     * as if the doc was in the folder for the given locale.
     */
    private function getModuleResourcePath(array $docData, string $locale, string $url): string
    {
        // Note that we _must_ use Symfony's Path class here to resolve path traversal,
        // because otherwise Silverstripe's Path class will throw an exception while
        // resolving the resource path.
        // It's okay to deal with here because at this stage there's no chance of
        // traversing beyond the project root.
        $relativePath = Path::canonicalize(Controller::join_links([
            $docData['rootPathInModule'],
            $locale,
            $docData['pathFromLocale'],
            '..',
            $url,
        ]));
        return $docData['module'] . ':' . $relativePath;
    }
}
