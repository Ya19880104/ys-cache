<#
.SYNOPSIS
Build the installable WordPress ZIP file for YS CACHE.

.DESCRIPTION
The build copies only the runtime files listed below to dist/ys-cache.zip by default.

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

$mainFiles = @(
    'LICENSE',
    'README.md',
    'README.zh-TW.md',
    'docs/cache-integrations.md',
    'docs/nginx-cache.md',
    'ys-cache.php',
    'assets/css/ys-cache-admin.css',
    'assets/css/ys-cache-carousel.css',
    'assets/js/ys-cache-carousel.js',
    'assets/js/ys-cache-admin.js',
    'assets/js/ys-cache-admin-bar.js',
    'assets/js/ys-cache-import.js',
    'languages/ys-cache-zh_TW.mo',
    'src/Admin/YSCacheAdmin.php',
    'src/Admin/YSCacheAdminBar.php',
    'src/Admin/YSCacheImportAdmin.php',
    'src/Database/YSCacheSettingsRepo.php',
    'src/Migration/YSCacheImport.php',
    'src/Migration/YSCacheImportSettings.php',
    'src/Migration/YSCacheMigrationState.php',
    'src/YSCache.php',
    'src/YSCacheAssetOptimization.php',
    'src/YSCacheCdnDiscovery.php',
    'src/YSCacheElementor.php',
    'src/YSCacheEarly.php',
    'src/YSCacheEarlyStore.php',
    'src/YSCacheCarousel.php',
    'src/YSCachePixel.php',
    'src/YSCachePixelWarm.php',
    'src/YSCacheGuestTemplate.php',
    'src/YSCachePixelTransport.php',
    'src/YSCacheEnhanceIntegration.php',
    'src/YSCacheEnhancePurge.php',
    'src/YSCacheIntegrations.php',
    'src/YSCacheInvalidation.php',
    'src/YSCacheLiteSpeed.php',
    'src/YSCacheNginx.php',
    'src/YSCacheProviders.php',
    'src/YSCacheStorage.php',
    'templates/admin/settings.php',
    'templates/cached-page.php',
    'templates/guest-template.php',
    'templates/early-reader.php',
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
$optimizerPrefix = 'vendor/ys-cache-optimizer/peast'
$optimizerRoot = Join-Path $projectRoot $optimizerPrefix
$optimizerFiles = @(Get-ChildItem -LiteralPath $optimizerRoot -File -Recurse | ForEach-Object {
    [System.IO.Path]::GetRelativePath($optimizerRoot, $_.FullName).Replace('\', '/')
})
if ($optimizerFiles -notcontains 'LICENSE' -or $optimizerFiles -notcontains 'README.md' -or
    $optimizerFiles -notcontains 'src/Peast.php' -or ($optimizerFiles | Where-Object { $_ -notmatch '^(?:LICENSE|README\.md|src/[A-Za-z0-9_/]+\.php)$' })) {
    throw 'The optimizer dependency contains unexpected files or is missing required source.'
}
$mainFiles += $optimizerFiles | ForEach-Object { "$optimizerPrefix/$_" }
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
$buildDirectory = New-Item -Path $OutputDirectory -ItemType Directory -Force
$mainZip = Join-Path $buildDirectory.FullName 'ys-cache.zip'

Write-PluginArchive -Destination $mainZip -Entries $mainEntries
Get-Item -LiteralPath $mainZip | Select-Object Name, Length, FullName
