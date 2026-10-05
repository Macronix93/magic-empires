<?php

class Marketplace
{
    private mysqli $db;
    private User $user;
    private Kingdom $kingdom;

    public function __construct(User $user, Kingdom $kingdom)
    {
        $this->db = Database::get_instance()->get_connection();
        $this->user = $user;
        $this->kingdom = $kingdom;
    }

    public static function calculate_market_fee(int $supply_type, int $supply_value, int $demand_type, int $demand_value): int
    {
        $multipliers = [
            ResourceTypes::RESOURCE_TYPE_FOOD => MARKET_FEE_MULTIPLIER_FOOD,
            ResourceTypes::RESOURCE_TYPE_WOOD => MARKET_FEE_MULTIPLIER_WOOD,
            ResourceTypes::RESOURCE_TYPE_STONE => MARKET_FEE_MULTIPLIER_STONE,
            ResourceTypes::RESOURCE_TYPE_GOLD => MARKET_FEE_MULTIPLIER_GOLD
        ];

        $factor_s = $multipliers[$supply_type] ?? 0.001;
        $variable_fee_s = floor($supply_value * $factor_s);

        $factor_d = $multipliers[$demand_type] ?? 0.001;
        $variable_fee_d = floor($demand_value * $factor_d);

        $max_variable = max($variable_fee_s, $variable_fee_d);

        return (int)(MARKET_BASE_FEE + $max_variable);
    }

    public static function calculate_listing_fee(int $supply_value): int
    {
        return (int)max(1, ceil($supply_value / 20000));
    }

    public function get_max_capacity(): int
    {
        $level = $this->kingdom->get_kingdom_building_level(BuildingTypes::BUILDING_MARKETPLACE);
        return $level * MARKET_CAPACITY_PER_LEVEL;
    }

    public function get_daily_trades_info(): array
    {
        $uid = $this->user->get_user_id();
        $trade_check = $this->db->execute_query(
            "SELECT daily_trades_count, last_trade_reset FROM users WHERE id = ?",
            [$uid]
        )->fetch_assoc();

        $daily_trades_count = (int)($trade_check["daily_trades_count"] ?? 0);
        $today_start = strtotime("today midnight");

        if ((int)($trade_check["last_trade_reset"] ?? 0) < $today_start) {
            $daily_trades_count = 0;
            $this->db->execute_query(
                "UPDATE users SET daily_trades_count = 0, last_trade_reset = ? WHERE id = ?",
                [time(), $uid]
            );
        }

        $res_markets = $this->db->execute_query("
            SELECT SUM(LEAST(buildinglevel, ?)) as total_upgrades 
            FROM buildings 
            WHERE kingdomid IN (SELECT id FROM kingdoms WHERE userid = ?) 
              AND buildingid = ?",
            [MARKET_UPGRADE_LIMIT, $uid, BuildingTypes::BUILDING_MARKETPLACE]
        );
        $total_upgrades = (int)$res_markets->fetch_column();
        $max_trades = (int)floor(MARKET_DAILY_TRADES_BASE + ($total_upgrades * MARKET_TRADES_PER_UPGRADE));

        return [
            "current" => $daily_trades_count,
            "max" => $max_trades,
            "is_full" => ($daily_trades_count >= $max_trades)
        ];
    }

    public function create_offer(int $supply, int $supply_value, int $demand, int $demand_value, bool $guild_only = false): ?string
    {
        $daily = $this->get_daily_trades_info();
        if ($daily["is_full"]) {
            return "Du hast heute bereits {$daily["max"]} Angebote erstellt oder angenommen!";
        }

        if ($supply < 0 || $supply > 3 || $demand < 0 || $demand > 3) {
            return "Diese Ressource gibt es nicht!";
        }
        if ($supply === $demand) {
            return "Die Ressourcentypen dürfen nicht gleich sein!";
        }
        if ($supply_value <= 0 || $demand_value <= 0) {
            return "Die Mengen müssen größer als 0 sein!";
        }

        $listing_fee = self::calculate_listing_fee($supply_value);
        if ($this->user->get_user_coins() < $listing_fee) {
            return "Du hast nicht genug Münzen für die Einstellgebühr (Benötigt: $listing_fee " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_COINS) . ")!";
        }

        $max_capacity = $this->get_max_capacity();
        if ($supply_value > $max_capacity || $demand_value > $max_capacity) {
            return "Dein Marktplatz kann maximal " . fnum($max_capacity) . " Ressourcen pro Angebot handhaben!";
        }

        $stocks = [
            ResourceTypes::RESOURCE_TYPE_FOOD => $this->kingdom->get_kingdom_food(),
            ResourceTypes::RESOURCE_TYPE_WOOD => $this->kingdom->get_kingdom_wood(),
            ResourceTypes::RESOURCE_TYPE_STONE => $this->kingdom->get_kingdom_stone(),
            ResourceTypes::RESOURCE_TYPE_GOLD => $this->kingdom->get_kingdom_gold()
        ];
        if (($stocks[$supply] ?? 0) < $supply_value) {
            return "Soviel Ressourcen kannst du nicht bieten!";
        }

        $ratio1 = $supply_value / $demand_value;
        $ratio2 = $demand_value / $supply_value;
        $grace = 0.01;
        if ($ratio1 > MAX_MARKET_RATIO + $grace || $ratio2 > MAX_MARKET_RATIO + $grace) {
            return "Das Handelsverhältnis ist zu extrem! (Maximal 1:" . MAX_MARKET_RATIO . " erlaubt)";
        }

        $kid = $this->kingdom->get_kingdom_id();
        $uid = $this->user->get_user_id();

        $check_existing = $this->db->execute_query("SELECT offerid FROM marketplace WHERE kingdomid = ?", [$kid]);
        if ($check_existing->num_rows > 0) {
            return "Du hast bereits ein Angebot für dieses Königreich am laufen!";
        }

        $my_guild_id = $this->user->get_user_guild_id();
        $offer_guild_id = ($guild_only && $my_guild_id > 0) ? $my_guild_id : 0;
        $calculated_fee = self::calculate_market_fee($supply, $supply_value, $demand, $demand_value);
        $expires_at = time() + MARKET_OFFER_DURATION;

        $this->db->begin_transaction();
        try {
            $this->user->give_user_coins(-$listing_fee);
            $this->kingdom->modify_resource($supply, -$supply_value);

            $this->db->execute_query("
                INSERT INTO marketplace (userid, username, kingdomid, supply, supplyvalue, demand, demandvalue, coins, expires_at, guild_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                $uid, $this->user->get_user_name(), $kid,
                $supply, $supply_value, $demand, $demand_value,
                $calculated_fee, $expires_at, $offer_guild_id
            ]);

            $this->db->execute_query("UPDATE users SET daily_trades_count = daily_trades_count + 1 WHERE id = ?", [$uid]);
            $this->db->commit();

            Logger::get_instance()->log_game("TRADE", "OFFER_CREATE", [
                "supply_res" => $supply,
                "supply_amount" => $supply_value,
                "demand_res" => $demand,
                "demand_amount" => $demand_value,
                "fee" => $calculated_fee,
                "listing_fee_paid" => $listing_fee
            ], $kid);

            return null;
        } catch (Exception $e) {
            $this->db->rollback();
            return "Fehler beim Einstellen: " . $e->getMessage();
        }
    }

    public function accept_offer(int $offer_id, Map $map): array
    {
        $daily = $this->get_daily_trades_info();
        if ($daily["is_full"]) {
            return ["success" => false, "error" => "Du hast dein tägliches Limit von {$daily["max"]} Handelsaktionen bereits erreicht!"];
        }

        $uid = $this->user->get_user_id();
        $kid = $this->kingdom->get_kingdom_id();
        $my_guild_id = $this->user->get_user_guild_id();

        $this->db->begin_transaction();
        $result = $this->db->execute_query("
            SELECT m.*, k.mapx, k.mapy, u.ip AS seller_ip, u.device_id AS seller_device 
            FROM marketplace m 
            JOIN kingdoms k ON m.kingdomid = k.id 
            JOIN users u ON m.userid = u.id 
            WHERE m.offerid = ? FOR UPDATE",
            [$offer_id]
        );
        $row = $result->fetch_assoc();

        if (!$row) {
            $this->db->rollback();
            return ["success" => false, "error" => "Dieses Angebot existiert nicht mehr oder wurde bereits von jemand anderem angenommen!"];
        }

        if ((int)$row["userid"] === $uid) {
            $this->db->rollback();
            return ["success" => false, "error" => "Du kannst dein eigenes Angebot nicht annehmen!"];
        }

        // Multi-Account Protection: Same IP
        if ($row["seller_ip"] === $_SERVER["REMOTE_ADDR"]) {
            Logger::get_instance()->log_game("TRADE", "SAME_IP_TRADE", [
                "seller_id" => $row["userid"],
                "buyer_id" => $uid,
                "ip" => $_SERVER["REMOTE_ADDR"]
            ]);
        }

        // Multi-Account Protection: Block same device id
        $buyer_device = $_SESSION["device_id"] ?? '';
        $is_same_device = (!empty($row["seller_device"]) && $row["seller_device"] === $buyer_device);
        if ($is_same_device) {
            $this->db->rollback();
            return ["success" => false, "error" => "Handel zwischen Accounts am selben Gerät ist nicht gestattet!"];
        }

        // Guild Protection
        $target_guild = (int)($row["guild_id"] ?? 0);
        if ($target_guild > 0 && ($my_guild_id <= 0 || $my_guild_id !== $target_guild)) {
            $this->db->rollback();
            return ["success" => false, "error" => "Dieses Angebot ist ausschließlich für Mitglieder der entsprechenden Gilde reserviert!"];
        }

        $demand = (int)$row["demand"];
        $demand_value = (int)$row["demandvalue"];
        $has_resources = match ($demand) {
            ResourceTypes::RESOURCE_TYPE_FOOD => $this->kingdom->get_kingdom_food() >= $demand_value,
            ResourceTypes::RESOURCE_TYPE_WOOD => $this->kingdom->get_kingdom_wood() >= $demand_value,
            ResourceTypes::RESOURCE_TYPE_STONE => $this->kingdom->get_kingdom_stone() >= $demand_value,
            ResourceTypes::RESOURCE_TYPE_GOLD => $this->kingdom->get_kingdom_gold() >= $demand_value,
            default => false
        };

        if (!$has_resources) {
            $this->db->rollback();
            return ["success" => false, "error" => "Du hast nicht genügend Ressourcen, um dieses Angebot zu erfüllen!"];
        }

        $coins_cost = (int)$row["coins"];
        if ($this->user->get_user_coins() < $coins_cost) {
            $this->db->rollback();
            return ["success" => false, "error" => "Deine Münzen reichen nicht für das Handelsangebot!"];
        }

        try {
            $this->db->execute_query("DELETE FROM marketplace WHERE offerid = ?", [$offer_id]);
            $this->kingdom->modify_resource($demand, -$demand_value);
            $this->user->give_user_coins(-$coins_cost);

            $now = time();
            $my_x = $this->kingdom->get_kingdom_map_x();
            $my_y = $this->kingdom->get_kingdom_map_y();
            $creator_id = (int)$row["userid"];
            $creator_name = $row["username"];
            $supply = (int)$row["supply"];
            $supply_value = (int)$row["supplyvalue"];

            $buyer_seconds = $map->get_arrival_time($my_x, $my_y, $row["mapx"], $row["mapy"], $kid, null, false, true);
            $buyer_arrival_time = $now + $buyer_seconds;
            $seller_seconds = $map->get_arrival_time($my_x, $my_y, $row["mapx"], $row["mapy"], $row["kingdomid"], null, false, true);
            $seller_arrival_time = $now + $seller_seconds;

            // Supply to buyer
            $this->db->execute_query("
                INSERT INTO events (actionid, userid, kingdomid, buildingid, buildinglevel, buildingname, arrivaltime) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ", [ActionTypes::ACTION_RECEIVE_RESOURCES, $uid, $kid, $supply, $supply_value, TransportTypes::TRANSPORT_TYPE_TRADE_DELIVERY, $buyer_arrival_time]);

            // Demand to seller
            $this->db->execute_query("
                INSERT INTO events (actionid, userid, kingdomid, buildingid, buildinglevel, buildingname, arrivaltime) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ", [ActionTypes::ACTION_RECEIVE_RESOURCES, $creator_id, $row["kingdomid"], $demand, $demand_value, "Handelserlös", $seller_arrival_time]);

            $this->db->execute_query("UPDATE users SET daily_trades_count = daily_trades_count + 1 WHERE id = ?", [$uid]);

            // Message to seller
            $cost = [$demand => $demand_value];
            $seller_json = [
                "template" => "trade_accepted",
                "buyer_name" => $this->user->get_user_name(),
                "buyer_kname" => $this->kingdom->get_kingdom_name(),
                "arrival_time" => $seller_seconds,
                "cost" => $cost
            ];
            Messages::send_server_message($creator_id, $creator_name, MessageCategories::CATEGORY_TRADE, $seller_json);

            Logger::get_instance()->log_game("TRADE", "OFFER_ACCEPT", [
                "offer_id" => $offer_id,
                "seller_id" => $creator_id,
                "resource" => $supply,
                "amount" => $supply_value,
                "cost_res" => $demand,
                "cost_amount" => $demand_value,
                "from_kingdom" => $row["kingdomid"],
                "to_kingdom" => $kid
            ], $kid);

            $res_map = [
                ResourceTypes::RESOURCE_TYPE_FOOD => "food",
                ResourceTypes::RESOURCE_TYPE_WOOD => "wood",
                ResourceTypes::RESOURCE_TYPE_STONE => "stone",
                ResourceTypes::RESOURCE_TYPE_GOLD => "gold"
            ];
            $s_name = $res_map[$supply];
            $d_name = $res_map[$demand];

            Stats::update_global_stat("total_trades");
            Stats::update_player_stat($uid, "trades_count");
            Stats::update_player_stat($uid, "trade_received_" . $s_name, $supply_value);
            Stats::update_player_stat($uid, "trade_sent_" . $d_name, $demand_value);
            Stats::update_player_stat($creator_id, "trades_count");
            Stats::update_player_stat($creator_id, "trade_sent_" . $s_name, $supply_value);
            Stats::update_player_stat($creator_id, "trade_received_" . $d_name, $demand_value);

            $this->db->commit();

            return [
                "success" => true,
                "arrival_str" => convert_sec_to_str($buyer_seconds)
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            return ["success" => false, "error" => "Fehler bei der Transaktion: " . $e->getMessage()];
        }
    }

    public function cancel_offer(int $offer_id): ?string
    {
        $uid = $this->user->get_user_id();
        $result = $this->db->execute_query("
            SELECT supply, supplyvalue, kingdomid FROM marketplace 
            WHERE offerid = ? AND userid = ?",
            [$offer_id, $uid]
        );
        $row = $result->fetch_assoc();

        if ($row) {
            $origin_kingdom_id = (int)$row["kingdomid"];
            $origin_kingdom = new Kingdom($origin_kingdom_id);

            $origin_kingdom->modify_resource((int)$row["supply"], (int)$row["supplyvalue"]);
            $this->db->execute_query("DELETE FROM marketplace WHERE offerid = ?", [$offer_id]);
            $this->db->execute_query("UPDATE users SET daily_trades_count = GREATEST(0, daily_trades_count - 1) WHERE id = ?", [$uid]);

            Logger::get_instance()->log_game("TRADE", "OFFER_DELETE", [
                "offer_id" => $offer_id,
                "refund_res" => $row["supply"],
                "refund_amount" => $row["supplyvalue"]
            ], $origin_kingdom_id);

            return null;
        }

        return "Dieses Angebot existiert nicht oder ist nicht von deinem aktuellen Königreich!";
    }

    public function send_internal_transport(int $target_id, array $amounts, Map $map): array
    {
        $target_market_lvl = (int)($this->db->execute_query(
            "SELECT buildinglevel FROM buildings WHERE kingdomid = ? AND buildingid = ?",
            [$target_id, BuildingTypes::BUILDING_MARKETPLACE]
        )->fetch_column() ?? 0);

        if ($target_market_lvl <= 0) {
            return ["success" => false, "error" => "Das Zielkönigreich besitzt keinen Marktplatz!"];
        }

        $uid = $this->user->get_user_id();
        $kid = $this->kingdom->get_kingdom_id();

        $res_target = $this->db->execute_query(
            "SELECT id, mapx, mapy, kingdomname FROM kingdoms WHERE id = ? AND userid = ?",
            [$target_id, $uid]
        );
        $target_row = $res_target->fetch_assoc();

        if (!$target_row || $target_id === $kid) {
            return ["success" => false, "error" => "Ungültiges Ziel-Königreich!"];
        }

        $daily = $this->get_daily_trades_info();
        if ($daily["is_full"]) {
            return ["success" => false, "error" => "Du hast dein tägliches Limit von {$daily["max"]} Handelsaktionen bereits erreicht!"];
        }

        $total_sum = array_sum(array_map("intval", $amounts));
        if ($total_sum <= 0) {
            return ["success" => false, "error" => "Bitte gib eine Menge größer als 0 an!"];
        }

        $max_capacity = $this->get_max_capacity();
        if ($total_sum > $max_capacity) {
            return ["success" => false, "error" => "Kapazität überschritten (Max. " . fnum($max_capacity) . ")!"];
        }

        $stocks = [
            ResourceTypes::RESOURCE_TYPE_FOOD => $this->kingdom->get_kingdom_food(),
            ResourceTypes::RESOURCE_TYPE_WOOD => $this->kingdom->get_kingdom_wood(),
            ResourceTypes::RESOURCE_TYPE_STONE => $this->kingdom->get_kingdom_stone(),
            ResourceTypes::RESOURCE_TYPE_GOLD => $this->kingdom->get_kingdom_gold()
        ];
        if (array_any($amounts, fn($val, $type) => (int)$val > ($stocks[(int)$type] ?? 0))) {
            return ["success" => false, "error" => "Du hast nicht genug Ressourcen!"];
        }

        $this->db->begin_transaction();
        try {
            foreach ($amounts as $type => $val) {
                if ((int)$val > 0) $this->kingdom->modify_resource((int)$type, -(int)$val);
            }

            $my_x = $this->kingdom->get_kingdom_map_x();
            $my_y = $this->kingdom->get_kingdom_map_y();
            $arrival_data = $map->calculate_arrival_data($my_x, $my_y, $target_row["mapx"], $target_row["mapy"], $kid, true);
            $seconds = $arrival_data["seconds"];
            $arrival_time = $arrival_data["timestamp"];

            $this->db->execute_query("
                INSERT INTO events (actionid, userid, kingdomid, arrivaltime, targetid, targetx, targety, buildingtime, buildingname, loot_food, loot_wood, loot_stone, loot_gold) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                ActionTypes::ACTION_RECEIVE_RESOURCES,
                $uid,
                $target_id,
                $arrival_time,
                $kid,
                $my_x,
                $my_y,
                time(),
                TransportTypes::TRANSPORT_TYPE_INTERNAL,
                (int)($amounts[ResourceTypes::RESOURCE_TYPE_FOOD] ?? 0),
                (int)($amounts[ResourceTypes::RESOURCE_TYPE_WOOD] ?? 0),
                (int)($amounts[ResourceTypes::RESOURCE_TYPE_STONE] ?? 0),
                (int)($amounts[ResourceTypes::RESOURCE_TYPE_GOLD] ?? 0)
            ]);

            $this->db->execute_query("UPDATE users SET daily_trades_count = daily_trades_count + 1 WHERE id = ?", [$uid]);

            Logger::get_instance()->log_game("TRADE", "INTERNAL_TRANSPORT", [
                "target_kingdom" => $target_id,
                "food" => (int)($amounts[ResourceTypes::RESOURCE_TYPE_FOOD] ?? 0),
                "wood" => (int)($amounts[ResourceTypes::RESOURCE_TYPE_WOOD] ?? 0),
                "stone" => (int)($amounts[ResourceTypes::RESOURCE_TYPE_STONE] ?? 0),
                "gold" => (int)($amounts[ResourceTypes::RESOURCE_TYPE_GOLD] ?? 0)
            ], $kid);

            $this->db->commit();

            return [
                "success" => true,
                "target_name" => $target_row["kingdomname"],
                "arrival_str" => convert_sec_to_str($seconds)
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            return ["success" => false, "error" => "Fehler beim Transport: " . $e->getMessage()];
        }
    }
}