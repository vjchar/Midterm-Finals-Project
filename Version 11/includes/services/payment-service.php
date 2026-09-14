<?php

declare(strict_types=1);

/**
 * @return list<array<string, mixed>>
 */
function payments_for_booking(int $bookingId): array
{
    $statement = database()->prepare(
        'SELECT p.*, u.name AS customer_name, recorder.name AS recorder_name FROM payments p
         JOIN users u ON u.id = p.user_id LEFT JOIN users recorder ON recorder.id = p.recorded_by
         WHERE p.booking_id = ? ORDER BY p.created_at DESC',
    );
    $statement->execute([$bookingId]);
    return $statement->fetchAll();
}

/**
 * Summarize paid and outstanding amounts for one booking.
 *
 * @param array{id: int|string, deposit: int|string, total: int|string} $booking
 * @return array{
 *     payments: list<array<string, mixed>>,
 *     deposit_due: int,
 *     rental_due: int,
 *     extra_due: int,
 *     extension_due: int,
 *     paid_total: int
 * }
 */
function booking_payment_summary(array $booking): array
{
    $payments = payments_for_booking((int) $booking["id"]);
    $paidDeposit = 0;
    $paidRental = 0;
    $paidExtra = 0;
    $paidExtension = 0;
    $extraDue = 0;
    foreach ($payments as $payment) {
        $amount = (int) $payment["amount"];
        if ($payment["payment_type"] === "extra_charge") {
            $extraDue += $amount;
        }
        if ($payment["status"] !== "paid") {
            continue;
        }
        if ($payment["payment_type"] === "deposit") {
            $paidDeposit += $amount;
        } elseif ($payment["payment_type"] === "balance") {
            $paidRental += $amount;
        } elseif ($payment["payment_type"] === "extra_charge") {
            $paidExtra += $amount;
        } elseif ($payment["payment_type"] === "extension") {
            $paidExtension += $amount;
        }
    }
    $extensionStatement = database()->prepare(
        "SELECT r.id, r.price_difference,
                COALESCE(SUM(CASE WHEN p.status = 'paid' THEN p.amount ELSE 0 END),0) AS paid_amount
         FROM rental_adjustment_requests r
         LEFT JOIN payments p ON p.rental_adjustment_id = r.id AND p.payment_type = 'extension'
         WHERE r.booking_id = ? AND r.request_type = 'extension' AND r.status = 'approved'
         GROUP BY r.id, r.price_difference"
    );
    $extensionStatement->execute([(int) $booking["id"]]);
    $extensionDueTotal = 0;
    foreach ($extensionStatement->fetchAll() as $extensionRow) {
        $extensionDueTotal += max(0, (int) $extensionRow["price_difference"] - (int) $extensionRow["paid_amount"]);
    }
    return [
        "payments" => $payments,
        "deposit_due" => max(0, (int) $booking["deposit"] - $paidDeposit),
        "rental_due" => max(0, (int) $booking["total"] - $paidRental - $paidExtension),
        "extra_due" => max(0, $extraDue - $paidExtra),
        "extension_due" => max(0, $extensionDueTotal - $paidExtension),
        "paid_total" => $paidDeposit + $paidRental + $paidExtra + $paidExtension,
    ];
}

/**
 * Create a customer-submitted deposit or balance payment request.
 *
 * @param array{id: int|string, user_id: int|string, reference: string, deposit: int|string, total: int|string} $booking
 */
function create_payment_request(
    array $booking,
    int $userId,
    string $type,
    string $method,
    int $amount,
    string $reference,
    string $proofFilename = "",
    ?int $rentalAdjustmentId = null,
): int {
    if ((int) $booking["user_id"] !== $userId) {
        throw new RuntimeException(
            "This booking does not belong to your account.",
        );
    }
    $validType = in_array($type, ["deposit", "balance", "extension"], true);
    $validMethod = in_array($method, ["gcash", "bank_transfer", "cash"], true);
    if (!$validType || !$validMethod) {
        throw new InvalidArgumentException(
            "Choose a valid payment type and method.",
        );
    }
    $summary = booking_payment_summary($booking);
    $maximum = match ($type) {
        "deposit" => $summary["deposit_due"],
        "balance" => $summary["rental_due"],
        "extension" => $summary["extension_due"],
    };
    if ($type !== "extension") {
        $rentalAdjustmentId = null;
    }
    if ($type === "extension") {
        if (!$rentalAdjustmentId) {
            throw new InvalidArgumentException("Choose an approved extension request.");
        }
        $adjustment = rental_adjustment_find($rentalAdjustmentId);
        if (!$adjustment || (int) $adjustment["booking_id"] !== (int) $booking["id"] ||
            (int) $adjustment["user_id"] !== $userId || $adjustment["request_type"] !== "extension" ||
            $adjustment["status"] !== "approved") {
            throw new RuntimeException("That extension is not awaiting payment.");
        }
        $paid = database()->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE rental_adjustment_id=? AND payment_type='extension' AND status IN ('pending','paid')");
        $paid->execute([$rentalAdjustmentId]);
        $maximum = max(0, (int) $adjustment["price_difference"] - (int) $paid->fetchColumn());
        if ($amount !== $maximum || $maximum < 1) {
            throw new InvalidArgumentException("Submit the exact outstanding extension amount.");
        }
    } elseif ($amount < 1 || $amount > $maximum) {
        throw new InvalidArgumentException(
            "Enter an amount no greater than the current amount due.",
        );
    }
    $hasReference = mb_strlen(trim($reference)) >= 4;
    if ($method !== "cash" && !$hasReference && $proofFilename === "") {
        throw new InvalidArgumentException(
            "Provide a transaction reference or payment proof.",
        );
    }
    $currentTimestamp = date("Y-m-d H:i:s");
    $statement = database()->prepare(
        "INSERT INTO payments (booking_id, rental_adjustment_id, user_id, payment_type, method, amount, transaction_reference, proof_filename, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)",
    );
    $statement->execute([
        (int) $booking["id"],
        $rentalAdjustmentId,
        $userId,
        $type,
        $method,
        $amount,
        mb_substr(trim($reference), 0, 120),
        $proofFilename,
        $currentTimestamp,
        $currentTimestamp,
    ]);
    $paymentId = (int) database()->lastInsertId();
    if ($type === "extension") {
        $customerPaymentTitle = "Extension Payment Submitted";
        $customerPaymentMessage = "Your extension payment for booking " . $booking["reference"] . " is awaiting verification.";
    } else {
        $nextJourney = booking_next_step($booking);
        if ($nextJourney["stage"] === "documents") {
            $customerPaymentTitle = "Payment Submitted — Upload Documents";
            $customerPaymentMessage = "Your payment for booking " . $booking["reference"] . " was submitted and is awaiting verification. Next step: upload your required documents.";
        } elseif ($nextJourney["stage"] === "verification") {
            $customerPaymentTitle = "Payment Submitted — Verification Pending";
            $customerPaymentMessage = "Your payment for booking " . $booking["reference"] . " was submitted. Your current requirements are now awaiting verification.";
        } else {
            $customerPaymentTitle = "Payment Submitted";
            $customerPaymentMessage = "Your payment for booking " . $booking["reference"] . " was submitted. " . $nextJourney["message"];
        }
    }
    notify_user(
        $userId,
        $customerPaymentTitle,
        $customerPaymentMessage,
        "payment",
        (int) $booking["id"],
    );
    notify_admins(
        "Payment awaiting verification",
        "A " . str_replace("_", " ", $type) . " payment was submitted for booking " . $booking["reference"] . ".",
        "payment",
        (int) $booking["id"],
    );
    write_audit("payment_submitted", "payment", $paymentId, [
        "booking_id" => (int) $booking["id"],
        "rental_adjustment_id" => $rentalAdjustmentId,
    ]);
    return $paymentId;
}

/**
 * Apply an administrator's review result to one payment request.
 */
function review_payment(
    int $paymentId,
    string $status,
    string $notes,
    int $adminId,
): void {
    if (!in_array($status, ["paid", "failed", "refunded"], true)) {
        throw new InvalidArgumentException("Choose a valid payment status.");
    }
    $databaseConnection = database();
    $databaseConnection->beginTransaction();
    try {
        $selectSql = "SELECT p.*, b.reference FROM payments p JOIN bookings b ON b.id = p.booking_id WHERE p.id = ? LIMIT 1";
        if ($databaseConnection->getAttribute(PDO::ATTR_DRIVER_NAME) === "mysql") {
            $selectSql .= " FOR UPDATE";
        }
        $select = $databaseConnection->prepare($selectSql);
        $select->execute([$paymentId]);
        $payment = $select->fetch();
        if (!$payment) {
            throw new RuntimeException("Payment not found.");
        }
        if ($status === "paid" && $payment["payment_type"] === "extension") {
            if (!(int) ($payment["rental_adjustment_id"] ?? 0)) {
                throw new RuntimeException("This extension payment is missing its rental adjustment link.");
            }
            $adjustment = rental_adjustment_find((int) $payment["rental_adjustment_id"]);
            if (!$adjustment || $adjustment["status"] !== "approved") {
                throw new RuntimeException("This extension is no longer awaiting activation.");
            }
            assert_extension_available($adjustment, (string) $adjustment["requested_return_at"]);
        }
        $currentTimestamp = date("Y-m-d H:i:s");
        $statement = $databaseConnection->prepare(
            "UPDATE payments SET status = ?, notes = ?, recorded_by = ?, paid_at = ?, updated_at = ? WHERE id = ?",
        );
        $paidAt = $status === "paid" ? $currentTimestamp : null;
        $statement->execute([
            $status,
            mb_substr(trim($notes), 0, 2000),
            $adminId,
            $paidAt,
            $currentTimestamp,
            $paymentId,
        ]);
        if ($status === "paid" && $payment["payment_type"] === "extension") {
            activate_extension_request((int) $payment["rental_adjustment_id"], $adminId);
        }
        $updatedBooking = booking_find_by_reference((string) $payment["reference"]);
        $nextJourney = $updatedBooking ? booking_next_step($updatedBooking) : null;
        if ($status === "failed") {
            $paymentTitle = "Payment Update Required";
            $paymentMessage = "Your payment for booking " . $payment["reference"] . " could not be verified. Review the payment and submit an updated payment if required.";
        } elseif ($status === "paid") {
            $paymentTitle = "Payment Verified";
            $paymentMessage = "Your " . str_replace("_", " ", (string) $payment["payment_type"]) . " payment for booking " . $payment["reference"] . " was verified.";
            if ($nextJourney && $nextJourney["stage"] === "documents") {
                $paymentMessage .= " Next step: upload your required documents.";
            } elseif ($nextJourney && $nextJourney["stage"] === "verification") {
                $paymentMessage .= " Your submitted requirements are now in verification.";
            }
        } else {
            $paymentTitle = "Payment " . ucfirst($status);
            $paymentMessage = "Your " . str_replace("_", " ", (string) $payment["payment_type"]) . " payment for booking " . $payment["reference"] . " was marked " . $status . ".";
        }
        notify_user(
            (int) $payment["user_id"],
            $paymentTitle,
            $paymentMessage,
            "payment",
            (int) $payment["booking_id"],
        );
        write_audit("payment_" . $status, "payment", $paymentId);
        $databaseConnection->commit();
    } catch (Throwable $error) {
        if ($databaseConnection->inTransaction()) {
            $databaseConnection->rollBack();
        }
        throw $error;
    }
}
