# Documentation structure and publishing

Reprint's proposed public documentation home is one project handbook at
`https://developer.wordpress.org/reprint/`. This location still needs agreement
and registration with the Meta team. Merging this repository does not create
that handbook or publish its pages.

## Why a project handbook

[Playground](https://developer.wordpress.org/playground/) already has a project
handbook on Developer Resources with starting guides, developer material, and
reference. Reprint needs the same kinds of pages, but not several subsites yet.

Keep all Reprint user and integration documentation together:

| Location | Role |
| --- | --- |
| `developer.wordpress.org/reprint/` | Proposed canonical handbook: tutorials, task guides, integration, reference, and explanation. |
| `wordpress.org/reprint/` | Product introduction and goal-based links into the handbook. |
| WordPress.org plugin directory | Plugin installation, requirements, downloads, and support; link onward for client workflows. |
| Repository README | Short project introduction, working example, and documentation/contribution links. |
| Repository contributor docs | Code architecture, private formats, tests, design decisions, and releases. |
| Developer Blog or Learn WordPress | Optional introductions and lessons linking to the handbook, not another command reference. |

Do not put Reprint commands in the
[WordPress core Code Reference](https://developer.wordpress.org/reference/),
which describes WordPress functions, classes, and hooks. Reprint is also a
separate client, not a built-in WP-CLI command. The Plugin Handbook teaches
plugin development; it is not the home for using this particular plugin/client.

## Audiences and page types

| Reader | First question | Starting point |
| --- | --- | --- |
| Curious WordPress user | What does this do, and can I try it? | Demo and first-copy tutorial. |
| Person copying a site | How do I get a working copy and check it? | Copy tutorial, task guides, and troubleshooting. |
| Administrator authorizing a copy | What do I install and paste in WordPress? | Short authorization guide. |
| Product developer | How do I drive the client and interpret each result? | Integration guide and public command/output reference. |
| Host or support engineer | What must the environment provide, and what failed? | Requirements, hosting guide, progress/results, and troubleshooting. |
| Reprint contributor | How do I change the implementation without breaking it? | CONTRIBUTING and repository-only notes. |

A tutorial takes one tested route to a first result. A task guide starts with a
specific goal and ends with checks. Reference gives exact commands, options,
outputs, and limits. Explanation builds the reader's model of the system.
The [WP-CLI handbook](https://make.wordpress.org/cli/handbook/) also separates
user/developer guides, reference, and contributing.

The handbook home groups pages under Get started, Guides, Build with Reprint,
Reference, and Understand Reprint. Readers enter by goal: view the demo, clone
my site, or integrate Reprint into my product. Use Try Reprint only once the linked route actually lets a reader
complete a Reprint copy. Do not make them select a document
type before they know the tool.

A technical detail belongs in public reference when a caller relies on it.
A local implementation quirk belongs beside the code and in a regression test.
Explain the larger design choices and tradeoffs in How Reprint works. Keep
local implementation reasons with the code and regression tests, rather than
growing a separate catalogue of past bugs. Keep proposed work and past patch
narratives in issues and PRs instead of presenting them as current instructions.

## What this repository publishes

`docs/manifest.json` is the explicit allowlist. It contains only Markdown pages
under `docs/handbook/`. The contributor directory, this publishing plan, and
working instructions are not handbook pages.

Pages use plain Markdown, fenced code, and tables. There is no MDX conversion,
build output, or custom link rewriting. Put example addresses in fenced code
or plain text. The native parser's URL preservation can consume an inline-code
URL's closing backtick and prevent later links from rendering; the handbook test
guards against that form. Source filenames describe the page;
only the handbook home is `README.md`.

Keep the existing seven page slugs. New pages are siblings beneath the handbook
home, and the home groups them by purpose. Proposed examples are:

```text
/reprint/
/reprint/copy-a-site/
/reprint/build-with-reprint/
/reprint/commands/
/reprint/file-transfers/
/reprint/progress-and-results/
```

The manifest's parent relationship puts every page beneath the handbook root.
Do not add nested URL prefixes just to group the menu. Internal links target
published routes: `commands/` from the home and `../commands/` from a child.
They are not GitHub source-file links. Repository-facing pages use ordinary
file links instead. A different approved handbook base still works with these
relative links.

For example, `../commands/` from `/reprint/copy-a-site/` resolves to
`/reprint/commands/` in the browser. The importer can leave the link unchanged.
A Markdown file link such as `commands.md` would instead request a file on
the website and would need rewriting; do not use that form in the handbook.

This has a source-browsing tradeoff: published-route links do not navigate
between Markdown files on GitHub. Once the canonical handbook address is
confirmed and live, full WordPress.org page URLs can work from either place
without a publishing transform. Until then, do not hard-code the proposed URL
as though the handbook were already live.

Relative source-code links belong only in repository-facing pages, which are
not imported. Prefer linking to a whole source file over naming a private
method or line range. If a line range is necessary to explain past behavior,
link to a specific commit so edits cannot move the referenced lines.

## One-time WordPress.org registration

Coordinate the URL, handbook label, initial import, and discovery links with
Meta before deployment. This PR prepares source content only.

The current
[DevHub importer](https://github.com/WordPress/wporg-developer/blob/trunk/source/wp-content/themes/wporg-developer-2023/inc/import-docs.php)
registers a handbook and recurring imports from a Markdown manifest. In a
separate `WordPress/wporg-developer` PR, add
`source/wp-content/themes/wporg-developer-2023/inc/import-reprint.php`:

```php
<?php

class DevHub_Reprint_Importer extends DevHub_Docs_Importer {
    /** Registers the handbook and its scheduled Markdown imports. */
    public function init() {
        parent::do_init(
            'reprint',
            'reprint',
            'https://raw.githubusercontent.com/WordPress/reprint/trunk/docs/manifest.json'
        );
    }
}

DevHub_Reprint_Importer::instance()->init();
```

Require it in the theme's `functions.php` inside the existing
`class_exists( '\\WordPressdotorg\\Markdown\\Importer' )` conditional, alongside
`import-playground.php`. The short post-type base creates `reprint-handbook`.
No Reprint-specific Markdown filters are needed.

Registration alone does not add a homepage card, sidebar/menu arrangement, or
links from the plugin and product pages. Include the Developer Resources entry
link in the Meta review and arrange the other links after the handbook is live.

After that PR is reviewed, merged, and built, a permitted Meta deployer follows
[the wporg-developer deployment procedure](https://make.wordpress.org/meta/handbook/tutorials-guides/wordpress-org-sandboxes/deploying-wordpress-org/#wporg-developer).
Do not deploy the importer from the Reprint release workflow.

## First import and checks

Test the real importer and Markdown parser in a disposable local WordPress
installation first. Confirm that every manifest page renders, tables and code
survive, and unchanged relative links reach the expected pages.

WordPress.org sandboxes share production databases and cache. An import there
creates real records. Coordinate the initial import with Meta and read
[Working on a sandbox](https://make.wordpress.org/meta/handbook/tutorials-guides/wordpress-org-sandboxes/working-on-a-sandbox/)
before running commands. After the registration is deployed, the permitted
operator can run:

```sh
cd /home/wporg/public_html
wp --url=https://developer.wordpress.org cron event run devhub_reprint_import_manifest
wp --url=https://developer.wordpress.org cron event run devhub_reprint_import_all_markdown
wp --url=https://developer.wordpress.org rewrite flush
wp --url=https://developer.wordpress.org post list \
  --post_type=reprint-handbook --fields=ID,post_title,post_name,post_parent
```

Check the proposed home and every imported page. Compare the record count with
the manifest, not a hard-coded count. Verify child slugs, home landing behavior,
sidebar order, edit-source links, code blocks, tables, internal links, and the
WordPress.org client download. Confirm how the handbook appears in site search
and navigation; creating a post type does not confirm discoverability.

Only after those checks, change the README, product page, and plugin listing to
link to the confirmed live handbook URLs. Keep GitHub links for development and
source contribution; user downloads remain on WordPress.org. If any handbook
URLs have already been published elsewhere, preserve their slugs or arrange
redirects before changing them.

## Ongoing updates

The shared importer schedules manifest and Markdown imports every 15 minutes.
After registration, ordinary handbook updates merge here and are imported from
`trunk`; they do not require another theme deployment. The schedule still
depends on WordPress.org cron running successfully.

Before publishing instructions, check them against the stable client and
matching plugin users can download, as well as current source help. Label
build-specific features and limits. In particular, do not turn source-build
push notes into instructions for the downloads-only WordPress.org plugin.
Unreleased work stays out of normal getting-started instructions.

Keep one maintained copy of each command/output contract. Link to reference
from task guides instead of copying long option lists. Update the relevant
page with behavior changes, and run the manifest/link tests before merging.
The native-import smoke test is needed when changing page structure or syntax.
