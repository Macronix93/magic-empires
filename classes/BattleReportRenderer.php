<?php

class BattleReportRenderer
{
    public static function render_unit_card(string $name, int $initial, int $losses, string $icon_name, bool $is_scouting = false): string
    {
        $survivors = max(0, $initial - $losses);
        $loss_text = ($losses > 0) ? "<span class='loss-red'>(-" . fnum($losses) . ")</span>" : "";
        $survivor_class = ($survivors > 0) ? "survivor-green" : "loss-red";
        $icon_path = "images/icons/" . $icon_name . ".png";
        $troop_count_text = "<small style='color: #ccc;'> von $initial</small>";

        if ($is_scouting) {
            $survivor_class = "";
            $troop_count_text = "";
        }

        return "
        <div class='battle-unit-card'>
            <img src='$icon_path' style='width: 24px; height: 24px; vertical-align: middle;' alt=''>
            <div class='battle-unit-info'>
                <span class='battle-unit-name'>$name</span>
                <span class='battle-unit-count $survivor_class'>$survivors $troop_count_text $loss_text</span>
            </div>
        </div>";
    }

    public static function render_vs_grid(array $attacker_units, array $defender_units, string $atk_label = "Deine Truppen", string $def_label = "Gegner"): string
    {
        $html = "<div class='battle-vs-wrapper'>";

        // Attacker Column
        $html .= "<div class='battle-column'><div class='report-section-title'>$atk_label</div>";
        foreach ($attacker_units as $u) {
            $html .= self::render_unit_card($u["name"], $u["initial"], $u["losses"], $u["icon"]);
        }
        $html .= "</div>";

        $html .= "<div class='battle-vs-divider'>VS</div>";

        // Defender Column
        $html .= "<div class='battle-column'><div class='report-section-title'>$def_label</div>";
        if (empty($defender_units)) {
            $html .= "<i>Keine Truppen stationiert</i>";
        } else {
            foreach ($defender_units as $u) {
                $html .= self::render_unit_card($u["name"], $u["initial"], $u["losses"], $u["icon"]);
            }
        }
        $html .= "</div></div>";

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

        $html = "<div class='battle-column' style='margin-top: 10px;'>";
        $html .= "<div class='report-section-title'>$title</div>";
        $html .= "<div style='display: flex; gap: 20px; justify-content: center; flex-wrap: wrap;'>";

        foreach ($items as $item) {
            $html .= "<div class='$color_class'>$item</div>";
        }
        $html .= "</div></div>";

        return $html;
    }

    public static function render_scout_resource_bar(array $res): string
    {
        return "<div style='display: flex; justify-content: space-around; background: rgba(0,0,0,0.4); padding: 10px; border-radius: 5px; border: 1px solid #555;'>
                <div>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) . " " . fnum($res["food"]) . "</div>
                <div>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) . " " . fnum($res["wood"]) . "</div>
                <div>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . " " . fnum($res["stone"]) . "</div>
                <div>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " " . fnum($res["gold"]) . "</div>
            </div>";
    }

    public static function render_own_scout_status(int $initial, int $losses): string
    {
        $survivors = $initial - $losses;
        $icon_path = "images/icons/icon_scout.png";
        $losses_text = ($losses > 0) ? "<span class='loss-red'>(-$losses)</span>" : "";

        return "<div class='battle-unit-card' style='border-left: 3px solid #3498db; background: rgba(52, 152, 219, 0.2); margin-top: 10px;'>
                <img src='$icon_path' style='width: 24px; height: 24px; vertical-align: middle;' alt=''>
                <div class='battle-unit-info'>
                    <span class='battle-unit-name' style='color:#3498db;'>Eigene Späher</span>
                    <span class='battle-unit-count'>$survivors <small>/ $initial</small> $losses_text</span>
                </div>
            </div>";
    }
}