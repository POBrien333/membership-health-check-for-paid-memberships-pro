<#
.SYNOPSIS
    Zip the staged release folder so WordPress can install it.

.DESCRIPTION
    Windows PowerShell's Compress-Archive writes entry names with backslashes,
    which the ZIP specification does not allow (APPNOTE 4.4.17.1: forward slashes
    only). PHP's unzip then reads the archive as a handful of files whose names
    happen to contain backslashes rather than as a directory tree, so WordPress
    finds no plugin folder inside, invents one named after the zip file, and the
    plugin lands one level too deep and unreadable.

    This writes each entry name explicitly, with forward slashes, so the archive
    has exactly one top-level directory and installs over previous versions.

.EXAMPLE
    php bin/build.php
    powershell -File bin/zip.ps1
#>

[CmdletBinding()]
param(
    [string] $Source,
    [string] $Destination
)

$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.IO.Compression.FileSystem

$root = Split-Path $PSScriptRoot -Parent
$slug = 'membership-health-check-for-paid-memberships-pro'

if ( -not $Source ) { $Source = Join-Path $root "dist\$slug" }

if ( -not ( Test-Path $Source ) ) {
    throw "Nothing staged at $Source. Run 'php bin/build.php' first."
}

# Deliberately unversioned. When WordPress cannot find a plugin folder inside an
# archive it names the destination directory after the zip file, so a version in
# the filename becomes a version in the installed folder name — which is what
# produced membership-health-check-for-paid-memberships-pro-0.4.0/ on the server.
# The guards below should stop that ever happening again, but a plain slug means
# even the fallback lands in the right place. The version lives in the plugin
# header and the changelog, where it belongs. Pass -Destination to keep an
# archived copy under a different name.
if ( -not $Destination ) { $Destination = Join-Path $root "dist\$slug.zip" }

$src = ( Resolve-Path $Source ).Path
$top = Split-Path $src -Leaf

if ( Test-Path $Destination ) { Remove-Item $Destination -Force }

$zip = [System.IO.Compression.ZipFile]::Open( $Destination, 'Create' )

try {
    foreach ( $file in Get-ChildItem $src -Recurse -File ) {
        $relative = $file.FullName.Substring( $src.Length + 1 ) -replace '\\', '/'
        [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $zip, $file.FullName, "$top/$relative"
        )
    }
}
finally {
    $zip.Dispose()
}

# Prove the archive is well formed rather than assuming it.
$check = [System.IO.Compression.ZipFile]::OpenRead( $Destination )
$bad   = @( $check.Entries | Where-Object { $_.FullName -match '\\' } )
$roots = @( $check.Entries | ForEach-Object { $_.FullName.Split( '/' )[0] } | Sort-Object -Unique )
$count = $check.Entries.Count
$check.Dispose()

if ( $bad.Count -gt 0 ) {
    throw "$($bad.Count) entries contain backslashes. WordPress will not install this."
}

if ( $roots.Count -ne 1 ) {
    throw "Archive has $($roots.Count) top-level entries; WordPress needs exactly one."
}

"{0}  ({1:N0} bytes, {2} files, root '{3}')" -f ( Split-Path $Destination -Leaf ), ( Get-Item $Destination ).Length, $count, $roots[0]
