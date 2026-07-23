<?php
ob_start();
ini_set('display_errors', 0);
if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . '/db.php';
header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id'])) throw new Exception('Session expired.');

    $user_id = (int)$_SESSION['user_id'];
    $input   = json_decode(file_get_contents('php://input'), true);
    $trade_id = (int)($input['trade_id'] ?? 0);
    
    // 1. Get requested amount and round to 2 decimals
    $requestedAmount = round(floatval($input['amount'] ?? 0), 2);

    if (!$trade_id || $requestedAmount <= 0) {
        throw new Exception('Invalid withdrawal amount.');
    }

    // 2. Fetch trade data
    $stmt = $conn->prepare("SELECT id, invested_amount, manual_profit FROM copy_trades WHERE id = ? AND user_id = ? AND status = 'active'");
    $stmt->bind_param("ii", $trade_id, $user_id);
    $stmt->execute();
    $trade = $stmt->get_result()->fetch_assoc();

    if (!$trade) throw new Exception('Active trade not found.');

    $invested = round((float)$trade['invested_amount'], 2);
    $m_profit = round((float)($trade['manual_profit'] ?? 0), 2);
    $totalAvailable = round($invested + $m_profit, 2);

    // 3. THE SMART CHECK: If the request is slightly over (rounding error) or exactly the total
    if ($requestedAmount > $totalAvailable) {
        // If it's just a tiny rounding difference (less than 1 cent), allow it as a full withdrawal
        if (($requestedAmount - $totalAvailable) < 0.01) {
            $requestedAmount = $totalAvailable;
        } else {
            throw new Exception("Limit exceeded. Total available: $" . number_format($totalAvailable, 2));
        }
    }

    $conn->begin_transaction();

    // 4. Update User Balance
    $updWallet = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
    $updWallet->bind_param("di", $requestedAmount, $user_id);
    $updWallet->execute();

    // 5. Calculate Deductions (Drain Profit first, then Principal)
    $remaining = $requestedAmount;
    
    if ($remaining <= $m_profit) {
        // Only taking from profit
        $newProfit = $m_profit - $remaining;
        $newInvested = $invested;
    } else {
        // Taking all profit and some/all of principal
        $remaining -= $m_profit;
        $newProfit = 0;
        $newInvested = $invested - $remaining;
    }

    // 6. Update or Stop the Trade
    if ($newInvested <= 0.01) {
        // Full withdrawal - STOP TRADE
        $updTrade = $conn->prepare("UPDATE copy_trades SET invested_amount = 0, manual_profit = 0, status = 'stopped', stopped_at = NOW() WHERE id = ?");
        $updTrade->bind_param("i", $trade_id);
    } else {
        // Partial withdrawal
        $updTrade = $conn->prepare("UPDATE copy_trades SET invested_amount = ?, manual_profit = ? WHERE id = ?");
        $updTrade->bind_param("ddi", $newInvested, $newProfit, $trade_id);
    }
    
    $updTrade->execute();
    $conn->commit();

    ob_clean();
    echo json_encode([
        'success' => true, 
        'message' => 'Successfully withdrawn $' . number_format($requestedAmount, 2) . '. Funds added to wallet.'
    ]);

} catch (Exception $e) {
    if (isset($conn)) $conn->rollback();
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}