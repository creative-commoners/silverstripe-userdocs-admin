<?php

namespace SilverStripe\UserDocs\Admin;

use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkRenderer;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use League\CommonMark\Xml\XmlNodeRendererInterface;
use League\Config\ConfigurationAwareInterface;
use League\Config\ConfigurationInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Injector\Injectable;

class HeadingPermalinkWrapper implements NodeRendererInterface, ConfigurationAwareInterface
{
    use Injectable;

    private HeadingPermalinkRenderer $innerRenderer;

    private string $baseUrl;

    public function __construct(HeadingPermalinkRenderer $innerRenderer, string $baseUrl)
    {
        $this->innerRenderer = $innerRenderer;
        $this->baseUrl = $baseUrl;
    }

    /**
     * {@inheritDoc}
     */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer)
    {
        $permalinkHtml = $this->innerRenderer->render($node, $childRenderer);

        if ($permalinkHtml instanceof HtmlElement) {
            // @TODO check this against what happens on the frontend - for some reason this is a) adding to the history and b) not actually scrolling the page.
            // @TODO make the ToC update its anchors too
            // @TODO skip if no base_url in the DOM
            $href = $permalinkHtml->getAttribute('href');
            $permalinkHtml->setAttribute('href', '/' . Controller::join_links($this->baseUrl, $href));
        } else {
            // Just treat it as a string
            $permalinkHtml = '';// @TODO
        }

        return $permalinkHtml;
    }

    public function setConfiguration(ConfigurationInterface $configuration): void
    {
        $this->innerRenderer->setConfiguration($configuration);
    }
}
