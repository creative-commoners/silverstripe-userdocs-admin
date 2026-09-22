<?php

namespace SilverStripe\UserDocs\Tests\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use PomoDocs\CommonMark\Alert\AlertExtension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\Messages\MessageProvider;
use SilverStripe\UserDocs\Markdown\MarkdownEnvironmentFactory;

class MarkdownEnvironmentFactoryTest extends SapphireTest
{
    public function testCreateSkipsNullExtensions(): void
    {
        // Setting an extension to null in YAML is how projects remove one
        $environment = (new MarkdownEnvironmentFactory())->create(Environment::class, [
            'extensions' => [
                'CommonMarkCoreExtension' => new CommonMarkCoreExtension(),
                'RemovedExtension' => null,
            ],
        ]);

        $this->assertCount(1, $environment->getExtensions());
    }

    public function testCreateLocalisesAlertLabels(): void
    {
        $messageProvider = $this->createMock(MessageProvider::class);
        $messageProvider->method('translate')
            ->willReturnCallback(fn (string $entity, string $default) => "translated $default");
        Injector::inst()->registerService($messageProvider, MessageProvider::class);

        $environment = (new MarkdownEnvironmentFactory())->create(Environment::class, [
            'config' => ['alert' => ['icons' => ['active' => false]]],
            'extensions' => [new CommonMarkCoreExtension(), new AlertExtension()],
        ]);

        $this->assertSame([
            'note' => 'translated Note',
            'tip' => 'translated Tip',
            'important' => 'translated Important',
            'warning' => 'translated Warning',
            'caution' => 'translated Caution',
        ], $environment->getConfiguration()->get('alert/labels'));
        $this->assertFalse($environment->getConfiguration()->get('alert/icons/active'));
    }

    public function testCreatePlacesTableOfContentsAfterTitle(): void
    {
        $converter = Injector::inst()->get(MarkdownConverter::class . '.userdocs');

        $html = $converter->convert("---\ntitle: Nav title\n---\n\n# Title\n\nIntro\n\n## Section")->getContent();

        // The ToC sits between the title and the intro, and only lists h2 and below
        $this->assertMatchesRegularExpression(
            '#</h1>\s*<ul class="table-of-contents">\s*'
            . '<li><a href="\#section">Section</a></li>\s*</ul>\s*<p>Intro</p>#',
            $html
        );
    }
}
