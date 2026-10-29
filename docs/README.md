# Documentation publishing

The user handbook starts at [handbook/](handbook/). The existing design and
protocol documents in this directory remain developer material and are not
part of the first handbook import.

## WordPress.org import

`manifest.json` uses the same entry format as the
[Playground handbook manifest](https://github.com/WordPress/wordpress-playground/blob/trunk/packages/docs/site/manifest.json):
`title`, `slug`, `markdown_source`, `parent`, and `order`.

Each Markdown source is relative to the manifest. Serve the raw manifest from:

```text
https://raw.githubusercontent.com/WordPress/reprint/trunk/docs/manifest.json
```

The WordPress.org Markdown importer resolves sources against that URL, reads
the first H1 as the page title, and creates the parent/child page hierarchy.
Use ordinary Markdown: headings, lists, tables, fenced code blocks, and links.
No Docusaurus, MDX components, or site build is required.

Pages live in directories matching their published slug hierarchy, with their
content in `README.md`. Directory links such as `../requirements/` work both
when browsing this repository and after importing the handbook. Keep the
trailing slash. No source-to-published-link rewrite is needed.

## Remaining publishing setup

This repository supplies the importable content. A WordPress.org administrator
still needs to register a Reprint handbook with the Markdown importer, choose
its public base URL, and configure the manifest URL above. For Developer
Resources, Playground registers its importer in
[wporg-developer](https://github.com/WordPress/wporg-developer/blob/trunk/source/wp-content/themes/wporg-developer-2023/inc/import-playground.php).
The handbook content does not depend on that base URL.

After registration, run an import and check page order, tables, code blocks,
and links between pages. Only then replace existing public documentation links
in the plugin, CLI, and directory readme with the live WordPress.org URLs.
Do not publish links to an unregistered handbook.

## Editing and checking

Add each new page to `manifest.json`, with its parent before it. Keep the
manifest title equal to the page's H1. The manifest is the import list and
navigation order; there is no second sidebar configuration.

From `tests/`, run:

```bash
../vendor/bin/phpunit HandbookManifestTest.php
```

The check covers the import list, source files, titles, hierarchy, internal
links, and public-link destinations. User-facing downloads, plugins, themes,
and support links use WordPress.org. GitHub links in the handbook are limited
to Reprint development. Keep the client and matching plugin download links in
the requirements page together when updating the example release.
