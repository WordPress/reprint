# Use Windows APIs to create literal filenames that ordinary path cleanup changes.
# The PHP source must copy readable names and reject unreadable names, never a sibling.
param([string]$ManifestPath)
$ErrorActionPreference = 'Stop'
Add-Type @'
using System;
using System.ComponentModel;
using System.Runtime.InteropServices;
using System.Text;
using Microsoft.Win32.SafeHandles;
public static class NamespaceFixtures {
    [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)]
    static extern SafeFileHandle CreateFileW(string name, uint access, uint sharing, IntPtr security, uint creation, uint flags, IntPtr template);
    [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)]
    static extern bool CreateDirectoryW(string name, IntPtr security);
    [DllImport("kernel32.dll", SetLastError=true)]
    static extern bool WriteFile(SafeFileHandle file, byte[] bytes, uint length, out uint written, IntPtr overlapped);
    [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)]
    static extern bool GetVolumeNameForVolumeMountPointW(string root, StringBuilder name, uint length);
    [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)]
    static extern uint QueryDosDeviceW(string name, StringBuilder target, uint length);
    [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)]
    [return: MarshalAs(UnmanagedType.I1)]
    static extern bool CreateSymbolicLinkW(string name, string target, uint flags);
    /// <summary>Stores a real Windows link target with the requested slash spelling.</summary>
    public static void Link(string name, string target, bool directory) {
        // PowerShell's location need not be the native process's current directory.
        var previous = Environment.CurrentDirectory;
        try {
            Environment.CurrentDirectory = System.IO.Path.GetDirectoryName(name);
            if (!CreateSymbolicLinkW(name, target, directory ? 1u : 0u)) throw new Win32Exception(Marshal.GetLastWin32Error(), name);
        } finally {
            Environment.CurrentDirectory = previous;
        }
    }
    /// <summary>Writes exact bytes through the literal Win32 filename.</summary>
    public static void Write(string name, string content) {
        using (var file = CreateFileW(name, 0x40000000, 7, IntPtr.Zero, 2, 0x80, IntPtr.Zero)) {
            if (file.IsInvalid) throw new Win32Exception(Marshal.GetLastWin32Error(), name);
            var bytes = Encoding.UTF8.GetBytes(content);
            uint written;
            if (!WriteFile(file, bytes, (uint)bytes.Length, out written, IntPtr.Zero) || written != bytes.Length)
                throw new Win32Exception(Marshal.GetLastWin32Error(), name);
        }
    }
    /// <summary>Creates a directory without removing its trailing dot or space.</summary>
    public static void Directory(string name) {
        if (!CreateDirectoryW(name, IntPtr.Zero)) throw new Win32Exception(Marshal.GetLastWin32Error(), name);
    }
    /// <summary>Returns the real volume GUID for a fixture drive.</summary>
    public static string Volume(string root) {
        var result = new StringBuilder(1024);
        if (!GetVolumeNameForVolumeMountPointW(root, result, 1024)) throw new Win32Exception(Marshal.GetLastWin32Error());
        return result.ToString();
    }
    /// <summary>Returns the drive device target for a GLOBALROOT fixture.</summary>
    public static string Device(string drive) {
        var result = new StringBuilder(1024);
        if (QueryDosDeviceW(drive, result, 1024) == 0) throw new Win32Exception(Marshal.GetLastWin32Error());
        return result.ToString();
    }
}
'@
$root = 'D:\Reprint namespace cases'
New-Item -ItemType Directory -Force "$root\Mixed Case" | Out-Null
[NamespaceFixtures]::Write("\\?\$root\Mixed Case\hello.txt", 'namespace file')
# Keep unreadable names separate so ordinary namespace selections still prove a
# complete successful pull. Parent traversal of this tree must fail explicitly.
$literalRoot = 'D:\Reprint literal cases'
New-Item -ItemType Directory -Force "$literalRoot\Mixed Case" | Out-Null
$literalError = 'Cannot read the exact Windows filename'
$destination = 'D:/Reprint namespace cases/Mixed Case/hello.txt'
$cases = [ordered]@{}
$spellings = [ordered]@{
    'ordinary-drive' = "$root\Mixed Case"
    'literal-drive' = "\\?\$root\Mixed Case"
    'device-drive' = "\\.\$root\Mixed Case"
    'device-share' = '\\.\UNC\localhost\D$\Reprint namespace cases\Mixed Case'
    'literal-share' = '\\?\UNC\localhost\D$\Reprint namespace cases\Mixed Case'
    'volume-guid' = [NamespaceFixtures]::Volume('D:\') + 'Reprint namespace cases\Mixed Case'
    'global-root' = '\\.\GLOBALROOT' + [NamespaceFixtures]::Device('D:') + '\Reprint namespace cases\Mixed Case'
    'parent-components' = "$root\Mixed Case\..\Mixed Case"
    'current-components' = "$root\.\Mixed Case"
    'folder-case' = 'd:\reprint NAMESPACE cases\mixed CASE'
    'root-relative' = '\Reprint namespace cases\Mixed Case'
    'forward-share' = '//localhost/D$/Reprint namespace cases/Mixed Case'
    'ip-share' = '\\127.0.0.1\D$\Reprint namespace cases\Mixed Case'
}
foreach ($entry in $spellings.GetEnumerator()) {
    $local = $destination
    if ($entry.Key -in @('device-share', 'literal-share', 'forward-share')) { $local = 'UNC/LOCALHOST/D$/Reprint namespace cases/Mixed Case/hello.txt' }
    if ($entry.Key -eq 'ip-share') { $local = 'UNC/127.0.0.1/D$/Reprint namespace cases/Mixed Case/hello.txt' }
    $cases[$entry.Key] = @{source=$entry.Value; destination=$local; content='namespace file'}
    # Selections follow the same absolute-path contract as Unix. Namespace
    # inputs and paths which need a working directory are rejected locally.
    if ($entry.Key -in @('literal-drive', 'device-drive', 'device-share', 'literal-share', 'volume-guid', 'global-root', 'root-relative')) {
        $cases[$entry.Key]['error'] = 'must be an absolute path'
    }
    if ($entry.Key -in @('parent-components', 'current-components')) {
        $cases[$entry.Key]['error'] = 'must not contain dot-segments'
    }
}
# Even an existing relative path is rejected. Neither process supplies an
# implicit base directory for a CLI selection.
New-Item -ItemType Directory -Force '.\relative source' | Out-Null
[System.IO.File]::WriteAllText("$pwd\relative source\hello.txt", 'relative file')
foreach ($entry in @{ 'directory-relative'='.\relative source'; 'drive-relative'='D:relative source' }.GetEnumerator()) {
    $cases[$entry.Key] = @{source=$entry.Value; error='must be an absolute path'}
}
$cases['file-case'] = @{
    source="$root\Mixed Case\HELLO.TXT"
    destination=$destination
    content='namespace file'
    absent=@('D:/Reprint namespace cases/Mixed Case/HELLO.TXT')
}
$cases['file-case-not-followed'] = @{
    source="$root\Mixed Case\HELLO.TXT"
    destination=$destination
    content='namespace file'
    options=@('--no-follow-symlinks')
    absent=@('D:/Reprint namespace cases/Mixed Case/HELLO.TXT')
}
$cases['file-case-share'] = @{
    source='\\localhost\d$\Reprint namespace cases\Mixed Case\HELLO.TXT'
    destination='UNC/LOCALHOST/D$/Reprint namespace cases/Mixed Case/hello.txt'
    content='namespace file'
    absent=@('UNC/LOCALHOST/D$/Reprint namespace cases/Mixed Case/HELLO.TXT')
}
$cases['combined-file-case'] = @{
    source=@("$root\Mixed Case\hello.txt", "$root\Mixed Case\HELLO.TXT")
    destination=$destination
    content='namespace file'
    absent=@('D:/Reprint namespace cases/Mixed Case/HELLO.TXT')
    unique_basename='hello.txt'
}
[NamespaceFixtures]::Write("\\?\$root\Mixed Case\trailing", 'ordinary sibling!')
$literalNames = @('NUL.txt', 'COM1.txt', 'COM¹.txt')
# Same-size siblings expose metadata aliasing: size checks cannot detect it.
[NamespaceFixtures]::Write("\\?\$literalRoot\Mixed Case\trailing", 'ordinary sibling!')
foreach ($name in @('trailing.', 'trailing ')) {
    [NamespaceFixtures]::Write("\\?\$literalRoot\Mixed Case\$name", "literal $name")
    $cases['trailing-name-' + $cases.Count] = @{source="$literalRoot\Mixed Case\$name"; error=$literalError}
}
$cases['trailing-name-parent'] = @{source="$literalRoot\Mixed Case"; error=$literalError}
foreach ($name in $literalNames) {
    [NamespaceFixtures]::Write("\\?\$root\Mixed Case\$name", "literal $name")
    $cases['literal-name-' + $cases.Count] = @{source="$root\Mixed Case\$name"; destination="D:/Reprint namespace cases/Mixed Case/$name"; content="literal $name"}
}
# Selecting the parent must preserve each readable reserved name too; checking
# hello.txt alone would miss a traversal that silently omitted those entries.
foreach ($key in $spellings.Keys) {
    $directory = $cases[$key].destination.Substring(0, $cases[$key].destination.Length - 'hello.txt'.Length)
    $files = @(
        @{destination=($directory + 'hello.txt'); content='namespace file'},
        @{destination=($directory + 'trailing'); content='ordinary sibling!'}
    )
    foreach ($name in $literalNames) {
        $files += @{destination=($directory + $name); content="literal $name"}
    }
    $cases[$key]['files'] = $files
}
# PHP's ordinary drive spelling can read an existing literal NUL.txt file.
# An actual device has no file suffix; the physical-device test covers rejection.
$cases['reserved-file'] = @{source="$root\Mixed Case\NUL.txt"; destination='D:/Reprint namespace cases/Mixed Case/NUL.txt'; content='literal NUL.txt'}
$cases['physical-device'] = @{source='\\.\PhysicalDrive0'; error='must be an absolute path'}

# Both spellings exist. A source that lowercases names would silently lose a file.
New-Item -ItemType Directory -Force "$root\case-sensitive" | Out-Null
fsutil.exe file setCaseSensitiveInfo "$root\case-sensitive" enable
if ($LASTEXITCODE -ne 0) { throw 'Could not enable case-sensitive names for the native fixture.' }
[NamespaceFixtures]::Write("\\?\$root\case-sensitive\item.txt", 'lowercase file')
[NamespaceFixtures]::Write("\\?\$root\case-sensitive\ITEM.txt", 'uppercase file')
$cases['case-sensitive-directory'] = @{
    source="$root\case-sensitive"
    files=@(
        @{destination='D:/Reprint namespace cases/case-sensitive/item.txt'; content='lowercase file'},
        @{destination='D:/Reprint namespace cases/case-sensitive/ITEM.txt'; content='uppercase file'}
    )
}
# Case-sensitive Windows directories must keep distinct files separate in
# both exclusions and remaps, just like Unix directories.
$cases['case-sensitive-exclude'] = @{
    source="$root\case-sensitive"
    options=@('--exclude', "$root\case-sensitive\item.txt")
    files=@(@{destination='D:/Reprint namespace cases/case-sensitive/ITEM.txt'; content='uppercase file'})
    absent=@('D:/Reprint namespace cases/case-sensitive/item.txt')
}
$cases['case-sensitive-remap'] = @{
    source="$root\case-sensitive"
    options=@('--remap', "$root\case-sensitive\item.txt", ':fs-root:/moved-item.txt')
    files=@(
        @{destination='moved-item.txt'; content='lowercase file'},
        @{destination='D:/Reprint namespace cases/case-sensitive/ITEM.txt'; content='uppercase file'}
    )
    absent=@('D:/Reprint namespace cases/case-sensitive/item.txt')
}
New-Item -ItemType Directory -Force "$literalRoot\folder" | Out-Null
[NamespaceFixtures]::Write("\\?\$literalRoot\folder\hello.txt", 'ordinary folder')
foreach ($name in @('folder.', 'folder ')) {
    [NamespaceFixtures]::Directory("\\?\$literalRoot\$name")
    [NamespaceFixtures]::Write("\\?\$literalRoot\$name\hello.txt", "literal $name")
    $cases['literal-directory-' + $cases.Count] = @{source="$literalRoot\$name"; error=$literalError}
}
# Listing an ordinary parent must not silently skip unreadable children.
$cases['literal-directory-parent'] = @{source=$literalRoot; error=$literalError}
# Equivalent ordinary drive spellings must not create separate Linux trees.
$cases['combined-drive-aliases'] = @{
    source=@($spellings['ordinary-drive'], $spellings['ordinary-drive'].Replace('\', '/'), $spellings['ordinary-drive'].Replace('D:', 'd:'))
    destination=$destination
    content='namespace file'
    files=$cases['ordinary-drive'].files
    unique_basename='hello.txt'
}

# An ordinary non-empty sibling must not hide an empty literal directory.
New-Item -ItemType Directory -Force "$literalRoot\empty" | Out-Null
[NamespaceFixtures]::Write("\\?\$literalRoot\empty\hello.txt", 'non-empty sibling')
foreach ($name in @('empty.', 'empty ')) {
    [NamespaceFixtures]::Directory("\\?\$literalRoot\$name")
    $cases['literal-empty-' + $cases.Count] = @{source="$literalRoot\$name"; error=$literalError}
}

# Keep link targets outside this selection so --no-follow-symlinks can prove
# that indexing a link does not also grant access to its target tree.
$linkRoot = 'D:\Reprint link cases'
New-Item -ItemType Directory -Force $linkRoot | Out-Null
New-Item -ItemType Junction -Path "$linkRoot\junction" -Target "$root\Mixed Case" | Out-Null
New-Item -ItemType SymbolicLink -Path "$linkRoot\file-link" -Target "$root\Mixed Case\hello.txt" | Out-Null
$cases['junction-followed'] = @{
    source="$linkRoot\junction"
    destination='D:/Reprint link cases/junction/hello.txt'
    content='namespace file'
    links=@('D:/Reprint link cases/junction')
}
$cases['file-link-followed'] = @{
    source="$linkRoot\file-link"
    destination='D:/Reprint link cases/file-link'
    content='namespace file'
    links=@('D:/Reprint link cases/file-link')
}
# Relative targets must use their source link's directory, even on the Linux client.
foreach ($spelling in @('backslash', 'forward-slash', 'root-relative', 'absolute-forward-slash')) {
    foreach ($kind in @('directory', 'file')) {
        $name = "target-$spelling-$kind"
        $target = '..\Reprint namespace cases\Mixed Case'
        if ($spelling -eq 'root-relative') { $target = '\Reprint namespace cases\Mixed Case' }
        $sourceTarget = "$root\Mixed Case"
        if ($kind -eq 'file') { $target += '\hello.txt'; $sourceTarget += '\hello.txt' }
        if ($spelling -eq 'forward-slash') { $target = $target.Replace('\', '/') }
        if ($spelling -eq 'absolute-forward-slash') { $target = $sourceTarget.Replace('\', '/') }
        [NamespaceFixtures]::Link("$linkRoot\$name", $target, $kind -eq 'directory')
        $local = "D:/Reprint link cases/$name"
        $cases[$name] = @{
            source="$linkRoot\$name"
            destination=($local + $(if ($kind -eq 'directory') { '/hello.txt' } else { '' }))
            content='namespace file'
            links=@($local)
            options=@('--remap', $sourceTarget, ':fs-root:/moved-target')
        }
        if ($spelling -eq 'forward-slash') {
            $cases[$name]['error'] = 'PHP cannot read the Windows link target'
        }
    }
}
# An unreadable target discovered under a parent must fail again on resume.
New-Item -ItemType Directory -Force 'D:\Reprint unreadable links' | Out-Null
[NamespaceFixtures]::Link('D:\Reprint unreadable links\directory', '../Reprint namespace cases/Mixed Case', $true)
$cases['unreadable-link-parent'] = @{source='D:\Reprint unreadable links'; error='PHP cannot read the Windows link target'}
[NamespaceFixtures]::Link("$linkRoot\cycle-a", './cycle-b', $false)
[NamespaceFixtures]::Link("$linkRoot\cycle-b", './cycle-a', $false)
$cases['junction-not-followed'] = @{
    source="$linkRoot\junction"
    files=@()
    options=@('--no-follow-symlinks')
    links=@('D:/Reprint link cases/junction')
    absent=@('D:/Reprint namespace cases')
}
$cases['parent-junction-not-followed'] = @{
    source="$linkRoot\junction\hello.txt"
    options=@('--no-follow-symlinks')
    error='use --follow-symlinks'
}

New-Item -ItemType Directory -Force 'D:\Reprint chunk boundaries' | Out-Null
[NamespaceFixtures]::Write('\\?\D:\Reprint chunk boundaries\readable', ('A' * 16384))

# Keep this read probe independent from the full WordPress fixture.
New-Item -ItemType Directory -Force 'D:\Reprint reader UNC' | Out-Null
[NamespaceFixtures]::Write('\\?\D:\Reprint reader UNC\readable.txt', 'short UNC file')
[NamespaceFixtures]::Write(('\\?\D:\Reprint reader UNC\' + ('a' * 251) + '.txt'), 'long UNC file')

$cases | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath $ManifestPath -Encoding utf8NoBOM
