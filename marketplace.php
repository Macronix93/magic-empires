<?php
require_once("includes/core.php");

$result = check_user_login_and_kingdom($user, $db_instance, BuildingTypes::BUILDING_MARKETPLACE);

$current_kingdom = $result['current_kingdom'];
$building = $result['building'];
$building_name = $building->get_building_name();
$kingdom = $result['kingdom'];

$default_supply = ResourceTypes::RESOURCE_TYPE_FOOD; // Nahrung
$default_demand = ResourceTypes::RESOURCE_TYPE_WOOD; // Holz

if (isset($_GET["accept"])) {
    $result = $db_instance->execute_query("SELECT username, kingdomid, supply, supplyvalue, demand, demandvalue FROM marketplace WHERE offerid = ?", [$_GET["accept"]]);
    $row = $result->fetch_assoc();

    if ($row && $current_kingdom != $row["kingdomid"]) {
        $supply = $row["supply"];
        $supply_value = $row["supplyvalue"];
        $demand = $row["demand"];
        $demand_value = $row["demandvalue"];

        // Check if kingdom has enough resources to handle the trade
        if ($demand == ResourceTypes::RESOURCE_TYPE_FOOD && $kingdom->get_kingdom_food() < $demand_value) {
            $error = "Soviel Nahrung kannst du nicht aufbringen!";
        } else if ($demand == ResourceTypes::RESOURCE_TYPE_WOOD && $kingdom->get_kingdom_wood() < $demand_value) {
            $error = "Soviel Holz kannst du nicht aufbringen!";
        } else if ($demand == ResourceTypes::RESOURCE_TYPE_STONE && $kingdom->get_kingdom_stone() < $demand_value) {
            $error = "Soviel Stein kannst du nicht aufbringen!";
        } else if ($demand == ResourceTypes::RESOURCE_TYPE_GOLD && $kingdom->get_kingdom_gold() < $demand_value) {
            $error = "Soviel Gold kannst du nicht aufbringen!";
        } else {
            $other_kingdom = new Kingdoms($db_instance, $row["kingdomid"]);

            // Give both kingdoms the respective resources
            switch ($supply) {
                case ResourceTypes::RESOURCE_TYPE_FOOD:
                    $kingdom->give_kingdom_food($supply_value);
                    break;
                case ResourceTypes::RESOURCE_TYPE_WOOD:
                    $kingdom->give_kingdom_wood($supply_value);
                    break;
                case ResourceTypes::RESOURCE_TYPE_STONE:
                    $kingdom->give_kingdom_stone($supply_value);
                    break;
                case ResourceTypes::RESOURCE_TYPE_GOLD:
                    $kingdom->give_kingdom_gold($supply_value);
                    break;
            }
            switch ($demand) {
                case ResourceTypes::RESOURCE_TYPE_FOOD:
                    $kingdom->give_kingdom_food(-$demand_value);
                    $other_kingdom->give_kingdom_food($demand_value);
                    break;
                case ResourceTypes::RESOURCE_TYPE_WOOD:
                    $kingdom->give_kingdom_wood(-$demand_value);
                    $other_kingdom->give_kingdom_wood($demand_value);
                    break;
                case ResourceTypes::RESOURCE_TYPE_STONE:
                    $kingdom->give_kingdom_stone(-$demand_value);
                    $other_kingdom->give_kingdom_stone($demand_value);
                    break;
                case ResourceTypes::RESOURCE_TYPE_GOLD:
                    $kingdom->give_kingdom_gold(-$demand_value);
                    $other_kingdom->give_kingdom_gold($demand_value);
                    break;
            }

            // Delete the marketplace offer
            $db_instance->execute_query("DELETE FROM marketplace WHERE offerid = ?", [$_GET["accept"]]);

            // Send a message to the other user that the offer has been accepted
            $message = "Dein Angebot</br></br>" . get_resource_icon($supply) . " $supply_value  gegen " . get_resource_icon($demand) . " $demand_value </br></br>
                        wurde vom Spieler " . $kingdom->get_kingdom_owner_name() . " (Königreich: " . $kingdom->get_kingdom_name() . ") 
                        angenommen!";
            send_server_message($other_kingdom->get_kingdom_owner_id(), $other_kingdom->get_kingdom_owner_name(), $message, MessageCategories::CATEGORY_TRADE);
        }
    } else {
        $error = "Dieses Angebot existiert nicht oder ist von deinem Königreich!";
    }
} else if (isset($_GET["delete"])) {
    $result = $db_instance->execute_query("SELECT supply, supplyvalue FROM marketplace WHERE offerid = ? AND kingdomid = ?", [$_GET["delete"], $current_kingdom]);
    $row = $result->fetch_assoc();

    if ($row) {
        $supply = $row["supply"];
        $supply_value = $row["supplyvalue"];

        // Give supply resources back to kingdom
        switch ($supply) {
            case ResourceTypes::RESOURCE_TYPE_FOOD:
                $kingdom->give_kingdom_food($supply_value);
                break;
            case ResourceTypes::RESOURCE_TYPE_WOOD:
                $kingdom->give_kingdom_wood($supply_value);
                break;
            case ResourceTypes::RESOURCE_TYPE_STONE:
                $kingdom->give_kingdom_stone($supply_value);
                break;
            case ResourceTypes::RESOURCE_TYPE_GOLD:
                $kingdom->give_kingdom_gold($supply_value);
                break;
        }

        // Delete the marketplace offer
        $db_instance->execute_query("DELETE FROM marketplace WHERE offerid = ?", [$_GET["delete"]]);
    } else {
        $error = "Dieses Angebot existiert nicht oder ist nicht von deinem aktuellen Königreich!";
    }
} else if (!empty($_GET["sv"]) && !empty($_GET["dv"])) {
    $supply_value = $_GET["sv"];
    $demand_value = $_GET["dv"];
    $supply = $_GET["s"];
    $demand = $_GET["d"];

    if ($supply < 0 || $supply > 3 || $demand < 0 || $demand > 3) {
        $error = "Diese Ressource gibt es nicht!";
    } else if ($supply == $demand) {
        $error = "Die Ressourcentypen dürfen nicht gleich sein!";
    } else {
        if ($supply_value <= 0 || !is_numeric($supply_value) || $demand_value <= 0 || !is_numeric($demand_value) || $supply_value > 99999 || $demand_value > 99999) {
            $error = "Die Werte müssen zwischen 1 und 99999 liegen!";
        } else {
            // Check if kingdom has enough ressources to handle the trade
            if ($supply == ResourceTypes::RESOURCE_TYPE_FOOD && $kingdom->get_kingdom_food() < $supply_value) {
                $error = "Soviel Nahrung kannst du nicht bieten!";
            } else if ($supply == ResourceTypes::RESOURCE_TYPE_WOOD && $kingdom->get_kingdom_wood() < $supply_value) {
                $error = "Soviel Holz kannst du nicht bieten!";
            } else if ($supply == ResourceTypes::RESOURCE_TYPE_STONE && $kingdom->get_kingdom_stone() < $supply_value) {
                $error = "Soviel Stein kannst du nicht bieten!";
            } else if ($supply == ResourceTypes::RESOURCE_TYPE_GOLD && $kingdom->get_kingdom_gold() < $supply_value) {
                $error = "Soviel Gold kannst du nicht bieten!";
            } else {
                // Check if there is already an offer for this kingdom
                $result = $db_instance->execute_query("SELECT offerid FROM marketplace WHERE kingdomid = ?", [$current_kingdom]);
                $offer_id = $result->fetch_assoc()['offerid'] ?? 0;

                if ($offer_id != 0) {
                    $error = "Du hast bereits ein Angebot für dieses Königreich am laufen!";
                } else {
                    // No offer found for the kingdom - insert to database
                    $query = "INSERT INTO marketplace (userid, username, kingdomid, supply, supplyvalue, demand, demandvalue) VALUES(?, ?, ?, ?, ?, ?, ?);";
                    $result = $db_instance->execute_query($query, [$user->get_user_id(), $user->get_user_name(), $current_kingdom, $supply, $supply_value, $demand, $demand_value]);

                    switch ($supply) {
                        case ResourceTypes::RESOURCE_TYPE_FOOD:
                            $kingdom->give_kingdom_food(-$supply_value);
                            break;
                        case ResourceTypes::RESOURCE_TYPE_WOOD:
                            $kingdom->give_kingdom_wood(-$supply_value);
                            break;
                        case ResourceTypes::RESOURCE_TYPE_STONE:
                            $kingdom->give_kingdom_stone(-$supply_value);
                            break;
                        case ResourceTypes::RESOURCE_TYPE_GOLD:
                            $kingdom->give_kingdom_gold(-$supply_value);
                            break;
                    }
                }
            }
        }
    }
}

/*
 * HTML Content Part
 */
$view .= '<table class="table">
<form action="marketplace.php" method="GET">
    <tr>
        <td>
            <label for="sv">Ich biete:</label>
            <input type="text"
                   name="sv"
                   id="sv"
                   size="5"
                   maxlength="5">
            <label>
                <select name="s" id="s">
                    <option value="' . ResourceTypes::RESOURCE_TYPE_FOOD . '">Nahrung</option>
                    <option value="' . ResourceTypes::RESOURCE_TYPE_WOOD . '">Holz</option>
                    <option value="' . ResourceTypes::RESOURCE_TYPE_STONE . '">Stein</option>
                    <option value="' . ResourceTypes::RESOURCE_TYPE_GOLD . '">Gold</option>
                </select>
            </label>
        </td>
        <td>
            <label for="dv">Ich suche:</label>
            <input type="text"
                   name="dv"
                   id="dv"
                   size="5"
                   maxlength="5">
            <label>
                <select name="d" id="d">
                    <option value="' . ResourceTypes::RESOURCE_TYPE_FOOD . '">Nahrung</option>
                    <option value="' . ResourceTypes::RESOURCE_TYPE_WOOD . '" selected>Holz</option>
                    <option value="' . ResourceTypes::RESOURCE_TYPE_STONE . '">Stein</option>
                    <option value="' . ResourceTypes::RESOURCE_TYPE_GOLD . '">Gold</option>
                </select>
            </label>
        </td>
        <td>
            <input type="submit" value="Abschicken">
        </td>
    </tr>
</form>
</table>
<br>
<br>
<table class="table" style="word-break: break-word">
<tr>
    <td class="td-center td-gradient">
        <b>Spieler</b>
    </td>
    <td class="td-center td-gradient">
        <b>Königreich</b>
    </td>
    <td class="td-center td-gradient">
        <b>Bietet</b>
    </td>
    <td class="td-center td-gradient">
        <b>Benötigt</b>
    </td>
    <td class="td-center td-gradient">
        <b>Aktion</b>
    </td>
</tr>';

$view .= "<p>Aktuelle Angebote</p>";

$query = "
            SELECT m.*, k.kingdomname 
            FROM marketplace m 
            LEFT JOIN kingdoms k 
            ON m.kingdomid = k.id
";
$result = $db_instance->execute_query($query);

foreach ($result as $row) {
    $kingdom_name = $row['kingdomname'];

    $action = ($row["kingdomid"] == $current_kingdom) ? "Löschen" : "Annehmen";
    $param = ($row["kingdomid"] == $current_kingdom) ? "delete" : "accept";

    $text_build = "<form action='marketplace.php' method='GET'>
                    <input type='hidden' name='$param' value='" . $row["offerid"] . "'>
                    <input type='submit' value='$action'>
                </form>";

    $view .= "<tr><td>{$row["username"]}</td>
                <td>$kingdom_name</td>
                <td class='td-center'>" . get_resource_icon($row["supply"]) . " " . fnum($row["supplyvalue"]) . "</td>
                <td class='td-center'>" . get_resource_icon($row["demand"]) . " " . fnum($row["demandvalue"]) . "</td>
                <td class='td-center'>$text_build</td>
                </tr>";
}
$view .= '</table>';


/*
 * HTML Section
 */
$title = $building_name;
$header = $building_name . " (" . $building->get_building_level() . ")";
$script_files = ["marketplace"];

if (!empty($error)) {
    $view = show_error_box($error) . $view;
}

include('layout/base.php');