<?php
declare(strict_types=1);
if (!defined('FILAMENT')) { http_response_code(403); exit('Forbidden'); }

/*
 * The part of the overview that changes when you search: the totals bar,
 * the cards and the paging. index.php includes this twice over: once inside
 * the full page, and once on its own when the search box asks for a
 * refresh without reloading everything.
 *
 * Expects: $items, $total, $stats, $f, $page, $pages, $admin.
 */
?>
<div class="statsbar">
    <span><strong><?= $stats['spools'] ?></strong> <?= $stats['spools'] === 1 ? 'spool' : 'spools' ?></span>
    <span><strong><?= $stats['sealed'] ?></strong> sealed</span>
    <span><strong><?= $stats['opened'] ?></strong> opened</span>
    <?php if ($stats['empty_spools'] > 0): ?>
        <span><strong><?= $stats['empty_spools'] ?></strong> empty</span>
    <?php endif; ?>
    <span><strong><?= esc(formatGrams($stats['grams_left'])) ?></strong> left</span>
    <span><strong><?= $stats['brands'] ?></strong> <?= $stats['brands'] === 1 ? 'brand' : 'brands' ?></span>
</div>

<?php if ($items === []): ?>

    <section class="empty">
        <?php if (filtersActive($f)): ?>
            <p class="empty-text">Nothing matches that search.</p>
            <a class="btn" href="index.php">Clear the filters</a>
        <?php else: ?>
            <p class="empty-text">No filament in the cabinet yet.</p>
            <?php if ($admin): ?>
                <a class="btn btn-primary btn-big" href="edit.php">Add your first spool</a>
            <?php else: ?>
                <a class="btn" href="login.php">Log in to add some</a>
            <?php endif; ?>
        <?php endif; ?>
    </section>

<?php else: ?>

    <div class="grid">
        <?php foreach ($items as $item): ?>
            <?php
            $hex       = (string)($item['color_hex'] ?? '');
            $pct       = (int)$item['remaining_pct'];
            $isEmpty   = $item['status'] === 'empty';
            $swatchCls = 'swatch' . (isLightColor($hex) ? ' swatch-light' : '');
            ?>
            <a class="spool<?= $isEmpty ? ' is-empty' : '' ?>" href="filament.php?id=<?= (int)$item['id'] ?>">

                <div class="spool-photo">
                    <?php if (!empty($item['photo'])): ?>
                        <img src="<?= esc(photoThumbUrl($item['photo'])) ?>" alt="" loading="lazy" decoding="async">
                    <?php else: ?>
                        <?php
                        // No colour recorded means the tile keeps the card's own
                        // grey, and white lettering would vanish on it in light mode.
                        $labelCls = $hex === '' ? ' is-plain' : (isLightColor($hex) ? ' on-light' : '');
                        ?>
                        <span class="spool-nophoto" <?= $hex !== '' ? 'style="background:' . esc($hex) . '"' : '' ?>>
                            <span class="spool-nophoto-label<?= $labelCls ?>">
                                <?= esc($item['material']) ?>
                            </span>
                        </span>
                    <?php endif; ?>

                    <span class="spool-status status-<?= esc($item['status']) ?>">
                        <?= esc(statusLabel($item['status'])) ?>
                    </span>

                    <?php if ((int)$item['photo_count'] > 1): ?>
                        <span class="spool-photocount"><?= (int)$item['photo_count'] ?> photos</span>
                    <?php endif; ?>
                </div>

                <div class="spool-body">
                    <div class="spool-title">
                        <span class="<?= $swatchCls ?>" style="background:<?= esc($hex !== '' ? $hex : 'var(--surface-2)') ?>"></span>
                        <strong><?= esc($item['color_name'] !== '' ? $item['color_name'] : 'Unnamed colour') ?></strong>
                    </div>

                    <div class="spool-meta">
                        <span class="chip"><?= esc($item['material']) ?></span>
                        <span class="spool-brand"><?= esc($item['brand']) ?></span>
                    </div>

                    <div class="bar" role="img"
                         aria-label="<?= $pct ?> percent left of <?= (int)$item['weight_g'] ?> gram">
                        <span style="width:<?= max(0, min(100, $pct)) ?>%"></span>
                    </div>

                    <div class="spool-foot">
                        <span><?= $pct ?>% &middot; <?= esc(formatGrams(remainingGrams($item))) ?></span>
                        <?php if ($item['source'] !== 'bought'): ?>
                            <span class="chip chip-soft"><?= esc(sourceLabel($item['source'])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="pager">
            <?php if ($page > 1): ?>
                <a class="btn btn-ghost" href="<?= esc(filterQuery($f, ["page" => $page - 1])) ?>">&larr; Back</a>
            <?php else: ?>
                <span class="btn btn-ghost" aria-disabled="true">&larr; Back</span>
            <?php endif; ?>

            <span class="pager-label">Page <?= $page ?> of <?= $pages ?> &middot; <?= $total ?> spools</span>

            <?php if ($page < $pages): ?>
                <a class="btn btn-ghost" href="<?= esc(filterQuery($f, ["page" => $page + 1])) ?>">Next &rarr;</a>
            <?php else: ?>
                <span class="btn btn-ghost" aria-disabled="true">Next &rarr;</span>
            <?php endif; ?>
        </nav>
    <?php endif; ?>

<?php endif; ?>
