<?php
/**
 * Updates Page
 *
 * Lists published changelog entries newest-first.
 * Content is shown in the user's active UI language with fallback to English then Finnish.
 * Clicking a title or the "Read more" button opens a modal with the full entry.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../assets/lib/Database.php';
require_once __DIR__ . '/../../assets/lib/sf_terms.php';

sf_require_login();

Database::setConfig($config['db'] ?? []);

$user    = sf_current_user();
$uiLang  = $_SESSION['ui_lang'] ?? 'fi';
$base    = rtrim($config['base_url'] ?? '', '/');

// Load published changelog entries newest first
$db = Database::getInstance();
$stmt = $db->prepare(
    "SELECT *
     FROM sf_changelog
     WHERE is_published = 1
     ORDER BY COALESCE(publish_date, DATE(created_at)) DESC, created_at DESC"
);
$stmt->execute();
$entries = $stmt->fetchAll();

/**
 * Resolve translated title/content for the given language with fallback.
 *
 * @param array  $translations  Decoded JSON array
 * @param string $lang          Desired language code
 * @param string $field         'title' or 'content'
 * @return string
 */
function resolveTranslation(array $translations, string $lang, string $field): string
{
    if (!empty($translations[$lang][$field])) {
        return $translations[$lang][$field];
    }
    // Fallback chain: en → fi → first available
    foreach (['en', 'fi'] as $fallback) {
        if (!empty($translations[$fallback][$field])) {
            return $translations[$fallback][$field];
        }
    }
    foreach ($translations as $t) {
        if (!empty($t[$field])) {
            return $t[$field];
        }
    }
    return '';
}

/**
 * Sanitize changelog HTML content.
 * Allows safe formatting tags and removes all attributes.
 * Identical logic to sf_sanitize_ai_html() used on the view page.
 * Falls back to nl2br for plain-text (no HTML tags) content.
 */
function sf_updates_sanitize_html(string $html): string
{
    // Plain-text content: convert newlines to <br> tags
    if (strip_tags($html) === $html) {
        return nl2br(htmlspecialchars($html, ENT_QUOTES, 'UTF-8'));
    }
    // HTML content: strip disallowed tags and remove all attributes
    $allowed = '<p><br><strong><em><u><ol><ul><li><span>';
    $html = strip_tags($html, $allowed);
    $html = preg_replace('/<(\w+)(?:\s[^>]*)?(\/?)>/', '<$1$2>', $html);
    return $html;
}
?>

<div class="sf-page-container">
    <div class="sf-page-header">
        <h1 class="sf-page-title">
            <?= htmlspecialchars(sf_term('updates_title', $uiLang), ENT_QUOTES, 'UTF-8') ?>
        </h1>
    </div>
    <p class="sf-updates-description">
        <?= htmlspecialchars(sf_term('updates_description', $uiLang), ENT_QUOTES, 'UTF-8') ?>
    </p>

    <?php if (empty($entries)): ?>
        <div class="sf-updates-empty">
            <p><?= htmlspecialchars(sf_term('updates_empty', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    <?php else: ?>
        <?php
        // Build sorted unique month list for filter buttons
        $months = [];
        foreach ($entries as $e) {
            $rawDate = !empty($e['publish_date']) ? $e['publish_date'] : $e['created_at'];
            $ts = strtotime($rawDate);
            if ($ts === false) { continue; }
            $key = date('Y-m', $ts);
            if (!isset($months[$key])) {
                // Localise month label: use IntlDateFormatter when available, otherwise a manual map
                if (class_exists('IntlDateFormatter')) {
                    $localeMap = ['fi' => 'fi_FI', 'sv' => 'sv_SE', 'en' => 'en_US', 'it' => 'it_IT', 'el' => 'el_GR'];
                    $locale = $localeMap[$uiLang] ?? 'en_US';
                    $fmt = new IntlDateFormatter(
                        $locale,
                        IntlDateFormatter::NONE,
                        IntlDateFormatter::NONE,
                        null,
                        null,
                        'MMMM yyyy'
                    );
                    $label = ucfirst($fmt->format($ts));
                } else {
                    $label = date('m/Y', $ts);
                }
                $months[$key] = $label;
            }
        }
        ?>
        <?php if (!empty($months)): ?>
        <div class="sf-updates-filter" role="group" aria-label="<?= htmlspecialchars(sf_term('updates_filter_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
            <span class="sf-updates-filter-label">
                <?= htmlspecialchars(sf_term('updates_filter_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </span>
            <div class="sf-updates-filter-buttons">
                <button type="button"
                        class="sf-btn sf-btn-small sf-btn-primary sf-updates-filter-btn"
                        data-month="all">
                    <?= htmlspecialchars(sf_term('updates_filter_all', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <?php foreach ($months as $key => $label): ?>
                    <button type="button"
                            class="sf-btn sf-btn-small sf-btn-secondary sf-updates-filter-btn"
                            data-month="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php
        // Create month-abbreviation formatter once (locale stays constant for all entries)
        $dateLocaleMap = ['fi' => 'fi_FI', 'sv' => 'sv_SE', 'en' => 'en_US', 'it' => 'it_IT', 'el' => 'el_GR'];
        $dateLocale    = $dateLocaleMap[$uiLang] ?? 'en_US';
        $monthAbbrFmt  = class_exists('IntlDateFormatter') ? new IntlDateFormatter(
            $dateLocale,
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            null,
            null,
            'MMM'
        ) : null;
        ?>
        <div class="sf-updates-timeline" id="sfUpdatesTimeline">
            <?php foreach ($entries as $entry): ?>
                <?php
                $translations = [];
                if (!empty($entry['translations'])) {
                    $decoded = json_decode($entry['translations'], true);
                    if (is_array($decoded)) {
                        $translations = $decoded;
                    }
                }
                $title   = resolveTranslation($translations, $uiLang, 'title');
                $content = resolveTranslation($translations, $uiLang, 'content');
                // Use publish_date when set, otherwise fall back to created_at
                $rawDate = !empty($entry['publish_date']) ? $entry['publish_date'] : $entry['created_at'];
                $displayTimestamp = strtotime($rawDate);
                if ($displayTimestamp === false) { $displayTimestamp = time(); }
                $dateStr  = date('d.m.Y', $displayTimestamp);
                $monthKey = date('Y-m', $displayTimestamp);
                $dateDayStr   = date('j', $displayTimestamp);
                $dateYearStr  = date('Y', $displayTimestamp);
                $dateMonthStr = $monthAbbrFmt
                    ? mb_strtoupper((string)$monthAbbrFmt->format($displayTimestamp))
                    : mb_strtoupper(date('M', $displayTimestamp));
                $entryId  = (int)$entry['id'];
                // Sanitize content for safe HTML rendering
                $sanitizedContent = sf_updates_sanitize_html($content);
                // Parse images
                $images = [];
                if (!empty($entry['images'])) {
                    $decodedImages = json_decode($entry['images'], true);
                    if (is_array($decodedImages)) {
                        // Only keep paths that match our own upload path pattern (security)
                        foreach ($decodedImages as $imgPath) {
                            if (is_string($imgPath) && preg_match('#^uploads/changelog/[a-zA-Z0-9._-]+$#', $imgPath)) {
                                $images[] = $imgPath;
                            }
                        }
                    }
                }
                ?>
                <div class="sf-updates-item sf-card-appear" data-month="<?= htmlspecialchars($monthKey, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="sf-updates-item-date">
                        <span class="sf-updates-date-day"><?= htmlspecialchars($dateDayStr, ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="sf-updates-date-month"><?= htmlspecialchars($dateMonthStr, ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="sf-updates-date-year"><?= htmlspecialchars($dateYearStr, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div class="sf-updates-item-body<?= !empty($images) ? ' sf-updates-item-has-images' : '' ?>">
                        <div class="sf-updates-item-text">
                            <?php if ($title !== ''): ?>
                                <h2 class="sf-updates-item-title">
                                    <button type="button"
                                            class="sf-updates-title-btn"
                                            data-entry-id="<?= $entryId ?>"
                                            aria-haspopup="dialog">
                                        <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>
                                    </button>
                                </h2>
                            <?php endif; ?>
                            <?php if ($content !== '' || !empty($images)): ?>
                                <button type="button"
                                        class="sf-btn sf-btn-small sf-btn-secondary sf-updates-read-more"
                                        data-entry-id="<?= $entryId ?>"
                                        aria-haspopup="dialog">
                                    <?= htmlspecialchars(sf_term('updates_read_more', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                                </button>
                                <!-- Hidden content rendered server-side for safe injection into modal -->
                                <div id="sf-update-content-<?= $entryId ?>"
                                     class="sf-updates-hidden-content"
                                     aria-hidden="true">
                                    <div class="sf-update-text-content"><?= $sanitizedContent ?></div>
                                    <?php if (!empty($images)): ?>
                                        <div class="sf-update-images">
                                            <?php foreach ($images as $imgPath): ?>
                                                <div class="sf-update-image-wrap">
                                                    <img src="<?= htmlspecialchars($base . '/' . $imgPath, ENT_QUOTES, 'UTF-8') ?>"
                                                         alt="<?= htmlspecialchars(sf_term('updates_screenshot_alt', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
                                                         class="sf-update-image"
                                                         loading="lazy">
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($images)): ?>
                            <div class="sf-updates-item-image-preview"
                                 data-entry-id="<?= $entryId ?>"
                                 role="button"
                                 tabindex="0"
                                 aria-haspopup="dialog">
                                <img src="<?= htmlspecialchars($base . '/' . $images[0], ENT_QUOTES, 'UTF-8') ?>"
                                     alt="<?= htmlspecialchars(sf_term('updates_screenshot_alt', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
                                     class="sf-updates-item-preview-img"
                                     loading="lazy">
                                <?php if (count($images) > 1): ?>
                                    <span class="sf-updates-item-image-count">+<?= count($images) - 1 ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Detail Modal -->
<div id="sfUpdateDetailModal" class="sf-modal hidden" role="dialog" aria-modal="true" aria-labelledby="sfUpdateDetailModalTitle">
    <div class="sf-modal-content sf-updates-modal-content">
        <div class="sf-modal-header">
            <h3 id="sfUpdateDetailModalTitle" class="sf-updates-modal-title"></h3>
            <button type="button" class="sf-modal-close-btn" data-modal-close aria-label="<?= htmlspecialchars(sf_term('updates_close', $uiLang), ENT_QUOTES, 'UTF-8') ?>">×</button>
        </div>
        <div class="sf-modal-body sf-updates-modal-body" id="sfUpdateDetailModalBody"></div>
        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close>
                <?= htmlspecialchars(sf_term('updates_close', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<style>
.sf-updates-description {
    margin: 1.25rem 0 2rem;
    color: rgba(255, 255, 255, 0.8);
    font-size: 1rem;
    line-height: 1.5;
}

.sf-updates-empty {
    padding: 48px 24px;
    text-align: center;
    color: var(--sf-muted);
    font-size: 1rem;
}

.sf-updates-filter {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.sf-updates-filter-label {
    font-size: 0.85rem;
    color: rgba(255, 255, 255, 0.7);
    white-space: nowrap;
    padding-top: 5px;
    flex-shrink: 0;
}

.sf-updates-filter-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.sf-updates-timeline {
    display: flex;
    flex-direction: column;
    gap: 0;
    padding: 24px 0;
    position: relative;
}

.sf-updates-timeline::before {
    content: '';
    position: absolute;
    left: 108px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: var(--sf-border);
}

.sf-updates-item {
    display: flex;
    gap: 24px;
    padding: 0 0 32px 0;
    position: relative;
}

.sf-updates-item::before {
display: none;
}

.sf-updates-item-date {
    width: 96px;
    flex-shrink: 0;
    background: var(--sf-yellow, #FEE000);
    color: #1a1a1a;
    border-radius: 8px;
    padding: 10px 8px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    position: relative;
    align-self: flex-start;
    text-align: center;
}

.sf-updates-item-date::after {
    content: '';
    position: absolute;
    right: -10px;
    top: 14px;
    border-width: 8px 0 8px 10px;
    border-style: solid;
    border-color: transparent transparent transparent var(--sf-yellow, #FEE000);
}

.sf-updates-date-day {
    font-size: 1.8rem;
    font-weight: 700;
    line-height: 1;
    color: #1a1a1a;
}

.sf-updates-date-month {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #1a1a1a;
}

.sf-updates-date-year {
    font-size: 0.7rem;
    font-weight: 500;
    color: rgba(26, 26, 26, 0.7);
}

.sf-updates-item-body {
    flex: 1;
    background: var(--sf-surface, #fff);
    border: 1px solid var(--sf-border);
    border-radius: var(--sf-radius, 14px);
    padding: 18px 22px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.05);
    text-align: left;
}

.sf-updates-item-title {
    font-size: 1.05rem;
    font-weight: 600;
    margin: 0 0 12px;
    color: var(--sf-text, #111827);
    text-align: left;
}

.sf-updates-title-btn {
    background: none;
    border: none;
    padding: 0;
    margin: 0;
    font-size: inherit;
    font-weight: inherit;
    color: inherit;
    cursor: pointer;
    text-align: left;
    text-decoration: underline;
    text-decoration-color: transparent;
    transition: text-decoration-color 0.15s;
    font-family: inherit;
    line-height: inherit;
}

.sf-updates-title-btn:hover,
.sf-updates-title-btn:focus-visible {
    text-decoration-color: currentColor;
    outline: none;
}

.sf-updates-hidden-content {
    display: none;
}

/* Modal content formatting */
.sf-updates-modal-content {
    max-width: 640px;
    width: 100%;
}

.sf-updates-modal-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--sf-text, #111827);
    margin: 0;
    text-align: left;
}

.sf-updates-modal-body {
    font-size: 0.93rem;
    color: var(--sf-text, #111827);
    line-height: 1.7;
    text-align: left;
}

.sf-updates-modal-body p {
    margin: 0 0 0.75em;
}

.sf-updates-modal-body p:last-child {
    margin-bottom: 0;
}

.sf-updates-modal-body ul,
.sf-updates-modal-body ol {
    margin: 0 0 0.75em;
    padding-left: 1.5em;
}

.sf-updates-modal-body li {
    margin-bottom: 0.25em;
}

@media (max-width: 600px) {
    .sf-updates-timeline::before {
        display: none;
    }
    .sf-updates-item::before {
        display: none;
    }
    .sf-updates-item {
        flex-direction: column;
        gap: 0;
        padding: 0 0 32px 0;
        margin-top: 16px;
    }
    .sf-updates-item-date {
        position: absolute;
        top: -14px;
        left: 16px;
        width: auto;
        flex-direction: row;
        gap: 6px;
        align-items: center;
        padding: 4px 14px;
        border-radius: 20px;
        font-size: 0.78rem;
        font-weight: 700;
        box-shadow: 0 2px 6px rgba(0,0,0,0.12);
        z-index: 2;
    }
    .sf-updates-item-date::after {
        display: none;
    }
    .sf-updates-date-day,
    .sf-updates-date-month,
    .sf-updates-date-year {
        font-size: 0.78rem;
        font-weight: 700;
        line-height: 1;
    }
    .sf-updates-item-body {
        width: 100%;
        padding-top: 24px;
    }
}

/* Update detail images */
.sf-update-images {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid #e5e7eb;
}

.sf-update-image {
    width: 100%;
    height: auto;
    display: block;
    border-radius: 10px;
}

/* When images are present, widen the modal */
.sf-updates-modal-content.has-images {
    max-width: 920px;
}

/* Card body grid when images exist */
.sf-updates-item-has-images {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 16px;
    align-items: start;
}

.sf-updates-item-text {
    min-width: 0;
}

/* Large image preview on the right of the card */
.sf-updates-item-image-preview {
    position: relative;
    cursor: pointer;
    border-radius: 10px;
    overflow: hidden;
    flex-shrink: 0;
    align-self: start;
}

.sf-updates-item-preview-img {
    width: 200px;
    height: 130px;
    object-fit: cover;
    display: block;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    transition: opacity 0.15s ease;
}

.sf-updates-item-image-preview:hover .sf-updates-item-preview-img {
    opacity: 0.85;
}

.sf-updates-item-image-count {
    position: absolute;
    bottom: 6px;
    right: 6px;
    background: rgba(0, 0, 0, 0.55);
    color: #fff;
    font-size: 0.78rem;
    font-weight: 600;
    padding: 2px 7px;
    border-radius: 20px;
    pointer-events: none;
}

/* Image wrap in hidden content (no link) */
.sf-update-image-wrap {
    border-radius: 10px;
    overflow: hidden;
    border: 1px solid #e5e7eb;
}

/* Modal two-column layout */
.sf-updates-modal-body-grid {
    display: grid;
    grid-template-columns: 1fr 360px;
    gap: 24px;
    align-items: start;
}

.sf-updates-modal-body-text {
    min-width: 0;
}

.sf-updates-modal-body-images {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

@media (max-width: 768px) {
    .sf-updates-item-has-images {
        grid-template-columns: 1fr;
    }
    .sf-updates-item-image-preview {
        display: none;
    }
    .sf-updates-modal-body-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
(function () {
    var modal = document.getElementById('sfUpdateDetailModal');
    var modalTitle = document.getElementById('sfUpdateDetailModalTitle');
    var modalBody = document.getElementById('sfUpdateDetailModalBody');

    function openUpdateModal(entryId) {
        var titleBtn = document.querySelector('.sf-updates-title-btn[data-entry-id="' + entryId + '"]');
        var contentEl = document.getElementById('sf-update-content-' + entryId);

        if (modalTitle) {
            modalTitle.textContent = titleBtn ? titleBtn.textContent.trim() : '';
        }
        if (modalBody && contentEl) {
            var textEl = contentEl.querySelector('.sf-update-text-content');
            var imagesEl = contentEl.querySelector('.sf-update-images');

            if (textEl && imagesEl) {
                var grid = document.createElement('div');
                grid.className = 'sf-updates-modal-body-grid';

                var textCol = document.createElement('div');
                textCol.className = 'sf-updates-modal-body-text';
                textCol.innerHTML = textEl.innerHTML;

                var imagesCol = document.createElement('div');
                imagesCol.className = 'sf-updates-modal-body-images';
                imagesCol.innerHTML = imagesEl.innerHTML;

                grid.appendChild(textCol);
                grid.appendChild(imagesCol);
                modalBody.innerHTML = '';
                modalBody.appendChild(grid);
            } else {
                modalBody.innerHTML = contentEl.innerHTML;
            }
        }

        // Toggle wider modal if images are present
        var modalContent = modal ? modal.querySelector('.sf-updates-modal-content') : null;
        if (modalContent) {
            var hasImages = modalBody && modalBody.querySelector('.sf-update-images, .sf-updates-modal-body-images');
            modalContent.classList.toggle('has-images', !!hasImages);
        }

        if (modal) {
            modal.classList.remove('hidden');
            document.body.classList.add('sf-modal-open');
            var closeBtn = modal.querySelector('.sf-modal-close-btn');
            if (closeBtn) closeBtn.focus({ preventScroll: true });
        }
    }

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('.sf-updates-title-btn, .sf-updates-read-more, .sf-updates-item-image-preview');
        if (trigger) {
            e.preventDefault();
            var entryId = parseInt(trigger.dataset.entryId, 10);
            if (entryId > 0) {
                openUpdateModal(entryId);
            }
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') { return; }
        var trigger = e.target.closest('.sf-updates-item-image-preview');
        if (trigger) {
            e.preventDefault();
            var entryId = parseInt(trigger.dataset.entryId, 10);
            if (entryId > 0) {
                openUpdateModal(entryId);
            }
        }
    });

    // Month filter
    var filterBtns = document.querySelectorAll('.sf-updates-filter-btn');
    if (filterBtns.length) {
        filterBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var month = this.dataset.month;
                // Toggle active state on buttons
                filterBtns.forEach(function (b) {
                    b.classList.remove('sf-btn-primary');
                    b.classList.add('sf-btn-secondary');
                });
                this.classList.add('sf-btn-primary');
                this.classList.remove('sf-btn-secondary');
                // Show/hide timeline items
                var items = document.querySelectorAll('#sfUpdatesTimeline .sf-updates-item');
                items.forEach(function (item) {
                    if (month === 'all' || item.dataset.month === month) {
                        item.style.display = '';
                    } else {
                        item.style.display = 'none';
                    }
                });
            });
        });
    }
})();
</script>