<?php

class BattleReportRenderer
{
    private static ?array $soldier_cache = null;
    private static ?array $monster_cache = null;

    private static function enrich_unit_data(array &$unit): void
    {
        $db_instance = Database::get_instance()->get_connection();

        $id = $unit["id"] ?? null;

        if (is_string($id) && str_starts_with($id, 'm')) {
            $m_id = (int)substr($id, 1);

            if (self::$monster_cache === null) {
                $res = $db_instance->query("SELECT id, monster_name, icon FROM monster_list");

                while ($row = $res->fetch_assoc()) {
                    self::$monster_cache[(int)$row["id"]] = $row;
                }
            }

            if (empty($unit["name"]) && isset(self::$monster_cache[$m_id])) {
                $unit["name"] = self::$monster_cache[$m_id]["monster_name"];
            }

            if (empty($unit["icon"]) && isset(self::$monster_cache[$m_id])) {
                $unit["icon"] = self::$monster_cache[$m_id]["icon"];
            }
        } else if ($id !== null) {
            $s_id = (int)$id;

            if (self::$soldier_cache === null) {
                $res = $db_instance->query("SELECT id, soldiername, icon FROM soldier_list");

                while ($row = $res->fetch_assoc()) {
                    self::$soldier_cache[(int)$row["id"]] = $row;
                }
            }

            if (empty($unit["name"]) && isset(self::$soldier_cache[$s_id])) {
                $unit["name"] = self::$soldier_cache[$s_id]["soldiername"];
            }

            if (empty($unit["icon"]) && isset(self::$soldier_cache[$s_id])) {
                $unit["icon"] = self::$soldier_cache[$s_id]["icon"];
            }
        }
    }

    public static function render_unit_card(
        string|array $name_or_unit,
        int          $initial = 0,
        int          $losses = 0,
        string       $icon_name = "",
        bool         $is_scouting = false
    ): string
    {
        if (is_array($name_or_unit)) {
            $u = $name_or_unit;
            self::enrich_unit_data($u);

            $name = $u["name"] ?? "Einheit";
            $initial = (int)($u["initial"] ?? $u["count"] ?? 0);
            $losses = (int)($u["losses"] ?? 0);
            $icon_name = $u["icon"] ?? "icon_error";
            if (isset($u["is_scouting"])) $is_scouting = (bool)$u["is_scouting"];
        } else {
            $name = $name_or_unit;
        }

        $survivors = max(0, $initial - $losses);
        $loss_text = ($losses > 0) ? "<span class='loss-red'>(-" . fnum($losses) . ")</span>" : "";
        $survivor_class = ($survivors > 0) ? "survivor-green" : "loss-red";
        $icon_path = "images/icons/" . ($icon_name ?: "icon_error") . ".png";
        $troop_count_text = "<small style='color: #ccc;'> von $initial</small>";

        if ($is_scouting) {
            $survivor_class = "";
            $troop_count_text = "";
        }

        return "
        <div class='battle-unit-card'>
            <img src='$icon_path' style='vertical-align: middle;' alt=''>
            <div class='battle-unit-info'>
                <span class='battle-unit-name'>$name</span>
                <span class='battle-unit-count $survivor_class'>$survivors $troop_count_text $loss_text</span>
            </div>
        </div>";
    }

    public static function render_vs_grid(array $attacker_units, array $defender_units, string $atk_label = "Deine Truppen", string $def_label = "Gegner"): string
    {
        // Calculate Power Sums
        $sum_atk_atk = 0;
        $sum_atk_def = 0;
        $atk_cards_html = "";

        if (empty($attacker_units)) {
            $atk_cards_html = "<i>Wir haben keine Truppen!</i>";
        } else {
            foreach ($attacker_units as $u) {
                $sum_atk_atk += ($u["initial"] ?? 0) * ($u["atk"] ?? 0);
                $sum_atk_def += ($u["initial"] ?? 0) * ($u["def"] ?? 0);

                $atk_cards_html .= self::render_unit_card($u);
            }
        }

        $sum_def_atk = 0;
        $sum_def_def = 0;
        $def_cards_html = "";

        if (empty($defender_units)) {
            $def_cards_html = "<i>Keine Truppen stationiert.</i>";
        } else {
            foreach ($defender_units as $u) {
                $sum_def_atk += ($u["initial"] ?? 0) * ($u["atk"] ?? 0);
                $sum_def_def += ($u["initial"] ?? 0) * ($u["def"] ?? 0);

                $def_cards_html .= self::render_unit_card($u);
            }
        }

        $html = "<div class='battle-vs-wrapper'>";

        // Attacker Box
        $html .= "<div class='battle-column'>";
        $html .= "<div class='report-section-title'>$atk_label</div>";
        $html .= "<div class='battle-strength'>";
        $html .= "<span>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_ATTACK) . " " . fnum($sum_atk_atk) . "</span>";
        $html .= "<span>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_DEFENSE) . " " . fnum($sum_atk_def) . "</span>";
        $html .= "</div>";
        $html .= $atk_cards_html;
        $html .= "</div>";

        // Divider
        $html .= "<div class='battle-vs-divider'>VS</div>";

        // Defender Box
        $html .= "<div class='battle-column'>";
        $html .= "<div class='report-section-title'>$def_label</div>";
        $html .= "<div class='battle-strength'>";
        $html .= "<span>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_ATTACK) . " " . fnum($sum_def_atk) . "</span>";
        $html .= "<span>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_DEFENSE) . " " . fnum($sum_def_def) . "</span>";
        $html .= "</div>";
        $html .= $def_cards_html;
        $html .= "</div>";

        $html .= "</div>";
        return $html;
    }

    public static function render_resource_box(array $res, string $title, string $color_class = "passed"): string
    {
        $items = [];

        if (($res["food"] ?? 0) > 0) $items[] = get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) . " " . fnum($res["food"]);
        if (($res["wood"] ?? 0) > 0) $items[] = get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) . " " . fnum($res["wood"]);
        if (($res["stone"] ?? 0) > 0) $items[] = get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . " " . fnum($res["stone"]);
        if (($res["gold"] ?? 0) > 0) $items[] = get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " " . fnum($res["gold"]);

        if (empty($items)) return "";

        $html = "<div class='battle-column'>";
        $html .= "<div class='report-section-title'>$title</div>";
        $html .= "<div style='display: flex; gap: 20px; justify-content: center; flex-wrap: wrap;'>";

        foreach ($items as $item) {
            $html .= "<div class='$color_class'>$item</div>";
        }
        $html .= "</div></div>";

        return $html;
    }

    public static function render_scout_resource_bar(array $stocks, array $production = []): string
    {
        $html = "";
        $types = [
            "food" => ResourceTypes::RESOURCE_TYPE_FOOD,
            "wood" => ResourceTypes::RESOURCE_TYPE_WOOD,
            "stone" => ResourceTypes::RESOURCE_TYPE_STONE,
            "gold" => ResourceTypes::RESOURCE_TYPE_GOLD,
            "coal" => ResourceTypes::RESOURCE_TYPE_COAL,
            "iron" => ResourceTypes::RESOURCE_TYPE_IRON,
            "sapphire" => ResourceTypes::RESOURCE_TYPE_SAPPHIRE,
            "diamond" => ResourceTypes::RESOURCE_TYPE_DIAMOND
        ];

        $show_production = !empty($production);

        foreach ($types as $key => $constant) {
            if (!array_key_exists($key, $stocks)) {
                continue;
            }

            $raw_stock = $stocks[$key] ?? 0;

            if (!$show_production && $raw_stock <= 0) {
                continue;
            }

            $s_val = fnum($raw_stock);

            $html .= "<div style='display: flex; flex-direction: column; align-items: center;'>
                    <div class='scout-resource-bar' style='gap: 5px;'>
                        " . get_resource_icon($constant) . " <span>$s_val</span>
                    </div>";

            if ($show_production) {
                $p_val = fnum($production[$key] ?? 0);
                $html .= "<div style='font-size: 0.85em; color: #0BDA51; opacity: 0.9;'>$p_val/h</div>";
            }

            $html .= "</div>";
        }

        if (empty($html)) return "";

        return "<div style='display: flex; justify-content: space-evenly; background: rgba(0,0,0,0.4); padding: 12px 5px; border-radius: 5px; border: 1px solid #555; margin-top: 5px;'>
            $html
        </div>";
    }

    public static function render_own_scout_status(int $initial, int $losses): string
    {
        $survivors = $initial - $losses;
        $icon_path = "images/icons/icon_scout.png";
        $losses_text = ($losses > 0) ? "<span class='loss-red'>(-$losses)</span>" : "";

        return "<div class='battle-unit-card' style='border-left: 3px solid #3498db; background: rgba(52, 152, 219, 0.2);'>
                <img src='$icon_path' style='width: 24px; height: 24px; vertical-align: middle;' alt=''>
                <div class='battle-unit-info'>
                    <span class='battle-unit-name' style='color:#3498db;'>Eigene Späher</span>
                    <span class='battle-unit-count'>$survivors $losses_text</span>
                </div>
            </div>";
    }

    public static function render_outcome_box(string $title, string $main_text, int $wall_before = 0, int $wall_after = 0, string $sub_text = "", string $type = "neutral",
                                              array  $resources = []): string
    {
        $styles = [
            "success" => ["bg" => "rgba(46, 204, 113, 0.2)", "border" => "#2ecc71"],
            "error" => ["bg" => "rgba(231, 76, 60, 0.2)", "border" => "#e74c3c"],
            "neutral" => ["bg" => "rgba(212, 175, 55, 0.1)", "border" => "rgb(165, 124, 0)"],
            "normal" => ["bg" => "rgba(255, 255, 255, 0.05)", "border" => "rgba(212, 175, 55, 0.2)"],
            "support" => ["bg" => "rgba(0, 123, 255, 0.15)", "border" => "#3498db"]
        ];

        $style = $styles[$type] ?? $styles["neutral"];

        $html = "<div class='battle-column' style='background: {$style["bg"]}; border-color: {$style["border"]};'>";
        $html .= "<div class='report-section-title' style='border-color: {$style["border"]};'>$title</div>";
        $html .= "<div style='font-size: 1.1em; margin-bottom: 5px;'>$main_text</div>";

        if ($wall_before > 0 || $wall_after > 0) {
            $wall_icon = get_resource_icon(ResourceTypes::RESOURCE_TYPE_DEFENSE);
            $destroyed = ($wall_after <= 0) ? " <span class='error'>(Zerstört)</span>" : "";
            $html .= "<div>$wall_icon Mauer: " . fnum($wall_before) . " &rarr; " . fnum($wall_after) . "$destroyed</div>";
        }

        if (!empty($resources)) {
            $html .= "<div style='display: flex; gap: 15px; justify-content: center; margin: 7px;'>";

            foreach ($resources as $res_id => $amount) {
                if ($amount > 0) {
                    $html .= "<div>" . get_resource_icon($res_id) . " <span class='passed'>+" . fnum($amount) . "</span></div>";
                }
            }

            $html .= "</div>";
        }

        if (!empty($sub_text)) {
            $html .= "<div style='font-style: italic; opacity: 0.9; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 5px;'>$sub_text</div>";
        }

        $html .= "</div>";

        return $html;
    }

    public static function render_resource_list(array $resources): string
    {
        if (empty($resources)) {
            return "";
        }

        $items_html = "";

        foreach ($resources as $res_id => $amount) {
            if ($amount > 0) {
                $items_html .= "<div style='display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;'>" .
                    get_resource_icon($res_id) . " <span class='passed'>+" . fnum($amount) . "</span></div>";
            }
        }

        if (empty($items_html)) {
            return "";
        }

        return "<div style='display: flex; gap: 15px; justify-content: center; flex-wrap: wrap; margin-top: 10px;'>
            $items_html
        </div>";
    }

    public static function render_scout_intel(array $intel): string
    {
        $html = "<div class='report-section-title'>Gelagerte Ressourcen</div>";
        $html .= self::render_scout_resource_bar($intel["resources"] ?? [], $intel["production"] ?? []);

        // Buildings
        if (!empty($intel["buildings"])) {
            $html .= "<div class='report-section-title' style='margin-top: 10px;'>Identifizierte Gebäude</div>";
            $html .= "<div style='display: grid; grid-template-columns: 1fr 1fr; gap: 5px; text-align: left;'>";

            foreach ($intel["buildings"] as $b) {
                $bid = (int)$b["id"];
                $html .= "<div class='scout-item'><img src='images/icons/icon_building$bid.png' class='ressource-icons' alt=''> <span>" . e($b["name"]) . " (" . (int)$b["level"] . ")</span></div>";
            }

            $html .= "</div>";
        }

        // Troops
        if (!empty($intel["troops"])) {
            $html .= "<div class='report-section-title' style='margin-top: 10px;'>Gegnerische Garnison</div>";
            $html .= "<div style='display: flex; flex-wrap: wrap; gap: 5px; margin-top: 10px; justify-content: center;'>";

            foreach ($intel["troops"] as $t) {
                $html .= self::render_unit_card($t, 0, 0, "", true);
            }

            $html .= "</div>";
        }

        // Techs
        if (!empty($intel["techs"])) {
            $html .= "<div class='report-section-title' style='margin-top: 10px;'>Erforschte Technologien</div>";
            $html .= "<div style='display: grid; grid-template-columns: 1fr 1fr; gap: 5px; text-align: left;'>";

            foreach ($intel["techs"] as $t) {
                $tid = (int)$t["id"];
                $html .= "<div class='scout-item'><img src='images/icons/icon_tech$tid.png' class='ressource-icons' alt=''> <span>" . e($t["name"]) . " (" . (int)$t["level"] . ")</span></div>";
            }

            $html .= "</div>";
        }

        return $html;
    }
}