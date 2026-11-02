# Documentation structure and publishing

Reprint's public documentation source targets one project handbook at
`https://developer.wordpress.org/reprint/`. The source links are prepared for
that address; registration and deployment still need coordination with Meta.
Merging this repository does not create that handbook or publish its pages.

## Why a project handbook

[Playground](https://developer.wordpress.org/playground/) already has a project
handbook on Developer Resources with starting guides, developer material, and
reference. Reprint needs the same kinds of pages, but not several subsites yet.

Keep all Reprint user and integration documentation together:

| Location | Role |
| --- | --- |
| `developer.wordpress.org/reprint/` | Target handbook: tutorials, task guides, integration, reference, and explanation. |
| `wordpress.org/reprint/` | Product introduction and goal-based links into the handbook. |
| WordPress.org plugin directory | Plugin installation, requirements, downloads, and support; link onward for client workflows. |
| Repository README | Short project introduction, working example, and documentation/contribution links. |
| Handbook contributor section | Development setup, architecture, protocol reference, tests, and releases. |
| Repository-only working files | Code naming rules, agent instructions, and this deployment plan. |
| Developer Blog or Learn WordPress | Optional introductions and lessons linking to the handbook, not another command reference. |

Do not put Reprint commands in the
[WordPress core Code Reference](https://developer.wordpress.org/reference/),
which describes WordPress functions, classes, and hooks. Reprint is also a
separate client, not a built-in WP-CLI command. The Plugin Handbook teaches
plugin development; it is not the home for using this particular plugin/client.

## Audiences and page types

| Reader | What you need | Starting point |
| --- | --- | --- |
| Curious WordPress user | See what Reprint does and try it. | Demo and first-copy tutorial. |
| Person copying a site | Make a working copy and check it. | Copy tutorial, task guides, and troubleshooting. |
| Administrator authorizing a copy | Install the plugin and authorize the client. | Short authorization guide. |
| Product developer | Run the client in your product and interpret its results. | Integration guide and public command/output reference. |
| Host or support engineer | Check environment requirements and find the cause of a failure. | Requirements, hosting guide, progress/results, and troubleshooting. |
| Reprint contributor | Set up a checkout, test your change, and submit it. | Handbook contributor guide and development setup. |

A tutorial takes one tested route to a first result. A task guide starts with a
specific goal and ends with checks. Reference gives exact commands, options,
outputs, and limits. Explanation builds the reader's model of the system.
The [WP-CLI handbook](https://make.wordpress.org/cli/handbook/) also separates
user/developer guides, reference, and contributing.

The handbook home groups pages under Guides, Build with Reprint, Reference,
Understand Reprint, and Contributing. Readers enter by what they want to do:
try Reprint, clone a site, or add it to a product. A Try Reprint entry must include steps that complete a real
copy; visiting the sample site alone does not try the client.

Use the reader's goal as link text. Address readers as "you" and "your".
Add a description only when it helps them
choose, such as a required login or a destination limitation. Do not repeat
the link label or announce what kind of document follows.

A technical detail belongs in public reference when a caller relies on it.
A local implementation quirk belongs beside the code and in a regression test.
Describe requirements as capabilities, such as OpenSSL support, rather than
checks for PHP functions. Keep command options, output fields, limits, and
side effects exact; leave driver calls and parser bookkeeping with the source.
Explain the larger design choices and tradeoffs in How Reprint works. Keep
local implementation reasons with the code and regression tests, rather than
growing a separate catalogue of past bugs. Keep proposed work and past patch
narratives in issues and PRs instead of presenting them as current instructions.

## What this repository publishes

`docs/manifest.json` lists the pages to import from `docs/handbook/` and
`docs/contributing/`. The contributor section includes development setup,
architecture, protocol reference, and releases. This deployment plan and the
code-naming contract stay in the repository; they are not introductory or
reference pages for handbook readers.

Pages use plain Markdown, fenced code, and tables. Source filenames describe
the page. There is no MDX conversion, build output, or custom link rewriting.
Put example addresses in fenced code or plain text; the native Markdown parser
can swallow links after a URL in an inline-code span.

## Website and repository links

The imported handbook pages use complete website URLs for links to other
handbook pages. Keep section anchors when linking to a heading. The root
README and CONTRIBUTING file links stay relative so they open documentation
in the GitHub branch being read.

Keep external links, source-code links, and downloads as complete HTTPS URLs.
Files outside the manifest, such as this publishing plan and the terminology
contract, use complete GitHub URLs. There are no importer filters or a
link-rewriting build.

The handbook targets are:

```text
https://developer.wordpress.org/reprint/
https://developer.wordpress.org/reprint/try-reprint/
https://developer.wordpress.org/reprint/contributing/architecture/
```

`HandbookManifestTest.php` maps the manifest to website URLs and checks that
page links name an imported page. It rejects relative page links in imported
content and checks that every page can be reached from the handbook home.
These checks use the source files, not HTTP requests; website availability
still needs checking after deployment.

The handbook home has slug `handbook` and no parent. The Handbook plugin serves
that landing page at `/reprint/`. The main pages also have no parent, so a page
such as `copy-a-site` becomes `/reprint/copy-a-site/`. Giving it `handbook` as a
parent would instead add `/handbook/` to the child URL.

The contributor home has slug `contributing` and no parent. Its child pages
use `contributing` as their parent, which gives them
`/reprint/contributing/PAGE/` URLs. Keep the existing seven slugs. Check the
actual permalinks with the WordPress.org Handbook plugin as well as the
Markdown importer; importing content into ordinary WordPress pages does not
check handbook routing.

Link to source code with a complete GitHub URL. Prefer a whole file over a
private method or a line range. If a line range is necessary, pin the link to
a commit so later edits cannot move those lines. User downloads stay on
WordPress.org.

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

The demo's `/public.key` is a separate setup task. The tutorial keeps its
intended address, which returns 404 until the demo key is configured. The file
must contain an unencrypted PEM client signing key whose matching public key is
authorized on the demo for downloads. A public verification key alone cannot
sign client requests. Use a key dedicated to the demo, and confirm that its
export contains only data and files intended to be public.

After configuring the key, run the documented download and copy commands
against the live demo, then check the local posts, photographs, and
generated-server restart. Do not claim that the demo copy is verified before
these checks pass. Do not replace the key URL with a placeholder or describe
the Playground button as a Reprint copy until that route actually runs the
client.

Test the real importer and Markdown parser in a disposable local WordPress
installation first. Confirm that every manifest page renders, tables and code
survive, and the prepared website links reach the expected pages.

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

If an earlier manifest has already been imported, inspect `post_parent` in the
listed records before using the new URLs. The Markdown importer does not
automatically reparent existing pages. Main pages need parent `0`; only the
contributor reference pages use the contributor home as their parent. A Meta
operator can correct a selected record with:

```sh
wp --url=https://developer.wordpress.org post update PAGE_ID --post_parent=0
```

Replace `PAGE_ID` with the inspected main-page ID. Do not apply that command
to contributor child pages. Recheck the resulting permalinks before updating
links elsewhere.

Coordinate the website-link change, registration deployment, and first import;
the handbook URLs return 404 until those steps finish. Merging the link
preparation alone does not publish the handbook. The root README and
CONTRIBUTING links still open the repository files.
Check the imported website links before updating the product page and plugin
listing.
Keep GitHub links for development and source contribution; user downloads
remain on WordPress.org. If any handbook
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
