<?php

declare(strict_types=1);


/**
 * FILE: includes/admin-vehicle-service.php
 * FILE PURPOSE: Administrator vehicle management service.
 * USED BY: Admin vehicle-management pages.
 * RESPONSIBILITY: Handles admin-side vehicle create/update/archive/upload operations and related validation while keeping page templates focused on presentation.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
/**
 * An expected, user-correctable problem with the administrator vehicle form.
 */
final class AdminVehicleValidationException extends InvalidArgumentException
{
}

/**
 * Validate and persist an administrator vehicle form submission.
 *
 * This service is loaded after includes/bootstrap.php, so the shared database,
 * upload, CSRF, catalog, and audit helpers are already available.
 *
 * @param array<string, mixed> $submittedFields
 * @param array<string, mixed> $uploadedFiles
 * @return array{vehicle_id: int, created: bool}
 * @throws AdminVehicleValidationException
 */
function admin_vehicle_save_submission(
    array $submittedFields,
    array $uploadedFiles,
): array {
    admin_vehicle_validate_csrf_token($submittedFields);

    $vehicleId = (int) ($submittedFields["id"] ?? 0);
    $existingVehicle = $vehicleId !== 0
        ? vehicle_find($vehicleId, false)
        : null;

    if ($vehicleId !== 0 && !$existingVehicle) {
        throw new AdminVehicleValidationException("Vehicle not found.");
    }

    $validatedVehicle = admin_vehicle_validate_submission(
        $submittedFields,
        $uploadedFiles,
        $existingVehicle,
    );
    $databaseConnection = database();

    admin_vehicle_assert_unique_slug(
        $databaseConnection,
        $validatedVehicle["slug"],
        $vehicleId,
    );

    if ($existingVehicle) {
        admin_vehicle_update(
            $databaseConnection,
            $vehicleId,
            $validatedVehicle,
        );
        $savedVehicleId = $vehicleId;
        $auditAction = "vehicle_updated";
        $wasCreated = false;
    } else {
        $savedVehicleId = admin_vehicle_insert(
            $databaseConnection,
            $validatedVehicle,
        );
        $auditAction = "vehicle_created";
        $wasCreated = true;
    }

    write_audit($auditAction, "vehicle", $savedVehicleId);

    return ["vehicle_id" => $savedVehicleId, "created" => $wasCreated];
}

/**
 * @param array<string, mixed> $submittedFields
 * @throws AdminVehicleValidationException
 */
function admin_vehicle_validate_csrf_token(array $submittedFields): void
{
    $submittedToken = $submittedFields["csrf_token"] ?? null;
    $submittedToken = is_scalar($submittedToken) ? (string) $submittedToken : "";

    if (!verify_csrf($submittedToken)) {
        http_response_code(419);
        throw new AdminVehicleValidationException(
            "Your session expired. Refresh the page and try again.",
        );
    }
}

/**
 * @param array<string, mixed> $submittedFields
 * @param array<string, mixed> $uploadedFiles
 * @param array<string, mixed>|null $existingVehicle
 * @return array{
 *     slug: string,
 *     name: string,
 *     brand: string,
 *     category: string,
 *     image: string,
 *     price: int,
 *     deposit: int,
 *     seats: int,
 *     doors: int,
 *     luggage: string,
 *     transmission: string,
 *     fuel: string,
 *     daily_km: int,
 *     overview_title: string,
 *     overview: string,
 *     description: string,
 *     inclusions: list<string>,
 *     features: list<string>,
 *     is_active: int,
 *     availability_status: string,
 *     updated_at: string
 * }
 * @throws AdminVehicleValidationException
 */
function admin_vehicle_validate_submission(
    array $submittedFields,
    array $uploadedFiles,
    ?array $existingVehicle,
): array {
    $slug = strtolower(
        admin_vehicle_text_field($submittedFields, "slug"),
    );
    $name = admin_vehicle_text_field($submittedFields, "name");
    $brand = admin_vehicle_text_field($submittedFields, "brand");
    $category = admin_vehicle_text_field($submittedFields, "category");
    $transmission = admin_vehicle_text_field(
        $submittedFields,
        "transmission",
    );
    $fuel = admin_vehicle_text_field($submittedFields, "fuel");

    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        throw new AdminVehicleValidationException(
            "Use a lowercase URL slug with letters, numbers, and hyphens.",
        );
    }

    if (
        mb_strlen($name) < 2 ||
        mb_strlen($name) > 180 ||
        mb_strlen($brand) < 2 ||
        mb_strlen($brand) > 100
    ) {
        throw new AdminVehicleValidationException(
            "Enter a valid vehicle name and brand.",
        );
    }

    if (!in_array($category, ["Luxury", "SUV", "Pickup", "Van", "EV"], true)) {
        throw new AdminVehicleValidationException("Choose a valid category.");
    }

    if (!in_array($transmission, ["Automatic", "Manual"], true)) {
        throw new AdminVehicleValidationException(
            "Choose a valid transmission.",
        );
    }

    if (!in_array($fuel, ["Gasoline", "Diesel", "Electric", "Hybrid"], true)) {
        throw new AdminVehicleValidationException("Choose a valid fuel type.");
    }

    $availabilityStatus = admin_vehicle_text_field(
        $submittedFields,
        "availability_status",
        (string) ($existingVehicle["availability_status"] ?? "available"),
    );
    if (
        !in_array(
            $availabilityStatus,
            ["available", "maintenance", "unavailable"],
            true,
        )
    ) {
        throw new AdminVehicleValidationException(
            "Choose a valid fleet status.",
        );
    }

    $numericValues = admin_vehicle_validate_numeric_fields($submittedFields);
    $luggage = admin_vehicle_text_field($submittedFields, "luggage");
    $overviewTitle = admin_vehicle_text_field(
        $submittedFields,
        "overview_title",
    );
    $overview = admin_vehicle_text_field($submittedFields, "overview");
    $description = admin_vehicle_text_field(
        $submittedFields,
        "description",
    );

    if (
        $luggage === "" ||
        mb_strlen($overviewTitle) < 5 ||
        mb_strlen($overview) < 30 ||
        mb_strlen($description) < 30
    ) {
        throw new AdminVehicleValidationException(
            "Complete the luggage, overview, and description fields.",
        );
    }

    $inclusions = admin_vehicle_multiline_values(
        admin_vehicle_text_field($submittedFields, "inclusions"),
    );
    $features = admin_vehicle_multiline_values(
        admin_vehicle_text_field($submittedFields, "features"),
    );
    if (!$inclusions || !$features) {
        throw new AdminVehicleValidationException(
            "Add at least one inclusion and one feature.",
        );
    }

    $imageFilename = admin_vehicle_image_filename(
        $submittedFields,
        $uploadedFiles,
        $existingVehicle,
        $slug,
    );
    $isActive = isset($submittedFields["is_active"]) ? 1 : 0;
    if (!$isActive) {
        $availabilityStatus = "unavailable";
    }

    return [
        "slug" => $slug,
        "name" => $name,
        "brand" => $brand,
        "category" => $category,
        "image" => $imageFilename,
        "price" => $numericValues["price"],
        "deposit" => $numericValues["deposit"],
        "seats" => $numericValues["seats"],
        "doors" => $numericValues["doors"],
        "luggage" => $luggage,
        "transmission" => $transmission,
        "fuel" => $fuel,
        "daily_km" => $numericValues["daily_km"],
        "overview_title" => $overviewTitle,
        "overview" => $overview,
        "description" => $description,
        "inclusions" => $inclusions,
        "features" => $features,
        "is_active" => $isActive,
        "availability_status" => $availabilityStatus,
        "updated_at" => date("Y-m-d H:i:s"),
    ];
}

/**
 * @param array<string, mixed> $submittedFields
 * @return array{price: int, deposit: int, seats: int, doors: int, daily_km: int}
 * @throws AdminVehicleValidationException
 */
function admin_vehicle_validate_numeric_fields(array $submittedFields): array
{
    $numericFieldRanges = [
        "price" => [500, 100000],
        "deposit" => [0, 100000],
        "seats" => [1, 30],
        "doors" => [1, 8],
        "daily_km" => [0, 2000],
    ];
    $numericValues = [];

    foreach ($numericFieldRanges as $fieldName => [$minimum, $maximum]) {
        $validatedValue = filter_var(
            $submittedFields[$fieldName] ?? null,
            FILTER_VALIDATE_INT,
            ["options" => ["min_range" => $minimum, "max_range" => $maximum]],
        );
        if ($validatedValue === false) {
            throw new AdminVehicleValidationException(
                "Enter valid numeric vehicle values.",
            );
        }
        $numericValues[$fieldName] = (int) $validatedValue;
    }

    /** @var array{price: int, deposit: int, seats: int, doors: int, daily_km: int} $numericValues */
    return $numericValues;
}

/**
 * @param array<string, mixed> $submittedFields
 * @param array<string, mixed> $uploadedFiles
 * @param array<string, mixed>|null $existingVehicle
 * @throws AdminVehicleValidationException
 */
function admin_vehicle_image_filename(
    array $submittedFields,
    array $uploadedFiles,
    ?array $existingVehicle,
    string $slug,
): string {
    $imageFilename = basename(
        admin_vehicle_text_field(
            $submittedFields,
            "image_filename",
            (string) ($existingVehicle["image"] ?? ""),
        ),
    );

    $uploadedImage = $uploadedFiles["vehicle_image"] ?? null;
    if (
        is_array($uploadedImage) &&
        ($uploadedImage["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
    ) {
        try {
            /** @var array{error?: int, size?: int, tmp_name?: string, name?: string, type?: string} $uploadedImage */
            $imageFilename = upload_file(
                $uploadedImage,
                ROOT . "/assets/images/cars",
                [
                    "image/png" => "png",
                    "image/jpeg" => "jpg",
                    "image/webp" => "webp",
                ],
                8 * 1024 * 1024,
                $slug,
            );
        } catch (RuntimeException $uploadError) {
            throw new AdminVehicleValidationException(
                $uploadError->getMessage(),
                0,
                $uploadError,
            );
        }
    }

    if (
        $imageFilename === "" ||
        !is_file(ROOT . "/assets/images/cars/" . $imageFilename)
    ) {
        throw new AdminVehicleValidationException(
            "Choose an uploaded image or enter an existing filename from assets/images/cars.",
        );
    }

    return $imageFilename;
}

/**
 * @param array<string, mixed> $submittedFields
 */
function admin_vehicle_text_field(
    array $submittedFields,
    string $fieldName,
    string $defaultValue = "",
): string {
    $submittedValue = $submittedFields[$fieldName] ?? $defaultValue;
    return is_scalar($submittedValue) ? trim((string) $submittedValue) : "";
}

/**
 * @return list<string>
 */
function admin_vehicle_multiline_values(string $submittedValue): array
{
    return array_values(
        array_filter(
            array_map("trim", preg_split("/\R/", $submittedValue) ?: []),
        ),
    );
}

/**
 * @throws AdminVehicleValidationException
 */
function admin_vehicle_assert_unique_slug(
    PDO $databaseConnection,
    string $slug,
    int $vehicleId,
): void {
    $duplicateSlugStatement = $databaseConnection->prepare(
        "SELECT COUNT(*) FROM vehicles WHERE slug = :slug AND id <> :vehicle_id",
    );
    $duplicateSlugStatement->execute([
        "slug" => $slug,
        "vehicle_id" => $vehicleId,
    ]);

    if ((int) $duplicateSlugStatement->fetchColumn() > 0) {
        throw new AdminVehicleValidationException(
            "That vehicle slug is already used.",
        );
    }
}

/**
 * @param array{
 *     slug: string, name: string, brand: string, category: string,
 *     image: string, price: int, deposit: int, seats: int, doors: int,
 *     luggage: string, transmission: string, fuel: string, daily_km: int,
 *     overview_title: string, overview: string, description: string,
 *     inclusions: list<string>, features: list<string>, is_active: int,
 *     availability_status: string, updated_at: string
 * } $vehicle
 */
function admin_vehicle_update(
    PDO $databaseConnection,
    int $vehicleId,
    array $vehicle,
): void {
    $updateVehicleStatement = $databaseConnection->prepare(
        <<<'SQL'
        UPDATE vehicles
        SET
            slug = :slug,
            name = :name,
            brand = :brand,
            category = :category,
            image = :image,
            price = :price,
            deposit = :deposit,
            seats = :seats,
            doors = :doors,
            luggage = :luggage,
            transmission = :transmission,
            fuel = :fuel,
            daily_km = :daily_km,
            overview_title = :overview_title,
            overview = :overview,
            description = :description,
            inclusions = :inclusions,
            features = :features,
            is_active = :is_active,
            availability_status = :availability_status,
            updated_at = :updated_at
        WHERE id = :vehicle_id
        SQL,
    );
    $updateVehicleStatement->execute(
        admin_vehicle_database_parameters($vehicle) + [
            "vehicle_id" => $vehicleId,
        ],
    );
}

/**
 * @param array{
 *     slug: string, name: string, brand: string, category: string,
 *     image: string, price: int, deposit: int, seats: int, doors: int,
 *     luggage: string, transmission: string, fuel: string, daily_km: int,
 *     overview_title: string, overview: string, description: string,
 *     inclusions: list<string>, features: list<string>, is_active: int,
 *     availability_status: string, updated_at: string
 * } $vehicle
 */
function admin_vehicle_insert(PDO $databaseConnection, array $vehicle): int
{
    $insertVehicleStatement = $databaseConnection->prepare(
        <<<'SQL'
        INSERT INTO vehicles (
            slug,
            name,
            brand,
            category,
            image,
            price,
            deposit,
            seats,
            doors,
            luggage,
            transmission,
            fuel,
            daily_km,
            overview_title,
            overview,
            description,
            inclusions,
            features,
            is_active,
            availability_status,
            updated_at,
            created_at
        ) VALUES (
            :slug,
            :name,
            :brand,
            :category,
            :image,
            :price,
            :deposit,
            :seats,
            :doors,
            :luggage,
            :transmission,
            :fuel,
            :daily_km,
            :overview_title,
            :overview,
            :description,
            :inclusions,
            :features,
            :is_active,
            :availability_status,
            :updated_at,
            :created_at
        )
        SQL,
    );
    $insertVehicleStatement->execute(
        admin_vehicle_database_parameters($vehicle) + [
            "created_at" => $vehicle["updated_at"],
        ],
    );

    return (int) $databaseConnection->lastInsertId();
}

/**
 * Convert validated domain values to the scalar values bound by PDO.
 *
 * @param array{
 *     slug: string, name: string, brand: string, category: string,
 *     image: string, price: int, deposit: int, seats: int, doors: int,
 *     luggage: string, transmission: string, fuel: string, daily_km: int,
 *     overview_title: string, overview: string, description: string,
 *     inclusions: list<string>, features: list<string>, is_active: int,
 *     availability_status: string, updated_at: string
 * } $vehicle
 * @return array<string, int|string>
 */
function admin_vehicle_database_parameters(array $vehicle): array
{
    return [
        "slug" => $vehicle["slug"],
        "name" => $vehicle["name"],
        "brand" => $vehicle["brand"],
        "category" => $vehicle["category"],
        "image" => $vehicle["image"],
        "price" => $vehicle["price"],
        "deposit" => $vehicle["deposit"],
        "seats" => $vehicle["seats"],
        "doors" => $vehicle["doors"],
        "luggage" => $vehicle["luggage"],
        "transmission" => $vehicle["transmission"],
        "fuel" => $vehicle["fuel"],
        "daily_km" => $vehicle["daily_km"],
        "overview_title" => $vehicle["overview_title"],
        "overview" => $vehicle["overview"],
        "description" => $vehicle["description"],
        "inclusions" => json_encode(
            $vehicle["inclusions"],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ),
        "features" => json_encode(
            $vehicle["features"],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ),
        "is_active" => $vehicle["is_active"],
        "availability_status" => $vehicle["availability_status"],
        "updated_at" => $vehicle["updated_at"],
    ];
}
