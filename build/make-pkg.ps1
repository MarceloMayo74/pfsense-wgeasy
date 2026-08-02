# Builds dist/pfSense-pkg-wgeasy-<version>.pkg from the wgeasy/ tree.
#
#   powershell -ExecutionPolicy Bypass -File build\make-pkg.ps1
#
# The archive replicates the exact layout of a working third-party pfSense
# package (pfSense-pkg-wg-export): a tar with +COMPACT_MANIFEST and +MANIFEST
# first, then the files under absolute paths. FreeBSD pkg(8) installs it with
# a plain `pkg add`.

$ErrorActionPreference = 'Stop'

$version = '0.4.1'
$name    = 'pfSense-pkg-wgeasy'

$root  = Split-Path $PSScriptRoot -Parent
$src   = Join-Path $root 'wgeasy'
$dist  = Join-Path $root 'dist'
$stage = Join-Path $dist 'stage'

# ---------------------------------------------------------------- staging ---
if (Test-Path $stage) { Remove-Item -Recurse -Force $stage }
New-Item -ItemType Directory -Force -Path $stage | Out-Null

# Path in the package => source file in the repo
$files = [ordered]@{
    '/usr/local/www/wgeasy/vpn_wg_easy.php'             = 'usr\local\www\wgeasy\vpn_wg_easy.php'
    '/usr/local/www/wgeasy/vpn_wg_easy_edit.php'        = 'usr\local\www\wgeasy\vpn_wg_easy_edit.php'
    '/usr/local/www/wgeasy/js/wgeasy_qrcode.js'         = 'usr\local\www\wgeasy\js\wgeasy_qrcode.js'
    '/usr/local/www/widgets/widgets/wgeasy_peers.widget.php' = 'usr\local\www\widgets\widgets\wgeasy_peers.widget.php'
    '/usr/local/www/widgets/include/wgeasy_peers.inc'   = 'usr\local\www\widgets\include\wgeasy_peers.inc'
    '/usr/local/pkg/wgeasy.inc'                         = 'usr\local\pkg\wgeasy.inc'
    '/usr/local/pkg/wgeasy.xml'                         = 'usr\local\pkg\wgeasy.xml'
    '/usr/local/share/pfSense/menu/wgeasy.xml'          = 'usr\local\share\pfSense\menu\wgeasy.xml'
    '/usr/local/share/pfSense-pkg-wgeasy/info.xml'      = 'usr\local\share\pfSense-pkg-wgeasy\info.xml'
    '/usr/local/share/pfSense-pkg-wgeasy/wgeasy_menu.php' = 'tools\wgeasy_menu.php'
    '/etc/inc/priv/wgeasy.priv.inc'                     = 'etc\inc\priv\wgeasy.priv.inc'
}

$textExt = @('.php', '.inc', '.xml', '.js')

foreach ($entry in $files.GetEnumerator()) {
    $srcFile = Join-Path $src $entry.Value
    if (-not (Test-Path $srcFile)) { throw "missing source file: $($entry.Value)" }

    $dstFile = Join-Path $stage ($entry.Key.TrimStart('/') -replace '/', '\')
    New-Item -ItemType Directory -Force -Path (Split-Path $dstFile -Parent) | Out-Null

    if ($textExt -contains [System.IO.Path]::GetExtension($srcFile)) {
        # Normalize to LF, no BOM: what pfSense itself ships
        $text = [System.IO.File]::ReadAllText($srcFile) -replace "`r`n", "`n"
        [System.IO.File]::WriteAllText($dstFile, $text, (New-Object System.Text.UTF8Encoding($false)))
    } else {
        Copy-Item $srcFile $dstFile
    }
}

# Refuse to package anything carrying a UTF-8 BOM: it breaks header() in PHP
Get-ChildItem $stage -Recurse -File | ForEach-Object {
    $b = [System.IO.File]::ReadAllBytes($_.FullName)
    if ($b.Length -ge 3 -and $b[0] -eq 0xEF -and $b[1] -eq 0xBB -and $b[2] -eq 0xBF) {
        throw "BOM found in $($_.FullName)"
    }
}

# -------------------------------------------------------------- manifests ---
$fileMap  = [ordered]@{}
$flatsize = 0

foreach ($pkgPath in $files.Keys) {
    $stagedFile = Join-Path $stage ($pkgPath.TrimStart('/') -replace '/', '\')
    $hash = (Get-FileHash $stagedFile -Algorithm SHA256).Hash.ToLower()
    $fileMap[$pkgPath] = '1$' + $hash
    $flatsize += (Get-Item $stagedFile).Length
}

$dirMap = [ordered]@{
    '/usr/local/www/wgeasy'               = 'y'
    '/usr/local/www/wgeasy/js'            = 'y'
    '/usr/local/share/pfSense-pkg-wgeasy' = 'y'
}

$meta = [ordered]@{
    name         = $name
    origin       = "net-vpn/$name"
    version      = $version
    comment      = 'WireGuard Easy: simple client creation for WireGuard'
    maintainer   = 'marcelomayo1974@gmail.com'
    www          = 'https://github.com/MarceloMayo74/pfsense-wgeasy'
    abi          = '*'
    arch         = '*'
    prefix       = '/'
    flatsize     = $flatsize
    licenselogic = 'single'
    licenses     = @('APACHE20')
    desc         = 'Adds VPN > WireGuard Easy: creates a peer through the native pfSense-pkg-WireGuard API and returns the client .conf with QR code and email delivery. No native file is modified.'
}

$compact = $meta | ConvertTo-Json -Compress -Depth 5

$full = [ordered]@{}
foreach ($k in $meta.Keys) { $full[$k] = $meta[$k] }
$full['files']       = $fileMap
$full['directories'] = $dirMap

$fullJson = $full | ConvertTo-Json -Compress -Depth 5

$utf8 = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText((Join-Path $stage '+COMPACT_MANIFEST'), $compact, $utf8)
[System.IO.File]::WriteAllText((Join-Path $stage '+MANIFEST'), $fullJson, $utf8)

# ---------------------------------------------------------------- archive ---
# Windows' bundled tar cannot rewrite entry names to absolute paths, so the
# ustar archive is written directly: 512 byte headers, data padded to 512,
# closed by two zero blocks, then gzip. libarchive (what pkg(8) uses) reads
# gzip'ed ustar archives natively.

function New-TarHeader([string]$entryName, [long]$size, [long]$mtime) {
    $header = New-Object byte[] 512
    $ascii = [System.Text.Encoding]::ASCII

    $put = { param($text, $offset) $b = $ascii.GetBytes($text); [Array]::Copy($b, 0, $header, $offset, $b.Length) }

    & $put $entryName 0                                            # name
    & $put ('{0:D7}' -f 644) 100                                   # mode 0000644
    & $put '0000000' 108                                           # uid 0 (root)
    & $put '0000000' 116                                           # gid 0 (wheel)
    & $put ([Convert]::ToString($size, 8).PadLeft(11, '0')) 124    # size, octal
    & $put ([Convert]::ToString($mtime, 8).PadLeft(11, '0')) 136   # mtime, octal
    $header[156] = [byte][char]'0'                                 # typeflag: regular file
    & $put "ustar" 257                                             # magic
    $header[262] = 0
    & $put '00' 263                                                # version
    & $put 'root' 265                                              # uname
    & $put 'wheel' 297                                             # gname

    # Checksum: computed with the checksum field itself set to spaces
    for ($i = 148; $i -lt 156; $i++) { $header[$i] = 0x20 }
    $sum = 0
    foreach ($b in $header) { $sum += $b }
    & $put ([Convert]::ToString($sum, 8).PadLeft(6, '0')) 148
    $header[154] = 0
    $header[155] = 0x20

    return $header
}

$out = Join-Path $dist "$name-$version.pkg"
if (Test-Path $out) { Remove-Item -Force $out }

$mtime = [long][System.DateTimeOffset]::UtcNow.ToUnixTimeSeconds()

# Manifests first, then the files: the order pkg expects
$entries = [ordered]@{
    '+COMPACT_MANIFEST' = (Join-Path $stage '+COMPACT_MANIFEST')
    '+MANIFEST'         = (Join-Path $stage '+MANIFEST')
}

foreach ($pkgPath in $files.Keys) {
    $entries[$pkgPath] = Join-Path $stage ($pkgPath.TrimStart('/') -replace '/', '\')
}

$tarStream = New-Object System.IO.MemoryStream

foreach ($entry in $entries.GetEnumerator()) {
    $data = [System.IO.File]::ReadAllBytes($entry.Value)

    $header = New-TarHeader $entry.Key $data.Length $mtime
    $tarStream.Write($header, 0, 512)
    $tarStream.Write($data, 0, $data.Length)

    $pad = (512 - ($data.Length % 512)) % 512
    if ($pad -gt 0) { $tarStream.Write((New-Object byte[] $pad), 0, $pad) }
}

# End of archive: two zero blocks
$tarStream.Write((New-Object byte[] 1024), 0, 1024)

$fileStream = [System.IO.File]::Create($out)
$gzip = New-Object System.IO.Compression.GZipStream($fileStream, [System.IO.Compression.CompressionLevel]::Optimal)
$tarBytes = $tarStream.ToArray()
$gzip.Write($tarBytes, 0, $tarBytes.Length)
$gzip.Dispose()
$fileStream.Dispose()
$tarStream.Dispose()

# ----------------------------------------------------------------- verify ---
$listing = tar -tf $out 2>$null
if (($listing[0] -ne '+COMPACT_MANIFEST') -or ($listing[1] -ne '+MANIFEST')) {
    throw "manifests are not the first entries: $($listing[0..1] -join ', ')"
}

$missing = @($files.Keys | Where-Object { $listing -notcontains $_ })
if ($missing.Count -gt 0) { throw "entries missing from the archive: $($missing -join ', ')" }

$check = Join-Path $dist 'check'
if (Test-Path $check) { Remove-Item -Recurse -Force $check }
New-Item -ItemType Directory -Force -Path $check | Out-Null

Push-Location $check
try {
    tar -xf $out
    if ($LASTEXITCODE -ne 0) { throw 'extraction failed' }
} finally {
    Pop-Location
}

foreach ($pkgPath in $files.Keys) {
    $extracted = Join-Path $check ($pkgPath.TrimStart('/') -replace '/', '\')
    $hash = '1$' + (Get-FileHash $extracted -Algorithm SHA256).Hash.ToLower()
    if ($hash -ne $fileMap[$pkgPath]) { throw "checksum mismatch after extraction: $pkgPath" }
}

Remove-Item -Recurse -Force $check

$magic = ([System.IO.File]::ReadAllBytes($out)[0..3] | ForEach-Object { $_.ToString('X2') }) -join ' '

Write-Host ''
Write-Host "OK  $out"
Write-Host "    $((Get-Item $out).Length) bytes, magic $magic, $($files.Count) files, flatsize $flatsize"
Write-Host ''
Write-Host 'Install on pfSense:'
Write-Host "    scp dist/$name-$version.pkg root@FIREWALL:/root/"
Write-Host "    ssh root@FIREWALL pkg add /root/$name-$version.pkg"

