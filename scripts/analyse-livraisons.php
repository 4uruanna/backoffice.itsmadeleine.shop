<?php

/**
 * M2 : analyse les livraisons enregistrées par la sonde (var/log/deliveries/*.json).
 *
 *   php scripts/analyse-livraisons.php <dossier> [--fixtures=tests/fixtures]
 *
 * - Vérifie la signature HMAC sur le corps brut (secret lu dans SHOPIFY_CLIENT_SECRET,
 *   ou demandé sans écho ; Entrée vide = pas de vérification).
 * - Résume chaque livraison : en-têtes, enveloppe, commande, e-mail présent ou non,
 *   statut financier, annulation, tags, erreurs.
 * - Avec --fixtures : écrit une copie anonymisée de chaque livraison (e-mail et
 *   statusPageUrl remplacés, signature recalculée avec le secret des tests).
 *
 * Aucune dépendance : PHP 8.3+ suffit (brew install php).
 */

declare(strict_types=1);

const TEST_SECRET = 'secret-de-test';
const FAKE_EMAIL = 'client@example.com';
const FAKE_STATUS_URL = 'https://example.myshopify.com/12345/orders/abcdef/authenticate?key=fixture';

$args = array_slice($argv, 1);
$dir = null;
$fixturesDir = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--fixtures=')) {
        $fixturesDir = rtrim(substr($arg, 11), '/');
    } elseif ($dir === null) {
        $dir = rtrim($arg, '/');
    }
}

if ($dir === null || !is_dir($dir)) {
    fwrite(STDERR, "Usage : php scripts/analyse-livraisons.php <dossier des livraisons> [--fixtures=tests/fixtures]\n");
    exit(1);
}

$files = glob($dir . '/*.json') ?: [];
sort($files);
if ($files === []) {
    fwrite(STDERR, "Aucun fichier .json dans $dir\n");
    exit(1);
}

$secret = getenv('SHOPIFY_CLIENT_SECRET') ?: askSecret();

$labelsUsed = [];
foreach ($files as $file) {
    $record = json_decode((string) file_get_contents($file), true);
    if (!is_array($record) || !isset($record['raw_body'])) {
        printf("\n== %s : format inattendu (pas de raw_body), ignoré\n", basename($file));
        continue;
    }

    $raw = (string) $record['raw_body'];
    $headers = is_array($record['headers'] ?? null) ? $record['headers'] : [];
    $body = json_decode($raw, true);

    printf("\n== %s\n", basename($file));
    printf("   reçue le       : %s\n", $record['received_at'] ?? '?');

    $signature = header_value($headers, 'Shopify-Hmac-Sha256') ?? header_value($headers, 'X-Shopify-Hmac-Sha256');
    if ($secret === '') {
        echo "   signature      : non vérifiée (pas de secret)\n";
    } else {
        $expected = base64_encode(hash_hmac('sha256', $raw, $secret, true));
        printf("   signature      : %s\n", ($signature !== null && hash_equals($expected, $signature)) ? 'valide' : 'INVALIDE');
    }

    foreach ($headers as $name => $value) {
        if (stripos((string) $name, 'hmac') === false) {
            printf("   en-tête        : %s = %s\n", $name, $value);
        }
    }

    if (!is_array($body)) {
        echo "   corps          : JSON illisible\n";
        continue;
    }

    printf("   enveloppe      : %s\n", implode(', ', array_keys($body)));
    [$orderPath, $order] = find_order($body);
    $errors = find_key($body, 'errors');

    if ($order === null) {
        echo "   commande       : introuvable dans le corps\n";
    } else {
        printf("   chemin         : %s\n", $orderPath);
        printf("   commande       : %s (id %s)\n", $order['name'] ?? '?', $order['legacyResourceId'] ?? '?');
        $email = $order['email'] ?? null;
        printf("   e-mail         : %s\n", is_string($email) && $email !== '' ? 'PRÉSENT (' . mask_email($email) . ')' : 'ABSENT (' . var_export($email, true) . ')');
        printf("   statut fin.    : %s\n", $order['displayFinancialStatus'] ?? '?');
        printf("   cancelledAt    : %s\n", var_export($order['cancelledAt'] ?? null, true));
        printf("   statusPageUrl  : %s\n", !empty($order['statusPageUrl']) ? 'présent' : 'ABSENT');
        $tags = order_tags($order);
        printf("   tags produits  : %s\n", $tags === [] ? '(aucun)' : implode(' | ', $tags));
        printf("   tag retrait-qr : %s\n", is_tagged($order) ? 'oui' : 'non');
    }

    if ($errors !== null) {
        printf("   ERREURS        : %s\n", json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    if ($fixturesDir !== null && $order !== null) {
        $label = fixture_label($order, $errors !== null);
        $labelsUsed[$label] = ($labelsUsed[$label] ?? 0) + 1;
        $name = $labelsUsed[$label] > 1 ? "$label-{$labelsUsed[$label]}" : $label;
        $path = write_fixture($fixturesDir, $name, $raw, $headers, $order);
        printf("   fixture        : %s\n", $path);
    }
}

echo "\n";

function askSecret(): string
{
    fwrite(STDERR, "Secret client (Entrée pour ne pas vérifier les signatures) : ");
    $tty = stream_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($tty) {
        shell_exec('stty echo');
    }
    fwrite(STDERR, "\n");

    return trim((string) $line);
}

/** @param array<string, string> $headers */
function header_value(array $headers, string $name): ?string
{
    foreach ($headers as $key => $value) {
        if (strcasecmp((string) $key, $name) === 0) {
            return (string) $value;
        }
    }

    return null;
}

/** @return array{0: string, 1: ?array<string, mixed>} */
function find_order(array $node, string $path = ''): array
{
    foreach ($node as $key => $value) {
        if (!is_array($value)) {
            continue;
        }
        $here = $path === '' ? (string) $key : "$path.$key";
        if ($key === 'order' && (isset($value['legacyResourceId']) || isset($value['name']))) {
            return [$here, $value];
        }
        [$found, $order] = find_order($value, $here);
        if ($order !== null) {
            return [$found, $order];
        }
    }

    return ['', null];
}

function find_key(array $node, string $wanted): mixed
{
    foreach ($node as $key => $value) {
        if ($key === $wanted) {
            return $value;
        }
        if (is_array($value)) {
            $found = find_key($value, $wanted);
            if ($found !== null) {
                return $found;
            }
        }
    }

    return null;
}

/** @return list<string> */
function order_tags(array $order): array
{
    $tags = [];
    foreach ($order['lineItems']['nodes'] ?? [] as $line) {
        $productTags = $line['product']['tags'] ?? null;
        if ($productTags === null) {
            $tags[] = '(produit nul)';
            continue;
        }
        $tags[] = implode(',', (array) $productTags) ?: '(sans tag)';
    }

    return $tags;
}

function is_tagged(array $order): bool
{
    foreach ($order['lineItems']['nodes'] ?? [] as $line) {
        foreach ((array) ($line['product']['tags'] ?? []) as $tag) {
            if (strcasecmp(trim((string) $tag), 'retrait-qr') === 0) {
                return true;
            }
        }
    }

    return false;
}

function mask_email(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

    return substr($local, 0, 1) . '***@' . $domain;
}

function fixture_label(array $order, bool $hasErrors): string
{
    if ($hasErrors) {
        return 'errors';
    }
    if (!empty($order['cancelledAt'])) {
        return 'annulee';
    }
    $status = strtolower((string) ($order['displayFinancialStatus'] ?? 'inconnu'));
    return str_replace('_', '-', $status) . (is_tagged($order) ? '' : '-non-taguee');
}

/** @param array<string, string> $headers */
function write_fixture(string $dir, string $name, string $raw, array $headers, array $order): string
{
    $anon = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', FAKE_EMAIL, $raw) ?? $raw;

    $statusUrl = $order['statusPageUrl'] ?? null;
    if (is_string($statusUrl) && $statusUrl !== '') {
        foreach ([JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_UNICODE] as $flags) {
            $anon = str_replace(
                substr((string) json_encode($statusUrl, $flags), 1, -1),
                substr((string) json_encode(FAKE_STATUS_URL, $flags), 1, -1),
                $anon,
            );
        }
    }

    $fixtureHeaders = [];
    foreach ($headers as $key => $value) {
        $fixtureHeaders[$key] = stripos((string) $key, 'hmac') !== false
            ? base64_encode(hash_hmac('sha256', $anon, TEST_SECRET, true))
            : $value;
    }

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $path = "$dir/livraison-$name.json";
    file_put_contents($path, json_encode([
        'note' => 'Livraison réelle anonymisée (M2). Signature recalculée avec le secret des tests : ' . TEST_SECRET,
        'headers' => $fixtureHeaders,
        'raw_body' => $anon,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

    return $path;
}
