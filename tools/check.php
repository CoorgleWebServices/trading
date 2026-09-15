<?php
/* ---------------------------------------------------------------------------
 * Deploy check - a TEMPORARY diagnostic, not part of the running bot.
 *
 * It lives in tools/ rather than the project root on purpose: the root is what
 * you upload to public_html, and a file that enumerates your installation
 * should not sit in a public folder longer than it takes to read it.
 *
 * Use:  copy next to index.php, open it in a browser, read the tables, then
 *       DELETE it from the server. The panel also has this built in under
 *       Diagnostics, which is behind your login - prefer that when the panel
 *       loads at all. This standalone copy is for when it does not.
 *
 * Reports presence and absence only. It never prints API keys, the panel
 * password, balances, or anything out of data/.
 * ------------------------------------------------------------------------- */
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
$root = __DIR__;

function mark(bool $ok): string { return $ok ? '<b style="color:#116b34">YES</b>' : '<b style="color:#b3261e">NO</b>'; }
function has(string $f, string $needle): bool {
    $p = __DIR__ . '/' . $f;
    return is_file($p) && strpos((string)@file_get_contents($p), $needle) !== false;
}

$files = [
  'index.php' => 'panel', 'cron.php' => 'cron', 'config.php' => 'config', 'bootstrap.php' => 'bootstrap',
  'lib/Util.php'=>'', 'lib/Db.php'=>'', 'lib/Log.php'=>'', 'lib/Binance.php'=>'', 'lib/Indicators.php'=>'',
  'lib/Strategy.php'=>'', 'lib/Risk.php'=>'', 'lib/Exchange.php'=>'', 'lib/Bot.php'=>'', 'lib/Panel.php'=>'',
  'lib/EngineOrders.php'=>'engines', 'lib/EngineGrid.php'=>'engines', 'lib/EnginePmm.php'=>'engines',
  'lib/Sleeve.php'=>'portfolio', 'lib/Scanner.php'=>'portfolio', 'lib/Learn.php'=>'learning',
  'assets/panel.css'=>'', 'assets/panel.js'=>'',
];
/* A feature counts as deployed only when every file it needs exists AND the marker is there,
   so a half-updated folder cannot report a feature that would fatal at runtime. */
$feat = [
  'Demo mode (demo-api.binance.com)' => [['lib/Binance.php'], 'lib/Binance.php', 'demo-api.binance.com'],
  'Engine selector in Settings'      => [['index.php','lib/EngineOrders.php'], 'index.php', 'data-engine-select'],
  'Grid engine'                      => [['lib/EngineGrid.php','lib/EngineOrders.php','lib/Bot.php'], 'lib/Bot.php', 'EngineGrid'],
  'Market making (pmm)'              => [['lib/EnginePmm.php','lib/EngineOrders.php','lib/Bot.php'], 'lib/Bot.php', 'EnginePmm'],
  'Portfolio sleeves'                => [['lib/Sleeve.php','index.php'], 'index.php', 'portfolio_enabled'],
  'Volatility scanner'               => [['lib/Scanner.php','lib/Bot.php'], 'lib/Bot.php', 'Scanner'],
  'Insights / learning page'         => [['lib/Learn.php','index.php'], 'index.php', 'insights'],
  'BNB fee discount'                 => [['lib/Binance.php'], 'lib/Binance.php', 'bnbBurn'],
  'Asset cache-busting (mtime)'      => [['index.php'], 'index.php', 'panel_asset_version'],
];
$missing = [];
echo '<meta name=viewport content="width=device-width,initial-scale=1">';
echo '<style>body{font:15px/1.5 system-ui,sans-serif;max-width:760px;margin:24px auto;padding:0 16px}';
echo 'table{border-collapse:collapse;width:100%;margin:10px 0 22px}td,th{border-bottom:1px solid #ddd;padding:6px 8px;text-align:left}';
echo 'code{background:#f3f3f3;padding:1px 5px;border-radius:4px}</style>';
echo '<h1>Micro-Trader deploy check</h1>';
echo '<p>Folder: <code>' . htmlspecialchars($root, ENT_QUOTES) . '</code><br>PHP ' . htmlspecialchars(PHP_VERSION, ENT_QUOTES) . '</p>';

echo '<h2>Files</h2><table><tr><th>File</th><th>Present</th><th>Part of</th></tr>';
foreach ($files as $f => $grp) {
    $ok = is_file($root . '/' . $f);
    if (!$ok) { $missing[] = $f; }
    echo '<tr><td><code>' . htmlspecialchars($f, ENT_QUOTES) . '</code></td><td>' . mark($ok) . '</td><td>' . htmlspecialchars($grp, ENT_QUOTES) . '</td></tr>';
}
echo '</table>';

echo '<h2>Features actually in your files</h2><table><tr><th>Feature</th><th>Deployed</th><th>Why not</th></tr>';
foreach ($feat as $label => $spec) {
    $need = $spec[0]; $lack = [];
    foreach ($need as $nf) { if (!is_file($root . '/' . $nf)) { $lack[] = $nf; } }
    $ok = !$lack && has($spec[1], $spec[2]);
    $why = $lack ? 'missing ' . implode(', ', $lack) : ($ok ? '' : 'file present but out of date \u{2014} re-upload');
    echo '<tr><td>' . htmlspecialchars($label, ENT_QUOTES) . '</td><td>' . mark($ok) .
         '</td><td style="color:#8a6d00">' . htmlspecialchars($why, ENT_QUOTES) . '</td></tr>';
}
echo '</table>';

echo '<h2>Runtime</h2><table>';
foreach (['curl','pdo_sqlite','json','openssl'] as $x) {
    echo '<tr><td>ext ' . $x . '</td><td>' . mark(extension_loaded($x)) . '</td></tr>';
}
echo '<tr><td><code>data/</code> exists</td><td>' . mark(is_dir($root . '/data')) . '</td></tr>';
echo '<tr><td><code>data/</code> writable</td><td>' . mark(is_writable($root . '/data')) . '</td></tr>';
echo '<tr><td>config saved (setup done)</td><td>' . mark(is_file($root . '/data/config.json')) . '</td></tr>';
echo '<tr><td>database created</td><td>' . mark(is_file($root . '/data/trader.sqlite')) . '</td></tr>';
echo '</table>';

echo $missing
  ? '<p style="padding:12px;border:1px solid #b3261e;border-radius:8px"><b>' . count($missing) . ' file(s) missing.</b> Re-upload the zip to this folder and Extract, overwriting. Missing: <code>' . htmlspecialchars(implode(', ', $missing), ENT_QUOTES) . '</code></p>'
  : '<p style="padding:12px;border:1px solid #116b34;border-radius:8px"><b>All files present.</b> If an option still does not show, hard refresh the page with Ctrl+Shift+R (Cmd+Shift+R on Mac).</p>';

echo '<p style="color:#b3261e"><b>Delete this file when you are done.</b></p>';
