<?php
require_once("../includes/ajax_core.php");

if (!$user->is_admin()) {
    echo show_error_box("Zugriff verweigert.");
    exit;
}

$log_id = get_int("id");
if ($log_id <= 0) {
    echo show_error_box("Ungültige Log-ID.");
    exit;
}

$log = $db_instance->execute_query("
    SELECT l.*, u.username, k.kingdomname, k.mapx AS k_x, k.mapy AS k_y
    FROM game_logs l
    LEFT JOIN users u ON l.userid = u.id
    LEFT JOIN kingdoms k ON l.kingdomid = k.id
    WHERE l.id = ?
    LIMIT 1
", [$log_id])->fetch_assoc();

if (!$log) {
    echo show_error_box("Dieser Log-Eintrag existiert nicht mehr.");
    exit;
}

$raw_details = (string)($log["details"] ?? "");
$details = json_decode($raw_details, true);
if (!is_array($details)) {
    $details = [];
}

$category = (string)$log["category"];
$action = (string)$log["action"];

$category_labels = [
    "COMBAT" => "Kampf",
    "TRADE" => "Handel",
    "ECONOMY" => "Wirtschaft",
    "ACCOUNT" => "Account",
    "ADMIN" => "Admin",
    "ACHIEVEMENT" => "Errungenschaft",
    "EVENT" => "Event",
];

$action_labels = [
    "RECRUIT_CANCEL" => "Rekrutierung abgebrochen",
    "UPGRADE_START" => "Truppen-Aufwertung gestartet",
    "REGISTER" => "Neuregistrierung",
    "LOGIN_SUCCESS" => "Erfolgreicher Login",
    "AVATAR_UPLOAD" => "Profilbild hochgeladen",
    "USERNAME_CHANGE" => "Benutzername geändert",
    "PASSWORD_CHANGE" => "Passwort geändert",
    "EMAIL_CHANGE" => "E-Mail-Adresse geändert",
    "VACATION_START" => "Urlaubsmodus aktiviert",
    "SELF_DELETION" => "Account selbst gelöscht",
    "KINGDOM_RENAME" => "Königreich umbenannt",
    "SHRINE_ALIGNMENT_CHANGE" => "Schrein-Gesinnung gewechselt",
    "BUILDING_CANCEL" => "Gebäudebau abgebrochen",
    "BUILDING_UPGRADE" => "Gebäude ausgebaut",
    "RESEARCH_FINISH" => "Forschung abgeschlossen",
    "WALL_REPAIR" => "Mauer repariert",
    "ATTACK_SEND" => "Truppen entsandt",
    "ATTACK_RECALL" => "Truppen zurückgerufen",
    "RESULT" => "PvP-Kampfergebnis",
    "SPY_RESULT" => "Spionage (Spieler)",
    "THIEF_MISSION" => "Diebeszug",
    "MONSTER_BATTLE" => "Kampf gegen Monstercamp",
    "TILE_SPY" => "Spionage (Vorratslager)",
    "TILE_PLUNDER" => "Vorratslager geplündert",
    "WORLD_EVENT_ATTACK" => "Welt-Event Angriff",
    "WORLD_EVENT_SPAWN" => "Welt-Event gestartet",
    "TROOP_REDIRECTED" => "Truppen umgeleitet",
    "SUPPORT_ORPHANED_RETURN" => "Verwaiste Unterstützung erstattet",
    "KINGDOM_FOUNDED" => "Königreich gegründet",
    "SETTLE_FAILED" => "Gründung fehlgeschlagen",
    "MINE_OCCUPY" => "Mine besetzt",
    "MINE_OVERTAKEN" => "Mine erobert",
    "MINE_DEFENDED" => "Mine verteidigt",
    "MINE_RECALL" => "Minen-Schürfer zurückgerufen",
    "MINE_GUILD_LEAVE_RECALL" => "Minen-Rückzug (Gildenaustritt)",
    "MINE_DEPLETED" => "Mine vollständig abgebaut",
    "MINE_EXPIRED" => "Mine abgelaufen",
    "OFFER_CREATE" => "Handelsangebot erstellt",
    "OFFER_ACCEPT" => "Handelsangebot angenommen",
    "OFFER_DELETE" => "Handelsangebot gelöscht",
    "SAME_IP_TRADE" => "Handel über gleiche IP",
    "INTERNAL_TRANSPORT" => "Interner Ressourcentransport",
    "TRANSPORT_RETURNED" => "Ressourcentransport zurückgekehrt",
    "UNLOCKED" => "Errungenschaft freigeschaltet",
    "ADVENT_GIFT_COLLECTED" => "Advents-Geschenk geborgen",
];

// --- Lazy Lookup Helpers ---
$soldier_cache = null;
$get_soldiers = static function () use (&$soldier_cache, $db_instance): array {
    if ($soldier_cache === null) {
        $soldier_cache = [];
        $res = $db_instance->query("SELECT id, soldiername, icon FROM soldier_list");
        while ($r = $res->fetch_assoc()) {
            $soldier_cache[(int)$r["id"]] = $r;
        }
    }
    return $soldier_cache;
};

$user_cache = [];
$render_user_ref = static function (int $uid) use (&$user_cache, $db_instance): string {
    if ($uid <= 0) {
        return "<i>System</i>";
    }

    if (!array_key_exists($uid, $user_cache)) {
        $user_cache[$uid] = $db_instance->execute_query(
            "SELECT username FROM users WHERE id = ?",
            [$uid]
        )->fetch_column() ?: null;
    }

    if ($user_cache[$uid] !== null) {
        $uname = e($user_cache[$uid]);
        return "<a href='adminpanel.php?userid=$uid&tab=users' style='color: var(--link-color); text-decoration: underline;'><b>$uname</b></a> <small style='opacity: 0.7;'>(ID: $uid)</small>";
    }
    return "<i style='opacity: 0.7;'>Gelöschter Spieler (ID: $uid)</i>";
};

$render_coords = static function (int $x, int $y): string {
    if ($x >= 1 && $x <= MAX_X && $y >= 1 && $y <= MAX_Y) {
        return "<a href='map.php?startx=$x&starty=$y' data-on-click='mapJump' data-x='$x' data-y='$y' class='chat-coord-link'>$x:$y</a>";
    }
    return e("$x:$y");
};

$render_coords_str = static function (string $coord_str) use ($render_coords): string {
    if (preg_match('/^\s*(\d+)\s*:\s*(\d+)\s*$/', $coord_str, $m)) {
        return $render_coords((int)$m[1], (int)$m[2]);
    }
    return e($coord_str);
};

$kingdom_cache = [];
$render_kingdom_ref = static function (int $kid) use (&$kingdom_cache, $db_instance, $render_coords): string {
    if ($kid < 0) {
        return match ($kid) {
            MapFieldTypes::MAP_FIELD_EMPTY => "Freies Land",
            MapFieldTypes::MAP_FIELD_RESOURCE_TILE => "Vorratslager",
            MapFieldTypes::MAP_FIELD_MONSTER_CAMP => "Monstercamp",
            MapFieldTypes::MAP_FIELD_ABANDONED_KINGDOM => "Verlassene Ruine",
            MapFieldTypes::MAP_FIELD_MINE => "Erzmine",
            MapFieldTypes::MAP_FIELD_GIFT => "Advents-Geschenk",
            MapFieldTypes::MAP_FIELD_WORLD_EVENT => "Welt-Event (Auge des Sturms)",
            default => "Sonderfeld ($kid)",
        };
    }

    if (!array_key_exists($kid, $kingdom_cache)) {
        $kingdom_cache[$kid] = $db_instance->execute_query(
            "SELECT k.kingdomname, k.mapx, k.mapy, u.username FROM kingdoms k LEFT JOIN users u ON k.userid = u.id WHERE k.id = ?",
            [$kid]
        )->fetch_assoc() ?: null;
    }

    $k = $kingdom_cache[$kid];
    if (!$k) {
        return "<i style='opacity: 0.7;'>Königreich #$kid (nicht mehr vorhanden)</i>";
    }

    $coords = $render_coords((int)$k["mapx"], (int)$k["mapy"]);
    $owner = !empty($k["username"]) ? " – " . e($k["username"]) : "";
    return "<b>" . e($k["kingdomname"]) . "</b> $coords <small style='opacity: 0.7;'>(ID: $kid$owner)</small>";
};

$resolve_resource_meta = static function (int|string $key): ?array {
    $map_int = [
        ResourceTypes::RESOURCE_TYPE_FOOD => [ResourceTypes::RESOURCE_TYPE_FOOD, "Nahrung"],
        ResourceTypes::RESOURCE_TYPE_WOOD => [ResourceTypes::RESOURCE_TYPE_WOOD, "Holz"],
        ResourceTypes::RESOURCE_TYPE_STONE => [ResourceTypes::RESOURCE_TYPE_STONE, "Stein"],
        ResourceTypes::RESOURCE_TYPE_GOLD => [ResourceTypes::RESOURCE_TYPE_GOLD, "Gold"],
        ResourceTypes::RESOURCE_TYPE_COINS => [ResourceTypes::RESOURCE_TYPE_COINS, "Münzen"],
        ResourceTypes::RESOURCE_TYPE_COAL => [ResourceTypes::RESOURCE_TYPE_COAL, "Kohle"],
        ResourceTypes::RESOURCE_TYPE_IRON => [ResourceTypes::RESOURCE_TYPE_IRON, "Eisen"],
        ResourceTypes::RESOURCE_TYPE_SAPPHIRE => [ResourceTypes::RESOURCE_TYPE_SAPPHIRE, "Saphir"],
        ResourceTypes::RESOURCE_TYPE_DIAMOND => [ResourceTypes::RESOURCE_TYPE_DIAMOND, "Diamant"],
    ];

    if (is_int($key) || (is_string($key) && ctype_digit($key))) {
        return $map_int[(int)$key] ?? null;
    }

    $k = strtolower($key);
    $k = preg_replace('/^(?:cost_|loot_)/', '', $k);
    return match ($k) {
        "food" => $map_int[ResourceTypes::RESOURCE_TYPE_FOOD],
        "wood" => $map_int[ResourceTypes::RESOURCE_TYPE_WOOD],
        "stone" => $map_int[ResourceTypes::RESOURCE_TYPE_STONE],
        "gold" => $map_int[ResourceTypes::RESOURCE_TYPE_GOLD],
        "coins", "coin" => $map_int[ResourceTypes::RESOURCE_TYPE_COINS],
        "coal" => $map_int[ResourceTypes::RESOURCE_TYPE_COAL],
        "iron" => $map_int[ResourceTypes::RESOURCE_TYPE_IRON],
        "sapphire" => $map_int[ResourceTypes::RESOURCE_TYPE_SAPPHIRE],
        "diamond" => $map_int[ResourceTypes::RESOURCE_TYPE_DIAMOND],
        default => null,
    };
};

$render_resource_single = static function (int|string $res_key, int $amount) use ($resolve_resource_meta): string {
    $meta = $resolve_resource_meta($res_key);

    if (!$meta) {
        return fnum($amount, true) . " (" . e((string)$res_key) . ")";
    }

    return "<span style='display: inline-flex; align-items: center; gap: 5px;'>" .
        get_resource_icon($meta[0]) . " <b>" . fnum($amount, true) . "</b> $meta[1]</span>";
};

$render_resource_map = static function (array $res_arr) use ($resolve_resource_meta): string {
    $items = [];

    foreach ($res_arr as $k => $v) {
        $amt = (int)$v;

        if ($amt <= 0) continue;

        $meta = $resolve_resource_meta($k);
        if ($meta) {
            $items[] = "<span style='display: inline-flex; align-items: center; gap: 4px; background: rgba(0,0,0,0.25); padding: 3px 7px; border-radius: 4px; border: 1px solid rgba(212,175,55,0.25);'>" .
                get_resource_icon($meta[0]) . " <b>" . fnum($amt, true) . "</b></span>";
        } else {
            $items[] = e((string)$k) . ": <b>" . fnum($amt, true) . "</b>";
        }
    }

    if (empty($items)) {
        return "<span style='opacity: 0.6;'>Keine</span>";
    }
    return "<div style='display: flex; flex-wrap: wrap; gap: 6px; align-items: center;'>" . implode("", $items) . "</div>";
};

$render_soldier_single = static function (int $sid) use ($get_soldiers): string {
    $all = $get_soldiers();

    if (isset($all[$sid])) {
        $s = $all[$sid];
        return "<span style='display: inline-flex; align-items: center; gap: 5px;'>" .
            "<img src='images/icons/" . e($s["icon"]) . ".png' class='ressource-icons' alt=''> " .
            "<b>" . e($s["soldiername"]) . "</b> <small style='opacity: 0.7;'>(ID: $sid)</small></span>";
    }
    return "Einheit #$sid";
};

$render_troops_badges = static function (array $troops) use ($get_soldiers): string {
    $all = $get_soldiers();
    $badges = [];

    foreach ($troops as $key => $val) {
        if (is_array($val)) {
            $sid = (int)($val["id"] ?? -1);
            $cnt = (int)($val["count"] ?? 0);
        } else {
            $sid = (int)$key;
            $cnt = (int)$val;
        }

        if ($cnt <= 0) continue;

        $name = $all[$sid]["soldiername"] ?? "Einheit #$sid";
        $icon = $all[$sid]["icon"] ?? "icon_error";

        $badges[] = "<div class='unit-badge' title='" . e($name) . "'>" .
            "<img src='images/icons/" . e($icon) . ".png' class='ressource-icons' alt='" . e($name) . "'> " .
            "<b>" . fnum($cnt, true) . "</b></div>";
    }

    if (empty($badges)) {
        return "<span style='opacity: 0.6;'>Keine</span>";
    }
    return "<div style='display: flex; flex-wrap: wrap; gap: 5px; align-items: center;'>" . implode("", $badges) . "</div>";
};

$render_bool = static fn(bool $val): string => $val
    ? "<b class='passed'>Ja</b>"
    : "<b class='error'>Nein</b>";

// --- Build Detail Rows ---
$detail_rows = [];
$consumed = [];

// Combined / action-specific fields
if (isset($details["target_x"], $details["target_y"])) {
    $detail_rows[] = ["Ziel-Koordinaten", $render_coords((int)$details["target_x"], (int)$details["target_y"])];
    $consumed["target_x"] = $consumed["target_y"] = true;
}

if (isset($details["x"], $details["y"])) {
    $detail_rows[] = ["Koordinaten", $render_coords((int)$details["x"], (int)$details["y"])];
    $consumed["x"] = $consumed["y"] = true;
}

if (isset($details["wall_before"], $details["wall_after"])) {
    $wb = (int)$details["wall_before"];
    $wa = (int)$details["wall_after"];
    $diff = $wb - $wa;
    $diff_html = ($diff > 0) ? " <span class='error'>(-" . fnum($diff, true) . " HP)</span>" : "";
    $detail_rows[] = [
        "Mauer-HP",
        "<span style='display: inline-flex; align-items: center; gap: 5px;'>" .
        get_resource_icon(ResourceTypes::RESOURCE_TYPE_HEALTH) .
        " <b>" . fnum($wb, true) . "</b> &rarr; <b>" . fnum($wa, true) . "</b>$diff_html</span>"
    ];
    $consumed["wall_before"] = $consumed["wall_after"] = true;
}

if ($action === "UPGRADE_START" && isset($details["from"], $details["to"])) {
    $detail_rows[] = [
        "Aufwertung",
        "<div style='display: flex; align-items: center; gap: 8px; flex-wrap: wrap;'>" .
        $render_soldier_single((int)$details["from"]) . " &rarr; " . $render_soldier_single((int)$details["to"]) . "</div>"
    ];
    $consumed["from"] = $consumed["to"] = true;
} else if ($action === "TROOP_REDIRECTED" && isset($details["from"], $details["to"])) {
    $detail_rows[] = ["Ursprungskönigreich", $render_kingdom_ref((int)$details["from"])];
    $detail_rows[] = ["Umgeleitet nach", $render_kingdom_ref((int)$details["to"])];
    $consumed["from"] = $consumed["to"] = true;
}

if ($action === "OFFER_CREATE" && isset($details["supply_res"], $details["supply_amount"], $details["demand_res"], $details["demand_amount"])) {
    $detail_rows[] = ["Angebot (Bietet)", $render_resource_single($details["supply_res"], (int)$details["supply_amount"])];
    $detail_rows[] = ["Gesucht (Fordert)", $render_resource_single($details["demand_res"], (int)$details["demand_amount"])];
    $consumed["supply_res"] = $consumed["supply_amount"] = $consumed["demand_res"] = $consumed["demand_amount"] = true;
}

if ($action === "OFFER_ACCEPT" && isset($details["resource"], $details["amount"], $details["cost_res"], $details["cost_amount"])) {
    $detail_rows[] = ["Erhalten (Käufer)", $render_resource_single($details["resource"], (int)$details["amount"])];
    $detail_rows[] = ["Bezahlt (Käufer)", $render_resource_single($details["cost_res"], (int)$details["cost_amount"])];
    $consumed["resource"] = $consumed["amount"] = $consumed["cost_res"] = $consumed["cost_amount"] = true;
}

if ($action === "OFFER_DELETE" && isset($details["refund_res"], $details["refund_amount"])) {
    $detail_rows[] = ["Erstattung", $render_resource_single($details["refund_res"], (int)$details["refund_amount"])];
    $consumed["refund_res"] = $consumed["refund_amount"] = true;
}

if ($action === "INTERNAL_TRANSPORT") {
    $transport_res = [];
    foreach (["food", "wood", "stone", "gold"] as $rk) {
        if (isset($details[$rk])) {
            $transport_res[$rk] = (int)$details[$rk];
            $consumed[$rk] = true;
        }
    }
    if (!empty($transport_res)) {
        $detail_rows[] = ["Gesendete Ressourcen", $render_resource_map($transport_res)];
    }
}

if ($action === "MINE_GUILD_LEAVE_RECALL" && (isset($details["loot_stone"]) || isset($details["loot_gold"]))) {
    $mine_loot = [
        "stone" => (int)($details["loot_stone"] ?? 0),
        "gold" => (int)($details["loot_gold"] ?? 0),
    ];
    $detail_rows[] = ["Beute", $render_resource_map($mine_loot)];
    $consumed["loot_stone"] = $consumed["loot_gold"] = true;
}

$key_labels = [
    "soldier_id" => "Einheit",
    "amount" => "Menge",
    "count" => "Anzahl",
    "email" => "E-Mail",
    "new_email" => "Neue E-Mail",
    "username" => "Spielername",
    "new_name" => "Neuer Name",
    "event_id" => "Event-ID",
    "filename" => "Dateiname",
    "extension" => "Dateiendung",
    "mime" => "MIME-Typ",
    "size" => "Dateigröße",
    "until" => "Urlaub bis",
    "days" => "Dauer",
    "alignment" => "Gesinnung",
    "building_id" => "Gebäude",
    "tech_id" => "Forschung",
    "level" => "Stufe",
    "refund" => "Erstattung",
    "hp_gained" => "Reparierte HP",
    "cost_stone" => "Steinkosten",
    "percent_step" => "Reparatur-Schritt",
    "target_kingdom_id" => "Ziel",
    "target_kingdom" => "Ziel-Königreich",
    "from_kingdom" => "Von Königreich",
    "to_kingdom" => "Nach Königreich",
    "new_kingdom_id" => "Neues Königreich",
    "source_kingdom" => "Ursprungskönigreich",
    "arrival_in" => "Marschdauer",
    "troops" => "Truppen",
    "troops_sent" => "Gesendete Truppen",
    "troops_defender" => "Verteidiger",
    "losses_attacker" => "Verluste Angreifer",
    "losses_defender" => "Verluste Verteidiger",
    "achievement_id" => "Errungenschaft",
    "mine_id" => "Minen-ID",
    "coords" => "Koordinaten",
    "target_coords" => "Ziel-Koordinaten",
    "mine_coords" => "Minen-Koordinaten",
    "completion_percent" => "Fortschritt",
    "event_type" => "Event-Typ",
    "type" => "Event-Typ",
    "damage_caused" => "Verursachter Schaden",
    "is_boss_kill" => "Boss besiegt (Todesstoß)",
    "attacker" => "Angreifer",
    "attacker_id" => "Angreifer",
    "defender_id" => "Verteidiger",
    "seller_id" => "Verkäufer",
    "buyer_id" => "Käufer",
    "victory" => "Sieg",
    "success" => "Erfolgreich",
    "was_emptied" => "Lager geleert",
    "attacker_losses" => "Eigene Verluste",
    "monsters_slain" => "Besiegte Monster",
    "loot_res" => "Beute (Rohstoffe)",
    "loot_coins" => "Beute (Münzen)",
    "loot" => "Beute",
    "resources" => "Ressourcen",
    "scouts_sent" => "Gesendete Späher",
    "scouts_lost" => "Verlorene Späher",
    "raiders_sent" => "Gesendete Räuber",
    "raiders_lost" => "Verlorene Räuber",
    "thieves_sent" => "Gesendete Diebe",
    "thieves_lost" => "Verlorene Diebe",
    "reason" => "Grund",
    "message" => "Hinweis",
    "day" => "Advents-Tag",
    "coins" => "Münzen",
    "offer_id" => "Angebots-ID",
    "fee" => "Käufer-Gebühr",
    "listing_fee_paid" => "Einstellgebühr",
    "ip" => "IP-Adresse",
    "monster_index" => "Event-Boss",
    "total_hp" => "Gesamt-HP",
    "server_power" => "Server-Kampfkraft",
];

foreach ($details as $key => $val) {
    if (isset($consumed[$key])) {
        continue;
    }

    $label = $key_labels[$key] ?? ucfirst(str_replace('_', ' ', (string)$key));
    $formatted = "";

    switch ($key) {
        case "soldier_id":
            $formatted = $render_soldier_single((int)$val);
            break;

        case "troops":
        case "troops_sent":
        case "troops_defender":
        case "losses_attacker":
        case "losses_defender":
            $formatted = is_array($val) ? $render_troops_badges($val) : e((string)$val);
            break;

        case "attacker_id":
        case "defender_id":
        case "seller_id":
        case "buyer_id":
            $formatted = $render_user_ref((int)$val);
            break;

        case "target_kingdom_id":
        case "target_kingdom":
        case "from_kingdom":
        case "to_kingdom":
        case "new_kingdom_id":
        case "source_kingdom":
            $formatted = $render_kingdom_ref((int)$val);
            break;

        case "coords":
        case "target_coords":
        case "mine_coords":
            $formatted = $render_coords_str((string)$val);
            break;

        case "building_id":
            $bid = (int)$val;
            $bname = $db_instance->execute_query("SELECT buildingname FROM building_list WHERE id = ?", [$bid])->fetch_column();
            $formatted = $bname
                ? "<span style='display: inline-flex; align-items: center; gap: 5px;'>
                    <img src='images/icons/icon_building$bid.png' class='ressource-icons' alt=''> <b>" . e($bname) . "</b> <small style='opacity: 0.7;'>(ID: $bid)</small></span>"
                : "Gebäude #$bid";
            break;

        case "tech_id":
            $tid = (int)$val;
            $tname = $db_instance->execute_query("SELECT techname FROM tech_list WHERE id = ?", [$tid])->fetch_column();
            $formatted = $tname
                ? "<span style='display: inline-flex; align-items: center; gap: 5px;'>
                    <img src='images/icons/icon_tech$tid.png' class='ressource-icons' alt=''> <b>" . e($tname) . "</b> <small style='opacity: 0.7;'>(ID: $tid)</small></span>"
                : "Forschung #$tid";
            break;

        case "achievement_id":
            $aid = (int)$val;
            $ach = $db_instance->execute_query("SELECT name, title, description FROM achievements WHERE id = ?", [$aid])->fetch_assoc();
            $formatted = $ach
                ? "<b>" . e($ach["name"]) . "</b> <small style='opacity: 0.7;'>(ID: $aid)</small><br><small style='opacity: 0.8;'>" . e($ach["description"]) . "</small>"
                : "Errungenschaft #$aid";
            break;

        case "alignment":
            $al_id = (int)$val;
            if ($al_id === AlignmentTypes::ALIGN_NONE) {
                $formatted = "Neutral (Keine)";
            } else {
                $al_name = $db_instance->execute_query("SELECT name FROM shrine_alignments WHERE id = ?", [$al_id])->fetch_column();
                $formatted = $al_name ? "<b>" . e($al_name) . "</b> <small style='opacity: 0.7;'>(ID: $al_id)</small>" : "Gesinnung #$al_id";
            }
            break;

        case "loot":
        case "loot_res":
        case "resources":
        case "refund":
            $formatted = is_array($val) ? $render_resource_map($val) : e((string)$val);
            break;

        case "cost_stone":
            $formatted = $render_resource_single(ResourceTypes::RESOURCE_TYPE_STONE, (int)$val);
            break;

        case "coins":
        case "loot_coins":
        case "fee":
        case "listing_fee_paid":
            $formatted = $render_resource_single(ResourceTypes::RESOURCE_TYPE_COINS, (int)$val);
            break;

        case "arrival_in":
            $formatted = "<b>" . convert_sec_to_str((int)$val) . "</b> <small style='opacity: 0.7;'>(" . fnum((int)$val, true) . " Sek.)</small>";
            break;

        case "until":
            $formatted = ((int)$val > 0) ? "<b>" . date("d.m.Y H:i:s", (int)$val) . " Uhr</b>" : "-";
            break;

        case "days":
            $d = (int)$val;
            $formatted = "<b>$d</b> " . ($d === 1 ? "Tag" : "Tage");
            break;

        case "size":
            $bytes = (int)$val;
            $formatted = "<b>" . fdec($bytes / 1024) . " KB</b> <small style='opacity: 0.7;'>(" . fnum($bytes, true) . " Bytes)</small>";
            break;

        case "percent_step":
        case "completion_percent":
            $formatted = "<b>" . fdec($val) . " %</b>";
            break;

        case "hp_gained":
            $formatted = "<span style='display: inline-flex; align-items: center; gap: 5px;'>" .
                get_resource_icon(ResourceTypes::RESOURCE_TYPE_HEALTH) . " <b class='passed'>+" . fnum((int)$val, true) . " HP</b></span>";
            break;

        case "event_type":
        case "type":
            $formatted = match ((string)$val) {
                "BOSS_HP" => "<b>Weltenboss (HP)</b>",
                "DAMAGE" => "<b>Schadens-Event</b>",
                default => e((string)$val),
            };
            break;

        case "monster_index":
            $pool = new WorldEvent()->get_monster_pool();
            $m = $pool[(int)$val] ?? null;
            $formatted = $m
                ? "<span style='display: inline-flex; align-items: center; gap: 6px;'><img src='images/icons/" . e($m["icon"]) . ".png' class='ressource-icons' alt=''> <b>" . e($m["name"]) . "</b></span>"
                : "Monster #" . (int)$val;
            break;

        case "victory":
        case "success":
        case "was_emptied":
        case "is_boss_kill":
            $formatted = $render_bool((bool)$val);
            break;

        default:
            if (is_bool($val)) {
                $formatted = $render_bool($val);
            } else if (is_array($val)) {
                $formatted = "<pre style='margin: 0; font-size: 12px; white-space: pre-wrap;'>" .
                    e(json_encode($val, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "</pre>";
            } else if (is_int($val) || is_float($val)) {
                $formatted = "<b>" . fnum($val, true) . "</b>";
            } else {
                $formatted = e((string)$val);
            }
            break;
    }

    $detail_rows[] = [$label, $formatted];
}

// Meta display values
$cat_label = $category_labels[$category] ?? $category;
$act_label = $action_labels[$action] ?? str_replace('_', ' ', $action);

if (!empty($log["userid"])) {
    $actor_html = $render_user_ref((int)$log["userid"]);
} else if (!empty($details["username"])) {
    $actor_html = "<b>" . e((string)$details["username"]) . "</b> <small style='opacity: 0.7;'>(Account gelöscht)</small>";
} else {
    $actor_html = "<i>System</i>";
}

$kingdom_html = null;
if (!empty($log["kingdomid"])) {
    $kid_meta = (int)$log["kingdomid"];

    if (!empty($log["kingdomname"])) {
        $kingdom_html = "<b>" . e($log["kingdomname"]) . "</b> " .
            $render_coords((int)$log["k_x"], (int)$log["k_y"]) .
            " <small style='opacity: 0.7;'>(ID: $kid_meta)</small>";
    } else {
        $kingdom_html = "<i style='opacity: 0.7;'>Königreich #$kid_meta (nicht mehr vorhanden)</i>";
    }
}
?>
<div id="modal-title-data" data-title="Game-Log #<?= $log_id ?>" data-width="700px" style="display: none;"></div>
<div style="padding: 10px; max-width: 680px; margin: 0 auto; text-align: left;">
    <div class="title-border" style="margin-top: 0;"><?= e($act_label) ?></div>

    <table class="table" style="width: 100%; margin-bottom: 18px;">
        <colgroup>
            <col style="width: 34%;">
            <col style="width: 66%;">
        </colgroup>
        <tr>
            <td><b>Log-ID:</b></td>
            <td>#<?= $log_id ?></td>
        </tr>
        <tr>
            <td><b>Kategorie:</b></td>
            <td><b style="color: var(--link-color);"><?= e($cat_label) ?></b> <small
                        style="opacity: 0.65;">(<?= e($category) ?> / <?= e($action) ?>)</small></td>
        </tr>
        <tr>
            <td><b>Zeitpunkt:</b></td>
            <td><?= date("d.m.Y H:i:s", (int)$log["created_at"]) ?> Uhr</td>
        </tr>
        <tr>
            <td><b>Spieler:</b></td>
            <td><?= $actor_html ?></td>
        </tr>
        <?php if ($kingdom_html !== null): ?>
            <tr>
                <td><b>Königreich:</b></td>
                <td><?= $kingdom_html ?></td>
            </tr>
        <?php endif; ?>
        <tr>
            <td><b>IP-Adresse:</b></td>
            <td><?= !empty($log["ip"]) ? e($log["ip"]) : "<i style='opacity: 0.6;'>-</i>" ?></td>
        </tr>
    </table>

    <div class="title-border" style="font-size: 16px;">Details</div>
    <?php if (!empty($detail_rows)): ?>
        <table class="table" style="width: 100%; margin-bottom: 15px;">
            <colgroup>
                <col style="width: 34%;">
                <col style="width: 66%;">
            </colgroup>
            <?php foreach ($detail_rows as [$row_label, $row_value]): ?>
                <tr>
                    <td style="vertical-align: middle;"><b><?= e($row_label) ?>:</b></td>
                    <td style="vertical-align: middle; word-break: break-word;"><?= $row_value ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p style="text-align: center; opacity: 0.65; margin: 10px 0 15px 0;"><i>Keine weiteren Detaildaten
                hinterlegt.</i></p>
    <?php endif; ?>

    <?php if ($raw_details !== "" && $raw_details !== "[]"): ?>
        <details
                style="background: rgba(0, 0, 0, 0.3); border: 1px solid rgba(165, 124, 0, 0.4); border-radius: 4px; padding: 8px 12px;">
            <summary style="cursor: pointer; font-size: 13px; color: var(--link-color); user-select: none;">Rohdaten
                (JSON) anzeigen
            </summary>
            <pre style="margin: 10px 0 0 0; padding: 10px; background: #000; border: 1px solid var(--border-gold);
                        border-radius: 3px; font-family: monospace; font-size: 12px; overflow: auto; max-height: 220px;
                        white-space: pre-wrap;"><?= e(json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        </details>
    <?php endif; ?>
</div>