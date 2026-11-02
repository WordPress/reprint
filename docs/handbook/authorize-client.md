# Authorize the Reprint client

Use this page when a Reprint client has given you a Migration Token to paste
into WordPress. If you have not started a copy yet, follow
[Copy a site to your computer](https://developer.wordpress.org/reprint/copy-a-site/); it includes these steps.

Authorizing the client lets it download the site's database and files,
including private content.

## Install the plugin

1. Sign in to the site as an administrator.
2. Open **Plugins → Add New Plugin** and search for **Reprint**.
3. Install and activate the
   [Reprint plugin from WordPress.org](https://wordpress.org/plugins/reprint/).
4. Open **Tools → Reprint Server**.

Activation alone does not authorize a client or start a transfer.
If your host prevents plugin installation, ask the host or site administrator
to install it.

## Authorize the client

1. Copy the Migration Token printed by the client.
2. In **Tools → Reprint Server**, find **Authorize a tool to copy this site**.
3. Paste the token into **Migration Token** and choose **Authorize tool**.
4. Run the same client command again to start the copy.

The WordPress confirmation means the token was saved. Check copy progress
in your terminal.

## If the screen asks for a connection token

Some hosts use a shared connection token instead of a Migration Token.
Follow the form shown in the plugin settings.

Choose **Generate new token**, then **Save connection token**. This token acts
as a password for downloads; keep it private.

Pass the saved value to the client with `--secret='TOKEN'` when running `pull`.
Replace `TOKEN` with the actual value. Your shell history may retain the token.

## Remove access

In **Tools → Reprint Server**, find the client's Migration Token ID and choose
**Remove Migration Token**. For connection-token access, clear or replace the
connection token; this disconnects clients using the old value.

If the screen says your host manages credentials through `public-keys.php`
or `secret.php`, ask the host to change them. This screen cannot edit those
files. Previously downloaded copies stay on your computer.

## Multisite networks

A network administrator must network-activate the plugin and manage access
in the network's Reprint Server settings. Client access can cover multiple
sites in that network. The local-copy tutorial covers single-site installations
only.

Next: [Copy a site to your computer](https://developer.wordpress.org/reprint/copy-a-site/), or
[Troubleshooting](https://developer.wordpress.org/reprint/troubleshooting/).
