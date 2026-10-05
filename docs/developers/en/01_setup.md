---
title: Setup userhelp docs
---

# Setup userhelp docs

User documentation can be registered by adding it to the [`UserDocsAdmin.documentation_roots`](api:SilverStripe\UserDocs\Admin\UserDocsAdmin->documentation_roots) configuration array. The path is a key in the array, and `true` is the value.

The path can either be in module:path format (i.e. the composer name of the momdule and the relative path within that module, separated by a colon), or can be a path relative to the document root of your project.

```yml
SilverStripe\UserDocs\Admin\UserDocsAdmin:
  documentation_roots:
    # module:path format
    'my/module:docs/userhelp': true
    # relative path format
    'app/docs/userhelp': true
```

If you want to unregister documentation for a given module, set the value in the array to `false`. This is useful for example if overriding a lot of functionality that a module provides, to the extent that the majority of its documentation is no longer valid for your project.

```yml
SilverStripe\UserDocs\Admin\UserDocsAdmin:
  documentation_roots:
    'my/module:docs/userhelp': false
```

## Documentation structure

The documentation tree is created based on the folder structure inside the registered documentation directories. Documentation is merged so that when multiple modules have the same folder structure, the documentation shares the same navigation tree. Any collisions where two modules have the same documentation file will be resolved by the module with the highest priority (as defined in the module manifest in the framework itself) taking precedence. The project itself is the highest priority module.

The root of a documentation navigation tree exists for each directory directly under the locale directory in registered documentation directories. The name of the root is derived from the name of the folder by default. See [documentation tree titles](#documentation-tree-titles) for details. A custom title can be set by creating a `index.md` file under this directory. Doing so will also ensure there is a link on the root node in the tree. If there is no `index.md` file directly in the root directory, the root itself will have no associated documentation and therefore no link. No level under the root level is allowed to exclude documentation.



To create documentation with a linkless root, have a directory structure like this:

```text
vendor/my/module/docs/userhelp/en
├─ 00_global_features
        ├─ 00_navigation_menus
                 ├─ index.md
                 ├─ 00_main_navigation.md
                 └─ 01_minor_navigation.md
        ├─ 01_header
                 └─ index.md
        └─ 02_footer
                 └─ index.md
└─ 01_something_else
        └─ index.md
```

- Global features
  - Navigation menus
    - Main navigation
    - Minor navigation
  - Header
  - Footer
- Something else

### Documentation tree titles

@TODO something about how the title is derived from file/folder names and overridden with frontmatter.

@TODO something about how having an h1 for the actual docs is still important.

### Overriding and merging documentation

Some modules may add additional functionality that needs to be documented under an existing section, or completely replace some functionality. This can be achieved by intentionally having a file with the same name in the same directory structure. For example, consider the following two modules:

```text
vendor/module/one/docs/userhelp/en
└─ 00_global_features
        └─ 00_navigation_menus
                 ├─ index.md
                 └─ 00_main_navigation.md
```

```text
vendor/module/one/docs/userhelp/en
└─ 00_global_features
        └─ 00_navigation_menus
                 ├─ index.md
                 └─ 01_minor_navigation.md
```

Assuming Module two has the higher priority, the documentation navigation tree will look like this:

- Global features
  - Navigation menus (documentation taken from module two)
    - Main navigation (documentation taken from module one)
    - Minor navigation (documentation taken from module two)

Note that the documentation from module two for "Navigation menus" completely overrides the docs from module one for the same file - but where they both provide separate docs, the docs seemlessly merge together into the tree.

## Images in docs

To include images in your documentation, expose the folder(s) (see [exposing static resources](/developer_guides/templates/requirements/#exposing-static-resources)) and use relative paths the same way you normally would.

## CSS to style your docs

The rendered documentation is encapsulated in a shadow DOM, which allows us to include CSS that styles the docs without affecting the rest of the CMS.

You can include your own stylesheet by adding the path for it to the [`UserDocsAdmin.css_files`](api:SilverStripe\UserDocs\Admin\UserDocsAdmin->css_files) configuration property.

```yaml
SilverStripe\UserDocs\Admin\UserDocsAdmin:
  css_files:
    - 'my/module:client/dist/docs.css'
```

## Supported markdown syntax

Out of the box, all [Commommark](https://commonmark.org/) and [GitHub flavored](https://github.github.com/gfm/) markdown is supported. GitHub's [alert syntax](https://docs.github.com/en/get-started/writing-on-github/getting-started-with-writing-and-formatting-on-github/basic-writing-and-formatting-syntax#alerts) is also supported.

You can include additional extensions to support additional markdown syntax by updating the injector configuration:

```yml
---
Name: userdocs-config
---
SilverStripe\Core\Injector\Injector:
  League\CommonMark\Environment\Environment.userdocs:
    constructor:
      extensions:
        MyCustomMarkdownExtension: '%$App\Markdown\MyCustomMarkdownExtension'
```

You can also set configuration for the markdown environment using YAML:

```yml
---
Name: userdocs-config
---
SilverStripe\Core\Injector\Injector:
  League\CommonMark\Environment\Environment.userdocs:
    constructor:
      config:
        table_of_contents:
          min_heading_level: 1
```
