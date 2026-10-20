---
title: Userhelp documentation structure
---

# Documentation structure

The documentation tree is created based on the folder structure inside the registered documentation directories. Documentation is merged so that when multiple modules have the same folder structure, the documentation shares the same navigation tree. Any collisions where two modules have the same documentation file will be resolved by the module with the highest priority (as defined in the module manifest in the framework itself) taking precedence. The project itself is the highest priority module.

The first directory under the registered base directory is for the locale. The default is `en/` for English.

The root of a documentation navigation tree exists for each directory directly under the locale directory in registered documentation directories. The name of the root is derived from the name of the folder by default. See [navigation tree titles](#navigation-tree-titles) for details. A custom title can be set by creating an `index.md` file under this directory. Doing so will also ensure there is a link on the root node in the tree provided there is content (other than frontmatter) in the file. If there is no `index.md` file directly in the root directory or the `index.md` file lacks content, the root itself will have no associated documentation and therefore no link. No level under the root level is allowed to exclude documentation.

> [!WARNING]
> You must not have a markdown file directly under the locale directory. You need to define the root node for the navigation tree first by adding a directory under the locale directory, and put your markdown files in there.

The order of documentation is derived first by any numeric prefix of the folder/file name (e.g. `01_file50.md` will come before `02_file1.md`). Prefixed documentation always appears before documentation with no numeric prefix. If two folders/files at the same level have the same or no numeric prefix, folders take precedence. The order is then based alphabetically on the navigation title.

For example, consider this documentation structure:

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

That will render the following navigation tree:

- Global features
  - Navigation menus
    - Main navigation
    - Minor navigation
  - Header
  - Footer
- Something else

## Navigation tree titles

The title of a page of documentation in the navigation tree is derived by default from the name of the folder/file that represents it. For example `00_some_documentation.md` will have the title "Some documentation" by default.

If you want to set the navigation tree title for a page to something specific, you can do this using [frontmatter](./setup#frontmatter-metadata). To change the title of a page from another module without altering content, see [extending a page of documentation](#extending-pages).

Note that the navigation tree title is not used in the documentation page itself. You should still include a `h1` heading to title your documentation page.

## Overriding and merging documentation

Some modules may add additional functionality that needs to be documented under an existing section, or completely replace some functionality. This can be achieved by intentionally having a file with the same name in the same directory structure. For example, consider the following two modules:

```text
vendor/module/one/docs/userhelp/en
└─ 00_global_features
        └─ 00_navigation_menus
                 ├─ index.md
                 └─ 00_main_navigation.md
```

```text
vendor/module/two/docs/userhelp/en
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

Note that the documentation from module two for "Navigation menus" completely overrides the docs from module one for the same file - but where they both provide separate docs, the docs seamlessly merge together into the tree.

> [!TIP]
> The numeric prefix is allowed to differ between your override folder/file and the original. It will still override the content and also update the ordering of the documentation.

### Extending a page of documentation {#extending-pages}

Sometimes you might want to just add additional information to an existing page of documentation without replacing the page completely.

You can do this using [frontmatter](./setup#frontmatter-metadata), with the `extends` key. By setting `extends: true` in your frontmatter, the content in that file will be added to the bottom of the existing content from the original file instead of replacing it. If multiple modules extend the same page, the files are all appended in the reverse of the module order defined by the `ModuleManifest`, so content from the highest priority module is added last.

If there is no original file for the page, the first extension is used as the page. If that extension has no content, the page isn't shown.

> [!TIP]
> Other frontmatter metadata in the file will still take effect. For example if you include a title in the frontmatter for your extension page, and the extension comes after the original page, that title will be used instead of the title from the original file. To change only the title of a page, use an extension that has a title in its frontmatter and no other content.

#### Replacing, removing and translating extensions

Each extension belongs to a module. With `extends: true`, that's the module that has the file. Set `extends` to the name of another module to provide that module's extension instead:

```yml
---
extends: 'other/module'
---
```

- To **override** an extension, add a file with the same path to a module that has a higher priority than the module with the extension (for example your project), and set `extends` to the name of that module. The content in your file is used instead of the content in the other module's extension.
- To **remove** an extension, override it with a file that only has frontmatter.
- To **translate** an extension, see [localising documentation](#localising-documentation).

If no installed module has the name, the extension isn't used, because the content it extends isn't there. If the value isn't a module name at all, a warning is logged and the extension isn't used.

Extensions are ordered by the module they belong to, so an override goes in the same place as the extension it replaces.

> [!WARNING]
> `extends` must be the boolean `true` or the name of a module. YAML reads `true`, `yes` and `on` as booleans, but `"true"` (in quotes) is a string. It isn't a module name, so the extension isn't used.

### Adding new tree nodes

Sometimes it might be useful to turn a page of documentation into a whole section, adding new tree nodes under a page that previously had no child pages. Take this structure for example:

```text
vendor/module/one/docs/userhelp/en
└─ 00_global_features
        └─ 00_navigation_menus.md
```

- Global features
  - Navigation menus

The "Navigation menus" page has no child pages. If you want to add child pages to that section in your module, you can do so by creating a folder named `00_navigation_menus/` and then adding new documentation pages inside that new directory:

```text
vendor/my/module/docs/userhelp/en
└─ 00_global_features
        └─ 00_navigation_menus
                 └─ 00_main_navigation.md
```

- Global features
  - Navigation menus
    - Main navigation

If you also want to override the documentation in the "Navigation menus" page, you would then need to create a `00_navigation_menus/index.md` file (though note the numeric prefix is allowed to differ).

## Localising documentation

While commercially supported modules will only include English documentation, anyone can include documentation for other languages, including translations of the official documentation.

You can do this by setting up documentation with the same file structure (including folder and file names) under the appropriate locale folder (for example `pt/` for Portuguese). Titles can be localised using [frontmatter](./setup#frontmatter-metadata).

> [!IMPORTANT]
> When localising documentation, you *must* match the folder and file names! Do not translate the names of folders or files. Instead, to update the title use frontmatter.

If you want to localise the name of a root that has no content directly associated with it, you can create an `index.md` file for that root folder and only include the frontmatter data.

To localise an [extension](#extending-pages), add a file with the same path under the appropriate locale folder, and set `extends` to the module the extension belongs to. For example if `other/module` extends a page, use `extends: 'other/module'` in your translation. If the translation is in the same module as the extension, `extends: true` works too. Each extension falls back to other locales separately from the page it extends, so a translated page still shows extensions that haven't been translated.

Note that content that hasn't been localised will fallback to unlocalised documentation. For example if your current locale is `pt_BR` and your default locale is `fr_FR`, the order in which documentation is looked up is as follows: `pt_BR`, `pt`, `fr_FR`, `fr`, `en`. English is always the final fallback that is checked, unless your current locale is also English in which case it takes priority.
