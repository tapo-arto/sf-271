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

        @page { size: A4; margin: 20mm 16mm 20mm 16mm; }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Open Sans', 'DejaVu Sans', sans-serif;
            font-size: 9.5pt;
            line-height: 1.4;
            color: #1a1a1a;
        }

        /* ---- Header ---- */
        .report-header {
            background: #1a1a1a;
            color: #fff;
            padding: 14px 18px;
            margin-bottom: 18px;
        }
        .report-header table { width: 100%; border-collapse: collapse; }
        .report-header td { vertical-align: middle; }
        .header-logo-cell { width: 130px; }
        .header-logo { max-width: 120px; max-height: 40px; }
        .header-title-cell { padding-left: 16px; }
        .header-report-title {
            font-size: 18pt;
            font-weight: bold;
            color: #f0b429;
            letter-spacing: 0.5px;
            line-height: 1;
        }
        .header-subtitle {
            font-size: 8.5pt;
            color: #d1d5db;
            margin-top: 4px;
        }
        .header-meta-cell { text-align: right; font-size: 8pt; color: #9ca3af; }
        .header-meta-cell .meta-date { color: #d1d5db; font-weight: bold; }

        /* ---- Period banner ---- */
        .period-banner {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 6px;
            padding: 8px 14px;
            margin-bottom: 16px;
            font-size: 8.5pt;
            color: #1e40af;
        }
        .period-banner strong { color: #1d4ed8; }

        /* ---- Section headers ---- */
        .section-header {
            background: #1d4ed8;
            color: #fff;
            padding: 7px 14px;
            border-radius: 5px;
            font-size: 10pt;
            font-weight: bold;
            margin-bottom: 12px;
            letter-spacing: 0.3px;
        }

        /* ---- Statistics boxes ---- */
        .stats-table { width: 100%; border-collapse: collapse; }
        .stats-table td { width: 33.33%; padding: 0 6px; vertical-align: top; }
        .stats-table td:first-child { padding-left: 0; }
        .stats-table td:last-child { padding-right: 0; }

        .stat-box {
            border-radius: 8px;
            padding: 14px 16px;
            text-align: center;
        }
        .stat-box--red    { background: #fef2f2; border: 1.5px solid #fecaca; }
        .stat-box--yellow { background: #fffbeb; border: 1.5px solid #fde68a; }
        .stat-box--total  { background: #eff6ff; border: 1.5px solid #bfdbfe; }

        .stat-label {
            font-size: 8pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 6px;
        }
        .stat-box--red    .stat-label { color: #b91c1c; }
        .stat-box--yellow .stat-label { color: #92400e; }
        .stat-box--total  .stat-label { color: #1e40af; }

        .stat-count {
            font-size: 28pt;
            font-weight: bold;
            line-height: 1;
        }
        .stat-box--red    .stat-count { color: #dc2626; }
        .stat-box--yellow .stat-count { color: #d97706; }
        .stat-box--total  .stat-count { color: #2563eb; }

        /* ---- Worksite bars ---- */
        .worksite-row { margin-bottom: 7px; }
        .worksite-name {
            font-size: 8.5pt;
            color: #374151;
            margin-bottom: 3px;
            font-weight: 600;
        }
        .worksite-bar-wrap { background: #f3f4f6; border-radius: 4px; height: 18px; position: relative; width: 100%; }
        .worksite-bar {
            background: linear-gradient(90deg, #2563eb, #3b82f6);
            border-radius: 4px;
            height: 18px;
            display: block;
        }
        .worksite-count {
            position: absolute;
            right: 0;
            top: 0;
            height: 18px;
            line-height: 18px;
            font-size: 7.5pt;
            font-weight: bold;
            color: #374151;
            padding-right: 6px;
        }

        /* ---- Category bar chart ---- */
        .cat-row { margin-bottom: 8px; }
        .cat-name { font-size: 8.5pt; color: #374151; margin-bottom: 3px; font-weight: 600; }
        .cat-bar-wrap { background: #f3f4f6; border-radius: 4px; height: 18px; position: relative; }
        .cat-bar { background: linear-gradient(90deg, #059669, #10b981); border-radius: 4px; height: 18px; }
        .cat-count {
            position: absolute;
            right: 0;
            top: 0;
            height: 18px;
            line-height: 18px;
            font-size: 7.5pt;
            font-weight: bold;
            color: #374151;
            padding-right: 6px;
        }

        /* ---- Injury section two-column layout ---- */
        .injury-layout-table { width: 100%; border-collapse: collapse; }
        .injury-svg-cell { width: 45%; vertical-align: top; padding-right: 16px; }
        .injury-chart-cell { width: 55%; vertical-align: top; }

        /* ---- SVG body map ---- */
        .body-map-figures { display: block; text-align: center; }
        .body-map-svg { width: 42%; display: inline-block; vertical-align: top; }
        .body-map-label { font-size: 7.5pt; color: #6b7280; text-align: center; margin-top: 4px; }

        /* ---- Legend ---- */
        .heatmap-legend { margin-top: 10px; font-size: 7.5pt; color: #6b7280; }
        .legend-bar {
            height: 8px;
            border-radius: 4px;
            margin-bottom: 3px;
            background: linear-gradient(90deg, #e5e7eb, #fef3c7, #f59e0b, #dc2626);
        }
        .legend-labels { display: flex; justify-content: space-between; }

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
        .injuries-table tr:nth-child(even) td { background: #fafafa; }

        .type-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            vertical-align: middle;
            margin-right: 4px;
        }
        .type-dot--red    { background: #ef4444; }
        .type-dot--yellow { background: #f59e0b; }
        .type-dot--green  { background: #10b981; }

        /* ---- Footer ---- */
        .report-footer {
            position: fixed;
            bottom: -16mm;
            left: -14mm;
            right: -14mm;
            background: #f9fafb;
            border-top: 1px solid #e5e7eb;
            padding: 5px 16mm;
            font-size: 7.5pt;
            color: #9ca3af;
        }
        .report-footer table { width: 100%; border-collapse: collapse; }
        .report-footer td { padding: 0; }

        .section { margin-bottom: 22px; }
        .empty-note { font-size: 8.5pt; color: #9ca3af; font-style: italic; padding: 8px 0; }

        .page-break { page-break-before: always; }
    </style>
</head>
<body>

<!-- Fixed footer on every page -->
<div class="report-footer">
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
                <div class="header-subtitle">SafetyFlash</div>
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
        <?php foreach ($worksiteStats as $ws):
            $wsName  = htmlspecialchars($ws['site'] ?? '');
            $wsCount = (int)($ws['count'] ?? 0);
            $barPct  = $maxWorksiteCount > 0 ? round(($wsCount / $maxWorksiteCount) * 100) : 0;
        ?>
        <div class="worksite-row">
            <div class="worksite-name"><?= $wsName ?></div>
            <div class="worksite-bar-wrap">
                <div class="worksite-bar" style="width:<?= $barPct ?>%;"></div>
                <span class="worksite-count"><?= $wsCount ?></span>
            </div>
        </div>
        <?php endforeach; ?>
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
                <div class="body-map-figures">
                    <?php if ($coloredFrontSvg !== ''): ?>
                    <div class="body-map-svg">
                        <svg viewBox="0 0 261.58 620.34" xmlns="http://www.w3.org/2000/svg" width="100%">
                            <?= $coloredFrontSvg ?>
                        </svg>
                        <div class="body-map-label"><?= htmlspecialchars(sf_term('body_map_front_label', $uiLang)) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($coloredBackSvg !== ''): ?>
                    <div class="body-map-svg" style="margin-left:4%;">
                        <svg viewBox="0 0 261.58 597.52" xmlns="http://www.w3.org/2000/svg" width="100%">
                            <?= $coloredBackSvg ?>
                        </svg>
                        <div class="body-map-label"><?= htmlspecialchars(sf_term('body_map_back_label', $uiLang)) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <!-- Legend -->
                <div class="heatmap-legend">
                    <div class="legend-bar"></div>
                    <div class="legend-labels">
                        <span>0</span>
                        <span><?= $maxBpCount ?></span>
                    </div>
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
                    <?php foreach ($sortedCategories as $cat):
                        $catPct = $maxCategoryCount > 0 ? round(($cat['count'] / $maxCategoryCount) * 100) : 0;
                    ?>
                    <div class="cat-row">
                        <div class="cat-name"><?= htmlspecialchars($cat['name']) ?></div>
                        <div class="cat-bar-wrap">
                            <div class="cat-bar" style="width:<?= $catPct ?>%;"></div>
                            <span class="cat-count"><?= (int)$cat['count'] ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
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
                    <span class="type-dot type-dot--<?= htmlspecialchars($flashType) ?>"></span>
                    <?= htmlspecialchars($typeLbl) ?>
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
