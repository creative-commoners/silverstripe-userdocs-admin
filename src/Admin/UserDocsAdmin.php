<?php

namespace SilverStripe\UserDocs\Admin;

use League\CommonMark\ConverterInterface;
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
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\Hierarchy\MarkedSet;
use SilverStripe\Security\InheritedPermissions;
use SilverStripe\Security\PermissionCheckable;
use Symfony\Component\Filesystem\Path;

class UserDocsAdmin extends LeftAndMain
{
    private static string $url_segment = 'user-docs';

    private static string $menu_title = 'User docs';

    private static string $menu_icon_class = 'font-icon-help-circled';

    private static int $menu_priority = -999;

    /**
     * Array of paths to user documentation
     */
    private static array $documentation_roots = [
        'silverstripe/userdocs-admin:docs/userhelp' => true, // Note that the locale (en) will be the first subfolder
        // 'vendor/silverstripe/developer-docs/test'
    ];

    /**
     * Array of paths to CSS files that will be used in the shadow dom
     * where rendered documentation sits.
     */
    private static array $css_files = [
        'silverstripe/userdocs-admin:client/dist/styles/docs.css',
    ];

    private static array $allowed_actions = [
        'treeview', // @TODO we proooobably want to rename this, I suspect we'll only have the one view.
        'docs',
    ];

    private static array $url_handlers = [
        'EditForm/$ID' => 'EditForm', // irrelevant?
        'GET SearchForm' => 'getSearchForm',
        'treeview/$Slug' => 'treeview',
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

    /**
     * This method exclusively handles deferred ajax requests to render the
     * records tree deferred handler (no pjax-fragment)
     *
     * @return DBHTMLText HTML response with the rendered treeview
     */
    public function treeview()
    {
        $slug = rawurldecode($this->getCurrentDocSlugFromId($this->getRequest()->param('Slug')));
        if ($slug) {
            $this->setCurrentDocSlug($slug);
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
        $data = UserDocsManifest::singleton()->getLocalisedDocData();
        if (!$this->currentDocSlug || !array_key_exists($this->currentDocSlug, $data)) {
            $this->httpError(404);
        }

        $docData = $data[$this->currentDocSlug];
        $filePath = $docData['filePath'];
        if (!$docData['hasContent'] || !file_exists($filePath)) {
            $this->httpError(404);
        }
        /** @var MarkdownConverter $converter */
        $converter = Injector::inst()->get(MarkdownConverter::class . '.userdocs');
        $converter->getEnvironment()->addExtension(BaseUrlMarkdownExtension::create($this->Link('docs'), $docData));
        $markdown = $converter->convert(file_get_contents($filePath));
        // @TODO we need to hide tree on narrow screen like CMSMain does
        // @TODO find out how to do a post-render fix of header anchors in the event of base url in the head
        return DBField::create_field('HTMLFragment', $markdown->getContent());
    }

    public function getCssFiles(): array
    {
        return static::config()->get('css_files');
    }

    public function setCurrentDocSlug(?string $slug): static
    {
        $this->currentDocSlug = $slug;
        return $this;
    }

    public function getCurrentDocSlug(): ?string
    {
        return $this->currentDocSlug;
    }

    public function getCurrentDocSlugAsId(?string $slug = null): ?string
    {
        if ($slug === null) {
            $slug = $this->currentDocSlug;
        }
        if ($slug === null) {
            return $slug;
        }
        // The slug will be included in the URL using rawurlencode,
        // but some Apache configurations won't allow an encoded
        // slash, so we have to replace it with something else first.
        // We also have to replace any characters that will break
        // jQuery's selector lookup when used inside an attribute
        // selector.
        // @TODO make a more complete list of characters and make it a const
        return str_replace(['/', '.'], ['__SLASH__', '__DOT__'], $slug);
    }

    private function getCurrentDocSlugFromId(?string $id): string
    {
        if ($id === null) {
            return '';
        }
        // Reverse the replacement from getCurrentDocSlugAsId
        return str_replace(['__SLASH__', '__DOT__'], ['/', '.'], $id);
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
        $html = $this->getTreeFor();

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
    public function getTreeFor(): DBHTMLText|string
    {
        $treeData = UserDocsManifest::singleton()->getLocalisedTreeData();

        /*  @TODO

            So, there are a few things here.
            2. The root of each tree MAY NOT have a file associated with it and therefore may not directly appear in the tree.
            3. We're not validating that each step in the tree has an index

            What we still need to do:
            2. Throw exceptions if a branch has no associated file (e.g. a/b/c.md, b must have either an a/b.md or a/b/index.md)
            3. Throw exceptions if a folder and file clash (e.g. a/b.md and a/b/index.md both exist)
            4. Note for both of the above that if the titles don't match it's not a collision

        */

        $isFirstPageAssigned = false;
        $renderedTrees = [];
        foreach ($treeData as $root) {
            $renderedTrees[] = $this->renderWith(
                [static::class . '_SubTree'],
                [
                    'controller' => $this,
                    'node' => $this->getNodeData($root, true, $isFirstPageAssigned),
                    'children' => $this->getRenderableChildren($root, $isFirstPageAssigned),
                ],
            );
        }
        return DBField::create_field('HTMLFragment', implode($renderedTrees));
    }

    /**
     * Get the jstree marking classes for this node.
     * Derived from MarkedSet::markingClasses()
     */
    private function getMarkingClasses(array $node): string
    {
        $classes = [];
        // Set jstree open state, or mark it as a leaf (closed) if there are no children
        if (empty($node['children'])) {
            // No children
            $classes[] = "jstree-leaf closed";
        } elseif (str_starts_with($this->currentDocSlug, $node['slug'] . '/')) {
            // Open with children
            $classes[] = "jstree-open";
        } else {
            // Closed with children
            $classes[] = "jstree-closed closed";
        }
        return implode(' ', $classes);
    }

    private function getRenderableChildren(array $node, bool &$isFirstPageAssigned): ?array
    {
        if (empty($node['children'])) {
            return null;
        }
        $renderable = [];
        foreach ($node['children'] as $childData) {
            $renderable[] = [
                'node' => $this->getNodeData($childData, false, $isFirstPageAssigned),
                'children' => $this->getRenderableChildren($childData, $isFirstPageAssigned),
            ];
        }
        return $renderable;
    }

    private function getNodeData(array $node, bool $isRoot, bool &$isFirstPageAssigned): array
    {
        $isFirstPage = false;
        if (!$isFirstPageAssigned && $node['hasContent']) {
            $isFirstPageAssigned = true;
            $isFirstPage = true;
        }
        return [
            ...$node,
            'isRoot' => $isRoot,
            'isCurrentPage' => $node['slug'] === $this->currentDocSlug,
            'isFirstPage' => $isFirstPage,
        ];
    }

    /**
     * Return the entire tree as a nested set of ULs
     */
    public function TreeAsUL(): DBHTMLText|string
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
