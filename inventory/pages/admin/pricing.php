<?php
session_start();
require_once __DIR__ . '/../../inc/db.php';
require_once __DIR__ . '/../../inc/auth.php';
require_once __DIR__ . '/../../inc/layout.php';

require_login();

$db  = db();
$msg = '';
$parsed = [];
$parse_error = '';

// ── Parse PDF helper ───────────────────────────────────────────────────────────
function parse_cost_sheet(string $text): array {
    // Line format: SKU  COO  FACTORY#  THICK  SIZE_IN  SIZE_METRIC  MOQ  SQ/M  LOG  ROLL  LAND  NET  GP%
    // token[10] = LAND 1" cost, token[11] = NET sell price
    $results = [];
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if (!preg_match('/^([A-Z0-9]+)\s+[A-Z]{2}\s+\S+\s+[\d.]+mm/', $line, $m)) continue;
        $tokens = preg_split('/\s+/', $line);
        if (count($tokens) < 12) continue;
        $sku  = $tokens[0];
        $land = (float)$tokens[10];
        $net  = (float)$tokens[11];
        if ($land <= 0 || $net <= 0) continue;
        $results[$sku] = [
            'land'   => $land,
            'markup' => round($net / $land, 4),
        ];
    }
    return $results;
}

// ── Map PDF SKUs → DB base_sku (handle any known aliases) ─────────────────────
$sku_alias = [
    '928S' => '962S',
];

// ── POST: apply confirmed prices ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_prices'])) {
    $stmt = $db->prepare('UPDATE products SET land_cost_base=?, markup_multiplier=? WHERE base_sku=?');
    foreach ($_POST['land_cost'] as $base_sku => $land) {
        $land   = (float)$land;
        $markup = (float)($_POST['markup'][$base_sku] ?? 0);
        if ($land > 0 && $markup > 0) $stmt->execute([$land, $markup, $base_sku]);
    }
    $msg = 'Prices updated successfully.';
}

// ── POST: upload + parse PDF ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['cost_sheet'])) {
    $tmp = $_FILES['cost_sheet']['tmp_name'] ?? '';
    $ext = strtolower(pathinfo($_FILES['cost_sheet']['name'], PATHINFO_EXTENSION));

    if ($ext !== 'pdf' || empty($tmp)) {
        $parse_error = 'Please upload a PDF file.';
    } else {
        $text = shell_exec('pdftotext -layout ' . escapeshellarg($tmp) . ' -');
        if ($text === null || trim($text) === '') {
            $parse_error = 'Could not extract text from PDF. Ensure pdftotext (poppler-utils) is installed on the server.';
        } else {
            $raw = parse_cost_sheet($text);
            // Load current products for comparison
            $products = $db->query('SELECT base_sku, land_cost_base, markup_multiplier FROM products')->fetchAll(PDO::FETCH_UNIQUE);
            foreach ($raw as $pdf_sku => $vals) {
                $db_sku = $sku_alias[$pdf_sku] ?? $pdf_sku;
                if (!array_key_exists($db_sku, $products)) continue;
                $parsed[] = [
                    'pdf_sku'        => $pdf_sku,
                    'base_sku'       => $db_sku,
                    'cur_land'       => (float)$products[$db_sku]['land_cost_base'],
                    'cur_markup'     => (float)$products[$db_sku]['markup_multiplier'],
                    'new_land'       => $vals['land'],
                    'new_markup'     => $vals['markup'],
                    'land_changed'   => abs($vals['land']   - (float)$products[$db_sku]['land_cost_base'])       > 0.0001,
                    'markup_changed' => abs($vals['markup'] - (float)$products[$db_sku]['markup_multiplier']) > 0.0001,
                ];
            }
            if (empty($parsed)) {
                $parse_error = 'No matching SKUs found in the PDF.';
            }
        }
    }
}

// ── Load all products for manual table ────────────────────────────────────────
$products = $db->query(
    'SELECT p.*, c.name AS cat_name
     FROM products p JOIN categories c ON c.id = p.category_id
     ORDER BY c.name, p.base_sku'
)->fetchAll();

render_header('Admin — Bulk Pricing', 'admin');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Bulk Pricing Update</h4>
    <a href="/inventory/pages/admin/index.php" class="btn btn-sm btn-outline-secondary">Admin Menu</a>
</div>

<?php if ($msg): ?>
<div class="alert alert-success alert-dismissible fade show"><?= h($msg) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<!-- PDF Upload -->
<div class="card mb-4">
<div class="card-header"><strong>Upload Cost Sheet PDF</strong></div>
<div class="card-body">
<?php if ($parse_error): ?>
<div class="alert alert-danger"><?= h($parse_error) ?></div>
<?php endif; ?>
<form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-end">
    <div>
        <label class="form-label mb-1">Cost Information Sheet (.pdf)</label>
        <input type="file" name="cost_sheet" class="form-control" accept=".pdf" required>
    </div>
    <button type="submit" class="btn btn-primary">Parse PDF</button>
</form>
</div>
</div>

<?php if (!empty($parsed)): ?>
<!-- Parsed results preview -->
<div class="card mb-4">
<div class="card-header"><strong>Parsed Prices — Review &amp; Confirm</strong></div>
<div class="card-body p-0">
<form method="post">
<table class="table table-sm mb-0 align-middle">
<thead class="table-light">
<tr><th>SKU</th><th>Land Cost</th><th>New Land</th><th>Markup</th><th>New Markup</th><th>New Sell (1")</th></tr>
</thead>
<tbody>
<?php foreach ($parsed as $row):
    $changed = $row['land_changed'] || $row['markup_changed'];
?>
<tr class="<?= $changed ? 'table-warning' : '' ?>">
    <td class="fw-semibold">
        <?= h($row['base_sku']) ?>
        <?php if ($row['pdf_sku'] !== $row['base_sku']): ?>
        <span class="text-muted small">(PDF: <?= h($row['pdf_sku']) ?>)</span>
        <?php endif; ?>
    </td>
    <td class="text-muted"><?= currency($row['cur_land']) ?></td>
    <td>
        <input type="number" name="land_cost[<?= h($row['base_sku']) ?>]"
               value="<?= number_format($row['new_land'], 4) ?>"
               class="form-control form-control-sm" style="width:110px" step="0.0001" min="0">
    </td>
    <td class="text-muted"><?= number_format($row['cur_markup'], 4) ?></td>
    <td>
        <input type="number" name="markup[<?= h($row['base_sku']) ?>]"
               value="<?= number_format($row['new_markup'], 4) ?>"
               class="form-control form-control-sm" style="width:100px" step="0.0001" min="0">
    </td>
    <td><?= currency(round($row['new_land'] * $row['new_markup'], 2)) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<div class="p-3">
    <button type="submit" name="apply_prices" value="1" class="btn btn-success">Apply Changes</button>
</div>
</form>
</div>
</div>
<?php endif; ?>

<!-- Manual edit table -->
<div class="card">
<div class="card-header"><strong>Current Prices (Manual Edit)</strong></div>
<div class="card-body p-0">
<form method="post">
<table class="table table-bordered table-sm align-middle mb-0">
<thead class="table-light">
<tr><th>SKU</th><th>Product</th><th>Category</th><th>1&quot; Land Cost ($)</th><th>Markup</th><th>Sell (1&quot;)</th></tr>
</thead>
<tbody>
<?php foreach ($products as $p):
    $sell = round((float)$p['land_cost_base'] * (float)$p['markup_multiplier'], 2);
?>
<tr>
    <td class="fw-semibold"><?= h($p['base_sku']) ?></td>
    <td><?= h($p['name']) ?></td>
    <td><span class="badge bg-secondary"><?= h($p['cat_name']) ?></span></td>
    <td>
        <input type="number" name="land_cost[<?= h($p['base_sku']) ?>]"
               value="<?= number_format((float)$p['land_cost_base'], 4) ?>"
               class="form-control form-control-sm land-cost"
               step="0.0001" min="0" required data-sku="<?= h($p['base_sku']) ?>">
    </td>
    <td>
        <input type="number" name="markup[<?= h($p['base_sku']) ?>]"
               value="<?= number_format((float)$p['markup_multiplier'], 4) ?>"
               class="form-control form-control-sm markup"
               step="0.0001" min="1" required data-sku="<?= h($p['base_sku']) ?>">
    </td>
    <td class="sell-preview" id="sell-<?= h($p['base_sku']) ?>"><?= currency($sell) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<div class="p-3">
    <button type="submit" name="apply_prices" value="1" class="btn btn-primary">Save All</button>
</div>
</form>
</div>
</div>

<script>
document.querySelectorAll('.land-cost, .markup').forEach(function(el) {
    el.dataset.original = el.value;
    el.addEventListener('input', function() {
        var sku    = this.dataset.sku;
        var row    = this.closest('tr');
        var land   = parseFloat(row.querySelector('.land-cost').value) || 0;
        var markup = parseFloat(row.querySelector('.markup').value)    || 0;
        document.getElementById('sell-' + sku).textContent = '$' + (land * markup).toFixed(2);
        // Highlight row if any field differs from original
        var dirty = Array.from(row.querySelectorAll('.land-cost, .markup'))
            .some(function(i) { return parseFloat(i.value) !== parseFloat(i.dataset.original); });
        row.classList.toggle('table-warning', dirty);
    });
});
</script>

<?php render_footer(); ?>
