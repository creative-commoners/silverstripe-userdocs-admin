<?php

namespace SilverStripe\UserDocs\Admin;

use Override;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Hierarchy\MarkedSet;
use SilverStripe\Security\InheritedPermissions;
use SilverStripe\Security\PermissionCheckable;
use Symfony\Component\Filesystem\Path;

use function PHPUnit\Framework\isNumeric;

class UserDocsAdmin extends LeftAndMain
{
    private static string $url_segment = 'user-docs';

    private static string $menu_title = 'User docs';

    private static string $menu_icon_class = 'font-icon-help-circled';

    private static int $menu_priority = -999;

    private static array $documentation_roots = [
        'silverstripe/userdocs-admin:docs/userhelp' => true, // Note that the locale (en) will be the first subfolder
    ];

    private static array $allowed_actions = [
        'treeview', // @TODO we proooobably want to rename this, I suspect we'll only have the one view.
    ];

    private static array $url_handlers = [
        'EditForm/$ID' => 'EditForm', // irrelevant?
        'GET SearchForm' => 'getSearchForm',
        'treeview/$ID' => 'treeview',
    ];

    #[Override]
    public function init()
    {
        parent::init();
        $manifest = UserDocsManifest::singleton();
        $manifest->init($this->getDocRoots());
    }

    // @TODO:
    // 1. Build manifest - IN PROGRESS (see @TODO comments)
    // 2. Define a way to declare which tree root a doc page lives under
    //    - Probably just root dirs under /en/? e.g. /en/blocks/index.md
    //    - But then what about /en/index.md? Or /en/01_awawa.md? - DONE
    // 3. Build link tree from manifest
    // 4. Link to doc page that renders markdown (see https://commonmark.thephpleague.com/2.x/extensions/overview/)
    // 5. Deal with AAAALLLLL the other stuff like when one module replaces a file from another module which do we declare it's from?
    // 6. Figure out how the HECK search is gonna work.

    /**
     * This method exclusively handles deferred ajax requests to render the
     * records tree deferred handler (no pjax-fragment)
     *
     * @return DBHTMLText HTML response with the rendered treeview
     */
    public function treeview()
    {
        $id = $this->getRequest()->param('ID');
        if ($id) {
            $this->setCurrentRecordID($id);
        }
        return $this->renderWith($this->getTemplatesWithSuffix('_TreeView'));
    }

    public function LinkWithSearch($link) // @TODO
    {
        // Whitelist to avoid side effects
        $params = [
            'q' => (array)$this->getRequest()->getVar('q'),
            'ParentID' => $this->getRequest()->getVar('ParentID')
        ];
        $link = Controller::join_links(
            $link,
            array_filter(array_values($params ?? [])) ? '?' . http_build_query($params) : null
        );
        $this->extend('updateLinkWithSearch', $link);
        return $link;
    }

    /**
     * Get a subtree underneath the request param 'ID'.
     * If ID = 0, then get the whole tree.
     */
    public function getsubtree(HTTPRequest $request): HTTPResponse
    {
        $html = $this->getTreeFor(); // @TODO May need to put back the bit that takes an ID in case we're lazy loading children later

        // Trim off the outer tag
        $html = preg_replace('/^[\s\t\r\n]*<ul[^>]*>/', '', $html ?? '');
        $html = preg_replace('/<\/ul[^>]*>[\s\t\r\n]*$/', '', $html ?? '');

        return $this->getResponse()->setBody($html);
    }

    /**
     * Get a tree HTML listing which displays the nodes under the given criteria.
     *
     * @return string Nested unordered list with links to each record
     */
    public function getTreeFor()
    {
        $docData = UserDocsManifest::singleton()->getLocalisedState();
        // @TODO build a tree from the above.
        //       isIndex=true on a root means the root is a link, otherwise the root is NOT a link.
        //       Each segment of the slug should be another branch in the tree.
        //
        /**
         * Next steps:
         * 1. Figure out how you want to structure the data, and rework the below and the template to behave the way you want them to
         * 2. Build a documentation manifest and pull the menu through dynamically
         * 3. Everything else
         */

        // $

        return $this->renderWith(
            [static::class . '_SubTree'],
            [
                'rootTitle' => 'Awawa',
                'node' => ['IsInDB' => false],
                'children' => [
                    [
                        'node' => [
                            'IsInDB' => true,
                            'ID' => 1,
                            'ClassName' => 'Documentation',
                        ],
                        'TreeTitle' => 'Page One',
                        'Title' => 'Page One for real',
                        'SubTree' => $this->renderWith(
                            [static::class . '_SubTree'],
                            [
                                'node' => [
                                    'IsInDB' => true,
                                    'ID' => 1,
                                    'ClassName' => 'Documentation',
                                ]
                            ]
                        ),
                    ],
                    [
                        'node' => [
                            'IsInDB' => true,
                            'ID' => 2,
                            'ClassName' => 'Documentation',
                        ],
                        'TreeTitle' => 'Page Two',
                        'Title' => 'Page Two for real',
                        'SubTree' => $this->renderWith(
                            [static::class . '_SubTree'],
                            [
                                'node' => [
                                    'IsInDB' => true,
                                    'ID' => 2,
                                    'ClassName' => 'Documentation',
                                ]
                            ]
                        ),
                    ],
                ],
            ],
        );
    }

    /**
     * Return the entire tree as a nested set of ULs
     */
    public function TreeAsUL()
    {
        $html = $this->getTreeFor();
        $this->extend('updateTreeAsUL', $html);
        return $html;
    }

    private function getDocRoots(): array
    {
        $roots = [];
        foreach (static::config()->get('documentation_roots') as $dir => $include) {
            if (is_int($dir)) {
                $dir = $include;
                $include = true;
            }
            if (!$include) {
                continue;
            }
            $roots[] = Path::makeAbsolute(ModuleResourceLoader::singleton()->resolvePath($dir), BASE_PATH);
        }
        return $roots;
    }
}
