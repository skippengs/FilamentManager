<?php
declare(strict_types=1);
if (!defined('FILAMENT')) { http_response_code(403); exit('Forbidden'); }

function esc(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jsonOut(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Reads the JSON body of a fetch() request. */
function jsonIn(): array
{
    $raw  = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function checkCsrf(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

/**
 * Builds a query string from the current filters, with a few keys changed.
 * Passing null for a value drops that key, so paging links stay readable.
 */
function queryWith(array $base, array $changes = []): string
{
    $params = array_filter(
        array_merge($base, $changes),
        static fn($v): bool => $v !== null && $v !== '' && $v !== false
    );

    return $params === [] ? '' : '?' . http_build_query($params);
}

/** 1234.5 -> "1.2 kg", 400 -> "400 g" */
function formatGrams(float $grams): string
{
    if ($grams >= 1000) {
        return rtrim(rtrim(number_format($grams / 1000, 1, '.', ''), '0'), '.') . ' kg';
    }

    return round($grams) . ' g';
}

function formatPrice(?string $price): string
{
    return $price === null ? '' : '€ ' . number_format((float)$price, 2, ',', '.');
}

/**
 * The price the way you would type it back into the form: 19.95 stays as it
 * is, 20.00 loses its zeroes. Anything that is not a plain decimal - such as
 * the "19,95" you just typed and got an error on - is handed back untouched.
 */
function priceForInput(string $price): string
{
    if ($price === '') {
        return '';
    }

    return preg_match('/^\d+(\.\d+)?$/', $price) === 1 ? (string)(float)$price : $price;
}

/**
 * A one-off message that survives a redirect. Reading it clears it, so a
 * refresh does not show "Spool added." all over again.
 */
function takeFlash(): ?string
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_string($message) && $message !== '' ? $message : null;
}

/** "2026-09-18" -> "18 Sep 2026". Empty or broken dates give an empty string. */
function formatDate(?string $date): string
{
    if (!$date || $date === '0000-00-00') {
        return '';
    }

    try {
        return (new DateTimeImmutable($date))->format('j M Y');
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Is this colour light enough that black text sits better on it?
 * Used for the swatch outline, so white filament stays visible on a
 * white card.
 */
function isLightColor(?string $hex): bool
{
    if (!is_string($hex) || !preg_match('/^#[0-9a-f]{6}$/i', $hex)) {
        return false;
    }

    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

    // Rec. 601 luma: green counts heaviest, blue least.
    return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 186;
}
