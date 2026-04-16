<?php
/**
 * PDF Dashboard Report Template
 * Modern A4 layout for dashboard statistics
 */

// Variables available from generate_dashboard_report.php:
// $uiLang, $originalStats, $worksiteStats, $maxWorksiteCount,
// $bodyPartCounts, $categoryTotals, $recentInjuryFlashes,
// $periodLabel, $reportUserName, $site,
// $frontSvgRaw, $backSvgRaw, $appRoot,
// $includeStats, $includeWorksites, $includeInjuries, $includeRecent

$fontDir          = $appRoot . '/assets/fonts';
$fontRegularPath  = $fontDir . '/OpenSans-Regular.ttf';
$fontBoldPath     = $fontDir . '/OpenSans-Bold.ttf';
$logoPath         = $appRoot . '/assets/img/tapojarvi_logo.png';
$logoDataUri      = '';
if (file_exists($logoPath)) {
    $logoDataUri = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
}
$bgImagePath = $appRoot . '/assets/img/templates/SF_report_bg.jpg';
$bgBase64 = file_exists($bgImagePath) ? base64_encode(file_get_contents($bgImagePath)) : '';

$generatedAt = (new DateTime())->format('d.m.Y H:i');
$reportTitle = sf_term('dashboard_report_title_pdf', $uiLang);

// Build injury count map for heatmap coloring: svg_id => count
$bpCountMap = [];
$maxBpCount = 0;
foreach ($bodyPartCounts as $bp) {
    $bpCountMap[$bp['svg_id']] = (int)$bp['count'];
    if ((int)$bp['count'] > $maxBpCount) {
        $maxBpCount = (int)$bp['count'];
    }
}

/**
 * Get heatmap fill color for a given intensity (0–1).
 * Low = soft amber, high = deep red.
 */
function pdfHeatmapColor(float $intensity): string
{
    if ($intensity <= 0) return '#e5e7eb'; // no data – light grey
    // Lerp from #fef3c7 (amber-100) through #f59e0b (amber-400) to #dc2626 (red-600)
    if ($intensity < 0.5) {
        $t = $intensity * 2.0;
        $r = (int)round(254 + (245 - 254) * $t);
        $g = (int)round(243 + (158 - 243) * $t);
        $b = (int)round(199 + (11  - 199) * $t);
    } else {
        $t = ($intensity - 0.5) * 2.0;
        $r = (int)round(245 + (220 - 245) * $t);
        $g = (int)round(158 + (38  - 158) * $t);
        $b = (int)round(11  + (38  - 11 ) * $t);
    }
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

/**
 * Inject fill colors into raw SVG markup based on body-part injury counts.
 */
function pdfColorSvg(string $svgRaw, array $bpCountMap, int $maxCount): string
{
    if ($svgRaw === '' || $maxCount <= 0) {
        return $svgRaw;
    }
    // Replace each `id="bp-..."` path fill by adding or replacing fill attribute
    return preg_replace_callback(
        '/(<(?:path|ellipse|circle|rect)[^>]*\s)id="(bp-[^"]+)"([^>]*\/?>)/',
        static function (array $m) use ($bpCountMap, $maxCount): string {
            $svgId     = $m[2];
            $count     = $bpCountMap[$svgId] ?? 0;
            $intensity = $maxCount > 0 ? $count / $maxCount : 0;
            $color     = pdfHeatmapColor((float)$intensity);
            // Remove any existing fill/stroke attributes
            $before = preg_replace('/\s+fill="[^"]*"/', '', $m[1]);
            $after  = preg_replace('/\s+fill="[^"]*"/', '', $m[3]);
            $before = preg_replace('/\s+stroke="[^"]*"/', '', $before);
            $after  = preg_replace('/\s+stroke="[^"]*"/', '', $after);
            return $before . 'id="' . $svgId . '" fill="' . $color . '" stroke="#9ca3af" stroke-width="0.5"' . $after;
        },
        $svgRaw
    );
}

$coloredFrontSvg = pdfColorSvg($frontSvgRaw, $bpCountMap, $maxBpCount);
$coloredBackSvg  = pdfColorSvg($backSvgRaw,  $bpCountMap, $maxBpCount);

// Sort categories for display
arsort($categoryTotals);
$sortedCategories = array_values($categoryTotals);
$maxCategoryCount = !empty($sortedCategories) ? max(array_column($sortedCategories, 'count')) : 0;

// Type label map using existing terms
$typeLabels = [
    'red'    => sf_term('dashboard_stat_red',    $uiLang),
    'yellow' => sf_term('dashboard_stat_yellow', $uiLang),
    'green'  => sf_term('dashboard_stat_green',  $uiLang),
];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($uiLang) ?>">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($reportTitle) ?></title>
    <style>
        <?php if (file_exists($fontRegularPath)): ?>
        @font-face {
            font-family: 'Open Sans';
            src: url("file://<?= str_replace('\\', '/', $fontRegularPath) ?>") format('truetype');
            font-weight: normal;
        }
        <?php endif; ?>
        <?php if (file_exists($fontBoldPath)): ?>
        @font-face {
            font-family: 'Open Sans';
            src: url("file://<?= str_replace('\\', '/', $fontBoldPath) ?>") format('truetype');
            font-weight: bold;
        }
        <?php endif; ?>

        @page { size: A4; margin: 25mm 18mm 18mm 18mm; }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Open Sans', 'DejaVu Sans', sans-serif;
            font-size: 9pt;
            line-height: 1.4;
            color: #1a1a1a;
        }

        .bg-img { position: fixed; top: -25mm; left: -18mm; width: 210mm; height: 297mm; z-index: -1000; }

        /* ---- Header ---- */
        .report-header {
            background: #000;
            color: #fff;
            border-radius: 6px;
            padding: 10px 12px;
            margin-bottom: 14px;
        }
        .report-header table { width: 100%; border-collapse: collapse; }
        .report-header td { vertical-align: middle; }
        .header-logo-cell { width: 110px; }
        .header-logo { max-width: 98px; max-height: 32px; }
        .header-title-cell { padding-left: 10px; }
        .header-report-title {
            font-size: 15pt;
            font-weight: bold;
            color: #facc15;
            letter-spacing: 0.5px;
            line-height: 1;
        }
        .header-subtitle { font-size: 8pt; color: #d1d5db; margin-top: 3px; }
        .header-meta-cell { text-align: right; font-size: 7.5pt; color: #9ca3af; }
        .header-meta-cell .meta-date { color: #f3f4f6; font-weight: bold; }

        /* ---- Period banner ---- */
        .period-banner {
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid #dbeafe;
            border-radius: 6px;
            padding: 7px 12px;
            margin-bottom: 14px;
            font-size: 8.5pt;
            color: #1e40af;
        }
        .period-banner strong { color: #1d4ed8; }

        /* ---- Section headers ---- */
        .section-header {
            font-size: 11pt;
            font-weight: bold;
            padding: 8px 12px;
            background: #000;
            color: #ffffff;
            border-radius: 5px;
            margin-bottom: 12px;
            letter-spacing: 0.5px;
        }

        /* ---- Statistics boxes ---- */
        .stats-table { width: 100%; border-collapse: collapse; }
        .stats-table td { width: 33.33%; padding: 0 6px; vertical-align: top; }
        .stats-table td:first-child { padding-left: 0; }
        .stats-table td:last-child { padding-right: 0; }

        .stat-box {
            border-radius: 8px;
            padding: 10px 10px;
            text-align: center;
            border-width: 2px;
            border-style: solid;
        }
        .stat-box--red    { background: #fff1f2; border-color: #dc2626; }
        .stat-box--yellow { background: #fffbeb; border-color: #f59e0b; }
        .stat-box--total  { background: #eff6ff; border-color: #2563eb; }

        .stat-label {
            font-size: 7.5pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 5px;
        }
        .stat-box--red    .stat-label { color: #b91c1c; }
        .stat-box--yellow .stat-label { color: #92400e; }
        .stat-box--total  .stat-label { color: #1e40af; }

        .stat-count {
            font-size: 23pt;
            font-weight: bold;
            line-height: 1;
        }
        .stat-box--red    .stat-count { color: #dc2626; }
        .stat-box--yellow .stat-count { color: #d97706; }
        .stat-box--total  .stat-count { color: #2563eb; }

        /* ---- Worksite bars ---- */
        .worksite-table { width: 100%; border-collapse: collapse; }
        .worksite-table td { padding: 3px 0; vertical-align: middle; }
        .worksite-name {
            font-size: 8.5pt;
            color: #374151;
            font-weight: 600;
            width: 34%;
            padding-right: 8px;
        }
        .bar-track {
            width: 100%;
            border-collapse: collapse;
            background: #e5e7eb;
            border-radius: 4px;
        }
        .bar-fill {
            border-radius: 4px;
            padding: 2px 8px;
            color: #fff;
            font-weight: bold;
            font-size: 7.5pt;
        }

        /* ---- Category bar chart ---- */
        .cat-table { width: 100%; border-collapse: collapse; }
        .cat-table td { padding: 3px 0; vertical-align: middle; }
        .cat-name { font-size: 8.5pt; color: #374151; font-weight: 600; width: 46%; padding-right: 6px; }

        /* ---- Injury section two-column layout ---- */
        .injury-layout-table { width: 100%; border-collapse: collapse; }
        .injury-svg-cell { width: 48%; vertical-align: top; padding-right: 10px; }
        .injury-chart-cell { width: 52%; vertical-align: top; }

        /* ---- SVG body map ---- */
        .body-map-table { width: 100%; border-collapse: collapse; }
        .body-map-table td { width: 50%; vertical-align: top; text-align: center; padding: 0 4px; }
        .body-map-svg svg { width: 100%; height: auto; display: block; }
        .body-map-label { font-size: 7.5pt; color: #6b7280; text-align: center; margin-top: 4px; }

        /* ---- Legend ---- */
        .heatmap-legend { margin-top: 10px; font-size: 7.5pt; color: #6b7280; }
        .legend-bar { height: 8px; border-radius: 4px; margin-bottom: 3px; background: #f59e0b; }
        .legend-table { width: 100%; border-collapse: collapse; }
        .legend-table td:last-child { text-align: right; }

        /* ---- Recent injuries table ---- */
        .injuries-table { width: 100%; border-collapse: collapse; }
        .injuries-table th {
            background: #f3f4f6;
            font-size: 7.5pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #6b7280;
            padding: 6px 10px;
            text-align: left;
            border-bottom: 2px solid #e5e7eb;
        }
        .injuries-table td {
            font-size: 8.5pt;
            color: #374151;
            padding: 6px 10px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: top;
        }
        .injuries-table tr:nth-child(even) td { background: rgba(255, 255, 255, 0.65); }

        .type-pill {
            display: inline-table;
            border-radius: 3px;
            padding: 2px 6px;
            font-size: 7pt;
            font-weight: bold;
            color: #fff;
        }
        .type-pill--red    { background: #dc2626; }
        .type-pill--yellow { background: #d97706; }
        .type-pill--green  { background: #059669; }

        /* ---- Footer ---- */
        .footer {
            position: fixed;
            bottom: -18mm;
            left: -18mm;
            right: -18mm;
            height: 12mm;
            padding: 0 18mm;
            font-size: 8pt;
            color: #666;
        }
        .footer table { width: 100%; height: 100%; border-collapse: collapse; }
        .footer td { padding: 0; vertical-align: middle; }

        .section { margin-bottom: 18px; }
        .empty-note { font-size: 8.5pt; color: #9ca3af; font-style: italic; padding: 8px 0; }

        .page-break { page-break-before: always; }
    </style>
</head>
<body>

<?php if (!empty($bgBase64)): ?>
<img src="data:image/jpeg;base64,<?= $bgBase64 ?>" class="bg-img" alt="">
<?php endif; ?>

<!-- Fixed footer on every page -->
<div class="footer">
    <table>
        <tr>
            <td><?= htmlspecialchars(sf_term('dashboard_report_generated_at', $uiLang)) ?>: <?= htmlspecialchars($generatedAt) ?></td>
            <td style="text-align:right;"><?= htmlspecialchars(sf_term('dashboard_report_generated_by', $uiLang)) ?>: <?= htmlspecialchars($reportUserName) ?></td>
        </tr>
    </table>
</div>

<!-- Header -->
<div class="report-header">
    <table>
        <tr>
            <td class="header-logo-cell">
                <?php if ($logoDataUri): ?>
                    <img src="<?= htmlspecialchars($logoDataUri) ?>" class="header-logo" alt="Tapojärvi">
                <?php else: ?>
                    <span style="color:#f0b429; font-weight:bold; font-size:13pt;">Tapojärvi</span>
                <?php endif; ?>
            </td>
            <td class="header-title-cell">
                <div class="header-report-title"><?= htmlspecialchars($reportTitle) ?></div>
                <div class="header-subtitle">Dashboard-raportti</div>
            </td>
            <td class="header-meta-cell">
                <div><?= htmlspecialchars(sf_term('dashboard_report_generated_at', $uiLang)) ?></div>
                <div class="meta-date"><?= htmlspecialchars($generatedAt) ?></div>
            </td>
        </tr>
    </table>
</div>

<!-- Period banner -->
<div class="period-banner">
    <strong><?= htmlspecialchars(sf_term('dashboard_report_period_label', $uiLang)) ?>:</strong>
    <?= htmlspecialchars($periodLabel) ?>
    <?php if ($site !== ''): ?>
        &nbsp;·&nbsp;
        <strong><?= htmlspecialchars(sf_term('dashboard_report_site_filter', $uiLang)) ?>:</strong>
        <?= htmlspecialchars($site) ?>
    <?php endif; ?>
</div>

<!-- ===== STATISTICS ===== -->
<?php if ($includeStats): ?>
<div class="section">
    <div class="section-header"><?= htmlspecialchars(sf_term('dashboard_report_include_stats', $uiLang)) ?></div>
    <table class="stats-table">
        <tr>
            <td>
                <div class="stat-box stat-box--red">
                    <div class="stat-label"><?= htmlspecialchars(sf_term('dashboard_stat_red', $uiLang)) ?></div>
                    <div class="stat-count"><?= (int)($originalStats['red'] ?? 0) ?></div>
                </div>
            </td>
            <td>
                <div class="stat-box stat-box--yellow">
                    <div class="stat-label"><?= htmlspecialchars(sf_term('dashboard_stat_yellow', $uiLang)) ?></div>
                    <div class="stat-count"><?= (int)($originalStats['yellow'] ?? 0) ?></div>
                </div>
            </td>
            <td>
                <div class="stat-box stat-box--total">
                    <div class="stat-label"><?= htmlspecialchars(sf_term('dashboard_stat_total', $uiLang)) ?></div>
                    <div class="stat-count"><?= (int)($originalStats['total'] ?? 0) ?></div>
                </div>
            </td>
        </tr>
    </table>
</div>
<?php endif; ?>

<!-- ===== WORKSITES ===== -->
<?php if ($includeWorksites): ?>
<div class="section">
    <div class="section-header"><?= htmlspecialchars(sf_term('dashboard_report_include_worksites', $uiLang)) ?></div>
    <?php if (empty($worksiteStats)): ?>
        <p class="empty-note"><?= htmlspecialchars(sf_term('dashboard_no_data', $uiLang)) ?></p>
    <?php else: ?>
        <table class="worksite-table">
            <?php foreach ($worksiteStats as $idx => $ws):
                $wsName  = htmlspecialchars($ws['site'] ?? '');
                $wsCount = (int)($ws['count'] ?? 0);
                $barPct  = $maxWorksiteCount > 0 ? round(($wsCount / $maxWorksiteCount) * 100) : 0;
                $restPct = max(0, 100 - $barPct);
                $barColor = '#6b7280';
                if ($idx < 3) {
                    $barColor = '#2563eb';
                } elseif ($idx < 6) {
                    $barColor = '#7c3aed';
                }
            ?>
            <tr>
                <td class="worksite-name"><?= $wsName ?></td>
                <td>
                    <table class="bar-track">
                        <tr>
                            <td class="bar-fill" style="width:<?= $barPct ?>%; background:<?= $barColor ?>;"><?= $wsCount ?></td>
                            <td style="width:<?= $restPct ?>%;">&nbsp;</td>
                        </tr>
                    </table>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ===== INJURIES ===== -->
<?php if ($includeInjuries): ?>
<div class="section">
    <div class="section-header"><?= htmlspecialchars(sf_term('dashboard_report_include_injuries', $uiLang)) ?></div>
    <?php $hasInjuryData = !empty($bodyPartCounts) && array_sum(array_column($bodyPartCounts, 'count')) > 0; ?>
    <?php if (!$hasInjuryData): ?>
        <p class="empty-note"><?= htmlspecialchars(sf_term('dashboard_injury_empty', $uiLang)) ?></p>
    <?php else: ?>
    <table class="injury-layout-table">
        <tr>
            <!-- Body maps -->
            <td class="injury-svg-cell">
                <table class="body-map-table">
                    <tr>
                        <td>
                            <?php if ($coloredFrontSvg !== ''): ?>
                            <div class="body-map-svg"><?= $coloredFrontSvg ?></div>
                            <div class="body-map-label"><?= htmlspecialchars(sf_term('body_map_front_label', $uiLang)) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($coloredBackSvg !== ''): ?>
                            <div class="body-map-svg"><?= $coloredBackSvg ?></div>
                            <div class="body-map-label"><?= htmlspecialchars(sf_term('body_map_back_label', $uiLang)) ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <!-- Legend -->
                <div class="heatmap-legend">
                    <div class="legend-bar"></div>
                    <table class="legend-table"><tr><td>0</td><td><?= $maxBpCount ?></td></tr></table>
                </div>
            </td>
            <!-- Category bar chart -->
            <td class="injury-chart-cell">
                <p style="font-size:8.5pt; font-weight:bold; color:#374151; margin-bottom:10px;">
                    <?= htmlspecialchars(sf_term('dashboard_injury_chart_title', $uiLang)) ?>
                </p>
                <?php if (empty($sortedCategories)): ?>
                    <p class="empty-note"><?= htmlspecialchars(sf_term('dashboard_no_data', $uiLang)) ?></p>
                <?php else: ?>
                    <table class="cat-table">
                    <?php foreach ($sortedCategories as $cat):
                        $catPct = $maxCategoryCount > 0 ? round(($cat['count'] / $maxCategoryCount) * 100) : 0;
                        $catRestPct = max(0, 100 - $catPct);
                    ?>
                        <tr>
                            <td class="cat-name"><?= htmlspecialchars($cat['name']) ?></td>
                            <td>
                                <table class="bar-track">
                                    <tr>
                                        <td class="bar-fill" style="width:<?= $catPct ?>%; background:#059669;"><?= (int)$cat['count'] ?></td>
                                        <td style="width:<?= $catRestPct ?>%;">&nbsp;</td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </td>
        </tr>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ===== RECENT INJURIES ===== -->
<?php if ($includeRecent): ?>
<div class="section">
    <div class="section-header"><?= htmlspecialchars(sf_term('dashboard_report_include_recent', $uiLang)) ?></div>
    <?php if (empty($recentInjuryFlashes)): ?>
        <p class="empty-note"><?= htmlspecialchars(sf_term('dashboard_injury_empty', $uiLang)) ?></p>
    <?php else: ?>
    <table class="injuries-table">
        <thead>
            <tr>
                <th style="width:8%;"><?= htmlspecialchars(sf_term('dashboard_stat_red', $uiLang)) ?>/<?= htmlspecialchars(sf_term('dashboard_stat_yellow', $uiLang)) ?></th>
                <th style="width:42%;"><?= htmlspecialchars(sf_term('dashboard_by_worksite', $uiLang)) ?> / <?= htmlspecialchars(sf_term('dashboard_recent', $uiLang)) ?></th>
                <th style="width:25%;"><?= htmlspecialchars(sf_term('dashboard_report_site_filter', $uiLang)) ?></th>
                <th style="width:25%;"><?= htmlspecialchars(sf_term('dashboard_injury_chart_title', $uiLang)) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentInjuryFlashes as $flash):
                $flashType  = $flash['type'] ?? '';
                $flashTitle = $flash['title'] ?? '';
                $flashSite  = $flash['site'] ?? '';
                $flashDate  = '';
                if (!empty($flash['updated_at'])) {
                    try {
                        $flashDate = (new DateTime($flash['updated_at']))->format('d.m.Y');
                    } catch (Throwable $e) {}
                }
                $bpList = implode(', ', array_slice($flash['body_parts'] ?? [], 0, 3));
                if (count($flash['body_parts'] ?? []) > 3) $bpList .= '…';
                $typeLbl = $typeLabels[$flashType] ?? $flashType;
            ?>
            <tr>
                <td>
                    <span class="type-pill type-pill--<?= htmlspecialchars($flashType) ?>"><?= htmlspecialchars($typeLbl) ?></span>
                </td>
                <td>
                    <strong><?= htmlspecialchars($flashTitle) ?></strong>
                    <?php if ($flashDate): ?>
                        <br><span style="font-size:7.5pt; color:#9ca3af;"><?= htmlspecialchars($flashDate) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($flashSite) ?></td>
                <td style="font-size:7.5pt; color:#6b7280;"><?= htmlspecialchars($bpList) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>

</body>
</html>
