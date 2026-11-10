# Releasing Reprint

Use this guide to publish a stable client and plugin, retry a WordPress.org deployment, or update the plugin-directory screenshots.

## Before starting

Choose a tested commit on `trunk` and the version you want to release. Anyone with write access to the repository can run the release and WordPress.org publishing workflows.

The deployment needs the `svn` GitHub Actions environment with `SVN_USERNAME` and `SVN_PASSWORD`. The environment's deployment branch policy allows only `trunk`, so a workflow running on any other branch or tag cannot read those secrets. Keep that policy: it, not the workflow code, controls access to the SVN password. The current SVN username is `zieladam`, with that exact case. Use its generated [SVN account password](https://profiles.wordpress.org/zieladam/profile/edit/group/3/?screen=svn-password), not its WordPress.org login password. The version-bump workflow also needs `GH_TOKEN` from an account allowed to bypass the `trunk` ruleset.

## Publish a stable version

1. Open [Tag Release](https://github.com/WordPress/reprint/actions/workflows/tag-release.yml) and run it on `trunk`. Choose `patch`, `minor`, `major`, or enter a custom version.
2. Check the tag it creates, in the form `vMAJOR.MINOR.PATCH`. The build uses that tag to publish the GitHub release and Composer packages.
3. Check [Deploy to WordPress.org](https://github.com/WordPress/reprint/actions/workflows/release-wporg.yml). It refuses tags whose commit is not on `trunk`, then rebuilds the client and downloads-only plugin from the tag, validates them, and uploads them to the Reprint SVN repository. It does not deploy replacement attachments from a GitHub release. Drafts and prereleases are rejected.
4. Download the [stable client](https://plugins.svn.wordpress.org/reprint/downloads/reprint.phar) and check its version. Check the version and download on the [plugin page](https://wordpress.org/plugins/reprint/).

The workflow also opens and merges a PR for the next `-dev` version on `trunk`. Before merging it, the workflow checks that the exact commit changes only the plugin version strings. A failed version-only check stops that bump; it does not undo the release tag. This version-only PR can merge even if the full test suite fails. Other PRs still require normal CI.

The version-bump check uses the token's admin bypass. If you need an approval rule that workflow edits cannot remove, configure required reviewers on the `svn` environment separately.

## Check the client download

Each release stores the PHAR at `downloads/VERSION/reprint.phar`, outside the plugin's `trunk` and `tags` directories. The client is therefore not included when someone installs the WordPress plugin.

The unversioned WordPress.org URL points to the highest stable version already published in `downloads/`. Rerunning an older release does not move it backward. The workflow uploads the client before the plugin so the new readme does not link to a download that is still missing.

## Retry a deployment

Run [Deploy to WordPress.org](https://github.com/WordPress/reprint/actions/workflows/release-wporg.yml) manually on `trunk` and enter the existing release tag. It rebuilds from that tag, so changing a GitHub release attachment will not change the deployment.

The client upload can run again. An already-published plugin version is left unchanged. Check the failing job before deciding whether you need a new version.

## Update screenshots without releasing code

Run [Update WordPress.org screenshots](https://github.com/WordPress/reprint/actions/workflows/update-wporg-screenshots.yml) on `trunk`. It uses images in `.wordpress-org/` and captions in the plugin readme's `Screenshots` section. It updates the published assets and that readme section, leaving the plugin PHP, version, requirements, and other text unchanged.

This job uses the same `svn` environment and deployment queue as releases. After it completes, check the images and captions on the plugin page.
