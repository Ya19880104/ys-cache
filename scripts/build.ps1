<#
.SYNOPSIS
Build both installable WordPress ZIP files for YS CACHE.

.DESCRIPTION
The build copies only the runtime files listed below. It creates ys-cache.zip
and ys-cache-import.zip in dist by default.

.EXAMPLE
pwsh -NoProfile -File .\scripts\build.ps1

.EXAMPLE
pwsh -NoProfile -File .\scripts\build.ps1 -OutputDirectory C:\builds\ys-cache
#>
[CmdletBinding()]
param(
    [string] $OutputDirectory = (Join-Path $PSScriptRoot '..\dist')
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem

$projectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$importerRoot = Join-Path $projectRoot 'tools/ys-cache-import'

$mainFiles = @(
    'LICENSE',
    'README.md',
    'README.zh-TW.md',
    'docs/cache-integrations.md',
    'ys-cache.php',
    'assets/css/ys-cache-admin.css',
    'assets/js/ys-cache-admin.js',
    'assets/js/ys-cache-admin-bar.js',
    'languages/ys-cache-zh_TW.mo',
    'src/Admin/YSCacheAdmin.php',
    'src/Admin/YSCacheAdminBar.php',
    'src/Database/YSCacheSettingsRepo.php',
    'src/YSCache.php',
    'src/YSCacheCdnDiscovery.php',
    'src/YSCacheEnhanceIntegration.php',
    'src/YSCacheEnhancePurge.php',
    'src/YSCacheIntegrations.php',
    'src/YSCacheInvalidation.php',
    'src/YSCacheProviders.php',
    'src/YSCacheStorage.php',
    'templates/admin/settings.php',
    'templates/cached-page.php',
    'vendor/autoload.php'
)
$hubFiles = @(
    'composer.json',
    'index.php',
    'ys-plugin-hub-client.php',
    'assets/css/ys-marketplace.css',
    'assets/js/ys-marketplace.js',
    'src/Admin/YSHubAjaxHandler.php',
    'src/Admin/YSHubRestController.php',
    'src/Admin/YSToolboxBrandingCompatibility.php',
    'src/Admin/YSToolboxMenuNormalizer.php',
    'src/Database/YSHubClientLogRepo.php',
    'src/Database/YSHubClientSettingsRepo.php',
    'src/Database/YSHubClientTableMaker.php',
    'src/Http/YSCircuitBreaker.php',
    'src/Http/YSHubApiClient.php',
    'src/Marketplace/YSMarketplaceInstaller.php',
    'src/Marketplace/YSMarketplacePage.php',
    'src/Registry/YSEndpointRegistry.php',
    'src/Updater/YSPluginInfo.php',
    'src/Updater/YSUpdateChecker.php',
    'src/YSPluginHubClient.php',
    'templates/logs-page.php',
    'templates/marketplace-page.php'
)
$hubRoot = Join-Path $projectRoot 'vendor/yangsheep/ys-plugin-hub-client'
$actualHubFiles = Get-ChildItem -LiteralPath $hubRoot -Recurse -File | ForEach-Object {
    [System.IO.Path]::GetRelativePath($hubRoot, $_.FullName).Replace('\', '/')
}
$hubChanges = Compare-Object $hubFiles $actualHubFiles
if ($hubChanges) {
    throw "The Hub Client file list changed. Review the package allowlist before rebuilding."
}
$mainFiles += $hubFiles | ForEach-Object { 'vendor/yangsheep/ys-plugin-hub-client/' + $_ }
$importerFiles = @(
    'README.md',
    'ys-cache-import.php',
    'assets/ys-cache-import.js',
    'languages/ys-cache-rocket-import-zh_TW.mo',
    'src/YSCacheImport.php',
    'src/YSCacheImportAdmin.php',
    'src/YSCacheMigrationState.php',
    'src/YSCacheImportSettings.php'
)

function Get-ArchiveEntries {
    param([string] $Slug, [string] $SourceRoot, [string[]] $RelativePaths)

    $root = Get-Item -LiteralPath $SourceRoot -Force
    if ($root.Attributes -band [System.IO.FileAttributes]::ReparsePoint) {
        throw "The source root is a linked directory. $SourceRoot"
    }

    foreach ($relativePath in $RelativePaths) {
        $sourceFile = Get-Item -LiteralPath (Join-Path $SourceRoot $relativePath) -Force
        if ($sourceFile.PSIsContainer -or ($sourceFile.Attributes -band [System.IO.FileAttributes]::ReparsePoint)) {
            throw "Expected a regular runtime file. $($sourceFile.FullName)"
        }

        $parent = $sourceFile.Directory
        while ($parent.FullName -ne $root.FullName) {
            if ($parent.Attributes -band [System.IO.FileAttributes]::ReparsePoint) {
                throw "A runtime file uses a linked directory. $($parent.FullName)"
            }
            $parent = $parent.Parent
        }

        [PSCustomObject] @{ Path = $sourceFile.FullName; Entry = "$Slug/$relativePath" }
    }
}

function Write-PluginArchive {
    param([string] $Destination, [PSCustomObject[]] $Entries)

    $temporaryFile = Join-Path (Split-Path -Parent $Destination) ('.ys-cache-' + [Guid]::NewGuid().ToString('N') + '.zip')
    try {
        $archive = [System.IO.Compression.ZipFile]::Open($temporaryFile, [System.IO.Compression.ZipArchiveMode]::Create)
        try {
            foreach ($file in $Entries) {
                [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                    $archive, $file.Path, $file.Entry, [System.IO.Compression.CompressionLevel]::Optimal
                )
            }
        }
        finally {
            $archive.Dispose()
        }
        Move-Item -LiteralPath $temporaryFile -Destination $Destination -Force
    }
    finally {
        if (Test-Path -LiteralPath $temporaryFile -PathType Leaf) {
            Remove-Item -LiteralPath $temporaryFile -Force
        }
    }
}

$mainEntries = @(Get-ArchiveEntries -Slug 'ys-cache' -SourceRoot $projectRoot -RelativePaths $mainFiles)
# Retain the installed plugin basename so this archive upgrades existing installations.
$importerSlug = 'ys-cache-rocket-import'
$importerEntries = @(Get-ArchiveEntries -Slug $importerSlug -SourceRoot $importerRoot -RelativePaths $importerFiles)
($importerEntries | Where-Object Entry -eq "$importerSlug/ys-cache-import.php").Entry = "$importerSlug/$importerSlug.php"
$importerEntries += @(Get-ArchiveEntries -Slug $importerSlug -SourceRoot $projectRoot -RelativePaths @('LICENSE'))
$buildDirectory = New-Item -Path $OutputDirectory -ItemType Directory -Force
$mainZip = Join-Path $buildDirectory.FullName 'ys-cache.zip'
$importerZip = Join-Path $buildDirectory.FullName 'ys-cache-import.zip'

Write-PluginArchive -Destination $mainZip -Entries $mainEntries
Write-PluginArchive -Destination $importerZip -Entries $importerEntries
Get-Item -LiteralPath $mainZip, $importerZip | Select-Object Name, Length, FullName
