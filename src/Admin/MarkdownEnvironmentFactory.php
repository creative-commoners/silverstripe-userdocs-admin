<?php

namespace SilverStripe\UserDocs\Admin;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Renderer\Block\ListBlockRenderer;
use League\CommonMark\Extension\TableOfContents\Node\TableOfContents;
use League\CommonMark\Extension\TableOfContents\TableOfContentsExtension;
use League\CommonMark\Extension\TableOfContents\TableOfContentsRenderer;
use PomoDocs\CommonMark\Alert\AlertExtension;
use SilverStripe\Core\Injector\Factory;

class MarkdownEnvironmentFactory implements Factory
{
    public function create(string $service, array $params = []): ?object
    {
        $config = $params['config'] ?? [];
        // Add localised alert text if AlertExtension is being added.
        $extensions = array_filter($params['extensions']);
        foreach ($extensions as $extension) {
            if ($extension instanceof AlertExtension) {
                $config['alert']['labels'] = [
                    'note' => _t(__CLASS__ . '.alert_note', 'Note'),
                    'tip' => _t(__CLASS__ . '.alert_tip', 'Tip'),
                    'important' => _t(__CLASS__ . '.alert_important', 'Important'),
                    'warning' => _t(__CLASS__ . '.alert_warning', 'Warning'),
                    'caution' => _t(__CLASS__ . '.alert_caution', 'Caution'),
                ];
            }
        }

        $environment = new Environment($config);
        $hasTableOfContents = false;
        foreach ($extensions as $extension) {
            $environment->addExtension($extension);
            if ($extension instanceof TableOfContentsExtension) {
                $hasTableOfContents = true;
            }
        }

        if ($hasTableOfContents) {
            // We can't use $environment->getRenderersForClass() to get the existing table of contents renderer
            // because that will initialise the environment which doesn't allow adding new renderers.
            $innerRenderer = new TableOfContentsRenderer(new ListBlockRenderer());
            $environment->addRenderer(TableOfContents::class, TableOfContentsWrapper::create($innerRenderer), 99999);
            // Add a table of contents placeholder after the first h1 in any document, so that the TOC is
            // rendered below the page title instead of above it.
            $environment->addEventListener(DocumentParsedEvent::class, [TableOfContentsEventHandler::create(), 'onDocumentParsed']);
        }

        return $environment;
    }
}
