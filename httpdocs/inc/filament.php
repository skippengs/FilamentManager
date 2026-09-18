<?php
declare(strict_types=1);
if (!defined('FILAMENT')) { http_response_code(403); exit('Forbidden'); }

/*
 * Everything that knows what a spool of filament is: the vocabulary, the
 * search, and loading a spool with its photos.
 */

/* ---------------------------------------------------------------
 * Vocabulary
 * --------------------------------------------------------------- */

// Suggestions only. The field is free text, so an odd material you bought
// once does not need a code change.
const MATERIALS = [
    'PLA', 'PLA+', 'PLA Silk', 'PLA Matte', 'PETG', 'ABS', 'ASA', 'TPU',
    'Nylon', 'PC', 'PVA', 'HIPS', 'PLA-CF', 'PETG-CF', 'Wood', 'Glow',
];

// Shown in the brand dropdown alongside every brand already in your
// collection, so the common ones are one tap away.
const BRAND_SUGGESTIONS = [
    'Bambu Lab', 'Prusament', 'eSun', 'Sunlu', 'Overture', 'Polymaker',
    'Elegoo', 'Creality', 'Anycubic', 'Fillamentum', 'Extrudr', '123-3D',
    'AzureFilm', 'Formfutura', 'Spectrum', 'Eryone', 'Jayo', 'Hatchbox',
];

const STATUSES = [
    'sealed' => 'Sealed',
    'open'   => 'Opened',
    'empty'  => 'Empty',
];

const SOURCES = [
    'bought'   => 'Bought',
    'gift'     => 'Gift',
    'giveaway' => 'Giveaway',
];

const DIAMETERS = ['1.75', '2.85', '3.00'];

// Common spool sizes, offered as buttons next to the weight field.
const WEIGHT_SUGGESTIONS = [250, 500, 750, 1000, 2000, 3000];

const SORTS = [
    'recent'    => 'Newest first',
    'brand'     => 'Brand',
    'material'  => 'Material',
    'color'     => 'Colour',
    'remaining' => 'Least left first',
    'oldest'    => 'Oldest first',
];

function statusLabel(string $status): string
{
    return STATUSES[$status] ?? $status;
}

function sourceLabel(string $source): string
{
    return SOURCES[$source] ?? $source;
}

/** How many grams this spool still holds, going by the percentage. */
function remainingGrams(array $item): float
{
    return (int)$item['weight_g'] * (int)$item['remaining_pct'] / 100;
}

/* ---------------------------------------------------------------
 * Search and filters
 * --------------------------------------------------------------- */

/** The filters as they came in from the URL, cleaned up. */
function readFilters(array $get): array
{
    $sort   = (string)($get['sort'] ?? 'recent');
    $status = (string)($get['status'] ?? '');
    $source = (string)($get['source'] ?? '');

    return [
        'q'        => trim((string)($get['q'] ?? '')),
        'material' => trim((string)($get['material'] ?? '')),
        'brand'    => trim((string)($get['brand'] ?? '')),
        'status'   => isset(STATUSES[$status]) ? $status : '',
        'source'   => isset(SOURCES[$source]) ? $source : '',
        'sort'     => isset(SORTS[$sort]) ? $sort : 'recent',
    ];
}

/**
 * The current filters as a query string, with anything still at its default
 * left out. Keeps "?sort=recent" from trailing along behind every link.
 */
function filterQuery(array $f, array $changes = []): string
{
    if (($f['sort'] ?? '') === 'recent') {
        $f['sort'] = '';
    }

    return queryWith($f, $changes);
}

/** True when anything is narrowing the list down. */
function filtersActive(array $f): bool
{
    return $f['q'] !== '' || $f['material'] !== '' || $f['brand'] !== ''
        || $f['status'] !== '' || $f['source'] !== '';
}

/**
 * Turns the filters into a WHERE clause plus its parameters.
 *
 * The search box is split on spaces and every word has to match somewhere,
 * so "bambu matte black" finds the spool even though those three words sit
 * in three different columns.
 */
function buildWhere(array $f): array
{
    $where  = ['1 = 1'];
    $params = [];

    $words = preg_split('/\s+/u', $f['q'], -1, PREG_SPLIT_NO_EMPTY) ?: [];

    // Every column gets a placeholder of its own. Reusing one name across
    // several LIKEs is what you would write by hand, but PDO refuses it
    // once prepared statements are handed to MySQL rather than emulated.
    // status and source are in here so that typing "gift" or "sealed" works
    // as a search as well as a filter.
    $columns = ['brand', 'material', 'color_name', 'notes', 'vendor', 'color_hex', 'status', 'source'];

    foreach ($words as $i => $word) {
        // Escape the LIKE wildcards, otherwise a note containing a % turns
        // into a match-everything search.
        $like  = '%' . addcslashes($word, '%_\\') . '%';
        $parts = [];

        foreach ($columns as $c => $column) {
            $key            = ':q' . $i . '_' . $c;
            $parts[]        = $column . ' LIKE ' . $key;
            $params[$key]   = $like;
        }

        $where[] = '(' . implode(' OR ', $parts) . ')';
    }

    if ($f['material'] !== '') {
        $where[]             = 'material = :material';
        $params[':material'] = $f['material'];
    }

    if ($f['brand'] !== '') {
        $where[]          = 'brand = :brand';
        $params[':brand'] = $f['brand'];
    }

    if ($f['status'] !== '') {
        $where[]           = 'status = :status';
        $params[':status'] = $f['status'];
    }

    if ($f['source'] !== '') {
        $where[]           = 'source = :source';
        $params[':source'] = $f['source'];
    }

    return [implode("\n  AND ", $where), $params];
}

/** Whitelisted ORDER BY, because you cannot bind a column name. */
function buildOrderBy(string $sort): string
{
    return match ($sort) {
        'brand'     => 'brand, material, color_name',
        'material'  => 'material, brand, color_name',
        'color'     => 'color_name, brand',
        'remaining' => "status = 'empty', remaining_pct, brand",
        'oldest'    => 'created_at, id',
        default     => 'created_at DESC, id DESC',
    };
}

/**
 * One page of spools, each with its primary photo.
 *
 * @return array{items: array, total: int}
 */
function searchFilament(PDO $pdo, array $f, int $page = 1, int $perPage = PER_PAGE): array
{
    [$where, $params] = buildWhere($f);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM {filament} WHERE $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // LIMIT and OFFSET are built from integers we cast ourselves; binding
    // them turns them into strings that MySQL refuses in this position.
    $perPage = max(1, min(200, $perPage));
    $offset  = max(0, ($page - 1) * $perPage);
    $order   = buildOrderBy($f['sort']);

    $stmt = $pdo->prepare(
        "SELECT f.*,
                (SELECT p.filename FROM {filament_photo} p
                  WHERE p.filament_id = f.id
                  ORDER BY p.is_primary DESC, p.id
                  LIMIT 1) AS photo,
                (SELECT COUNT(*) FROM {filament_photo} p2 WHERE p2.filament_id = f.id) AS photo_count
           FROM {filament} f
          WHERE $where
          ORDER BY $order
          LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);

    return ['items' => $stmt->fetchAll(), 'total' => $total];
}

/** One spool plus all of its photos, or null when the id is unknown. */
function loadFilament(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM {filament} WHERE id = ?');
    $stmt->execute([$id]);
    $item = $stmt->fetch();

    if (!$item) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM {filament_photo} WHERE filament_id = ? ORDER BY is_primary DESC, id'
    );
    $stmt->execute([$id]);
    $item['photos'] = $stmt->fetchAll();

    return $item;
}

/** Totals for the bar above the list, counted over the current filters. */
function collectionStats(PDO $pdo, array $f): array
{
    [$where, $params] = buildWhere($f);

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)                                         AS spools,
                SUM(status = 'sealed')                           AS sealed,
                SUM(status = 'open')                             AS opened,
                SUM(status = 'empty')                            AS empty_spools,
                COUNT(DISTINCT brand)                            AS brands,
                COALESCE(SUM(weight_g * remaining_pct / 100), 0) AS grams_left
           FROM {filament}
          WHERE $where"
    );
    $stmt->execute($params);

    $row = $stmt->fetch() ?: [];

    return [
        'spools'       => (int)($row['spools'] ?? 0),
        'sealed'       => (int)($row['sealed'] ?? 0),
        'opened'       => (int)($row['opened'] ?? 0),
        'empty_spools' => (int)($row['empty_spools'] ?? 0),
        'brands'       => (int)($row['brands'] ?? 0),
        'grams_left'   => (float)($row['grams_left'] ?? 0),
    ];
}

/** Distinct values already in the collection, for the filter dropdowns. */
function distinctValues(PDO $pdo, string $column): array
{
    if (!in_array($column, ['brand', 'material'], true)) {
        return [];
    }

    $stmt = $pdo->query(
        "SELECT DISTINCT $column AS v FROM {filament}
          WHERE $column <> '' ORDER BY $column"
    );

    return array_column($stmt->fetchAll(), 'v');
}
