<?php
/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud <gilbeau.hammoud@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Repliziert exakt OC\IntegrityCheck\Checker::writeAppSignature aus
 * nextcloud/server (master, 2026-07): gleiche Bibliothek (phpseclib 2),
 * gleiche Hash-, Sortier- und Signatur-Parameter.
 *
 * Aufruf: php sign-app.php <appPath> <privateKeyPath> <certificatePath>
 *
 * Braucht phpseclib 2 (nicht Teil des App-vendor/). Einmalig bereitstellen:
 *   mkdir -p ~/.nextcloud/signer && cd ~/.nextcloud/signer \
 *     && composer require phpseclib/phpseclib:^2.0
 * Anderer Ort: Umgebungsvariable SIGNER_AUTOLOAD auf die autoload.php zeigen lassen.
 */

declare(strict_types=1);

$autoload = getenv('SIGNER_AUTOLOAD') ?: null;
if ($autoload === null) {
    foreach ([__DIR__ . '/vendor/autoload.php', getenv('HOME') . '/.nextcloud/signer/vendor/autoload.php'] as $candidate) {
        if (file_exists($candidate)) {
            $autoload = $candidate;
            break;
        }
    }
}
if ($autoload === null || !file_exists($autoload)) {
    fwrite(STDERR, "phpseclib-Autoload nicht gefunden. Setup siehe Script-Kopf (composer require phpseclib/phpseclib:^2.0) oder SIGNER_AUTOLOAD setzen.\n");
    exit(1);
}
require $autoload;

use phpseclib\Crypt\RSA;
use phpseclib\File\X509;

if ($argc !== 4) {
    fwrite(STDERR, "usage: php sign-app.php <appPath> <keyPath> <crtPath>\n");
    exit(1);
}

[$_, $appPath, $keyPath, $crtPath] = $argv;
$appPath = rtrim($appPath, '/');

if (!is_dir($appPath . '/appinfo')) {
    fwrite(STDERR, "appinfo/ fehlt unter $appPath\n");
    exit(1);
}

// ExcludeFileByNameFilterIterator (nur Dateinamen, Verzeichnisse passieren immer)
$excludedFilenames = [
    '.DS_Store', '.directory', '.rnd', '.webapp', 'Thumbs.db', 'nextcloud-init-sync.lock',
];
$excludedFilenamePatterns = [
    '/^\.webapp-nextcloud-(\d+\.){2}(\d+)(-r\d+)?$/',
];

// getFolderIterator + generateHashes
$dirItr = new RecursiveDirectoryIterator($appPath, RecursiveDirectoryIterator::SKIP_DOTS);
$filterItr = new RecursiveCallbackFilterIterator($dirItr, function (SplFileInfo $current) use ($excludedFilenames, $excludedFilenamePatterns): bool {
    if ($current->isDir()) {
        return true;
    }
    $name = $current->getFilename();
    if (in_array($name, $excludedFilenames, true)) {
        return false;
    }
    foreach ($excludedFilenamePatterns as $pattern) {
        if (preg_match($pattern, $name) > 0) {
            return false;
        }
    }
    return true;
});
$iterator = new RecursiveIteratorIterator($filterItr, RecursiveIteratorIterator::SELF_FIRST);

$hashes = [];
$baseDirectoryLength = strlen($appPath);
foreach ($iterator as $filename => $data) {
    if ($data->isDir()) {
        continue;
    }
    $relativeFileName = substr($filename, $baseDirectoryLength);
    $relativeFileName = ltrim($relativeFileName, '/');
    if ($relativeFileName === 'appinfo/signature.json') {
        continue;
    }
    $hashes[$relativeFileName] = hash_file('sha512', $filename);
}

// createSignatureData
ksort($hashes);

$rsa = new RSA();
if ($rsa->loadKey(file_get_contents($keyPath)) !== true) {
    fwrite(STDERR, "Privater Key konnte nicht geladen werden\n");
    exit(1);
}
$rsa->setSignatureMode(RSA::SIGNATURE_PSS);
$rsa->setMGFHash('sha512');
$rsa->setSaltLength(0);
$signature = $rsa->sign(json_encode($hashes));

$x509 = new X509();
$x509->loadX509(file_get_contents($crtPath));
$x509->setPrivateKey($rsa);

$signatureData = [
    'hashes' => $hashes,
    'signature' => base64_encode($signature),
    'certificate' => $x509->saveX509($x509->currentCert),
];

file_put_contents(
    $appPath . '/appinfo/signature.json',
    json_encode($signatureData, JSON_PRETTY_PRINT)
);

echo 'signature.json geschrieben: ' . count($hashes) . " Datei-Hashes\n";
