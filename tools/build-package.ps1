param(
    [Parameter(Mandatory = $true)]
    [string] $OutputDirectory,

    [string] $Version
)

$ErrorActionPreference = 'Stop'
$scriptDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
$integrationRoot = (Resolve-Path (Join-Path $scriptDirectory '..')).Path
$moduleSource = Join-Path $integrationRoot 'modules\servers\lumio'
if (-not (Test-Path -LiteralPath (Join-Path $moduleSource 'lumio.php') -PathType Leaf)) {
    throw 'Lumio WHMCS module source is incomplete.'
}

$moduleSourcePath = [IO.Path]::GetFullPath($moduleSource)
$outputPath = [IO.Path]::GetFullPath($OutputDirectory)
$moduleSourcePrefix = $moduleSourcePath.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
if ([string]::Equals($outputPath, $moduleSourcePath, [StringComparison]::OrdinalIgnoreCase) -or
    $outputPath.StartsWith($moduleSourcePrefix, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Output directory must be outside the Lumio module source tree.'
}

$publicModuleManifest = @(
    'CHANGELOG.md'
    'assets/admin-product-mapper.js'
    'assets/admin-server-config.js'
    'hooks.php'
    'index.php'
    'LICENSE'
    'lib/AdminCatalogBootstrap.php'
    'lib/ApiClient.php'
    'lib/Autoload.php'
    'lib/Configuration.php'
    'lib/ConnectionTester.php'
    'lib/Contract/ApiClientInterface.php'
    'lib/Contract/LoggerInterface.php'
    'lib/Contract/RuntimeInterface.php'
    'lib/Contract/ServicePropertiesInterface.php'
    'lib/Contract/StateRepositoryInterface.php'
    'lib/Contract/TransportInterface.php'
    'lib/CronRunner.php'
    'lib/Exception/ApiException.php'
    'lib/Exception/ConfigurationException.php'
    'lib/Exception/TransportException.php'
    'lib/Http/CurlTransport.php'
    'lib/Http/HttpResponse.php'
    'lang/chinese.php'
    'lang/english.php'
    'lib/Logging/NullLogger.php'
    'lib/Logging/WhmcsLogger.php'
    'lib/ModuleFactory.php'
    'lib/ModuleInspector.php'
    'lib/ModuleWorkflow.php'
    'lib/Persistence/StateRepository.php'
    'lib/Support/Sanitizer.php'
    'lib/Version.php'
    'lib/WhmcsRuntime.php'
    'lib/WhmcsServiceProperties.php'
    'lumio.php'
    'README.md'
    'templates/clientarea.tpl'
)

$forbiddenDisclosures = [ordered]@{
    'private key block' = '-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----'
    'embedded URL credentials' = '(?i)https?://[^/\s:@]+:[^/\s@]+@'
    'literal Lumio API key' = '(?i)lumio_live_[A-Za-z0-9_-]{20,32}\.[A-Za-z0-9_-]{40,64}'
    'absolute Windows path' = '(?i)\b[A-Z]:\\'
    'Unix user path' = '(?i)/(home|Users)/[^/\s]+'
    'server deployment path' = '(?i)/(opt|srv|var/www)/[^/\s]+'
}

function Get-NormalizedRelativePath {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Root,

        [Parameter(Mandatory = $true)]
        [string] $Path
    )

    $rootPath = [IO.Path]::GetFullPath($Root)
    $fullPath = [IO.Path]::GetFullPath($Path)
    $prefix = $rootPath.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    if (-not $fullPath.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Path is outside the approved package root: $fullPath"
    }
    return $fullPath.Substring($prefix.Length).Replace('\', '/')
}

function New-PortableZip {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Root,

        [Parameter(Mandatory = $true)]
        [string] $Destination
    )

    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $archive = [IO.Compression.ZipFile]::Open(
        $Destination,
        [IO.Compression.ZipArchiveMode]::Create
    )
    try {
        $files = @(Get-ChildItem -LiteralPath $Root -Recurse -File | Sort-Object FullName)
        foreach ($file in $files) {
            $entryName = Get-NormalizedRelativePath -Root $Root -Path $file.FullName
            if ($entryName.Contains('\')) {
                throw "ZIP entry contains a Windows path separator: $entryName"
            }
            [IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $archive,
                $file.FullName,
                $entryName,
                [IO.Compression.CompressionLevel]::Optimal
            ) | Out-Null
        }
    } finally {
        $archive.Dispose()
    }
}

function Assert-PortableZipEntries {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Path
    )

    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $archive = [IO.Compression.ZipFile]::OpenRead($Path)
    try {
        foreach ($entry in $archive.Entries) {
            if ($entry.FullName.Contains('\')) {
                throw "ZIP entry contains a Windows path separator: $($entry.FullName)"
            }
        }
    } finally {
        $archive.Dispose()
    }
}

function Assert-PublicModuleManifest {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Root,

        [Parameter(Mandatory = $true)]
        [string[]] $Manifest
    )

    if (-not (Test-Path -LiteralPath $Root -PathType Container)) {
        throw "Public module root does not exist: $Root"
    }
    $rootItem = Get-Item -LiteralPath $Root -Force
    if (($rootItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
        throw "Public module root must not be a reparse point: $($rootItem.FullName)"
    }
    if (($rootItem.Attributes -band [IO.FileAttributes]::Hidden) -ne 0) {
        throw "Public module root must not be hidden: $($rootItem.FullName)"
    }
    $entries = @(Get-ChildItem -LiteralPath $Root -Recurse -Force)
    foreach ($entry in $entries) {
        if (($entry.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
            throw "Public module contains a reparse point: $($entry.FullName)"
        }
        if (($entry.Attributes -band [IO.FileAttributes]::Hidden) -ne 0) {
            throw "Public module contains a hidden entry: $($entry.FullName)"
        }
    }

    $actual = @(
        $entries |
            Where-Object { -not $_.PSIsContainer } |
            ForEach-Object { Get-NormalizedRelativePath -Root $Root -Path $_.FullName }
    )
    $missing = @($Manifest | Where-Object { $actual -cnotcontains $_ })
    $unexpected = @($actual | Where-Object { $Manifest -cnotcontains $_ })
    if ($missing.Count -gt 0) {
        throw ('Public module manifest is missing files: ' + ($missing -join ', '))
    }
    if ($unexpected.Count -gt 0) {
        throw ('Public module manifest contains unapproved files: ' + ($unexpected -join ', '))
    }
}

function Assert-PublicArtifactBoundary {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Root,

        [Parameter(Mandatory = $true)]
        [System.Collections.IDictionary] $Patterns,

        [Parameter(Mandatory = $true)]
        [string[]] $Manifest
    )

    foreach ($relative in $Manifest) {
        $platformRelative = $relative.Replace('/', [IO.Path]::DirectorySeparatorChar)
        $filePath = Join-Path $Root $platformRelative
        $contents = [IO.File]::ReadAllText($filePath)
        foreach ($entry in $Patterns.GetEnumerator()) {
            if ([Text.RegularExpressions.Regex]::IsMatch($contents, [string] $entry.Value)) {
                throw "Public package boundary check failed: $($entry.Key) found in $filePath"
            }
        }
    }
}

function Get-PublicModuleVersion {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Root
    )

    $versionFile = Join-Path $Root 'lib\Version.php'
    $contents = [IO.File]::ReadAllText($versionFile)
    $match = [Text.RegularExpressions.Regex]::Match(
        $contents,
        "public\s+const\s+NUMBER\s*=\s*'(?<version>\d+\.\d+\.\d+)'\s*;"
    )
    if (-not $match.Success) {
        throw "Unable to read the public module version from $versionFile"
    }
    return $match.Groups['version'].Value
}

function Assert-PublicModuleVersionContract {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Root,

        [Parameter(Mandatory = $true)]
        [string] $ExpectedVersion
    )

    $actualVersion = Get-PublicModuleVersion -Root $Root
    if ($actualVersion -cne $ExpectedVersion) {
        throw "Packaged module version mismatch: expected $ExpectedVersion, found $actualVersion."
    }

    $readmeFirstLine = [IO.File]::ReadAllLines((Join-Path $Root 'README.md'))[0]
    if ($readmeFirstLine -cne "# Lumio WHMCS Provisioning Module $ExpectedVersion") {
        throw 'README module version does not match lib/Version.php.'
    }

    $changelog = [IO.File]::ReadAllText((Join-Path $Root 'CHANGELOG.md'))
    $escapedVersion = [Text.RegularExpressions.Regex]::Escape($ExpectedVersion)
    if (-not [Text.RegularExpressions.Regex]::IsMatch($changelog, "(?m)^##\s+$escapedVersion(?:\s+-|\s*$)")) {
        throw 'CHANGELOG module version does not match lib/Version.php.'
    }
}

$moduleVersion = Get-PublicModuleVersion -Root $moduleSource
if ([string]::IsNullOrWhiteSpace($Version)) {
    $Version = $moduleVersion
} elseif ($Version -notmatch '^\d+\.\d+\.\d+$') {
    throw 'Version must use semantic version format, for example 1.0.0.'
} elseif ($Version -cne $moduleVersion) {
    throw "Requested package version $Version does not match lib/Version.php version $moduleVersion."
}

Assert-PublicModuleManifest -Root $moduleSource -Manifest $publicModuleManifest
Assert-PublicArtifactBoundary -Root $moduleSource -Patterns $forbiddenDisclosures -Manifest $publicModuleManifest
Assert-PublicModuleVersionContract -Root $moduleSource -ExpectedVersion $Version

if (Test-Path -LiteralPath $outputPath) {
    if (-not (Test-Path -LiteralPath $outputPath -PathType Container)) {
        throw "Output path is not a directory: $outputPath"
    }
} else {
    New-Item -ItemType Directory -Path $outputPath | Out-Null
}

$zipName = "lumio-whmcs-module-v$Version.zip"
$zipPath = Join-Path $outputPath $zipName
$hashPath = "$zipPath.sha256"
if (Test-Path -LiteralPath $zipPath) {
    throw "Package already exists: $zipPath"
}
if (Test-Path -LiteralPath $hashPath) {
    throw "Hash file already exists: $hashPath"
}

$tempRoot = Split-Path -Parent ([IO.Path]::GetFullPath((Join-Path ([IO.Path]::GetTempPath()) 'lumio-temp-sentinel')))
$workRoot = [IO.Path]::GetFullPath((Join-Path $tempRoot ('lumio-whmcs-package-' + [Guid]::NewGuid().ToString('N'))))
$workRootName = [IO.Path]::GetFileName($workRoot)
if (-not [string]::Equals((Split-Path -Parent $workRoot), $tempRoot, [StringComparison]::OrdinalIgnoreCase) -or
    $workRootName -notmatch '^lumio-whmcs-package-[a-f0-9]{32}$') {
    throw "Unsafe temporary path: $workRoot"
}

$staging = Join-Path $workRoot 'staging'
$verification = Join-Path $workRoot 'verification'
$candidateZip = Join-Path $workRoot $zipName
$candidateHash = "$candidateZip.sha256"
$hashPublished = $false
$zipPublished = $false

try {
    $moduleTarget = Join-Path $staging 'modules\servers\lumio'
    New-Item -ItemType Directory -Path $moduleTarget -Force | Out-Null
    foreach ($relative in $publicModuleManifest) {
        $platformRelative = $relative.Replace('/', [IO.Path]::DirectorySeparatorChar)
        $source = Join-Path $moduleSource $platformRelative
        $target = Join-Path $moduleTarget $platformRelative
        $targetDirectory = Split-Path -Parent $target
        if (-not (Test-Path -LiteralPath $targetDirectory -PathType Container)) {
            New-Item -ItemType Directory -Path $targetDirectory -Force | Out-Null
        }
        Copy-Item -LiteralPath $source -Destination $target
    }

    New-PortableZip -Root $staging -Destination $candidateZip
    Assert-PortableZipEntries -Path $candidateZip

    New-Item -ItemType Directory -Path $verification | Out-Null
    Expand-Archive -LiteralPath $candidateZip -DestinationPath $verification
    $verifiedModule = Join-Path $verification 'modules\servers\lumio'
    Assert-PublicModuleManifest -Root $verifiedModule -Manifest $publicModuleManifest
    Assert-PublicArtifactBoundary -Root $verifiedModule -Patterns $forbiddenDisclosures -Manifest $publicModuleManifest
    Assert-PublicModuleVersionContract -Root $verifiedModule -ExpectedVersion $Version

    $hash = (Get-FileHash -LiteralPath $candidateZip -Algorithm SHA256).Hash.ToLowerInvariant()
    [IO.File]::WriteAllText($candidateHash, "$hash  $zipName`n", [Text.UTF8Encoding]::new($false))
    $resultJson = [pscustomobject]@{
        package = $zipPath
        bytes = (Get-Item -LiteralPath $candidateZip).Length
        sha256 = $hash
        checksum_file = $hashPath
    } | ConvertTo-Json -Compress

    Move-Item -LiteralPath $candidateHash -Destination $hashPath
    $hashPublished = $true
    Move-Item -LiteralPath $candidateZip -Destination $zipPath
    $zipPublished = $true
    $resultJson
} catch {
    if ($zipPublished -and (Test-Path -LiteralPath $zipPath)) {
        Remove-Item -LiteralPath $zipPath -Force
    }
    if ($hashPublished -and (Test-Path -LiteralPath $hashPath)) {
        Remove-Item -LiteralPath $hashPath -Force
    }
    throw
} finally {
    $resolvedWorkRoot = [IO.Path]::GetFullPath($workRoot)
    if ([string]::Equals((Split-Path -Parent $resolvedWorkRoot), $tempRoot, [StringComparison]::OrdinalIgnoreCase) -and
        ([IO.Path]::GetFileName($resolvedWorkRoot)) -match '^lumio-whmcs-package-[a-f0-9]{32}$' -and
        (Test-Path -LiteralPath $resolvedWorkRoot)) {
        try {
            Remove-Item -LiteralPath $resolvedWorkRoot -Recurse -Force
        } catch {
            if (-not $zipPublished) {
                throw
            }
            Write-Warning 'Package was published successfully, but its temporary working directory could not be removed.'
        }
    }
}
