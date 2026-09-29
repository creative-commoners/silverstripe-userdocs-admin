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

## Images

To include images in your documentation, expose the folder(s) (see [exposing static resources](/developer_guides/templates/requirements/#exposing-static-resources)) and use relative paths the same way you normally would.

## Supported functionality

Out of the box, all [Commommark](https://commonmark.org/) and [GitHub flavored](https://github.github.com/gfm/) markdown is supported. GitHub's [alert syntax](https://docs.github.com/en/get-started/writing-on-github/getting-started-with-writing-and-formatting-on-github/basic-writing-and-formatting-syntax#alerts) is also supported.

TODO: Provide injector YAML for changing the extensions for markdown functionality support.
