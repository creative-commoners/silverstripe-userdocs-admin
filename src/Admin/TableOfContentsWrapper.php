<?php

namespace SilverStripe\UserDocs\Admin;

use League\CommonMark\Extension\TableOfContents\TableOfContentsRenderer;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use League\CommonMark\Xml\XmlNodeRendererInterface;
use SilverStripe\Core\Injector\Injectable;

class TableOfContentsWrapper implements NodeRendererInterface
{
    use Injectable;

    private TableOfContentsRenderer $innerRenderer;

    public function __construct(TableOfContentsRenderer $innerRenderer)
    {
        $this->innerRenderer = $innerRenderer;
    }

    /**
     * {@inheritDoc}
     */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer)
    {
        $tocListHtml = $this->innerRenderer->render($node, $childRenderer);
        $heading = new HtmlElement(
            'h2',
            ['id' => 'toc-heading', 'class' => 'toc-heading'],
            _t(__CLASS__ . '.on_this_page', 'On this page')
        );

        return new HtmlElement(
            'nav',
            ['class' => 'toc-container', 'aria-labelledby' => 'toc-heading'],
            [$heading, $tocListHtml]
        );
    }
}
