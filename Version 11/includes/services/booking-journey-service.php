<?php

declare(strict_types=1);

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
    ];

    foreach ($labels as $type => $label) {
        $document = $documents[$type] ?? null;
        $status = $document["status"] ?? "missing";
        if (!isset($state[$status]) || !is_array($state[$status])) {
            $status = "missing";
        }
        $state[$status][] = $label;
    }

    $state["all_submitted"] = count($state["missing"]) === 0;
    $state["all_approved"] = count($state["approved"]) === count($labels);
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
    $statement = database()->prepare(
        "SELECT id, status FROM reviews WHERE booking_id = ? LIMIT 1",
    );
    $statement->execute([$bookingId]);
    $review = $statement->fetch();
    return $review ?: null;
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
    $review = null;
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

    $terminal = ["cancelled", "rejected", "no_show"];
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

    if ($booking["status"] === "completed") {
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

    if ($booking["status"] === "returned") {
        if ((int) $paymentSummary["extra_due"] > 0) {
            $journey = array_merge($journey, [
                "stage" => "return_payment",
                "step_index" => null,
                "title" => "Return Charges Due",
                "message" => "Your vehicle has been returned. Complete the outstanding return charges to finish the rental.",
                "button_label" => "Review Payments",
                "target_url" => $paymentUrl,
                "severity" => "warning",
                "action_required" => true,
                "no_action" => false,
            ]);
        } else {
            $journey = array_merge($journey, [
                "stage" => "returned",
                "step_index" => null,
                "title" => "Return Being Finalized",
                "message" => "The vehicle return has been recorded. No action is required while the team finalizes the rental.",
                "button_label" => "View Booking",
                "target_url" => $bookingUrl,
                "severity" => "info",
                "action_required" => false,
                "no_action" => true,
            ]);
        }
        return booking_journey_with_steps($journey, $booking);
    }

    if ($booking["status"] === "active") {
        if ((int) $paymentSummary["extension_due"] > 0) {
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
        } elseif ($documents["rejected"] || $documents["missing"]) {
            $journey = array_merge($journey, [
                "stage" => "documents",
                "step_index" => 3,
                "title" => $documents["rejected"] ? "Document Requires Action" : "Upload Required Documents",
                "message" => $documents["rejected"]
                    ? "A required document needs to be replaced before preparation can continue."
                    : "Upload the remaining required document before preparation can continue.",
                "button_label" => $documents["rejected"] ? "Replace Document" : "Upload Documents",
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

    if ($documents["rejected"]) {
        $journey = array_merge($journey, [
            "stage" => "documents",
            "step_index" => 3,
            "title" => "Document Requires Action",
            "message" => "One of your required documents could not be verified. Replace the rejected document to continue.",
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

    if (!$deposit["verified"] || !$documents["all_approved"] || $booking["status"] === "pending") {
        $journey = array_merge($journey, [
            "stage" => "verification",
            "step_index" => 4,
            "title" => "Verification in Progress",
            "message" => "Your payment and required documents have been submitted. Our team is reviewing your booking requirements.",
            "button_label" => "View Verification Status",
            "target_url" => $bookingUrl . "#requirements",
            "severity" => "info",
            "action_required" => false,
            "no_action" => true,
        ]);
        return booking_journey_with_steps($journey, $booking);
    }

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
    $verificationComplete = $deposit["verified"] && $documents["all_approved"] && in_array(
        $booking["status"],
        ["confirmed", "ready", "active", "returned", "completed"],
        true,
    );
    $readyComplete = in_array(
        $booking["status"],
        ["ready", "active", "returned", "completed"],
        true,
    );

    $current = (int) ($journey["step_index"] ?? 0);
    $states = [
        1 => "complete",
        2 => $paymentComplete ? "complete" : ($current === 2 ? "current" : "pending"),
        3 => $documents["rejected"]
            ? "attention"
            : ($documentsComplete ? "complete" : ($current === 3 ? "current" : "pending")),
        4 => $verificationComplete
            ? "complete"
            : ($current === 4 ? "current" : "pending"),
        5 => $readyComplete
            ? "complete"
            : ($booking["status"] === "confirmed" ? "current" : "pending"),
    ];
    if ($deposit["failed"] && !$paymentComplete) {
        $states[2] = "attention";
    }

    $journey["steps"] = [
        1 => ["label" => "Booking", "state" => $states[1]],
        2 => ["label" => "Payment", "state" => $states[2]],
        3 => ["label" => "Documents", "state" => $states[3]],
        4 => ["label" => "Verification", "state" => $states[4]],
        5 => ["label" => $booking["status"] === "confirmed"
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
        "payment" => 115,
        "documents" => 110,
        "ready_pickup", "ready_delivery" => 105,
        "return_payment" => 100,
        "verification" => 85,
        "preparing" => 80,
        "early_return_pending", "extension_pending" => 75,
        "active" => 65,
        "review" => 55,
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
        if (str_contains($title, "payment submitted") || str_contains($title, "upload documents")) {
            return ["url" => "documents.php?reference={$encoded}", "label" => "Upload Documents"];
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
    if (($notification["type"] ?? "") === "document") {
        return ["url" => "documents.php", "label" => "Open Documents"];
    }
    if (($notification["type"] ?? "") === "payment") {
        return ["url" => "payments.php", "label" => "Open Payments"];
    }
    return ["url" => "notifications.php", "label" => "View Update"];
}
