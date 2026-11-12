<?php

class BattleReportRenderer
{
    private static ?array $soldier_cache = null;
    private static ?array $monster_cache = null;

    public static function render_vs_grid(
        array  $attacker_units,
        array  $defender_units,
        string $atk_label = "Deine Truppen",
        string $def_label = "Gegner",
        int    $left_wall_def = 0,
        int    $right_wall_def = 0,
        string $def_empty_msg = "<i>Keine Truppen stationiert.</i>"
    ): string
    {
        $atk_column = self::render_side_column($attacker_units, $atk_label, $left_wall_def, "<i>Wir haben keine Truppen!</i>");
        $def_column = self::render_side_column($defender_units, $def_label, $right_wall_def, $def_empty_msg);

        return "
        <div class='battle-vs-wrapper'>
            $atk_column
            <div class='battle-vs-divider'>VS</div>
            $def_column
        </div>";
    }

    private static function render_side_column(array $units, string $label, int $wall_def = 0, string $empty_msg = ""): string
    {
        $sum_atk = 0;
        $sum_def = $wall_def;
        $total_losses = 0;
        $is_fictional = false;
        $cards_html = "";

        if (empty($units)) {
            $cards_html = $empty_msg;
        } else {
            foreach ($units as $u) {
                $initial = (int)($u["initial"] ?? $u["count"] ?? 0);
                $losses = (int)($u["losses"] ?? 0);
                $sum_atk += $initial * (int)($u["atk"] ?? 0);
                $sum_def += $initial * (int)($u["def"] ?? 0);
                $total_losses += $losses;

                if (!empty($u["is_fictional"])) {
                    $is_fictional = true;
                }

                $cards_html .= self::render_unit_card($u);
            }
        }

        $summary_html = "";
        if ($total_losses > 0) {
            $loss_display = fnum($total_losses) . ($is_fictional ? " <small>(fiktiv)</small>" : "");

            $summary_html = "
                <div class='battle-report-losses'>
                    Verluste gesamt:
                    <b>$loss_display</b>
                </div>";
        }

        return "
        <div class='battle-column'>
            <div class='report-section-title'>$label</div>
            <div class='battle-strength'>
                <span>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_ATTACK) . " " . fnum($sum_atk) . "</span>
                <span>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_DEFENSE) . " " . fnum($sum_def) . "</span>
            </div>
            $cards_html
            $summary_html
        </div>";
    }

    public static function render_unit_card(
        string|array $name_or_unit,
        int          $initial = 0,
        int          $losses = 0,
        string       $icon_name = "",
        bool         $is_scouting = false
    ): string
    {
        $is_fictional = false;

        if (is_array($name_or_unit)) {
            $u = $name_or_unit;
            self::enrich_unit_data($u);

            $name = $u["name"] ?? "Einheit";
            $initial = (int)($u["initial"] ?? $u["count"] ?? 0);
            $losses = (int)($u["losses"] ?? 0);
            $icon_name = $u["icon"] ?? "icon_error";
            if (isset($u["is_scouting"])) $is_scouting = (bool)$u["is_scouting"];
            if (!empty($u["is_fictional"])) $is_fictional = true;
        } else {
            $name = $name_or_unit;
        }

        $survivors = $is_fictional ? $initial : max(0, $initial - $losses);

        $loss_class = $is_fictional ? "loss-gray" : "loss-red";
        $loss_text = ($losses > 0) ? "<span class='$loss_class'>(-" . fnum($losses) . ")</span>" : "";

        $survivor_color = ($survivors > 0) ? "survivor-green" : "loss-red";
        $icon_path = "images/icons/" . ($icon_name ?: "icon_error") . ".png";
        $troop_count_text = "<small style='color: #ccc;'> von $initial</small>";

        if ($is_scouting) {
            $survivor_color = "";
            $troop_count_text = "";
        }

        return "
        <div class='battle-unit-card'>
            <img src='$icon_path' style='vertical-align: middle;' alt=''>
            <div class='battle-unit-info'>
                <span class='battle-unit-name'>$name</span>
                <span class='battle-unit-count'><span class='$survivor_color'>$survivors</span>$troop_count_text $loss_text</span>
            </div>
        </div>";
    }

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
        } else if (is_string($id) && str_starts_with($id, "hero")) {
            $unit["icon"] = "icon_hero";

            if (empty($unit["name"])) {
                $lvl = $unit["h_lvl"] ?? null;
                $skill_id = $unit["s_id"] ?? null;

                if ($lvl && $skill_id && isset(ARENA_HERO_SKILLS[$skill_id])) {
                    $skill_name = ARENA_HERO_SKILLS[$skill_id]["name"];

                    $unit["name"] = "Held (St. $lvl - $skill_name)";
                } else {
                    $unit["name"] = "Held";
                }
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

    public static function render_resource_box(array $res, string $title, string $color_class = "passed",
                                               bool  $is_negative = false): string
    {
        $items = [];
        $is_neg = $is_negative || ($color_class === "error") || str_contains($title, "Gestohlene");
        $sign = $is_neg ? "-" : "";

        $food = $res["food"] ?? $res[ResourceTypes::RESOURCE_TYPE_FOOD] ?? 0;
        $wood = $res["wood"] ?? $res[ResourceTypes::RESOURCE_TYPE_WOOD] ?? 0;
        $stone = $res["stone"] ?? $res[ResourceTypes::RESOURCE_TYPE_STONE] ?? 0;
        $gold = $res["gold"] ?? $res[ResourceTypes::RESOURCE_TYPE_GOLD] ?? 0;

        if ($food > 0) $items[] = get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) . " " . $sign . fnum($food);
        if ($wood > 0) $items[] = get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) . " " . $sign . fnum($wood);
        if ($stone > 0) $items[] = get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . " " . $sign . fnum($stone);
        if ($gold > 0) $items[] = get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " " . $sign . fnum($gold);

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
        $html .= "<div class='report-section-title' style='border-color: {$style["border"]};'>" . Messages::wrap_emojis($title) . "</div>";
        $html .= "<div class='report-main-text'>" . Messages::wrap_emojis($main_text) . "</div>";

        if ($wall_before > 0 || $wall_after > 0) {
            $wall_icon = get_resource_icon(ResourceTypes::RESOURCE_TYPE_HEALTH);
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
            $html .= "<div class='report-sub-text'>$sub_text</div>";
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
            $html .= "<div class='scout-grid'>";

            foreach ($intel["buildings"] as $b) {
                $bid = (int)$b["id"];
                $html .= "<div class='scout-item'><img src='images/icons/icon_building$bid.png' class='ressource-icons' alt=''> <span>" . e($b["name"]) . " (" . (int)$b["level"] . ")</span></div>";
            }

            $html .= "</div>";
        }

        // Troops
        if (!empty($intel["garrison_scouted"]) || !empty($intel["troops"])) {
            $html .= "<div class='report-section-title' style='margin-top: 10px;'>Gegnerische Garnison & Mauer</div>";

            $total_atk = (int)($intel["total_atk"] ?? 0);
            $total_def = (int)($intel["total_def"] ?? 0);

            $w = $intel["wall"] ?? null;

            $html .= "<div class='all-troops-sum-stats scout-item' style='flex-wrap: wrap; gap: 15px; border: 1px solid #555; background: rgba(0,0,0,0.4);'>";

            if ($total_atk > 0) {
                $html .= "<span style='display: inline-flex; align-items: center; gap: 5px;'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_ATTACK) . " " . fnum($total_atk, true) . "</span>";
            }
            if ($total_def > 0) {
                $html .= "<span style='display: inline-flex; align-items: center; gap: 5px;'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_DEFENSE) . " " . fnum($total_def, true) . "</span>";
            }

            if ($w !== null && (int)$w["level"] > 0) {
                $html .= "<span style='display: inline-flex; align-items: center; gap: 5px;'>
                          " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_HEALTH) . " <b>Mauer:</b> " . fnum($w["hp"]) . " / " . fnum($w["max_hp"]) . "</span>";
            }
            $html .= "</div>";

            if (!empty($intel["troops"])) {
                $html .= "<div style='display: flex; flex-wrap: wrap; gap: 5px; justify-content: center;'>";

                foreach ($intel["troops"] as $t) {
                    $html .= self::render_unit_card($t, 0, 0, "", true);
                }

                $html .= "</div>";
            }
        }

        if (!empty($intel["support_troops"])) {
            $html .= "<div class='report-section-title' style='margin-top: 10px;'>Unterstützungstruppen</div>";

            $sup_atk = (int)($intel["support_atk"] ?? 0);
            $sup_def = (int)($intel["support_def"] ?? 0);
            if ($sup_atk > 0 || $sup_def > 0) {
                $html .= "<div class='all-troops-sum-stats' style='flex-wrap: wrap; gap: 15px;'>";
                $html .= "<span style='display: inline-flex; align-items: center; gap: 5px;'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_ATTACK) . " <b>" . fnum($sup_atk, true) . "</b></span>";
                $html .= "<span style='display: inline-flex; align-items: center; gap: 5px;'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_DEFENSE) . " <b>" . fnum($sup_def, true) . "</b></span>";
                $html .= "</div>";
            }

            $html .= "<div style='display: flex; flex-wrap: wrap; gap: 5px; justify-content: center;'>";

            foreach ($intel["support_troops"] as $st) {
                $html .= self::render_unit_card($st, 0, 0, "", true);
            }

            $html .= "</div>";
        }

        // Techs
        if (!empty($intel["techs"])) {
            $html .= "<div class='report-section-title' style='margin-top: 10px;'>Erforschte Technologien</div>";
            $html .= "<div class='scout-grid'>";

            foreach ($intel["techs"] as $t) {
                $tid = (int)$t["id"];
                $html .= "<div class='scout-item'><img src='images/icons/icon_tech$tid.png' class='ressource-icons' alt=''> <span>" . e($t["name"]) . " (" . (int)$t["level"] . ")</span></div>";
            }

            $html .= "</div>";
        }

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
            "villager" => ResourceTypes::RESOURCE_TYPE_VILLAGER,
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
}