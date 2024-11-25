<?php
require_once("includes/core.php");

if (!($user->is_logged_in())) {
    change_location("login.php");
    exit;
}

$current_kingdom = $user->get_current_kingdom();
$building = fetch_kingdom_building($current_kingdom, BuildingTypes::BUILDING_MARKETPLACE);
$building_name = $building->get_building_name();

if (!$building->is_built()) {
    change_location("towncenter.php");
    exit;
}

$kingdom = new Kingdoms($db_instance);
$kingdom->get_kingdom_info($current_kingdom);

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
            $other_kingdom_id = $row["kingdomid"];
            $other_kingdom = new Kingdoms($db_instance);
            $other_kingdom->get_kingdom_info($other_kingdom_id);

            $supply_resource = "";
            $demand_resource = "";

            // Give both kingdoms the respective resources
            switch ($supply) {
                case 0:
                    $kingdom->give_kingdom_food($current_kingdom, $supply_value);
                    $supply_resource = "Nahrung";
                    break;
                case 1:
                    $kingdom->give_kingdom_wood($current_kingdom, $supply_value);
                    $supply_resource = "Holz";
                    break;
                case 2:
                    $kingdom->give_kingdom_stone($current_kingdom, $supply_value);
                    $supply_resource = "Stein";
                    break;
                case 3:
                    $kingdom->give_kingdom_gold($current_kingdom, $supply_value);
                    $supply_resource = "Gold";
                    break;
            }
            switch ($demand) {
                case 0:
                    $kingdom->give_kingdom_food($current_kingdom, -$demand_value);
                    $other_kingdom->give_kingdom_food($other_kingdom_id, $demand_value);
                    $demand_resource = "Nahrung";
                    break;
                case 1:
                    $kingdom->give_kingdom_wood($current_kingdom, -$demand_value);
                    $other_kingdom->give_kingdom_wood($other_kingdom_id, $demand_value);
                    $demand_resource = "Holz";
                    break;
                case 2:
                    $kingdom->give_kingdom_stone($current_kingdom, -$demand_value);
                    $other_kingdom->give_kingdom_stone($other_kingdom_id, $demand_value);
                    $demand_resource = "Stein";
                    break;
                case 3:
                    $kingdom->give_kingdom_gold($current_kingdom, -$demand_value);
                    $other_kingdom->give_kingdom_gold($other_kingdom_id, $demand_value);
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
                $kingdom->give_kingdom_food($current_kingdom, $supply_value);
                break;
            case 1:
                $kingdom->give_kingdom_wood($current_kingdom, $supply_value);
                break;
            case 2:
                $kingdom->give_kingdom_stone($current_kingdom, $supply_value);
                break;
            case 3:
                $kingdom->give_kingdom_gold($current_kingdom, $supply_value);
                break;
        }

        // Delete the marketplace offer
        $db_instance->execute_query("DELETE FROM marketplace WHERE offerid = ?", [$_GET["delete"]]);
    } else {
        $error = "Dieses Angebot existiert nicht oder ist nicht von deinem aktuellen Königreich!";
    }
} else if (!empty($_GET["sv"]) && !empty($_GET["dv"])) {
    if ($_GET["s"] < 0 || $_GET["s"] > 3 || $_GET["d"] < 0 || $_GET["d"] > 3) {
        $error = "Diese Ressource gibt es nicht!";
    } else if ($_GET["s"] == $_GET["d"]) {
        $error = "Die Ressourcentypen dürfen nicht gleich sein!";
    } else {
        if ($_GET["sv"] <= 0 || !is_numeric($_GET["sv"]) || $_GET["dv"] <= 0 || !is_numeric($_GET["dv"]) || $_GET["sv"] > 99999 || $_GET["dv"] > 99999) {
            $error = "Die Werte müssen zwischen 1 und 99999 liegen!";
        } else {
            // Check if kingdom has enough ressources to handle the trade
            if ($_GET["s"] == 0 && $kingdom->get_kingdom_food() < $_GET["sv"]) {
                $error = "Soviel Nahrung kannst du nicht bieten!";
            } else if ($_GET["s"] == 1 && $kingdom->get_kingdom_wood() < $_GET["sv"]) {
                $error = "Soviel Holz kannst du nicht bieten!";
            } else if ($_GET["s"] == 2 && $kingdom->get_kingdom_stone() < $_GET["sv"]) {
                $error = "Soviel Stein kannst du nicht bieten!";
            } else if ($_GET["s"] == 3 && $kingdom->get_kingdom_gold() < $_GET["sv"]) {
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
                    $result = $db_instance->execute_query($query, [$user->get_user_id(), $user->get_user_name(), $current_kingdom, $_GET["s"], $_GET["sv"], $_GET["d"], $_GET["dv"]]);

                    switch ($_GET["s"]) {
                        case 0:
                            $kingdom->give_kingdom_food($current_kingdom, -$_GET["sv"]);
                            break;
                        case 1:
                            $kingdom->give_kingdom_wood($current_kingdom, -$_GET["sv"]);
                            break;
                        case 2:
                            $kingdom->give_kingdom_stone($current_kingdom, -$_GET["sv"]);
                            break;
                        case 3:
                            $kingdom->give_kingdom_gold($current_kingdom, -$_GET["sv"]);
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