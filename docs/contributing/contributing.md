# Contribute to Reprint

You can contribute a bug report, documentation change, test, or code change.
Choose the work you want to do:

| Your goal | Where to start |
| --- | --- |
| Report a problem | Open a [development issue](https://github.com/WordPress/reprint/issues). Include the versions, the command with credentials removed, and the result you expected. |
| Change code or tests | [Set up Reprint development](https://developer.wordpress.org/reprint/contributing/development/) to run a checkout and test your change. |
| Change documentation | Follow the documentation steps in [Set up Reprint development](https://developer.wordpress.org/reprint/contributing/development/). |
| Understand a transfer before changing it | Read [Architecture and durable state](https://developer.wordpress.org/reprint/contributing/architecture/). |
| Publish a version or update screenshots | Follow [Releasing Reprint](https://developer.wordpress.org/reprint/contributing/releases/). |

## Work on a transfer protocol

Start with a working copy made by the client. When you need to change what the
client and server send each other, use the relevant reference:

- [Pull HTTP protocol](https://developer.wordpress.org/reprint/contributing/pull-http/): request fields, path encoding, and continuing a download.
- [File push implementation](https://developer.wordpress.org/reprint/contributing/push/): choosing local changes, uploading them, and applying them on the target.
- [Database push implementation](https://developer.wordpress.org/reprint/contributing/database-push/): preparing replacement tables and confirming a full overwrite.

File and database push require a separately configured source build. The
WordPress.org plugin provides downloads only.

For the reasons behind HTTP access, streaming, and client-side comparison,
read [How Reprint works](https://developer.wordpress.org/reprint/how-it-works/).
