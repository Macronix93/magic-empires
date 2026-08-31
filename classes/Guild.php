<?php

class Guild
{
    private mysqli $db;
    private User $user;
    private ?int $id = null;
    private ?string $name = null;
    private ?string $tag = null;
    private ?string $motto = null;
    private int $min_score = 0;

    public function __construct(mysqli $db, User $user, ?int $guild_id = null)
    {
        $this->db = $db;
        $this->user = $user;

        if ($guild_id !== null && $guild_id > 0) {
            $this->load_guild($guild_id);
        }
    }

    public function load_guild(int $id): void
    {
        $res = $this->db->execute_query("SELECT * FROM guilds WHERE id = ?", [$id]);

        if ($row = $res->fetch_assoc()) {
            $this->id = (int)$row["id"];
            $this->name = $row["name"];
            $this->tag = $row["tag"];
            $this->motto = $row["motto"];
            $this->min_score = (int)$row["min_score"];
        }
    }

    public function create_guild(string $name, string $tag, string $motto = "", int $min_score = 0): ?string
    {
        $name = sanitize_input($name);
        $tag = sanitize_input($tag);
        $motto = sanitize_input($motto);
        $uid = $this->user->get_user_id();

        if (mb_strlen($name) < GUILD_NAME_MIN || mb_strlen($name) > GUILD_NAME_MAX) {
            return "Der Gildenname muss zwischen " . GUILD_NAME_MIN . " und " . GUILD_NAME_MAX . " Zeichen lang sein.";
        }
        if (!preg_match('/^[a-zA-Z0-9 äöüÄÖÜß\-_]+$/u', $name)) {
            return "Erlaubte Zeichen: Groß- und Kleinbuchstaben, Zahlen, _, - und Leerzeichen.";
        }
        if (mb_strlen($tag) < GUILD_TAG_MIN || mb_strlen($tag) > GUILD_TAG_MAX) {
            return "Das Gilden-Tag muss zwischen " . GUILD_TAG_MIN . " und " . GUILD_TAG_MAX . " Zeichen lang sein.";
        }
        if (!preg_match('/^[a-zA-Z0-9äöüÄÖÜß]+$/', $tag)) {
            return "Das Tag darf nur Buchstaben und Zahlen enthalten.";
        }
        if (!empty($motto) && (mb_strlen($motto) < GUILD_MOTTO_MIN || mb_strlen($motto) > GUILD_MOTTO_MAX)) {
            return "Motto darf zwischen " . GUILD_MOTTO_MIN . " und " . GUILD_MOTTO_MAX . " Zeichen lang sein.";
        }
        if ($min_score < 0) {
            return "Mindestpunktzahl darf nicht negativ sein.";
        }
        if ($min_score > GUILD_MAX_MINIMUM_SCORE) {
            return "Die Mindestpunktzahl darf nicht höher als " . GUILD_MAX_MINIMUM_SCORE . " sein!";
        }

        $k = new Kingdom($this->db, $this->user->get_current_kingdom());
        if ($k->get_kingdom_building_level(BuildingTypes::BUILDING_EMBASSY) <= 0) {
            return "Du benötigst eine Botschaft, um eine Gilde zu gründen!";
        }

        $check = $this->db->execute_query("SELECT id FROM guilds WHERE name = ? OR tag = ?", [$name, $tag]);
        if ($check->num_rows > 0) {
            return "Der Gilden-Name oder das Tag sind bereits vergeben.";
        }

        $now = time();
        $last_join = $this->db->execute_query("SELECT last_guild_join FROM users WHERE id = ?", [$uid])->fetch_column();
        $wait_time = (int)$last_join + GUILD_JOIN_COOLDOWN - $now;

        if ($wait_time > 0) {
            return "Gilden-Sperre: Du kannst erst in " . convert_sec_to_str($wait_time) . " wieder einer Gilde beitreten.";
        }

        $this->db->begin_transaction();

        try {
            // Create guild
            $this->db->execute_query(
                "INSERT INTO guilds (name, tag, motto, founder_id, min_score, max_members, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$name, $tag, $motto, $uid, $min_score, GUILD_BASE_MEMBER_LIMIT, $now]
            );
            $guild_id = $this->db->insert_id;

            // Update user with rank
            $this->db->execute_query(
                "UPDATE users SET guildid = ?, guild_rank_id = ?, last_guild_join = ? WHERE id = ?",
                [$guild_id, GuildRanks::GUILD_FOUNDER, $now, $uid]
            );

            $this->db->commit();

            return null;
        } catch (Exception $e) {
            $this->db->rollback();

            return "Fehler bei der Gründung: " . $e->getMessage();
        }
    }

    public function invite_user(int $target_id): ?string
    {
        $uid = $this->user->get_user_id();
        $perms = $this->get_user_permissions($uid);

        if (!$perms["can_invite"]) {
            return "Du hast keine Berechtigung, Spieler einzuladen.";
        }

        // Check if target is already in a guild
        $target = $this->db->execute_query("SELECT guildid, username FROM users WHERE id = ?", [$target_id])->fetch_assoc();

        if (!$target || $target["guildid"] != -1) {
            return "Dieser Spieler ist bereits in einer Gilde oder existiert nicht.";
        }

        $guild_id = $this->user->get_user_guild_id();
        $now = time();

        $check_invite = $this->db->execute_query(
            "SELECT id FROM guild_invites WHERE guild_id = ? AND user_id = ? AND expires_at > ?",
            [$guild_id, $target_id, $now]
        );
        if ($check_invite->num_rows > 0) {
            return "Dieser Spieler hat bereits eine laufende Einladung von deiner Gilde.";
        }

        $expires = time() + GUILD_INVITE_DURATION;

        $this->db->execute_query("
            INSERT INTO guild_invites (guild_id, user_id, invited_by, expires_at) 
            VALUES (?, ?, ?, ?) 
            ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at)",
            [$guild_id, $target_id, $uid, $expires]
        );

        $invite_id = $this->db->insert_id;
        $guild_name = $this->db->execute_query("SELECT name FROM guilds WHERE id = ?", [$guild_id])->fetch_column();

        $msg = "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                "Gilden-Einladung",
                "Du wurdest eingeladen, der Gilde <b>" . e($guild_name) . "</b> beizutreten.<br><br>
                <div style='display: flex; gap: 10px; justify-content: center;'>
                    <button data-on-click='acceptGuildInvite' data-id='$invite_id'>Annehmen</button>
                    <button data-on-click='declineGuildInvite' data-id='$invite_id'>Ablehnen</button>
                </div>",
                0, 0,
                "Die Einladung ist 48 Stunden gültig."
            ) . "</div>";

        send_server_message($target_id, $target["username"], $msg);

        return null;
    }

    public function decline_invite(int $invite_id): ?string
    {
        $uid = $this->user->get_user_id();

        $res = $this->db->execute_query("
            SELECT i.*, u.username as inviter_name, g.name as guild_name 
            FROM guild_invites i 
            JOIN users u ON i.invited_by = u.id 
            JOIN guilds g ON i.guild_id = g.id
            WHERE i.id = ? AND i.user_id = ?",
            [$invite_id, $uid]
        );
        $data = $res->fetch_assoc();

        if (!$data) {
            return "Diese Einladung ist nicht mehr gültig oder wurde bereits gelöscht.";
        }

        $this->db->execute_query("DELETE FROM guild_invites WHERE id = ?", [$invite_id]);

        $msg = "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                "Einladung abgelehnt",
                "Der Spieler <b>" . $this->user->get_user_name() . "</b> hat deine Einladung zu <b>" . e($data["guild_name"]) . "</b> abgelehnt.",
                0, 0, "", "error"
            ) . "</div>";

        send_server_message($data["invited_by"], $data["inviter_name"], $msg);

        return null;
    }

    public function join_guild(int $guild_id, bool $via_invite = false): ?string
    {
        $uid = $this->user->get_user_id();
        $now = time();

        $current_gid = $this->db->execute_query("SELECT guildid FROM users WHERE id = ?", [$uid])->fetch_column();
        if ($current_gid !== null && $current_gid > 0) {
            return "Du bist bereits Mitglied einer Gilde. Du musst diese erst verlassen.";
        }

        $last_join = $this->db->execute_query("SELECT last_guild_join FROM users WHERE id = ?", [$uid])->fetch_column();
        $wait_time = (int)$last_join + GUILD_JOIN_COOLDOWN - $now;

        if ($wait_time > 0) {
            return "Gilden-Sperre: Du kannst erst in " . convert_sec_to_str($wait_time) . " wieder einer Gilde beitreten.";
        }

        $target_guild_id = $guild_id;
        $inviter_id = null;

        if ($via_invite) {
            $inv_res = $this->db->execute_query(
                "SELECT guild_id, invited_by FROM guild_invites WHERE id = ? AND user_id = ? AND expires_at > ?",
                [$guild_id, $uid, $now]
            );
            $inv_data = $inv_res->fetch_assoc();

            if (!$inv_data) {
                return "Diese Einladung ist nicht mehr gültig, wurde bereits abgelehnt oder ist abgelaufen.";
            }

            $target_guild_id = (int)$inv_data["guild_id"];
            $inviter_id = (int)$inv_data["invited_by"];
        }

        $guild = $this->db->execute_query("
            SELECT g.*, (SELECT COUNT(*) FROM users WHERE guildid = g.id) as cur_members 
            FROM guilds g WHERE id = ?",
            [$target_guild_id]
        )->fetch_assoc();

        if (!$guild) {
            return "Die Gilde, der du beitreten möchtest, existiert nicht mehr.";
        }

        if ($guild["cur_members"] >= $guild["max_members"]) {
            return "Die Gilde ist bereits voll.";
        }

        if (!$via_invite && $this->user->get_user_score() < $guild["min_score"]) {
            return "Dein Punktestand ist zu niedrig für diese Gilde.";
        }

        $this->db->begin_transaction();

        try {
            $this->db->execute_query("UPDATE users SET guildid = ?, guild_rank_id = ?, last_guild_join = ? WHERE id = ?",
                [$target_guild_id, GuildRanks::GUILD_MEMBER, $now, $uid]);
            $this->db->execute_query("DELETE FROM guild_invites WHERE user_id = ?", [$uid]);

            $new_member_name = $this->user->get_user_name();

            // Special Msg for the Recruiter only
            if ($inviter_id) {
                $inviter_name = $this->db->execute_query("SELECT username FROM users WHERE id = ?", [$inviter_id])->fetch_column();

                $msg_recruiter = "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                        "Rekrutierung erfolgreich",
                        "Deine Einladung zur Gilde wurde von <b>" . e($new_member_name) . "</b> akzeptiert.",
                        0, 0,
                        "Heißt das neue Mitglied im Gilden-Rat willkommen!",
                        "success"
                    ) . "</div>";

                send_server_message($inviter_id, $inviter_name, $msg_recruiter);
            }

            // Broadcast Msg to all other members
            $exclude_ids = [$uid];
            if ($inviter_id) $exclude_ids[] = $inviter_id;

            $placeholders = implode(',', array_fill(0, count($exclude_ids), '?'));
            $params = array_merge([$target_guild_id], $exclude_ids);

            $members_res = $this->db->execute_query(
                "SELECT id, username FROM users WHERE guildid = ? AND id NOT IN ($placeholders)",
                $params
            );

            $msg_all = "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                    "Neues Gilden-Mitglied",
                    "<b>" . e($new_member_name) . "</b> ist deiner Gilde soeben beigetreten.",
                    0, 0,
                    "",
                    "success"
                ) . "</div>";

            while ($m = $members_res->fetch_assoc()) {
                send_server_message((int)$m["id"], $m["username"], $msg_all);
            }

            $res_max = $this->db->execute_query(
                "SELECT MAX(id) FROM guild_chat WHERE guild_id = ?",
                [$target_guild_id]
            );
            $max_msg_id = $res_max->fetch_row()[0] ?? 0;

            $this->db->execute_query(
                "UPDATE users SET last_guild_chat_id = ? WHERE id = ?",
                [$max_msg_id, $uid]
            );

            $this->db->commit();

            return null;
        } catch (Exception) {
            $this->db->rollback();

            return "Fehler beim Beitritt.";
        }
    }

    public function set_member_rank(int $target_uid, int $rank_id): ?string
    {
        $uid = $this->user->get_user_id();
        $my_guild = $this->user->get_user_guild_id();
        $perms = $this->get_user_permissions($uid);

        if (!$perms["can_edit_settings"]) {
            return "Du hast dazu keine Berechtigung.";
        }

        if ($target_uid === $uid) {
            return "Du kannst deinen eigenen Rang nicht ändern.";
        }

        $rank_exists = $this->db->execute_query("SELECT 1 FROM guild_rank_list WHERE id = ?", [$rank_id]);
        if ($rank_exists->num_rows === 0) {
            return "Dieser Rang existiert nicht!";
        }

        $target_res = $this->db->execute_query("
            SELECT u.username, u.guild_rank_id, rl.is_founder 
            FROM users u 
            JOIN guild_rank_list rl ON u.guild_rank_id = rl.id 
            WHERE u.id = ? AND u.guildid = ?",
            [$target_uid, $my_guild]
        );
        $target = $target_res->fetch_assoc();

        if (!$target) {
            return "Spieler nicht gefunden.";
        }

        if ($target["is_founder"] && !$perms["is_founder"]) {
            return "Du kannst den Rang des Gründers nicht ändern!";
        }

        $this->db->begin_transaction();

        try {
            if ($rank_id == GuildRanks::GUILD_FOUNDER) {
                if (!$perms["is_founder"]) {
                    return "Nur der aktuelle Gründer kann die Gilde übertragen!";
                }

                $this->db->execute_query("UPDATE users SET guild_rank_id = ? WHERE id = ?", [GuildRanks::GUILD_OFFICER, $uid]);
                $this->db->execute_query("UPDATE guilds SET founder_id = ? WHERE id = ?", [$target_uid, $my_guild]);
            }

            $this->db->execute_query("UPDATE users SET guild_rank_id = ? WHERE id = ?", [$rank_id, $target_uid]);

            $rank_name = $this->db->execute_query("SELECT rank_name FROM guild_rank_list WHERE id = ?", [$rank_id])->fetch_column();
            $msg = "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                    "Rangänderung",
                    "Dein Rang in der Gilde wurde auf <b>" . e($rank_name) . "</b> geändert.",
                    0, 0, "Veranlasst durch " . $this->user->get_user_name()
                ) . "</div>";

            send_server_message($target_uid, $target["username"], $msg);

            $this->db->commit();

            return null;
        } catch (Exception) {
            $this->db->rollback();

            return "Fehler.";
        }
    }

    public function kick_member(int $target_uid): ?string
    {
        $uid = $this->user->get_user_id();
        $perms = $this->get_user_permissions($uid);
        $my_guild = $this->user->get_user_guild_id();

        if (!$perms["can_kick"]) {
            return "Du darfst keine Mitglieder kicken!";
        }

        if ($target_uid === $uid) {
            return "Du kannst dich nicht selbst kicken!";
        }

        $res = $this->db->execute_query("
            SELECT u.username, rl.is_founder 
            FROM users u 
            JOIN guild_rank_list rl ON u.guild_rank_id = rl.id 
            WHERE u.id = ? AND u.guildid = ?",
            [$target_uid, $my_guild]
        );
        $target = $res->fetch_assoc();

        if (!$target) {
            return "Spieler nicht gefunden oder bereits nicht mehr in der Gilde.";
        }

        if ($target["is_founder"]) {
            return "Der Gründer kann nicht gekickt werden.";
        }

        $this->db->begin_transaction();
        try {
            $this->recall_all_stationed_troops($target_uid);

            $this->db->execute_query("UPDATE users SET guildid = -1, guild_rank_id = NULL WHERE id = ?", [$target_uid]);

            $guild_data = $this->get_guild_info($my_guild);
            $msg = "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                    "Gildenausschluss",
                    "Du wurdest aus der Gilde <b>[" . e($guild_data["tag"]) . "] " . e($guild_data["name"]) . "</b> entfernt.",
                    0, 0,
                    "",
                    "error"
                ) . "</div>";

            send_server_message($target_uid, $target["username"], $msg);

            $this->db->commit();

            return null;
        } catch (Exception) {
            $this->db->rollback();

            return "Fehler beim Rauswurf.";
        }
    }

    public function leave_guild(): ?string
    {
        $uid = $this->user->get_user_id();
        $my_guild = $this->user->get_user_guild_id();

        if ($my_guild <= 0) {
            return "Du bist in keiner Gilde.";
        }

        $perms = $this->get_user_permissions($uid);

        $this->db->begin_transaction();

        try {
            $this->recall_all_stationed_troops($uid);

            if ($perms["is_founder"]) {
                $res = $this->db->execute_query("
                    SELECT id, username FROM users 
                    WHERE guildid = ? AND id != ? 
                    ORDER BY guild_rank_id, ranking_points DESC LIMIT 1",
                    [$my_guild, $uid]
                );
                $successor = $res->fetch_assoc();

                if ($successor) {
                    $this->db->execute_query("UPDATE guilds SET founder_id = ? WHERE id = ?", [$successor["id"], $my_guild]);
                    $this->db->execute_query("UPDATE users SET guild_rank_id = ? WHERE id = ?", [GuildRanks::GUILD_FOUNDER, $successor["id"]]);

                    $msg = "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                            "Gildenführung",
                            "Der bisherige Gilden-Anführer hat die Gilde verlassen. <b>Du bist nun der neue Anführer!</b>",
                            0, 0, "", "success"
                        ) . "</div>";

                    send_server_message($successor["id"], $successor["username"], $msg);
                } else {
                    $this->db->execute_query("DELETE FROM guilds WHERE id = ?", [$my_guild]);
                }
            }

            $this->db->execute_query("UPDATE users SET guildid = -1, guild_rank_id = NULL WHERE id = ?", [$uid]);
            $this->db->commit();

            return null;
        } catch (Exception) {
            $this->db->rollback();

            return "Fehler beim Verlassen.";
        }
    }

    public function cancel_invite(int $invite_id): ?string
    {
        $my_guild = $this->user->get_user_guild_id();
        $rank_id = $this->user->get_guild_rank_id();

        if ($rank_id > GuildRanks::GUILD_OFFICER) {
            return "Nur die Gildenführung kann Einladungen zurückziehen.";
        }

        $res = $this->db->execute_query("SELECT guild_id FROM guild_invites WHERE id = ?", [$invite_id]);
        $inv_data = $res->fetch_assoc();

        if (!$inv_data || (int)$inv_data["guild_id"] !== $my_guild) {
            return "Die Einladung existiert nicht mehr oder wurde bereits vom Empfänger verarbeitet.";
        }

        $this->db->execute_query("DELETE FROM guild_invites WHERE id = ?", [$invite_id]);
        return null;
    }

    public function get_id(): ?int
    {
        return $this->id;
    }

    public function get_name(): ?string
    {
        return $this->name;
    }

    public function get_tag(): ?string
    {
        return $this->tag;
    }

    public function get_motto(): ?string
    {
        return $this->motto;
    }

    public function get_min_score(): ?int
    {
        return $this->min_score;
    }

    public function get_guild_info(int $guild_id): ?array
    {
        return $this->db->execute_query("SELECT * FROM guilds WHERE id = ?", [$guild_id])->fetch_assoc();
    }

    public function get_ranks(): mysqli_result
    {
        return $this->db->query("SELECT * FROM guild_rank_list ORDER BY id");
    }

    public function get_members_detailed(int $guild_id): mysqli_result
    {
        return $this->db->execute_query("
            SELECT u.id, u.username, u.ranking_points, rl.rank_name, rl.rank_color, rl.is_founder, u.guild_rank_id as rank_id
            FROM users u
            JOIN guild_rank_list rl ON u.guild_rank_id = rl.id
            WHERE u.guildid = ?
            ORDER BY rl.id, u.ranking_points DESC",
            [$guild_id]
        );
    }

    public function get_guild_list(): mysqli_result
    {
        return $this->db->query("
            SELECT g.*, u.username as founder_name, 
            (SELECT COUNT(*) FROM users WHERE guildid = g.id) as member_count,
            (SELECT SUM(ranking_points) FROM users WHERE guildid = g.id) as total_score
            FROM guilds g
            JOIN users u ON g.founder_id = u.id
            ORDER BY total_score DESC
        ");
    }

    public function get_user_permissions(int $user_id): array
    {
        $res = $this->db->execute_query("
            SELECT rl.* FROM users u 
            JOIN guild_rank_list rl ON u.guild_rank_id = rl.id 
            WHERE u.id = ?",
            [$user_id]
        );

        $perms = $res->fetch_assoc();
        return $perms ?: [
            "can_invite" => 0,
            "can_kick" => 0,
            "can_edit_settings" => 0,
            "is_founder" => 0
        ];
    }

    public function get_guild_details(int $guild_id): ?array
    {
        return $this->db->execute_query("
            SELECT g.*, u.username as founder_name,
            (SELECT COUNT(*) FROM users WHERE guildid = g.id) as members,
            (SELECT SUM(ranking_points) FROM users WHERE guildid = g.id) as score
            FROM guilds g JOIN users u ON g.founder_id = u.id WHERE g.id = ?",
            [$guild_id])->fetch_assoc();
    }

    public function update_settings(string $name, string $tag, string $motto, int $min_score): ?string
    {
        $uid = $this->user->get_user_id();
        $perms = $this->get_user_permissions($uid);
        $my_guild = $this->user->get_user_guild_id();
        $tag = sanitize_input($tag);
        $motto = sanitize_input($motto);
        $name = sanitize_input($name);

        if (!$perms["can_edit_settings"]) {
            return "Keine Berechtigung.";
        }

        if (mb_strlen($name) < GUILD_NAME_MIN || mb_strlen($name) > GUILD_NAME_MAX) {
            return "Der Gildenname muss zwischen " . GUILD_NAME_MIN . " und " . GUILD_NAME_MAX . " Zeichen lang sein.";
        }
        if (!preg_match('/^[a-zA-Z0-9 äöüÄÖÜß\-_]+$/u', $name)) {
            return "Erlaubte Zeichen: Groß- und Kleinbuchstaben, Zahlen, _, - und Leerzeichen.";
        }
        if (mb_strlen($tag) < GUILD_TAG_MIN || mb_strlen($tag) > GUILD_TAG_MAX) {
            return "Das Gilden-Tag muss zwischen " . GUILD_TAG_MIN . " und " . GUILD_TAG_MAX . " Zeichen lang sein.";
        }
        if (!preg_match('/^[a-zA-Z0-9äöüÄÖÜß]+$/', $tag)) {
            return "Das Tag darf nur Buchstaben und Zahlen enthalten.";
        }
        if (!empty($motto) && (mb_strlen($motto) < GUILD_MOTTO_MIN || mb_strlen($motto) > GUILD_MOTTO_MAX)) {
            return "Motto darf zwischen " . GUILD_MOTTO_MIN . " und " . GUILD_MOTTO_MAX . " Zeichen lang sein.";
        }
        if ($min_score < 0) {
            return "Mindestpunktzahl darf nicht negativ sein.";
        }
        if ($min_score > GUILD_MAX_MINIMUM_SCORE) {
            return "Die Mindestpunktzahl darf nicht höher als " . GUILD_MAX_MINIMUM_SCORE . " sein!";
        }

        $check = $this->db->execute_query(
            "SELECT id FROM guilds WHERE (name = ? OR tag = ?) AND id != ?",
            [$name, $tag, $my_guild]
        );
        if ($check->num_rows > 0) {
            return "Dieser Name oder das Tag wird bereits von einer anderen Gilde verwendet.";
        }

        $this->db->execute_query(
            "UPDATE guilds SET name = ?, tag = ?, motto = ?, min_score = ? WHERE id = ?",
            [$name, $tag, $motto, $min_score, $my_guild]
        );

        $msg = "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                "Gilden-Update",
                "Die Gilden-Einstellungen wurden durch <b>" . $this->user->get_user_name() . "</b> aktualisiert.",
                0, 0, "Die neuen Kriterien sind ab sofort gültig."
            ) . "</div>";

        $leaders = $this->db->execute_query("
            SELECT u.id, u.username FROM users u 
            JOIN guild_rank_list rl ON u.guild_rank_id = rl.id 
            WHERE u.guildid = ? AND rl.can_edit_settings = 1 AND u.id != ?",
            [$my_guild, $uid]
        );

        while ($l = $leaders->fetch_assoc()) {
            send_server_message($l["id"], $l["username"], $msg);
        }
        return null;
    }

    public function delete_invite_msg($db, $uid): void
    {
        if (isset($_GET["msg_id"])) {
            $msg_id = (int)$_GET["msg_id"];

            $db->execute_query("DELETE FROM server_messages WHERE id = ? AND receiverid = ?", [$msg_id, $uid]);
        }
    }

    public function get_pending_invites(int $guild_id): mysqli_result
    {
        return $this->db->execute_query("
            SELECT 
                i.id, 
                u.id AS invited_user_id, 
                u.username, 
                i.expires_at, 
                inviter.username as inviter_name
            FROM guild_invites i
            JOIN users u ON i.user_id = u.id
            JOIN users inviter ON i.invited_by = inviter.id
            WHERE i.guild_id = ? AND i.expires_at > ?
            ORDER BY i.expires_at",
            [$guild_id, time()]
        );
    }

    public function render_badge(?int $id = null, ?string $tag = null, ?string $name = null): string
    {
        $gid = $id ?? $this->id;
        $gtag = $tag ?? $this->tag;
        $gname = $name ?? $this->name;

        if (!$gid || $gid <= 0) {
            return "<i>Keine Gilde</i>";
        }

        $avatar_path = $this->get_avatar($gid);
        $popup_id = "g_av_pop_" . $gid;

        return "
            <div class='image-and-user'>
                <div class='avatar-container popup' id='$popup_id'>
                    <img class='user-image' src='$avatar_path' alt='Wappen'>
                    <div id='{$popup_id}_box' class='popupbox avatar-popup'>
                        <img src='$avatar_path' style='width: 80px; height: 80px; border-radius: 5px;' alt='Gildenwappen'>
                    </div>
                </div>
                <span style='cursor: pointer;' 
                      data-on-click='openGuildInfo' 
                      data-id='$gid'>
                    <b style='color: var(--link-color);'>[" . e($gtag) . "]</b> " . e($gname) . "
                </span>
            </div>";
    }

    public function get_avatar(?int $specific_id = null): string
    {
        $target_id = $specific_id ?? $this->id;
        if (!$target_id || $target_id <= 0) {
            return DEFAULT_GUILD_AVATAR;
        }

        $hashed_name = substr(hash("sha256", $target_id . "GUILD_AVATAR_SALT"), 0, 12);
        $directory = __DIR__ . "/../" . UPLOADS_FILE_PATH . GUILD_UPLOADS_FILE_PATH;

        $files = glob($directory . $hashed_name . ".*");

        if (!empty($files)) {
            $info = pathinfo($files[0]);

            return UPLOADS_FILE_PATH . GUILD_UPLOADS_FILE_PATH . $hashed_name . "." . $info["extension"] . "?t=" . filemtime($files[0]);
        }

        return DEFAULT_GUILD_AVATAR;
    }

    public function update_avatar(array $file): ?string
    {
        $guild_id = $this->id;
        if (!$guild_id) {
            return "Gilde nicht geladen.";
        }

        $g_data = $this->db->execute_query("SELECT last_avatar_change FROM guilds WHERE id = ?", [$guild_id])->fetch_assoc();
        $days_since = (time() - $g_data["last_avatar_change"]) / 86400;

        if ($days_since < AVATAR_CHANGE_COOLDOWN_DAYS && !$this->user->is_admin()) {
            $wait = ceil(AVATAR_CHANGE_COOLDOWN_DAYS - $days_since);
            return "Wappen-Änderung erst in $wait Tagen wieder möglich.";
        }

        $file_tmp = $file["tmp_name"];
        $file_size = $file["size"];
        $file_error = $file["error"];
        $ext = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
        $allowed = ["jpg", "jpeg", "png", "gif"];

        if ($file_error !== 0) {
            return "Dateifehler beim Upload.";
        }
        if (!in_array($ext, $allowed)) {
            return "Format nicht erlaubt (nur JPG, PNG, GIF).";
        }
        if ($file_size > MAX_UPLOAD_FILE_SIZE * 1024) {
            return "Datei zu groß (Max. " . MAX_UPLOAD_FILE_SIZE . " KB).";
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file_tmp);
        $allowed_mimes = ["image/jpeg", "image/pjpeg", "image/png", "image/x-png", "image/gif"];
        if (!in_array($mime, $allowed_mimes)) {
            return "Ungültiger Bildinhalt.";
        }

        $nsfw_result = check_image_content($file_tmp);
        if ($nsfw_result === "loading") {
            return "Sicherheitsprüfung läuft noch, bitte erneut versuchen.";
        }
        if (is_string($nsfw_result) && str_starts_with($nsfw_result, "error")) {
            return "Sicherheitscheck fehlgeschlagen.";
        }
        if ((float)$nsfw_result > 0.8) {
            return "Das Bild wurde als unangemessen eingestuft.";
        }

        $hashed_name = substr(hash("sha256", $guild_id . "GUILD_AVATAR_SALT"), 0, 12);
        $directory = __DIR__ . "/../" . UPLOADS_FILE_PATH . GUILD_UPLOADS_FILE_PATH;
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        array_map("unlink", glob($directory . $hashed_name . ".*"));

        $target_path = $directory . $hashed_name . "." . $ext;

        if (move_uploaded_file($file_tmp, $target_path)) {
            $this->db->execute_query("UPDATE guilds SET last_avatar_change = ? WHERE id = ?", [time(), $guild_id]);
            return null;
        }

        return "Speicherfehler auf dem Server.";
    }

    private function recall_all_stationed_troops(int $user_id): void
    {
        $res = $this->db->execute_query("
            SELECT st.*, 
                   k_src.mapx as src_x, k_src.mapy as src_y, k_src.kingdomname as src_name,
                   k_tgt.mapx as tgt_x, k_tgt.mapy as tgt_y, k_tgt.kingdomname as tgt_name,
                   u_owner.username as owner_name, u_owner.id as owner_uid,
                   u_host.username as host_name, u_host.id as host_uid,
                   sl.soldiername, sl.icon
            FROM stationed_troops st
            JOIN kingdoms k_src ON st.source_kingdom_id = k_src.id
            JOIN kingdoms k_tgt ON st.target_kingdom_id = k_tgt.id
            JOIN users u_owner ON st.owner_id = u_owner.id
            JOIN users u_host ON k_tgt.userid = u_host.id
            JOIN soldier_list sl ON st.soldier_id = sl.id
            WHERE st.owner_id = ? OR k_tgt.userid = ?
        ", [$user_id, $user_id]);

        $stacks = [];

        while ($t = $res->fetch_assoc()) {
            $key = $t["owner_uid"] . '_' . $t["source_kingdom_id"] . '_' . $t["target_kingdom_id"];
            if (!isset($stacks[$key])) {
                $stacks[$key] = [
                    "info" => $t,
                    "units" => []
                ];
            }
            $stacks[$key]["units"][] = $t;
        }

        $map_helper = new Map($this->db, $this->user);
        $now = time();

        foreach ($stacks as $stack) {
            $info = $stack["info"];
            $units = $stack["units"];

            $travel = $map_helper->get_arrival_time(
                $info["tgt_x"], $info["tgt_y"],
                $info["src_x"], $info["src_y"],
                $info["source_kingdom_id"],
                null, false, false, true
            );

            $this->db->execute_query("
                INSERT INTO events (actionid, userid, kingdomid, targetid, targetx, targety, arrivaltime, buildingtime, buildingname)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                ActionTypes::ACTION_SUPPORT_RETURN,
                $info["owner_uid"],
                $info["source_kingdom_id"],
                $info["target_kingdom_id"],
                $info["src_x"],
                $info["src_y"],
                $now + $travel,
                $now,
                "Gilden-Rückzug"
            ]);

            $new_event_id = $this->db->insert_id;
            $units_html = "<div style='display:flex; flex-wrap:wrap; gap:10px; justify-content:center; margin-top:15px;'>";

            foreach ($units as $u) {
                $this->db->execute_query("
                    INSERT INTO sent_troops (eventid, soldierid, soldiercount, initial_count) 
                    VALUES (?, ?, ?, ?)
                ", [$new_event_id, $u["soldier_id"], $u["soldiercount"], $u["soldiercount"]]);

                $units_html .= BattleReportRenderer::render_unit_card(
                    $u["soldiername"],
                    $u["soldiercount"],
                    0,
                    $u["icon"],
                    true
                );

                $this->db->execute_query("DELETE FROM stationed_troops WHERE id = ?", [$u["id"]]);
            }
            $units_html .= "</div>";

            $msg_owner = "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                    "Truppenrückzug: Allianz beendet",
                    "Da die Allianz mit <b>" . e($info["host_name"]) . "</b> nicht mehr besteht, haben deine Truppen das Königreich <b>" . e($info["tgt_name"]) . "</b> verlassen und den Rückmarsch angetreten.$units_html",
                    0, 0, "Ankunft in " . convert_sec_to_str($travel), "support"
                ) . "</div>";
            send_server_message($info["owner_uid"], $info["owner_name"], $msg_owner, MessageCategories::CATEGORY_WAR);

            $msg_host = "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                    "Unterstützung verloren",
                    "Aufgrund des Gilden-Austritts/Kicks haben die Truppen von <b>" . e($info["owner_name"]) . "</b> dein Königreich <b>" . e($info["tgt_name"]) . "</b> verlassen.$units_html",
                    0, 0, "Deine Verteidigung wurde geschwächt.", "error"
                ) . "</div>";
            send_server_message($info["host_uid"], $info["host_name"], $msg_host, MessageCategories::CATEGORY_WAR);
        }
    }
}