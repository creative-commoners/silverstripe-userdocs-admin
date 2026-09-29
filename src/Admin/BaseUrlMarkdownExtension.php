<?php

namespace SilverStripe\UserDocs\Admin;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Extension\CommonMark\Node\Inline\AbstractWebResource;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Manifest\ModuleResourceLoader;

class BaseUrlMarkdownExtension implements ExtensionInterface
{
    private string $baseUrl;

    private array $docData;

    public function __construct(string $baseUrl, array $docData)
    {
        $this->baseUrl = $baseUrl;
        $this->docData = $docData;
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
        }
    }

    private function updateUrl(AbstractWebResource $node): void
    {
        $url = $node->getUrl();

        // Skip empty URLs or URLs with a protocol (e.g., http://, https://, //, mailto:, ftp:// etc)
        if (empty($url) || preg_match('/^(https?:|ftp:)?\/\/|mailto:/i', $url)) {
            return;
        }

        if ($node instanceof Image) {
            preg_match('@' . preg_quote($this->docData['module'], '@') . '/(?<middle>.*?)/' . preg_quote($this->docData['locale'], '@') . '@', $this->docData['filePath'], $matches);
            $relativePath = ModuleResourceLoader::resourceURL($this->docData['module'] . ':' . Controller::join_links([
                $matches['middle'] ?? '',
                $this->docData['locale'],
                $url,
            ])); // @TODO this fails with collapsing relative folders (e.g. some/path/../more) - see https://cms-userhelp.ddev.site/admin/user-docs/docs/developer_guides/model/versioning
            $node->setUrl($relativePath);
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
