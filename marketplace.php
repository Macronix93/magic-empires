<?php
require_once("includes/core.php");

$result = check_user_login_and_kingdom($user, $db_instance, BuildingTypes::BUILDING_MARKETPLACE);

$current_kingdom = $result['current_kingdom'];
$building = $result['building'];
$building_name = $building->get_building_name();
$kingdom = $result['kingdom'];

if (isset($_GET["accept"])) {
    $result = $db_instance->execute_query("SELECT username, kingdomid, supply, supplyvalue, demand, demandvalue FROM marketplace WHERE offerid = ?", [$_GET["accept"]]);
    $row = $result->fetch_assoc();

    if ($row && $current_kingdom != $row["kingdomid"]) {
        $supply = $row["supply"];
        $supply_value = $row["supplyvalue"];
        $demand = $row["demand"];
        $demand_value = $row["demandvalue"];

        // Check if kingdom has enough resources to handle the trade
        if ($demand == 0 && $kingdom->get_kingdom_food() < $demand_value) {
            $error = "Soviel Nahrung kannst du nicht aufbringen!";
        } else if ($demand == 1 && $kingdom->get_kingdom_wood() < $demand_value) {
            $error = "Soviel Holz kannst du nicht aufbringen!";
        } else if ($demand == 2 && $kingdom->get_kingdom_stone() < $demand_value) {
            $error = "Soviel Stein kannst du nicht aufbringen!";
        } else if ($demand == 3 && $kingdom->get_kingdom_gold() < $demand_value) {
            $error = "Soviel Gold kannst du nicht aufbringen!";
        } else {
            $other_kingdom = new Kingdoms($db_instance);
            $other_kingdom->get_kingdom_info($row["kingdomid"]);

            $supply_resource = "";
            $demand_resource = "";

            // Give both kingdoms the respective resources
            switch ($supply) {
                case 0:
                    $kingdom->give_kingdom_food($supply_value);
                    $supply_resource = "Nahrung";
                    break;
                case 1:
                    $kingdom->give_kingdom_wood($supply_value);
                    $supply_resource = "Holz";
                    break;
                case 2:
                    $kingdom->give_kingdom_stone($supply_value);
                    $supply_resource = "Stein";
                    break;
                case 3:
                    $kingdom->give_kingdom_gold($supply_value);
                    $supply_resource = "Gold";
                    break;
            }
            switch ($demand) {
                case 0:
                    $kingdom->give_kingdom_food(-$demand_value);
                    $other_kingdom->give_kingdom_food($demand_value);
                    $demand_resource = "Nahrung";
                    break;
                case 1:
                    $kingdom->give_kingdom_wood(-$demand_value);
                    $other_kingdom->give_kingdom_wood($demand_value);
                    $demand_resource = "Holz";
                    break;
                case 2:
                    $kingdom->give_kingdom_stone(-$demand_value);
                    $other_kingdom->give_kingdom_stone($demand_value);
                    $demand_resource = "Stein";
                    break;
                case 3:
                    $kingdom->give_kingdom_gold(-$demand_value);
                    $other_kingdom->give_kingdom_gold($demand_value);
                    $demand_resource = "Gold";
                    break;
            }

            // Delete the marketplace offer
            $db_instance->execute_query("DELETE FROM marketplace WHERE offerid = ?", [$_GET["accept"]]);

            //TODO: Send a message to the other kingdom that the offer has been accepted
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

        // Give supply ressources back to kingdom
        switch ($supply) {
            case 0:
                $kingdom->give_kingdom_food($supply_value);
                break;
            case 1:
                $kingdom->give_kingdom_wood($supply_value);
                break;
            case 2:
                $kingdom->give_kingdom_stone($supply_value);
                break;
            case 3:
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
            if ($supply == 0 && $kingdom->get_kingdom_food() < $supply_value) {
                $error = "Soviel Nahrung kannst du nicht bieten!";
            } else if ($supply == 1 && $kingdom->get_kingdom_wood() < $supply_value) {
                $error = "Soviel Holz kannst du nicht bieten!";
            } else if ($supply == 2 && $kingdom->get_kingdom_stone() < $supply_value) {
                $error = "Soviel Stein kannst du nicht bieten!";
            } else if ($supply == 3 && $kingdom->get_kingdom_gold() < $supply_value) {
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
                        case 0:
                            $kingdom->give_kingdom_food(-$supply_value);
                            break;
                        case 1:
                            $kingdom->give_kingdom_wood(-$supply_value);
                            break;
                        case 2:
                            $kingdom->give_kingdom_stone(-$supply_value);
                            break;
                        case 3:
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
                <select name="s">
                    <option value="0">Nahrung</option>
                    <option value="1">Holz</option>
                    <option value="2">Stein</option>
                    <option value="3">Gold</option>
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
                <select name="d">
                    <option value="0">Nahrung</option>
                    <option value="1">Holz</option>
                    <option value="2">Stein</option>
                    <option value="3">Gold</option>
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

$resource_icon = array(
    "<img src='images/icons/icon_meat.png' class='ressource-icons' alt='Nahrung' title='Nahrung'/>",
    "<img src='images/icons/icon_wood.png' class='ressource-icons' alt='Holz' title='Holz'/>",
    "<img src='images/icons/icon_stone.png' class='ressource-icons' alt='Stein' title='Stein'/>",
    "<img src='images/icons/icon_gold.png' class='ressource-icons' alt='Gold' title='Gold'/>"
);

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
                <td class='td-center'>{$resource_icon[$row["supply"]]} " . fnum($row["supplyvalue"]) . "</td>
                <td class='td-center'>{$resource_icon[$row["demand"]]} " . fnum($row["demandvalue"]) . "</td>
                <td class='td-center'>$text_build</td>
                </tr>";
}
$view .= '</table>';


/*
 * HTML Section
 */
$title = $building_name;
$header = $building_name . " (" . $building->get_building_level() . ")";
$script_files = [];

if (!empty($error)) {
    $view = show_error_box($error) . $view;
}

include('layout/base.php');