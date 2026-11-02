# Copy a Windows-hosted site

Use the normal [copy tutorial](https://developer.wordpress.org/reprint/copy-a-site/) with a Linux, macOS, or WSL
client. Update both the source plugin and the client to versions with Windows
path support. These notes explain source paths and checks before migration;
they are not instructions for running the client in native Windows PHP.

## Choose source paths

Source selections accept full drive-letter paths such as `D:\Sites\example.test`
and network shares such as `\\server\share\site`. A share must name both the
server and share. WordPress tokens, such as `:wp-content:/uploads`, avoid
having to spell the host's absolute path.

Without flattening, paths under `--fs-root` keep drives and shares separate:

| Source path | Downloaded path under the filesystem root |
| --- | --- |
| `D:\Sites\example.test\index.php` | `D:/Sites/example.test/index.php` |
| `c:/Sites\example.test/index.php` | `C:/Sites/example.test/index.php` |
| `\\server\share\site\index.php` | `UNC/SERVER/SHARE/site/index.php` |

Use `--flatten-to=/var/www/site` when you need an assembled WordPress directory
instead. The assembled layout uses symlinks; keep the raw download tree.

Drive paths accept forward, backward, mixed, and repeated separators.
Selections must be absolute. Relative paths such as `.\site`, `\site`, and
`D:site`, dot segments, and namespace spellings beginning `\\?\` or `\\.\`
are rejected. Use an ordinary drive or share spelling for those selections.

## Resolve source and destination limits

- Rename file or directory components ending in a dot or space before copying.
  PHP can confuse these with other NTFS names; Reprint stops rather than
  silently copying the wrong file. Repeating the pull does not repair the name.
- Correct filename case in code and URLs. A reference to `HELLO.TXT` may work
  for `hello.txt` on Windows and fail on Linux. Reprint preserves the actual
  case; selection and remapping rules do not correct alternate spellings.
- Shorten names that exceed the destination filesystem's limit. The limit can
  count UTF-8 bytes rather than visible characters. Reprint does not rename
  them automatically.
- Physical devices and named pipes are not migration files. NTFS alternate
  data streams are not copied; only the main file contents move.
- PHP can follow some Windows links but cannot read their target text.
  Such a link stops the pull. Recreate it with a backslash target, or use a
  full drive-letter target if PHP cannot resolve the link.

`--no-follow-symlinks` copies a selected link without indexing its target.
Selecting a path reached through a linked parent needs the default symlink
following behavior. See [File transfer reference](https://developer.wordpress.org/reprint/file-transfers/).

After copying, test assets and links on the destination's actual filesystem.
