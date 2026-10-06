<?php

namespace SilverStripe\UserDocs\Admin;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Extension\CommonMark\Node\Inline\AbstractWebResource;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use Symfony\Component\Filesystem\Path;

class BaseUrlMarkdownExtension implements ExtensionInterface
{
    use Injectable;

    private string $baseUrl;

    private array $docData;

    private string $missingImagePath;

    public function __construct(string $baseUrl, array $docData)
    {
        $this->baseUrl = $baseUrl;
        $this->docData = $docData;
        $this->missingImagePath = ModuleResourceLoader::resourceURL('silverstripe/userdocs-admin:client/images/missing-image-placeholder.png');
    }

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addEventListener(DocumentParsedEvent::class, [$this, 'onDocumentParsed']);
    }

    public function onDocumentParsed(DocumentParsedEvent $event): void
    {
        $document = $event->getDocument();

        // Find all Link and Image nodes in the document
        foreach ($document->iterator() as $node) {
            if ($node instanceof Link || $node instanceof Image) {
                $this->updateUrl($node);
            }
            // @TODO find HeadingPermalink nodes andfix their anchors if possible
            //       might need a custom renderer for those though, unfortunately.
        }
    }

    private function updateUrl(AbstractWebResource $node): void
    {
        $url = $node->getUrl();

        // Skip empty URLs or URLs with a protocol (http://, https://, ftp://, //, mailto:)
        if (empty($url) || preg_match('/^(https?:|ftp:)?\/\/|mailto:/i', $url)) {
            return;
        }

        if ($node instanceof Image) {
            preg_match(
                '@'
                    . preg_quote($this->docData['module'], '@')
                    . '/(?<middle>.*?)/'
                    . preg_quote($this->docData['locale'], '@')
                    . '/(?<rest>.*)$@',
                $this->docData['filePath'],
                $matches
            );
            // Note that we _must_ use Symfony's Path class here to resolve path traversal,
            // because otherwise Silverstripe's Path class will throw an exception while
            // resolving the resource path.
            // It's okay to deal with here because at this stage there's no chance of
            // traversing beyond the project root.
            $relativePath = Path::canonicalize(Controller::join_links([
                $matches['middle'] ?? '',
                $this->docData['locale'],
                $matches['rest'],
                $this->docData['isIndex'] ? '' : '..',
                $url,
            ]));
            // If the image is missing, use a placeholder. Otherwise, use the correct image.
            $moduleResourcePath = $this->docData['module'] . ':' . $relativePath;
            $imagePath = ModuleResourceLoader::resourcePath($moduleResourcePath);
            if (!$imagePath || !file_exists(Path::join(BASE_PATH, $imagePath))) {
                $node->setUrl($this->missingImagePath);
                Injector::inst()->get(LoggerInterface::class)->warning("Could not find image '$moduleResourcePath'");
            } else {
                $node->setUrl(ModuleResourceLoader::resourceURL($moduleResourcePath));
            }
            return;
        }

        // @TODO Handle the case where the base url tag isn't included in the head!!!
        //       That means ignoring anchors (#), and dealing with relative URLs more appropriately.
        $urlParts = [$this->baseUrl];
        if (!str_starts_with($url, '/')) {
            $urlParts[] = $this->docData['slug'];
            if (!str_starts_with($url, '#') && !$this->docData['isIndex']) {
                $urlParts[] = '..';
            }
        }
        $urlParts[] = $url;

        $node->setUrl(Controller::join_links(...$urlParts));
    }
}
