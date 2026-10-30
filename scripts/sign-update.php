<?php
/**
 * Ed25519 release signing for update.json (0.10.0). Reads the base64 secret key from
 * ZOER_CONNECT_UPDATE_SIGNING_KEY (64-byte libsodium secret key, or its 32-byte seed).
 *
 *   php scripts/sign-update.php VERSION SHA256   prints the base64 signature
 *   php scripts/sign-update.php --public-key     prints the matching base64 public key
 *
 * The signed bytes must match GitHubUpdater::SIGNED_MESSAGE. Keys are never written to disk.
 */
if (PHP_SAPI !== 'cli') exit(1);
if (!function_exists('sodium_crypto_sign_detached')) { fwrite(STDERR, "PHP sodium is required to sign updates.\n"); exit(2); }
$encoded = getenv('ZOER_CONNECT_UPDATE_SIGNING_KEY');
$raw = is_string($encoded) ? base64_decode(trim($encoded), true) : false;
if (!is_string($raw) || !in_array(strlen($raw), [32, 64], true)) { fwrite(STDERR, "ZOER_CONNECT_UPDATE_SIGNING_KEY must be a base64 Ed25519 secret key or seed.\n"); exit(2); }
$secret = strlen($raw) === 32 ? sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($raw)) : $raw;
$public = sodium_crypto_sign_publickey_from_secretkey($secret);
if (($argv[1] ?? '') === '--public-key') { echo base64_encode($public), "\n"; exit(0); }
[$version, $sha256] = [$argv[1] ?? '', $argv[2] ?? ''];
if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-(?:alpha|beta|rc)\.[1-9][0-9]*)?$/D', $version) || !preg_match('/^[a-f0-9]{64}$/D', $sha256)) { fwrite(STDERR, "Usage: sign-update.php VERSION SHA256\n"); exit(2); }
$message = "zoer-connect-update-v1\n{$version}\n{$sha256}\n";
$signature = sodium_crypto_sign_detached($message, $secret);
if (!sodium_crypto_sign_verify_detached($signature, $message, $public)) { fwrite(STDERR, "Signature self-check failed.\n"); exit(1); }
echo base64_encode($signature), "\n";
