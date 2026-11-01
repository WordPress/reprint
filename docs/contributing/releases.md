# Releasing Reprint

To cut a release, run the **Tag Release** workflow from the GitHub Actions tab
(`.github/workflows/tag-release.yml`) and pick a bump type (`patch`, `minor`,
`major`, or `custom`). It creates and pushes the `vMAJOR.MINOR.PATCH` tag, then
opens a PR for the next `-dev` version using the repository's `GH_TOKEN` secret.
The workflow verifies that the commit changes only the version strings in the
plugin version files, then merges that exact commit
with the token's admin bypass. Full-suite failures do not block this mechanical
bump. `GH_TOKEN` must belong to an account allowed to bypass the `trunk` ruleset.
Other PRs still require normal CI. A failed version-only check stops the bump
without blocking the release.
Completing **Tag Release** starts the build, which publishes the GitHub
Release (`reprint.phar`,
`reprint-exporter-wp.zip`, and `reprint-wordpress-org.zip`) and the Composer
packages to Packagist. After the GitHub release is published, **Deploy to
WordPress.org** builds the client and downloads-only plugin from the release
tag, checks them, then publishes them to the `reprint` SVN repository. It does
not use attachments from the GitHub release. Draft releases and prereleases
are rejected.

The client goes in `downloads/VERSION/reprint.phar`, outside the plugin's
`trunk` and `tags` folders, so it is not included in plugin installations.
The same upload publishes the highest stable version in `downloads/` at
`https://plugins.svn.wordpress.org/reprint/downloads/reprint.phar`.
Rerunning an older release keeps this URL on the newest published client,
including when the unversioned download is being created for the first time.
The plugin page links to the unversioned client on WordPress.org.
Version bumps leave this URL unchanged. The PHAR is uploaded before the plugin,
so the download exists before the new readme links to it.

The deployment job uses the GitHub Actions environment `svn` in
`WordPress/reprint`. Store its secrets there: `SVN_USERNAME` set to `zieladam`
(case-sensitive, not an email address), and `SVN_PASSWORD` set to the password
generated in the [SVN account settings](https://profiles.wordpress.org/zieladam/profile/edit/group/3/?screen=svn-password).
Do not use the account login password.

`adamziel` is the only publishing account allowed by `release-wporg.yml`.
The check runs before checking out release code. It checks who started or
reran this workflow and, for automatic releases, who started or reran **Tag Release**.
Repository admins are not automatically exempt from this list.

This is a workflow check, not a protected environment approval rule. Someone
who can change the workflow could remove the check. Configure required
reviewers on `svn` if approval must be enforced separately from workflow code.

To publish an existing GitHub release or retry only the SVN deployment, run
**Deploy to WordPress.org** manually and enter its tag, such as `v0.11.3`.
The deployment rebuilds from that tag, so replacing a GitHub release asset
does not change what it publishes. The client upload can be rerun; the plugin
deployment action leaves an already-published plugin version unchanged.

To update the directory screenshots without releasing plugin code, run
**Update WordPress.org screenshots** on `trunk`. Only `adamziel` can run it.
It uses the same `svn` environment and deployment queue as releases. Images
come from `.wordpress-org/`; captions come from the `Screenshots` section of
the plugin readme. The job copies only that section into the published
version's readme, leaving its version, requirements, and other text unchanged.
The SVN update changes only readmes and assets, not plugin PHP files.
