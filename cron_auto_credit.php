<?php
/**
 * cron_auto_credit.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Run this via a server cron job every hour (or every 5–15 minutes):
 *
 *   crontab -e
 *   */15 * * * * /usr/bin/php /var/www/html/cron_auto_credit.php >> /var/log/copy_trade_cron.log 2>&1
 *
 * What it does:
 *   1. Finds all ACTIVE copy trades whose duration has elapsed.
 *   2. Calculates the final payout (invested + profit).
 *   3. Credits the user's wallet balance.
 *   4. Marks the trade as 'completed'.
 *   5. Logs the credit in copy_trade_auto_credits.
 *   6. Marks the profit schedule row as applied.
 * ─────────────────────────────────────────────────────────────────────────────
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

// ── CLI-only guard (optional but recommended) ────────────────────────────────
// Uncomment if you only want this to run from the command line:
// if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
// ────────────────────────────────────────────────────────────────────────────

define('LOG_PREFIX', '[' . date('Y-m-d H:i:s') . '] ');
function logMsg(string $msg): void { echo LOG_PREFIX . $msg . PHP_EOL; }

include __DIR__ . '/db.php';

logMsg('=== Auto-Credit Cron Started ===');

// ── 1. Find matured trades ────────────────────────────────────────────────────
// A trade has matured when: NOW() >= created_at + duration_days
$maturedResult = $conn->query("
    SELECT
        ct.id              AS trade_id,
        ct.user_id,
        ct.invested_amount AS principal,
        ct.manual_profit,
        ct.trading_fee,
        ct.created_at,
        t.duration_days,
        t.monthly_return,
        t.display_name     AS trader_name,
        u.name             AS user_name,
        u.email            AS user_email,
        u.balance          AS user_balance
    FROM copy_trades ct
    JOIN copy_traders t ON ct.leader_id = t.id
    JOIN users        u ON ct.user_id   = u.id
    WHERE ct.status = 'active'
      AND ct.matured_at IS NULL
      AND DATE_ADD(ct.created_at, INTERVAL t.duration_days DAY) <= NOW()
    ORDER BY ct.created_at ASC
");

if (!$maturedResult) {
    logMsg('ERROR: Query failed — ' . $conn->error);
    exit(1);
}

$matured = $maturedResult->fetch_all(MYSQLI_ASSOC);
logMsg('Found ' . count($matured) . ' matured trade(s) to process.');

if (empty($matured)) {
    logMsg('Nothing to do. Exiting.');
    exit(0);
}

// ── 2. Process each trade ─────────────────────────────────────────────────────
$credited = 0;
$errors   = 0;

foreach ($matured as $trade) {
    $trade_id  = (int)$trade['trade_id'];
    $user_id   = (int)$trade['user_id'];
    $principal = (float)$trade['principal'];
    $duration  = (int)$trade['duration_days'];

    // ── Determine final profit ───────────────────────────────────────────────
    // Use admin-set manual_profit if available, else calculate from monthly rate
    if ($trade['manual_profit'] !== null) {
        $profit = (float)$trade['manual_profit'];
        $profit_source = 'manual';
    } else {
        // Pro-rate the monthly return across the full duration
        $profit = round($principal * ($trade['monthly_return'] / 100) * ($duration / 30), 2);
        $profit_source = 'auto';
    }

    $total_credit = round($principal + $profit, 2); // what the user receives

    logMsg("Processing Trade #$trade_id | User: {$trade['user_name']} ({$trade['user_email']}) | Principal: \$$principal | Profit [$profit_source]: \$$profit | Total: \$$total_credit");

    // ── Begin transaction ────────────────────────────────────────────────────
    $conn->begin_transaction();

    try {
        // a) Credit wallet
        $stmt = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
        $stmt->bind_param('di', $total_credit, $user_id);
        if (!$stmt->execute()) throw new Exception('Failed to credit wallet: ' . $stmt->error);
        $stmt->close();

        // b) Mark trade as completed + set matured_at
        $stmt = $conn->prepare("UPDATE copy_trades SET status = 'completed', matured_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $trade_id);
        if (!$stmt->execute()) throw new Exception('Failed to update trade status: ' . $stmt->error);
        $stmt->close();

        // c) Log the credit
        $note = 'Auto-credit on maturity — ' . $profit_source . ' profit';
        $stmt = $conn->prepare("
            INSERT INTO copy_trade_auto_credits
              (trade_id, user_id, credited_amount, profit_amount, principal, credited_at, note)
            VALUES (?, ?, ?, ?, ?, NOW(), ?)
        ");
        $stmt->bind_param('iiddds', $trade_id, $user_id, $total_credit, $profit, $principal, $note);
        if (!$stmt->execute()) throw new Exception('Failed to log credit: ' . $stmt->error);
        $stmt->close();

        // d) Mark profit schedule as applied (if exists)
        $conn->query("
            UPDATE copy_trade_profit_schedule
            SET applied = 1, applied_at = NOW()
            WHERE trade_id = $trade_id AND applied = 0
        ");

        $conn->commit();

        logMsg("  ✓ Success — Credited \$$total_credit to User #$user_id");
        $credited++;

    } catch (Exception $e) {
        $conn->rollback();
        logMsg("  ✗ ERROR on Trade #$trade_id — " . $e->getMessage());
        $errors++;
    }
}

// ── 3. Summary ────────────────────────────────────────────────────────────────
logMsg('=== Auto-Credit Cron Finished ===');
logMsg("Credited: $credited | Errors: $errors");
exit($errors > 0 ? 1 : 0);