# Use Windows APIs to create literal filenames that ordinary path cleanup changes.
# The PHP source must read these real files; the fixture does not fake the exporter.
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
    [DllImport("kernel32.dll", SetLastError=true)]
    static extern bool WriteFile(SafeFileHandle file, byte[] bytes, uint length, out uint written, IntPtr overlapped);
    [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)]
    static extern bool GetVolumeNameForVolumeMountPointW(string root, StringBuilder name, uint length);
    [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)]
    static extern uint QueryDosDeviceW(string name, StringBuilder target, uint length);
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
$destination = 'D:/Reprint namespace cases/Mixed Case/hello.txt'
$cases = [ordered]@{}
$spellings = [ordered]@{
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
}
# The native server is started from this same checkout. Relative input must be
# resolved there, never against the Linux client's working directory.
New-Item -ItemType Directory -Force '.\relative source' | Out-Null
[System.IO.File]::WriteAllText("$pwd\relative source\hello.txt", 'relative file')
foreach ($entry in @{ 'directory-relative'='.\relative source'; 'drive-relative'='D:relative source' }.GetEnumerator()) {
    $cases[$entry.Key] = @{source=$entry.Value; destination="$pwd/relative source/hello.txt".Replace('\', '/'); content='relative file'}
}
foreach ($name in @('trailing.', 'trailing ', 'NUL.txt', 'COM1.txt', 'COM¹.txt')) {
    [NamespaceFixtures]::Write("\\?\$root\Mixed Case\$name", "literal $name")
    $cases['literal-name-' + $cases.Count] = @{source="\\?\$root\Mixed Case\$name"; destination="D:/Reprint namespace cases/Mixed Case/$name"; content="literal $name"}
}
# This names a device through normal Win32 lookup, not the literal NUL.txt above.
$cases['reserved-device'] = @{source="$root\Mixed Case\NUL.txt"; error='Windows device names cannot select migration files'}
$cases['physical-device'] = @{source='\\.\PhysicalDrive0'; error='Windows device names cannot select migration files'}
[NamespaceFixtures]::Write("\\?\$root\Mixed Case\hello.txt:notes", 'attached stream bytes')
[NamespaceFixtures]::Write("\\?\$root\Mixed Case\hello.txt:large", ('0123456789' * 600000))
$cases | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath $ManifestPath -Encoding utf8NoBOM
