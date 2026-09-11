<?php

declare(strict_types=1);

final class VehicleValidationException extends InvalidArgumentException
{
}

/** @return array{vehicle_id:int, created:bool} */
function vehicle_save_submission(array $submittedFields, array $uploadedFiles): array
{
    require_csrf();

    $vehicleId = (int) ($submittedFields["id"] ?? 0);
    $existingVehicle = $vehicleId !== 0 ? vehicle_find($vehicleId) : null;
    if ($vehicleId !== 0 && !$existingVehicle) {
        throw new VehicleValidationException("Vehicle not found.");
    }

    $validatedVehicle = vehicle_validate_submission(
        $submittedFields,
        $uploadedFiles,
        $existingVehicle,
    );
    $databaseConnection = database();
    vehicle_assert_unique_slug($databaseConnection, $validatedVehicle["slug"], $vehicleId);

    if ($existingVehicle) {
        vehicle_update($databaseConnection, $vehicleId, $validatedVehicle);
        return ["vehicle_id" => $vehicleId, "created" => false];
    }

    return [
        "vehicle_id" => vehicle_insert($databaseConnection, $validatedVehicle),
        "created" => true,
    ];
}

/** @return array<string,mixed> */
function vehicle_validate_submission(
    array $submittedFields,
    array $uploadedFiles,
    ?array $existingVehicle,
): array {
    $slug = strtolower(vehicle_text_field($submittedFields, "slug"));
    $name = vehicle_text_field($submittedFields, "name");
    $brand = vehicle_text_field($submittedFields, "brand");
    $category = vehicle_text_field($submittedFields, "category");
    $transmission = vehicle_text_field($submittedFields, "transmission");
    $fuel = vehicle_text_field($submittedFields, "fuel");

    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        throw new VehicleValidationException(
            "Use a lowercase URL slug with letters, numbers, and hyphens.",
        );
    }
    if (strlen($name) < 2 || strlen($name) > 180 || strlen($brand) < 2 || strlen($brand) > 100) {
        throw new VehicleValidationException("Enter a valid vehicle name and brand.");
    }
    if (!in_array($category, ["Luxury", "SUV", "Pickup", "Van", "EV"], true)) {
        throw new VehicleValidationException("Choose a valid category.");
    }
    if (!in_array($transmission, ["Automatic", "Manual"], true)) {
        throw new VehicleValidationException("Choose a valid transmission.");
    }
    if (!in_array($fuel, ["Gasoline", "Diesel", "Electric", "Hybrid"], true)) {
        throw new VehicleValidationException("Choose a valid fuel type.");
    }

    $numericValues = vehicle_validate_numeric_fields($submittedFields);
    $luggage = vehicle_text_field($submittedFields, "luggage");
    $overviewTitle = vehicle_text_field($submittedFields, "overview_title");
    $overview = vehicle_text_field($submittedFields, "overview");
    $description = vehicle_text_field($submittedFields, "description");
    if ($luggage === "" || strlen($overviewTitle) < 5 || strlen($overview) < 30 || strlen($description) < 20) {
        throw new VehicleValidationException(
            "Complete the luggage, overview, and description fields.",
        );
    }

    $inclusions = vehicle_multiline_values(vehicle_text_field($submittedFields, "inclusions"));
    $features = vehicle_multiline_values(vehicle_text_field($submittedFields, "features"));
    if (!$inclusions || !$features) {
        throw new VehicleValidationException("Add at least one inclusion and one feature.");
    }

    $imageFilename = vehicle_image_filename(
        $submittedFields,
        $uploadedFiles,
        $existingVehicle,
        $slug,
    );

    return [
        "slug" => $slug,
        "name" => $name,
        "brand" => $brand,
        "category" => $category,
        "image" => $imageFilename,
        "price" => $numericValues["price"],
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
        "updated_at" => date("Y-m-d H:i:s"),
    ];
}

/** @return array{price:int,seats:int,doors:int,daily_km:int} */
function vehicle_validate_numeric_fields(array $submittedFields): array
{
    $numericFieldRanges = [
        "price" => [500, 100000],
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
            throw new VehicleValidationException("Enter valid numeric vehicle values.");
        }
        $numericValues[$fieldName] = (int) $validatedValue;
    }
    return $numericValues;
}

function vehicle_image_filename(
    array $submittedFields,
    array $uploadedFiles,
    ?array $existingVehicle,
    string $slug,
): string {
    $imageFilename = basename(
        vehicle_text_field(
            $submittedFields,
            "image_filename",
            (string) ($existingVehicle["image"] ?? ""),
        ),
    );

    $uploadedImage = $uploadedFiles["vehicle_image"] ?? null;
    if (is_array($uploadedImage) && ($uploadedImage["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        try {
            $imageFilename = upload_file(
                $uploadedImage,
                ROOT . "/assets/images/cars",
                ["image/png" => "png", "image/jpeg" => "jpg", "image/webp" => "webp"],
                8 * 1024 * 1024,
                $slug,
            );
        } catch (RuntimeException $uploadError) {
            throw new VehicleValidationException($uploadError->getMessage(), 0, $uploadError);
        }
    }

    if ($imageFilename === "" || !is_file(ROOT . "/assets/images/cars/" . $imageFilename)) {
        throw new VehicleValidationException(
            "Choose an uploaded image or enter an existing filename from assets/images/cars.",
        );
    }
    return $imageFilename;
}

function vehicle_text_field(array $submittedFields, string $fieldName, string $defaultValue = ""): string
{
    $submittedValue = $submittedFields[$fieldName] ?? $defaultValue;
    return is_scalar($submittedValue) ? trim((string) $submittedValue) : "";
}

/** @return list<string> */
function vehicle_multiline_values(string $submittedValue): array
{
    return array_values(
        array_filter(array_map("trim", preg_split("/\R/", $submittedValue) ?: [])),
    );
}

function vehicle_assert_unique_slug(PDO $databaseConnection, string $slug, int $vehicleId): void
{
    $duplicateSlugStatement = $databaseConnection->prepare(
        "SELECT COUNT(*) FROM vehicles WHERE slug = :slug AND id <> :vehicle_id",
    );
    $duplicateSlugStatement->execute(["slug" => $slug, "vehicle_id" => $vehicleId]);
    if ((int) $duplicateSlugStatement->fetchColumn() > 0) {
        throw new VehicleValidationException("That vehicle slug is already used.");
    }
}

function vehicle_update(PDO $databaseConnection, int $vehicleId, array $vehicle): void
{
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
            updated_at = :updated_at
        WHERE id = :vehicle_id
        SQL,
    );
    $updateVehicleStatement->execute(vehicle_database_parameters($vehicle) + ["vehicle_id" => $vehicleId]);
}

function vehicle_insert(PDO $databaseConnection, array $vehicle): int
{
    $insertVehicleStatement = $databaseConnection->prepare(
        <<<'SQL'
        INSERT INTO vehicles (
            slug, name, brand, category, image, price, seats, doors, luggage,
            transmission, fuel, daily_km, overview_title, overview, description,
            inclusions, features, updated_at, created_at
        ) VALUES (
            :slug, :name, :brand, :category, :image, :price, :seats, :doors, :luggage,
            :transmission, :fuel, :daily_km, :overview_title, :overview, :description,
            :inclusions, :features, :updated_at, :created_at
        )
        SQL,
    );
    $insertVehicleStatement->execute(
        vehicle_database_parameters($vehicle) + ["created_at" => $vehicle["updated_at"]],
    );
    return (int) $databaseConnection->lastInsertId();
}

/** @return array<string,int|string> */
function vehicle_database_parameters(array $vehicle): array
{
    return [
        "slug" => $vehicle["slug"],
        "name" => $vehicle["name"],
        "brand" => $vehicle["brand"],
        "category" => $vehicle["category"],
        "image" => $vehicle["image"],
        "price" => $vehicle["price"],
        "seats" => $vehicle["seats"],
        "doors" => $vehicle["doors"],
        "luggage" => $vehicle["luggage"],
        "transmission" => $vehicle["transmission"],
        "fuel" => $vehicle["fuel"],
        "daily_km" => $vehicle["daily_km"],
        "overview_title" => $vehicle["overview_title"],
        "overview" => $vehicle["overview"],
        "description" => $vehicle["description"],
        "inclusions" => json_encode($vehicle["inclusions"], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        "features" => json_encode($vehicle["features"], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        "updated_at" => $vehicle["updated_at"],
    ];
}

function vehicle_delete(PDO $databaseConnection, int $vehicleId): void
{
    $find = $databaseConnection->prepare(
        "SELECT id, name FROM vehicles WHERE id = :id LIMIT 1",
    );
    $find->execute(["id" => $vehicleId]);
    $vehicle = $find->fetch();
    if (!$vehicle) {
        throw new VehicleValidationException("Vehicle not found.");
    }

    $delete = $databaseConnection->prepare("DELETE FROM vehicles WHERE id = :id");
    $delete->execute(["id" => $vehicleId]);
    if ($delete->rowCount() !== 1) {
        throw new RuntimeException("The vehicle could not be deleted.");
    }
}
