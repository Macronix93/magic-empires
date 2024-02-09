<?php
require_once("functions.php");
global $db_instance;

$stmt = $db_instance->prepare("SELECT id, maxwood, wood, woodperhour, maxfood, food, foodperhour, maxstone, stone, stoneperhour, maxgold, gold, goldperhour, maxvillager, villager, villagerperhour FROM kingdoms");
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $resources = array("wood", "food", "stone", "gold", "villager");

    foreach ($resources as $resource) {
        $newResource = $row[$resource] + $row["{$resource}perhour"];
        $maxResource = $row["max{$resource}"];

        // Check if the new resource exceeds the maximum limit and adjust if necessary
        if ($newResource > $maxResource) {
            $newResource = $maxResource;
        }

        $row[$resource] = $newResource;
    }

    // Update the kingdom's resources with the adjusted values
    $stmt = $db_instance->prepare("UPDATE kingdoms SET wood = ?, food = ?, stone = ?, gold = ?, villager = ? WHERE id = ?");
    $stmt->bind_param("iiiiii", $row["wood"], $row["food"], $row["stone"], $row["gold"], $row["villager"], $row["id"]);
    $stmt->execute();
}

$stmt->close();