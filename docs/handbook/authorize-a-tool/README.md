# Authorize a tool to copy your site

Use this guide when a migration tool, developer, or Reprint client asks for
access to copy your WordPress site. You only need WordPress admin for these
steps. Continue the actual transfer in the tool that sent you here.

Only authorize a tool you trust. It can download database data and files,
including private content, password hashes, and configuration secrets.

## Install the plugin

1. Sign in to the site as an administrator.
2. Open **Plugins → Add New Plugin** and search for **Reprint**.
3. Install and activate the
   [Reprint plugin from WordPress.org](https://wordpress.org/plugins/reprint/).
4. Open **Tools → Reprint Server**.

Activation alone does not authorize a client or start a transfer.
If your host prevents plugin installation, ask the host or site administrator
to install it.

## Authorize the tool

1. Return to the tool and copy its **Migration Token**.
2. In **Tools → Reprint Server**, find **Authorize a tool to copy this site**.
3. Paste the token into **Migration Token** and choose **Authorize tool**.
4. Return to the tool and start or resume the transfer there.

The Migration Token is the public part of the client's key. Never paste a
private key. Reprint accepts the public token as one line or a PEM block.

The confirmation means access was saved. It does not mean the client has
connected or finished copying your site. Check progress in the original tool.

If you are using the command-line client and do not have a token yet, follow
[Copy a site to your computer](../copy-a-site/).

## If the screen asks for a connection token

Hosts without the OpenSSL PHP extension use a shared connection token instead
of a Migration Token. The settings screen tells you which kind it accepts.

Paste the connection token supplied by your tool, or generate one on the
settings screen and copy it into the tool. Choose **Save connection token**.
Keep it secret: anyone who has it can download your site data.

For the command-line client, pass the saved value with `--secret='TOKEN'` when
running `pull`. Replace `TOKEN` with the actual value. Be aware that putting a
secret in a terminal command can leave it in shell history.

## Remove access

In **Tools → Reprint Server**, find the tool's Migration Token ID and choose
**Remove Migration Token**. For connection-token access, clear or replace the
connection token; this disconnects every tool using the old value.

If the screen says your host manages credentials through `public-keys.php`
or `secret.php`, ask the host to change them. This screen cannot edit those
files. Removing access does not delete copies already downloaded.

## Multisite networks

A network administrator must network-activate the plugin and manage access
in the network's Reprint Server settings. Access can authorize downloads from
sites in that network. Do not treat it as permission limited to one site.
The local-copy tutorial covers single-site installations only.

Next: return to your tool, or read [Troubleshooting](../troubleshooting/).
