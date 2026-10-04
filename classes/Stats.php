<?php

class Stats
{
    public static function update_player_stat(int $user_id, string $column, int $increment = 1): void
    {
        $db = Database::get_instance()->get_connection();
        $query = "INSERT INTO player_stats (userid, `$column`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `$column` = `$column` + VALUES(`$column`)";
        $db->execute_query($query, [$user_id, $increment]);
    }

    public static function update_global_stat(string $name, int $increment = 1): void
    {
        $db = Database::get_instance()->get_connection();
        $query = "UPDATE system_settings SET value = value + ? WHERE name = ?";
        $db->execute_query($query, [$increment, $name]);
    }
}