<?php

namespace SilverStripe\UserDocs\Admin;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\TableOfContents\TableOfContentsExtension;
use League\CommonMark\MarkdownConverter;
use Override;
use PomoDocs\CommonMark\Alert\AlertExtension;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBField;
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
        'vendor/silverstripe/developer-docs/test'
    ];

    private static array $allowed_actions = [
        'treeview', // @TODO we proooobably want to rename this, I suspect we'll only have the one view.
        'docs',
    ];

    private static array $url_handlers = [
        'EditForm/$ID' => 'EditForm', // irrelevant?
        'GET SearchForm' => 'getSearchForm',
        'treeview/$ID' => 'treeview',
        'docs/$*' => 'docs',
    ];

    private ?string $currentDocSlug = null;

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

    public function docs(HTTPRequest $request): HTTPResponse
    {
        // Get the slug and tell the request we've used up all parts of the URL
        // If we don't do this, the Director assumes there's still more actions
        // to be done and will end up returning an error response.
        $this->currentDocSlug = $request->remaining();
        $request->shift(substr_count($this->currentDocSlug, '/') + 1);
        return $this->getResponseNegotiator()->respond($request);
    }

    public function getRenderedDocs()
    {
        $data = UserDocsManifest::singleton()->getLocalisedState();
        if (!$this->currentDocSlug || !array_key_exists($this->currentDocSlug, $data)) {
            $this->httpError(404);
        }

        $docData = $data[$this->currentDocSlug];
        $environment = new Environment([
            'alert' => [
                'icons' => [
                    'active' => true,
                ],
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new FrontMatterExtension());
        $environment->addExtension(new HeadingPermalinkExtension());
        $environment->addExtension(new TableOfContentsExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addExtension(new AlertExtension()); // @TODO see https://github.com/pomodocs/commonmark-alert#configuration to make text localised
        $environment->addExtension(new BaseUrlMarkdownExtension($this->Link('docs'), $docData));
        // @TODO make markdown environment injectable with extensions definable in yaml
        // @TODO see https://commonmark.thephpleague.com/2.x/extensions/overview/ and decide what additional extensions should be applied
        // @TODO do we want https://commonmark.thephpleague.com/2.x/extensions/table-of-contents/?
        $converter = new MarkdownConverter($environment);
        // @TODO validate the file actually exists
        $markdown = $converter->convert(file_get_contents($docData['filePath']));
        // @TODO we want to add some CSS to this, which we also want to allow devs to add to.
        //       One CSS change we immediately need is on the `pre` element - overflow:show;
        // @TODO we need to hide tree on narrow screen like CMSMain does
        // @TODO update tab title? add breadcrumbs, make images render (vendor-expose??), fix special headers
        // @TODO add "on this page" text above table of contents
        // @TODO Remove icon before headings, put it after instead, use #, and make it only visible on hover
        // @TODO find out how to do a post-render fix of header anchors in the event of base url in the head
        return DBField::create_field('HTMLFragment', $markdown->getContent());
    }

    public function getCurrentDocSlug(): ?string
    {
        return $this->currentDocSlug;
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
        $treeData = UserDocsManifest::singleton()->getLocalisedTreeData();
        // @TODO build a tree from the above.

        /*

            So, there are a few things here.
            2. The root of each tree MAY NOT have a file associated with it and therefore may not directly appear in the tree.
            3. We're not validating that each step in the tree has an index

            What we still need to do:
            2. Throw exceptions if a branch has no associated file (e.g. a/b/c.md, b must have either an a/b.md or a/b/index.md)
            3. Throw exceptions if a folder and file clash (e.g. a/b.md and a/b/index.md both exist)
            4. Note for both of the above that if the titles don't match it's not a collision
            5. Cache the tree data in the manifest

        */

        $renderedTrees = [];
        foreach ($treeData as $root) {
            $renderedTrees[] = $this->renderWith(
                [static::class . '_SubTree'],
                [
                    'controller' => $this,
                    'node' => [...$root, 'id' => 0, 'isRoot' => true],
                    'children' => $this->getRenderableChildren($root),
                ],
            );
        }
        return DBField::create_field('HTMLFragment', implode($renderedTrees));
        // @TODO make the above do the following:
        // 1. recurse through children
        // 2. use better templating (i.e. we can recurse through children FROM THE TEMPLATE)
    }

    private function getRenderableChildren(array $node)
    {
        if (empty($node['children'])) {
            return null;
        }
        $renderable = [];
        foreach ($node['children'] as $childData) {
            $renderable[] = [
                'node' => [...$childData],
                'children' => $this->getRenderableChildren($childData),
            ];
        }
        return $renderable;
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
