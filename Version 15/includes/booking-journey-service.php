<?php

declare(strict_types=1);


/**
 * FILE: includes/booking-journey-service.php
 * FILE PURPOSE: Booking workflow and next-step decision service.
 * USED BY: Booking confirmation, booking view, progress components, and customer journey guidance.
 * RESPONSIBILITY: Determines the appropriate next action for a booking using focused journey resolver functions rather than placing workflow decisions in page files.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
/**
 * Return a compact status summary for the two required customer documents.
 *
 * Documents are customer-owned in the existing architecture, so the same
 * approved identity documents can satisfy each eligible booking for that user.
 *
 * @return array{
 *     documents: array<string, array<string, mixed>>,
 *     missing: list<string>,
 *     pending: list<string>,
 *     approved: list<string>,
 *     rejected: list<string>,
 *     all_submitted: bool,
 *     all_approved: bool
 * }
 */
function booking_document_journey_state(int $userId): array
{
    $documents = customer_documents($userId);
    $labels = [
        "drivers_license" => "Driver’s License",
        "government_id" => "Government ID",
    ];
    $state = [
        "documents" => $documents,
        "missing" => [],
        "pending" => [],
        "approved" => [],
        "rejected" => [],
        "expired" => [],
    ];

    foreach ($labels as $type => $label) {
        $document = $documents[$type] ?? null;
        $status = $document["status"] ?? "missing";
        if (
            $document &&
            $type === "drivers_license" &&
            !empty($document["expiry_date"]) &&
            new DateTimeImmutable((string) $document["expiry_date"]) <= new DateTimeImmutable("today")
        ) {
            $status = "expired";
        }
        if (!isset($state[$status]) || !is_array($state[$status])) {
            $status = "missing";
        }
        $state[$status][] = $label;
    }

    $state["all_submitted"] = count($state["missing"]) === 0;
    $state["all_approved"] = count($state["approved"]) === count($labels) && count($state["expired"]) === 0;
    return $state;
}

/**
 * Return the latest security-deposit payment state for one booking.
 *
 * @return array{latest_status: string, submitted: bool, verified: bool, failed: bool}
 */
function booking_deposit_journey_state(array $booking, array $paymentSummary): array
{
    $latestStatus = "missing";
    foreach ($paymentSummary["payments"] as $payment) {
        if ($payment["payment_type"] !== "deposit") {
            continue;
        }
        $latestStatus = (string) $payment["status"];
        break;
    }

    $verified = (int) $paymentSummary["deposit_due"] === 0;
    $submitted = $verified || $latestStatus === "pending";
    return [
        "latest_status" => $latestStatus,
        "submitted" => $submitted,
        "verified" => $verified,
        "failed" => !$verified && $latestStatus === "failed",
    ];
}

/**
 * Determine whether a completed booking already has a review.
 */
function booking_review_state(int $bookingId): ?array
{
    return review_for_booking($bookingId);
}

/**
 * Return one reusable customer-facing journey state for a booking.
 *
 * This does not create a second booking lifecycle. It translates trusted
 * booking/payment/document/rental state into customer-friendly next steps.
 *
 * @return array{
 *     stage: string,
 *     step_index: int|null,
 *     title: string,
 *     message: string,
 *     button_label: string,
 *     target_url: string,
 *     severity: string,
 *     action_required: bool,
 *     no_action: bool,
 *     payment: array<string, mixed>,
 *     documents: array<string, mixed>,
 *     requirements: array<string, mixed>,
 *     review: array<string, mixed>|null,
 *     steps: array<int, array{label:string,state:string}>
 * }
 */
function booking_next_step(array $booking): array
{
    $requirements = booking_requirements($booking);
    $paymentSummary = $requirements["payments"];
    $deposit = booking_deposit_journey_state($booking, $paymentSummary);
    $documents = booking_document_journey_state((int) $booking["user_id"]);
    $reference = urlencode((string) $booking["reference"]);
    $bookingUrl = "booking-view.php?reference={$reference}";
    $paymentUrl = "payments.php?reference={$reference}";
    $documentUrl = "documents.php?reference={$reference}";

    $journey = [
        "stage" => "booking",
        "step_index" => 1,
        "title" => "Booking Created",
        "message" => "Your reservation has been saved.",
        "button_label" => "View Booking",
        "target_url" => $bookingUrl,
        "severity" => "info",
        "action_required" => false,
        "no_action" => false,
        "payment" => $deposit,
        "documents" => $documents,
        "requirements" => $requirements,
        "review" => null,
        "steps" => [],
    ];

    $context = [
        "payment_summary" => $paymentSummary,
        "deposit" => $deposit,
        "documents" => $documents,
        "reference" => $reference,
        "booking_url" => $bookingUrl,
        "payment_url" => $paymentUrl,
        "document_url" => $documentUrl,
        "modification" => unresolved_booking_modification((int) $booking["id"]),
        "cancellation" => cancellation_request_for_booking((int) $booking["id"]),
        "refund_summary" => refund_summary_for_booking((int) $booking["id"]),
    ];

    $resolvers = [
        "resolve_cancelled_booking_journey",
        "resolve_cancellation_pending_journey",
        "resolve_modification_payment_journey",
        "resolve_modification_pending_journey",
        "resolve_terminal_booking_journey",
        "resolve_completed_booking_journey",
        "resolve_returned_booking_journey",
        "resolve_active_booking_journey",
        "resolve_ready_booking_journey",
        "resolve_confirmed_booking_journey",
    ];

    foreach ($resolvers as $resolver) {
        $resolved = $resolver($booking, $journey, $context);
        if ($resolved !== null) {
            return $resolved;
        }
    }

    return resolve_pending_booking_journey($booking, $journey, $context);
}

function resolve_cancelled_booking_journey(array $booking, array $journey, array $context): ?array
{
    $refundSummary = $context["refund_summary"];
    $bookingUrl = $context["booking_url"];

    if ($booking["status"] === "cancelled") {
        if ($refundSummary["pending"] > 0) {
            $journey = array_merge($journey, [
                "stage" => "refund_pending",
                "step_index" => null,
                "title" => "Refund Pending",
                "message" => "Your booking is cancelled and a refund of " . money((int) $refundSummary["pending"]) . " is awaiting processing.",
                "button_label" => "View Refund Details",
                "target_url" => $bookingUrl . "#refunds",
                "severity" => "info",
                "action_required" => false,
                "no_action" => true,
            ]);
        } elseif ($refundSummary["refunded"] > 0) {
            $journey = array_merge($journey, [
                "stage" => "refund_processed",
                "step_index" => null,
                "title" => "Refund Processed",
                "message" => "This booking is cancelled. Recorded refunds total " . money((int) $refundSummary["refunded"]) . ".",
                "button_label" => "View Refund History",
                "target_url" => $bookingUrl . "#refunds",
                "severity" => "success",
                "action_required" => false,
                "no_action" => true,
            ]);
        } else {
            $journey = array_merge($journey, [
                "stage" => "closed",
                "step_index" => null,
                "title" => "Booking Cancelled",
                "message" => "This booking is cancelled and has no remaining customer action.",
                "button_label" => "View Booking",
                "target_url" => $bookingUrl,
                "severity" => "secondary",
                "action_required" => false,
                "no_action" => true,
            ]);
        }
        return booking_journey_with_steps($journey, $booking);
    }
    return null;
}

function resolve_cancellation_pending_journey(array $booking, array $journey, array $context): ?array
{
    $cancellation = $context["cancellation"];
    $reference = $context["reference"];

    if (($cancellation["status"] ?? null) === "pending") {
        $journey = array_merge($journey, [
            "stage" => "cancellation_pending",
            "step_index" => null,
            "title" => "Cancellation Pending",
            "message" => "Your cancellation request is awaiting administrator review. Your current booking remains authoritative until a decision is made.",
            "button_label" => "View Cancellation",
            "target_url" => "booking-cancellation.php?reference={$reference}",
            "severity" => "info",
            "action_required" => false,
            "no_action" => true,
        ]);
        return booking_journey_with_steps($journey, $booking);
    }
    return null;
}

function resolve_modification_payment_journey(array $booking, array $journey, array $context): ?array
{
    $modification = $context["modification"];
    $paymentUrl = $context["payment_url"];

    if ($modification && $modification["status"] === "approved" && (int) $modification["price_difference"] > 0) {
        $pendingPayment = database()->prepare("SELECT COUNT(*) FROM payments WHERE booking_modification_id=? AND payment_type='modification' AND status='pending'");
        $pendingPayment->execute([(int) $modification["id"]]);
        if ((int) $pendingPayment->fetchColumn() > 0) {
            $journey = array_merge($journey, [
                "stage" => "modification_payment_verification",
                "step_index" => null,
                "title" => "Modification Payment Verification",
                "message" => "Your additional payment for the approved booking changes is awaiting verification. The original booking remains active until the change is activated.",
                "button_label" => "View Payments",
                "target_url" => $paymentUrl,
                "severity" => "info",
                "action_required" => false,
                "no_action" => true,
            ]);
        } else {
            $journey = array_merge($journey, [
                "stage" => "modification_payment",
                "step_index" => null,
                "title" => "Modification Payment Required",
                "message" => "Your requested booking changes were approved. Pay the additional " . money((int) $modification["price_difference"]) . " before the changes become active.",
                "button_label" => "Pay Modification Balance",
                "target_url" => $paymentUrl,
                "severity" => "warning",
                "action_required" => true,
                "no_action" => false,
            ]);
        }
        return booking_journey_with_steps($journey, $booking);
    }
    return null;
}

function resolve_modification_pending_journey(array $booking, array $journey, array $context): ?array
{
    $modification = $context["modification"];
    $reference = $context["reference"];

    if ($modification && $modification["status"] === "pending") {
        $journey = array_merge($journey, [
            "stage" => "modification_pending",
            "step_index" => null,
            "title" => "Booking Modification Pending",
            "message" => "Your requested pre-pickup changes are awaiting administrator review. Your current booking details remain authoritative for now.",
            "button_label" => "View Requested Changes",
            "target_url" => "booking-modification.php?reference={$reference}",
            "severity" => "info",
            "action_required" => false,
            "no_action" => true,
        ]);
        return booking_journey_with_steps($journey, $booking);
    }
    return null;
}

function resolve_terminal_booking_journey(array $booking, array $journey, array $context): ?array
{
    $bookingUrl = $context["booking_url"];

    $terminal = ["rejected", "no_show"];
    if (in_array($booking["status"], $terminal, true)) {
        $journey = array_merge($journey, [
            "stage" => "closed",
            "step_index" => null,
            "title" => "Booking Closed",
            "message" => "This booking has no remaining customer action.",
            "button_label" => "View Booking",
            "target_url" => $bookingUrl,
            "severity" => "secondary",
            "action_required" => false,
            "no_action" => true,
        ]);
        return booking_journey_with_steps($journey, $booking);
    }
    return null;
}

function resolve_completed_booking_journey(array $booking, array $journey, array $context): ?array
{
    $paymentSummary = $context["payment_summary"];
    $paymentUrl = $context["payment_url"];
    $bookingUrl = $context["booking_url"];

    if ($booking["status"] === "completed") {
        refresh_rental_settlement_status((int) $booking["id"]);
        $completedSettlement = rental_settlement_for_booking((int) $booking["id"]);
        if ($completedSettlement && (((int) $paymentSummary["deposit_due"] + (int) $paymentSummary["rental_due"] + (int) $paymentSummary["extra_due"]) > 0 || $completedSettlement["status"] === "balance_due")) {
            $pendingReturnPayment = database()->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE booking_id=? AND payment_type='extra_charge' AND status='pending'");
            $pendingReturnPayment->execute([(int) $booking["id"]]);
            $pendingReturnAmount = (int) $pendingReturnPayment->fetchColumn();
            $journey = array_merge($journey, [
                "stage" => $pendingReturnAmount > 0 ? "return_payment_verification" : "return_payment",
                "step_index" => null,
                "title" => $pendingReturnAmount > 0 ? "Return Payment Verification" : "Outstanding Return Balance",
                "message" => $pendingReturnAmount > 0
                    ? "Your return-balance payment is awaiting verification. No action is required right now."
                    : "A financial correction reopened an outstanding balance of " . money((int) $paymentSummary["deposit_due"] + (int) $paymentSummary["rental_due"] + (int) $paymentSummary["extra_due"]) . ". Complete it to restore final settlement.",
                "button_label" => $pendingReturnAmount > 0 ? "View Payments" : "Review Payments",
                "target_url" => $paymentUrl,
                "severity" => $pendingReturnAmount > 0 ? "info" : "warning",
                "action_required" => $pendingReturnAmount <= 0,
                "no_action" => $pendingReturnAmount > 0,
            ]);
            return booking_journey_with_steps($journey, $booking);
        }
        if ($completedSettlement && in_array($completedSettlement["status"], ["refund_pending", "refund_processing"], true)) {
            $journey = array_merge($journey, [
                "stage" => $completedSettlement["status"] === "refund_processing" ? "deposit_refund_processing" : "deposit_refund_pending",
                "step_index" => null,
                "title" => "Security Deposit Refund " . ($completedSettlement["status"] === "refund_processing" ? "Processing" : "Pending"),
                "message" => "Your rental is returned, but the security-deposit refund is still being processed. No action is required right now.",
                "button_label" => "View Settlement",
                "target_url" => $bookingUrl . "#settlement",
                "severity" => "info",
                "action_required" => false,
                "no_action" => true,
            ]);
            return booking_journey_with_steps($journey, $booking);
        }
        $review = booking_review_state((int) $booking["id"]);
        $journey["review"] = $review;
        if (!$review) {
            $journey = array_merge($journey, [
                "stage" => "review",
                "step_index" => null,
                "title" => "Rate Your Rental",
                "message" => "Your rental is complete. Share your experience with a verified review.",
                "button_label" => "Rate Your Rental",
                "target_url" => "rate-trip.php?reference={$reference}",
                "severity" => "success",
                "action_required" => true,
                "no_action" => false,
            ]);
        } else {
            $journey = array_merge($journey, [
                "stage" => "complete",
                "step_index" => null,
                "title" => "Rental Complete",
                "message" => "Your rental and review process are complete.",
                "button_label" => "View Booking",
                "target_url" => $bookingUrl,
                "severity" => "success",
                "action_required" => false,
                "no_action" => true,
            ]);
        }
        return booking_journey_with_steps($journey, $booking);
    }
    return null;
}

function resolve_returned_booking_journey(array $booking, array $journey, array $context): ?array
{
    $paymentSummary = $context["payment_summary"];
    $paymentUrl = $context["payment_url"];
    $bookingUrl = $context["booking_url"];

    if ($booking["status"] === "returned") {
        refresh_rental_settlement_status((int) $booking["id"]);
        $settlement = rental_settlement_for_booking((int) $booking["id"]);
        if (!$settlement) {
            $journey = array_merge($journey, [
                "stage" => "settlement_pending",
                "step_index" => null,
                "title" => "Return Settlement Pending",
                "message" => "Your vehicle return is recorded. The rental team is finalizing the security-deposit settlement and any legitimate return deductions.",
                "button_label" => "View Return Details",
                "target_url" => $bookingUrl . "#settlement",
                "severity" => "info",
                "action_required" => false,
                "no_action" => true,
            ]);
        } elseif (((int) $paymentSummary["deposit_due"] + (int) $paymentSummary["rental_due"] + (int) $paymentSummary["extra_due"]) > 0 || $settlement["status"] === "balance_due") {
            $pendingReturnPayment = database()->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE booking_id=? AND payment_type='extra_charge' AND status='pending'");
            $pendingReturnPayment->execute([(int) $booking["id"]]);
            $pendingReturnAmount = (int) $pendingReturnPayment->fetchColumn();
            if ($pendingReturnAmount > 0) {
                $journey = array_merge($journey, [
                    "stage" => "return_payment_verification",
                    "step_index" => null,
                    "title" => "Return Payment Verification",
                    "message" => "Your payment toward the outstanding return balance is awaiting administrator verification. No action is required right now.",
                    "button_label" => "View Payments",
                    "target_url" => $paymentUrl,
                    "severity" => "info",
                    "action_required" => false,
                    "no_action" => true,
                ]);
            } else {
                $journey = array_merge($journey, [
                    "stage" => "return_payment",
                    "step_index" => null,
                    "title" => "Outstanding Return Balance",
                    "message" => "Your security deposit was applied to the final return deductions. Complete the remaining " . money((int) $paymentSummary["deposit_due"] + (int) $paymentSummary["rental_due"] + (int) $paymentSummary["extra_due"]) . " balance to finish settlement.",
                    "button_label" => "Pay Outstanding Balance",
                    "target_url" => $paymentUrl,
                    "severity" => "warning",
                    "action_required" => true,
                    "no_action" => false,
                ]);
            }
        } elseif (in_array($settlement["status"], ["refund_pending", "refund_processing"], true)) {
            $journey = array_merge($journey, [
                "stage" => $settlement["status"] === "refund_processing" ? "deposit_refund_processing" : "deposit_refund_pending",
                "step_index" => null,
                "title" => $settlement["status"] === "refund_processing" ? "Security Deposit Refund Processing" : "Security Deposit Refund Pending",
                "message" => "Your return settlement is complete. A security-deposit refund of " . money((int) $settlement["deposit_refund_amount"]) . " is being handled by the rental team. No action is required right now.",
                "button_label" => "View Settlement",
                "target_url" => $bookingUrl . "#settlement",
                "severity" => "info",
                "action_required" => false,
                "no_action" => true,
            ]);
        } else {
            $journey = array_merge($journey, [
                "stage" => "returned",
                "step_index" => null,
                "title" => "Rental Financially Settled",
                "message" => "The return settlement is complete. No customer action is required while the team closes the rental.",
                "button_label" => "View Settlement",
                "target_url" => $bookingUrl . "#settlement",
                "severity" => "success",
                "action_required" => false,
                "no_action" => true,
            ]);
        }
        return booking_journey_with_steps($journey, $booking);
    }
    return null;
}

function resolve_active_booking_journey(array $booking, array $journey, array $context): ?array
{
    $paymentSummary = $context["payment_summary"];
    $paymentUrl = $context["payment_url"];
    $reference = $context["reference"];
    $bookingUrl = $context["booking_url"];

    if ($booking["status"] === "active") {
        if ((int) $paymentSummary["deposit_due"] + (int) $paymentSummary["rental_due"] > 0) {
            $journey = array_merge($journey, [
                "stage" => "payment",
                "step_index" => null,
                "title" => "Payment Balance Required",
                "message" => "A payment correction or remaining obligation requires " . money((int) $paymentSummary["deposit_due"] + (int) $paymentSummary["rental_due"]) . " before the account is fully settled.",
                "button_label" => "Review Payments",
                "target_url" => $paymentUrl,
                "severity" => "warning",
                "action_required" => true,
                "no_action" => false,
            ]);
        } elseif ((int) $paymentSummary["extension_due"] > 0) {
            $journey = array_merge($journey, [
                "stage" => "extension_payment",
                "step_index" => null,
                "title" => "Pay Extension Balance",
                "message" => "Your approved extension has an outstanding balance before the new return schedule can activate.",
                "button_label" => "Pay Extension Balance",
                "target_url" => $paymentUrl,
                "severity" => "warning",
                "action_required" => true,
                "no_action" => false,
            ]);
        } else {
            $unresolved = unresolved_rental_adjustment((int) $booking["id"]);
            if ($unresolved && $unresolved["request_type"] === "early_return") {
                $journey = array_merge($journey, [
                    "stage" => "early_return_pending",
                    "step_index" => null,
                    "title" => "Early Return Request Pending",
                    "message" => "Your request is waiting for the rental team. No action is required right now.",
                    "button_label" => "View Return Request",
                    "target_url" => "rental-adjustment.php?reference={$reference}",
                    "severity" => "info",
                    "action_required" => false,
                    "no_action" => true,
                ]);
            } elseif ($unresolved && $unresolved["request_type"] === "extension") {
                $journey = array_merge($journey, [
                    "stage" => "extension_pending",
                    "step_index" => null,
                    "title" => "Extension Request Pending",
                    "message" => "Your extension request is being reviewed. No action is required right now.",
                    "button_label" => "View Extension Request",
                    "target_url" => "rental-adjustment.php?reference={$reference}",
                    "severity" => "info",
                    "action_required" => false,
                    "no_action" => true,
                ]);
            } else {
                $journey = array_merge($journey, [
                    "stage" => "active",
                    "step_index" => null,
                    "title" => "Rental Active",
                    "message" => "Your rental is active. You can manage an early return or request an extension from the rental details.",
                    "button_label" => "View Active Rental",
                    "target_url" => $bookingUrl,
                    "severity" => "info",
                    "action_required" => false,
                    "no_action" => false,
                ]);
            }
        }
        return booking_journey_with_steps($journey, $booking);
    }
    return null;
}

function resolve_ready_booking_journey(array $booking, array $journey, array $context): ?array
{
    $bookingUrl = $context["booking_url"];

    if ($booking["status"] === "ready") {
        $isDelivery = $booking["pickup_method"] === "Vehicle delivery";
        $journey = array_merge($journey, [
            "stage" => $isDelivery ? "ready_delivery" : "ready_pickup",
            "step_index" => 5,
            "title" => $isDelivery ? "Ready for Delivery" : "Ready for Pickup",
            "message" => $isDelivery
                ? "Your vehicle has been prepared and is ready for the scheduled delivery."
                : "Your vehicle has been prepared and is ready for pickup.",
            "button_label" => $isDelivery ? "View Delivery Details" : "View Pickup Details",
            "target_url" => $bookingUrl . "#pickup-details",
            "severity" => "success",
            "action_required" => true,
            "no_action" => false,
        ]);
        return booking_journey_with_steps($journey, $booking);
    }
    return null;
}

function resolve_confirmed_booking_journey(array $booking, array $journey, array $context): ?array
{
    $deposit = $context["deposit"];
    $documents = $context["documents"];
    $paymentUrl = $context["payment_url"];
    $documentUrl = $context["document_url"];
    $bookingUrl = $context["booking_url"];

    if ($booking["status"] === "confirmed") {
        // Confirmation normally means the requirements were verified, but derive
        // the customer view from current records in case a payment/document was
        // later rejected or refunded.
        if (!$deposit["verified"] && !$deposit["submitted"]) {
            $journey = array_merge($journey, [
                "stage" => "payment",
                "step_index" => 2,
                "title" => $deposit["failed"] ? "Payment Update Required" : "Complete Payment",
                "message" => "The required security-deposit payment needs your attention before preparation can continue.",
                "button_label" => $deposit["failed"] ? "Resubmit Payment" : "Proceed to Payment",
                "target_url" => $paymentUrl,
                "severity" => "warning",
                "action_required" => true,
                "no_action" => false,
            ]);
        } elseif ($documents["rejected"] || $documents["missing"] || $documents["expired"]) {
            $journey = array_merge($journey, [
                "stage" => "documents",
                "step_index" => 3,
                "title" => ($documents["rejected"] || $documents["expired"]) ? "Document Requires Action" : "Upload Required Documents",
                "message" => $documents["expired"]
                    ? "A required document has expired and must be replaced before preparation can continue."
                    : ($documents["rejected"]
                        ? "A required document needs to be replaced before preparation can continue."
                        : "Upload the remaining required document before preparation can continue."),
                "button_label" => ($documents["rejected"] || $documents["expired"]) ? "Replace Document" : "Upload Documents",
                "target_url" => $documentUrl,
                "severity" => "warning",
                "action_required" => true,
                "no_action" => false,
            ]);
        } elseif (!$deposit["verified"] || !$documents["all_approved"]) {
            $journey = array_merge($journey, [
                "stage" => "verification",
                "step_index" => 4,
                "title" => "Verification in Progress",
                "message" => "Your submitted requirements are still being verified. No action is required right now.",
                "button_label" => "View Verification Status",
                "target_url" => $bookingUrl . "#requirements",
                "severity" => "info",
                "action_required" => false,
                "no_action" => true,
            ]);
        } else {
            $journey = array_merge($journey, [
                "stage" => "preparing",
                "step_index" => 5,
                "title" => "We’re Preparing Your Vehicle",
                "message" => "Your payment and required documents have been verified. We’ll notify you when the vehicle is ready for " .
                    ($booking["pickup_method"] === "Vehicle delivery" ? "delivery" : "pickup") . ".",
                "button_label" => "View Booking",
                "target_url" => $bookingUrl,
                "severity" => "info",
                "action_required" => false,
                "no_action" => true,
            ]);
        }
        return booking_journey_with_steps($journey, $booking);
    }
    return null;
}

function resolve_pending_booking_journey(array $booking, array $journey, array $context): array
{
    $deposit = $context["deposit"];
    $documents = $context["documents"];
    $paymentUrl = $context["payment_url"];
    $documentUrl = $context["document_url"];
    $bookingUrl = $context["booking_url"];

    // Pending booking onboarding: payment first, then documents, then verification.
    if (!$deposit["verified"] && !$deposit["submitted"]) {
        $journey = array_merge($journey, [
            "stage" => "payment",
            "step_index" => 2,
            "title" => $deposit["failed"] ? "Payment Update Required" : "Complete Payment",
            "message" => $deposit["failed"]
                ? "Your previous security-deposit payment could not be verified. Please submit a new payment."
                : "Complete the required security-deposit payment to continue processing your reservation.",
            "button_label" => $deposit["failed"] ? "Resubmit Payment" : "Proceed to Payment",
            "target_url" => $paymentUrl,
            "severity" => $deposit["failed"] ? "warning" : "primary",
            "action_required" => true,
            "no_action" => false,
        ]);
        return booking_journey_with_steps($journey, $booking);
    }

    if ($documents["rejected"] || $documents["expired"]) {
        $journey = array_merge($journey, [
            "stage" => "documents",
            "step_index" => 3,
            "title" => "Document Requires Action",
            "message" => $documents["expired"]
                ? "One of your required documents has expired. Replace it to continue."
                : "One of your required documents could not be verified. Replace the rejected document to continue.",
            "button_label" => "Replace Document",
            "target_url" => $documentUrl,
            "severity" => "warning",
            "action_required" => true,
            "no_action" => false,
        ]);
        return booking_journey_with_steps($journey, $booking);
    }

    if ($documents["missing"]) {
        $nextDocument = $documents["missing"][0];
        $journey = array_merge($journey, [
            "stage" => "documents",
            "step_index" => 3,
            "title" => "Upload Required Documents",
            "message" => "Next required document: {$nextDocument}. Upload the required documents while your payment is being verified.",
            "button_label" => "Upload Documents",
            "target_url" => $documentUrl,
            "severity" => "primary",
            "action_required" => true,
            "no_action" => false,
        ]);
        return booking_journey_with_steps($journey, $booking);
    }

    if (!$deposit["verified"] || !$documents["all_approved"]) {
        $journey = array_merge($journey, [
            "stage" => "verification",
            "step_index" => 4,
            "title" => "Verification in Progress",
            "message" => "Only the requirements that are not yet verified are still under review. Completed document checks will not be repeated.",
            "button_label" => "View Verification Status",
            "target_url" => $bookingUrl . "#requirements",
            "severity" => "info",
            "action_required" => false,
            "no_action" => true,
        ]);
        return booking_journey_with_steps($journey, $booking);
    }

    $journey = array_merge($journey, [
        "stage" => "preparing",
        "step_index" => 5,
        "title" => "We’re Preparing Your Vehicle",
        "message" => "Your payment and valid required documents are already verified. No document step remains; the team is preparing your vehicle.",
        "button_label" => "View Booking",
        "target_url" => $bookingUrl,
        "severity" => "info",
        "action_required" => false,
        "no_action" => true,
    ]);
    return booking_journey_with_steps($journey, $booking);
}


/**
 * Add the five customer-facing booking journey steps and their visual states.
 */
function booking_journey_with_steps(array $journey, array $booking): array
{
    $deposit = $journey["payment"];
    $documents = $journey["documents"];
    $paymentComplete = $deposit["submitted"] || $deposit["verified"];
    $documentsComplete = (bool) $documents["all_submitted"] && !$documents["rejected"];
    $verificationComplete = $deposit["verified"] && $documents["all_approved"];
    $readyComplete = in_array(
        $booking["status"],
        ["ready", "active", "returned", "completed"],
        true,
    );

    $current = (int) ($journey["step_index"] ?? 0);
    $states = [
        1 => "complete",
        2 => $paymentComplete ? "complete" : ($current === 2 ? "current" : "pending"),
        3 => ($documents["rejected"] || $documents["expired"])
            ? "attention"
            : ($documentsComplete && $documents["all_approved"] ? "complete" : ($current === 3 ? "current" : "pending")),
        4 => $verificationComplete
            ? "complete"
            : ($current === 4 ? "current" : "pending"),
        5 => $readyComplete
            ? "complete"
            : (($journey["stage"] ?? "") === "preparing" || $booking["status"] === "confirmed" ? "current" : "pending"),
    ];
    if ($deposit["failed"] && !$paymentComplete) {
        $states[2] = "attention";
    }

    $journey["steps"] = [
        1 => ["label" => "Booking", "state" => $states[1]],
        2 => ["label" => "Payment", "state" => $states[2]],
        3 => ["label" => "Documents", "state" => $states[3]],
        4 => ["label" => "Verification", "state" => $states[4]],
        5 => ["label" => (($journey["stage"] ?? "") === "preparing" || $booking["status"] === "confirmed")
            ? "Preparing Vehicle"
            : ($booking["status"] === "ready"
                ? ($booking["pickup_method"] === "Vehicle delivery" ? "Ready for Delivery" : "Ready for Pickup")
                : "Ready"), "state" => $states[5]],
    ];
    return $journey;
}

/**
 * Rank journeys so the account dashboard surfaces the most useful action first.
 */
function booking_journey_priority(array $journey): int
{
    return match ($journey["stage"]) {
        "extension_payment" => 120,
        "modification_payment" => 118,
        "payment" => 115,
        "documents" => 110,
        "ready_pickup", "ready_delivery" => 105,
        "cancellation_pending" => 103,
        "modification_pending" => 102,
        "return_payment" => 100,
        "settlement_pending" => 98,
        "return_payment_verification" => 97,
        "deposit_refund_pending", "deposit_refund_processing" => 96,
        "modification_payment_verification" => 95,
        "refund_pending" => 90,
        "verification" => 85,
        "preparing" => 80,
        "early_return_pending", "extension_pending" => 75,
        "active" => 65,
        "review" => 55,
        "refund_processed" => 50,
        "returned" => 40,
        default => 10,
    };
}

/**
 * @return array{booking:array<string,mixed>,journey:array<string,mixed>}|null
 */
function customer_priority_booking_journey(array $bookings): ?array
{
    $best = null;
    $bestPriority = -1;
    foreach ($bookings as $booking) {
        $journey = booking_next_step($booking);
        $priority = booking_journey_priority($journey);
        if ($priority > $bestPriority) {
            $bestPriority = $priority;
            $best = ["booking" => $booking, "journey" => $journey];
        }
    }
    return $best;
}

/**
 * Return the most recent non-final booking for a customer, when available.
 */
function latest_actionable_booking_for_user(int $userId): ?array
{
    $bookings = bookings_for_user($userId);
    foreach ([["pending", "confirmed", "ready"], ["active", "returned"]] as $preferredStatuses) {
        foreach ($bookings as $booking) {
            if (in_array($booking["status"], $preferredStatuses, true)) {
                return $booking;
            }
        }
    }
    return null;
}

/**
 * Map one notification to the most useful customer destination.
 *
 * @return array{url:string,label:string}
 */
function notification_journey_action(array $notification): array
{
    $title = strtolower((string) ($notification["title"] ?? ""));
    $reference = (string) ($notification["booking_reference"] ?? "");
    if ($reference !== "") {
        $encoded = urlencode($reference);
        if (str_contains($title, "booking created") || str_contains($title, "payment required")) {
            return ["url" => "payments.php?reference={$encoded}", "label" => "Proceed to Payment"];
        }
        if (str_contains($title, "upload documents")) {
            return ["url" => "documents.php?reference={$encoded}", "label" => "Upload Documents"];
        }
        if (str_contains($title, "payment submitted")) {
            return ["url" => "booking-view.php?reference={$encoded}#requirements", "label" => "View Current Step"];
        }
        if (str_contains($title, "payment update required") || str_contains($title, "payment rejected") || str_contains($title, "payment failed")) {
            return ["url" => "payments.php?reference={$encoded}", "label" => "Review Payment"];
        }
        if (str_contains($title, "document") && (str_contains($title, "required") || str_contains($title, "rejected") || str_contains($title, "update"))) {
            return ["url" => "documents.php?reference={$encoded}", "label" => "Review Documents"];
        }
        if (str_contains($title, "ready for pickup")) {
            return ["url" => "booking-view.php?reference={$encoded}#pickup-details", "label" => "View Pickup Details"];
        }
        if (str_contains($title, "ready for delivery")) {
            return ["url" => "booking-view.php?reference={$encoded}#pickup-details", "label" => "View Delivery Details"];
        }
        return ["url" => "booking-view.php?reference={$encoded}", "label" => "Open Booking"];
    }
    if (($notification["type"] ?? "") === "draft") {
        return ["url" => "saved-bookings.php", "label" => "Open Saved Bookings"];
    }
    if (($notification["type"] ?? "") === "document") {
        return ["url" => "documents.php", "label" => "Open Documents"];
    }
    if (($notification["type"] ?? "") === "payment") {
        return ["url" => "payments.php", "label" => "Open Payments"];
    }
    return ["url" => "notifications.php", "label" => "View Update"];
}
