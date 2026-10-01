<?php

namespace SilverStripe\UserDocs\Admin;

use League\CommonMark\Environment\Environment;
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

        // @TODO https://commonmark.thephpleague.com/2.x/extensions/tables/ might be useful for making tables responsive
        // @TODO Make a custom event listener to find the TableOfContents node and add the "on this page" text

        $environment = new Environment($config);
        foreach ($extensions as $extension) {
            $environment->addExtension($extension);
        }
        return $environment;
    }
}
