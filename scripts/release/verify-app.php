<?php
/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud <gilbeau.hammoud@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Repliziert OC\IntegrityCheck\Checker::verify + verifyAppSignature aus
 * nextcloud/server (master, 2026-07): Zertifikatskette gegen die
 * Nextcloud-Root-CA, CN-Pruefung, RSA-PSS-Signaturpruefung, Hash-Abgleich.
 *
 * Aufruf: php verify-app.php <appPath> <appId> <rootCrtPath>
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use phpseclib\Crypt\RSA;
use phpseclib\File\X509;

if ($argc !== 4) {
    fwrite(STDERR, "usage: php verify-app.php <appPath> <appId> <rootCrtPath>\n");
    exit(1);
}

[$_, $appPath, $appId, $rootCrtPath] = $argv;
$appPath = rtrim($appPath, '/');

$content = file_get_contents($appPath . '/appinfo/signature.json');
$signatureData = json_decode($content, true);
if (!is_array($signatureData)) {
    fwrite(STDERR, "FEHLER: Signature data not found.\n");
    exit(1);
}

$expectedHashes = $signatureData['hashes'];
ksort($expectedHashes);
$signature = base64_decode($signatureData['signature']);
$certificate = $signatureData['certificate'];

// Kette gegen Nextcloud Root Authority
$x509 = new X509();
preg_match_all('([\-]{3,}[\S\ ]+?[\-]{3,}[\S\s]+?[\-]{3,}[\S\ ]+?[\-]{3,})', file_get_contents($rootCrtPath), $matches);
foreach ($matches[0] as $rootCert) {
    $x509->loadCA($rootCert);
}
$x509->loadX509($certificate);
if (!$x509->validateSignature()) {
    fwrite(STDERR, "FEHLER: Certificate is not valid.\n");
    exit(1);
}
$cn = $x509->getDN(X509::DN_OPENSSL)['CN'];
if ($cn !== $appId && $cn !== 'core') {
    fwrite(STDERR, "FEHLER: Certificate is not valid for required scope (CN=$cn).\n");
    exit(1);
}
echo "Zertifikat: Kette gegen Nextcloud-Root-CA gueltig, CN=$cn\n";

// Signatur ueber die Hash-Liste
$rsa = new RSA();
$rsa->loadKey($x509->currentCert['tbsCertificate']['subjectPublicKeyInfo']['subjectPublicKey']);
$rsa->setSignatureMode(RSA::SIGNATURE_PSS);
$rsa->setMGFHash('sha512');
$rsa->setSaltLength(0);
if (!$rsa->verify(json_encode($expectedHashes), $signature)) {
    fwrite(STDERR, "FEHLER: Signature could not get verified.\n");
    exit(1);
}
echo "Signatur: RSA-PSS ueber " . count($expectedHashes) . " Hashes gueltig\n";

// Hash-Abgleich mit dem Ist-Zustand (wie compareResult)
$excludedFilenames = [
    '.DS_Store', '.directory', '.rnd', '.webapp', 'Thumbs.db', 'nextcloud-init-sync.lock',
];
$dirItr = new RecursiveDirectoryIterator($appPath, RecursiveDirectoryIterator::SKIP_DOTS);
$filterItr = new RecursiveCallbackFilterIterator($dirItr, function (SplFileInfo $c) use ($excludedFilenames): bool {
    return $c->isDir() || !in_array($c->getFilename(), $excludedFilenames, true);
});
$iterator = new RecursiveIteratorIterator($filterItr, RecursiveIteratorIterator::SELF_FIRST);

$currentHashes = [];
$baseDirectoryLength = strlen($appPath);
foreach ($iterator as $filename => $data) {
    if ($data->isDir()) {
        continue;
    }
    $rel = ltrim(substr($filename, $baseDirectoryLength), '/');
    if ($rel === 'appinfo/signature.json') {
        continue;
    }
    $currentHashes[$rel] = hash_file('sha512', $filename);
}
ksort($currentHashes);

$missing = array_diff_key($expectedHashes, $currentHashes);
$extra = array_diff_key($currentHashes, $expectedHashes);
$changed = [];
foreach (array_intersect_key($expectedHashes, $currentHashes) as $f => $h) {
    if ($currentHashes[$f] !== $h) {
        $changed[] = $f;
    }
}
if ($missing || $extra || $changed) {
    fwrite(STDERR, 'FEHLER: FILE_MISSING=' . count($missing) . ' EXTRA_FILE=' . count($extra) . ' INVALID_HASH=' . count($changed) . "\n");
    foreach (array_slice(array_merge(array_keys($missing), array_keys($extra), $changed), 0, 10) as $f) {
        fwrite(STDERR, "  $f\n");
    }
    exit(1);
}
echo "Hash-Abgleich: alle " . count($currentHashes) . " Dateien stimmen ueberein\n";
echo "VERIFIKATION OK\n";
