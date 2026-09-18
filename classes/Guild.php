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
    private int $last_settings_change = 0;
    private array $storage = [];

    public function __construct(User $user, ?int $guild_id = null)
    {
        $this->db = Database::get_instance()->get_connection();
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
            $this->last_settings_change = (int)$row["last_settings_change"];
            $this->storage = [
                "coal" => (int)$row["coal"], "iron" => (int)$row["iron"],
                "sapphire" => (int)$row["sapphire"], "diamond" => (int)$row["diamond"]
            ];
        }
    }

    public function create_guild(string $name, string $tag, string $motto = "", int $min_score = 0): ?string
    {
        $name = sanitize_input($name);
        $tag = sanitize_input($tag);
        $motto = sanitize_input($motto);
        $uid = $this->user->get_user_id();

        $error = $this->get_settings_error($name, $tag, $motto, $min_score);
        if (!empty($error)) {
            return $error;
        }

        $k = new Kingdom($this->user->get_current_kingdom());
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
                [$guild_id, GuildRanks::GUILD_LEADER, $now, $uid]
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

        send_server_message($target_id, $target["username"], $msg, MessageCategories::CATEGORY_GUILD);

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

        send_server_message($data["invited_by"], $data["inviter_name"], $msg, MessageCategories::CATEGORY_GUILD);

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
            return "Du kannst erst in " . convert_sec_to_str($wait_time) . " wieder einer Gilde beitreten.";
        }

        $target_guild_id = $guild_id;
        $inviter_id = null;

        $this->db->begin_transaction();

        try {
            if ($via_invite) {
                $inv_res = $this->db->execute_query(
                    "SELECT guild_id, invited_by FROM guild_invites WHERE id = ? AND user_id = ? AND expires_at > ? FOR UPDATE",
                    [$guild_id, $uid, $now]
                );
                $inv_data = $inv_res->fetch_assoc();

                if (!$inv_data) {
                    $this->db->rollback();
                    return "Diese Einladung ist nicht mehr gültig, wurde bereits abgelehnt oder ist abgelaufen.";
                }

                $target_guild_id = (int)$inv_data["guild_id"];
                $inviter_id = (int)$inv_data["invited_by"];
            }

            $guild_res = $this->db->execute_query("SELECT * FROM guilds WHERE id = ? FOR UPDATE", [$target_guild_id]);
            $guild = $guild_res->fetch_assoc();

            if (!$guild) {
                $this->db->rollback();
                return "Die Gilde, der du beitreten möchtest, existiert nicht mehr.";
            }

            $member_count = (int)$this->db->execute_query(
                "SELECT COUNT(*) FROM users WHERE guildid = ?",
                [$target_guild_id]
            )->fetch_column();

            $current_max_members = $this->get_max_members($target_guild_id);
            if ($member_count >= $current_max_members) {
                $this->db->rollback();
                return "Die Gilde ist bereits voll.";
            }

            if (!$via_invite) {
                if ((int)$guild["min_score"] === -1) {
                    $this->db->rollback();
                    return "Diese Gilde ist nur per Einladung erreichbar und kann nicht direkt betreten werden.";
                }

                if ($this->user->get_user_score() < (int)$guild["min_score"]) {
                    $this->db->rollback();
                    return "Dein Punktestand ist zu niedrig für diese Gilde.";
                }
            }

            $this->db->execute_query(
                "UPDATE users SET guildid = ?, guild_rank_id = ?, last_guild_join = ? WHERE id = ?",
                [$target_guild_id, GuildRanks::GUILD_MEMBER, $now, $uid]
            );
            $this->db->execute_query("DELETE FROM guild_invites WHERE user_id = ?", [$uid]);

            $new_member_name = $this->user->get_user_name();

            if ($inviter_id) {
                $inviter_name = $this->db->execute_query("SELECT username FROM users WHERE id = ?", [$inviter_id])->fetch_column();

                $msg_recruiter = "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                        "Rekrutierung erfolgreich",
                        "Deine Einladung zur Gilde wurde von <b>" . e($new_member_name) . "</b> akzeptiert.",
                        0, 0,
                        "Heißt das neue Mitglied im Gilden-Rat willkommen!",
                        "success"
                    ) . "</div>";

                send_server_message($inviter_id, $inviter_name, $msg_recruiter, MessageCategories::CATEGORY_GUILD);
            }

            $exclude_ids = [$uid];
            if ($inviter_id) {
                $exclude_ids[] = $inviter_id;
            }

            $this->notify_guild(
                "Neues Gilden-Mitglied",
                "<b>" . e($new_member_name) . "</b> ist deiner Gilde soeben beigetreten.",
                "",
                "success",
                $exclude_ids,
                null,
                $target_guild_id
            );

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
            return "Du kannst den Rang des Leaders nicht ändern!";
        }

        $my_rank = $this->user->get_guild_rank_id();
        $target_rank = (int)$target["guild_rank_id"];

        if (!$perms["is_founder"]) {
            if ($target_rank <= $my_rank) {
                return "Du kannst nur Mitglieder verwalten, die einen niedrigeren Rang als du haben!";
            }

            if ($rank_id <= $my_rank) {
                return "Du kannst niemanden auf deinen eigenen oder einen höheren Rang befördern!";
            }
        }

        $this->db->begin_transaction();

        try {
            if ($rank_id == GuildRanks::GUILD_LEADER) {
                if (!$perms["is_founder"]) {
                    return "Nur der aktuelle Leader kann die Gilde übertragen!";
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

            send_server_message($target_uid, $target["username"], $msg, MessageCategories::CATEGORY_GUILD);

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
            return "Der Leader kann nicht gekickt werden.";
        }

        $my_rank = $this->user->get_guild_rank_id();
        if (!$perms["is_founder"] && (int)$target["guild_rank_id"] <= $my_rank) {
            return "Du kannst nur Mitglieder entfernen, die einen niedrigeren Rang als du haben!";
        }

        $this->db->begin_transaction();

        try {
            $this->recall_all_stationed_troops($target_uid);
            $this->recall_all_mine_troops($target_uid);

            $this->db->execute_query("DELETE FROM guild_invites WHERE invited_by = ?", [$target_uid]);
            $this->db->execute_query("UPDATE users SET guildid = -1, guild_rank_id = NULL WHERE id = ?", [$target_uid]);

            $guild_data = $this->get_guild_info($my_guild);
            $msg = "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                    "Gildenausschluss",
                    "Du wurdest aus der Gilde <b>[" . e($guild_data["tag"]) . "] " . e($guild_data["name"]) . "</b> entfernt.",
                    0, 0,
                    "",
                    "error"
                ) . "</div>";

            send_server_message($target_uid, $target["username"], $msg, MessageCategories::CATEGORY_GUILD);

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
            $this->recall_all_mine_troops($uid);

            $this->db->execute_query("DELETE FROM guild_invites WHERE invited_by = ?", [$uid]);

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
                    $this->db->execute_query("UPDATE users SET guild_rank_id = ? WHERE id = ?", [GuildRanks::GUILD_LEADER, $successor["id"]]);

                    $msg = "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                            "Gildenführung",
                            "Der bisherige Gilden-Anführer hat die Gilde verlassen. <b>Du bist nun der neue Anführer!</b>",
                            0, 0, "", "success"
                        ) . "</div>";

                    send_server_message($successor["id"], $successor["username"], $msg, MessageCategories::CATEGORY_GUILD);
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

    public function get_min_score(): int
    {
        return $this->min_score;
    }

    public function get_last_settings_change(): int
    {
        return $this->last_settings_change;
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
            (SELECT SUM(ranking_points) FROM users WHERE guildid = g.id) as total_score,
            (" . GUILD_BASE_MEMBER_LIMIT . " + (IFNULL((SELECT level FROM guild_techs WHERE guild_id = g.id AND tech_id = " . GuildTechTypes::GUILD_TECH_MEMBER_LIMIT . "), 0) * " . GUILD_BONUS_MEMBER_LIMIT_PER_LVL . ")) as max_members
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
            (SELECT SUM(ranking_points) FROM users WHERE guildid = g.id) as score,
            (" . GUILD_BASE_MEMBER_LIMIT . " + (IFNULL((SELECT level FROM guild_techs WHERE guild_id = g.id AND tech_id = " . GuildTechTypes::GUILD_TECH_MEMBER_LIMIT . "), 0) * " . GUILD_BONUS_MEMBER_LIMIT_PER_LVL . ")) as max_members
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

        $error = $this->get_settings_error($name, $tag, $motto, $min_score);
        if (!empty($error)) {
            return $error;
        }

        $old_data = $this->get_guild_info($my_guild);
        $changed_fields = [];
        $identity_changed = false;

        if ($old_data["name"] !== $name) {
            $changed_fields[] = "Name";
            $identity_changed = true;
        }
        if ($old_data["tag"] !== $tag) {
            $changed_fields[] = "Tag";
            $identity_changed = true;
        }
        if ($old_data["motto"] !== $motto) {
            $changed_fields[] = "Motto";
        }
        if ((int)$old_data["min_score"] !== $min_score) {
            $changed_fields[] = "Beitritts-Limit";
        }

        if (empty($changed_fields)) {
            return null;
        }

        if ($identity_changed) {
            $now = time();
            $wait_time = (int)$old_data["last_settings_change"] + (GUILD_SETTINGS_CHANGE_COOLDOWN_DAYS * 86400) - $now;

            if ($wait_time > 0) {
                return "Gildenname und Tag können erst in " . convert_sec_to_str($wait_time) . " wieder geändert werden.";
            }

            $check = $this->db->execute_query(
                "SELECT id FROM guilds WHERE (name = ? OR tag = ?) AND id != ?",
                [$name, $tag, $my_guild]
            );

            if ($check->num_rows > 0) {
                return "Dieser Gildenname oder das Tag wird bereits von einer anderen Gilde verwendet.";
            }
        }

        if ($identity_changed) {
            $this->db->execute_query(
                "UPDATE guilds SET name = ?, tag = ?, motto = ?, min_score = ?, last_settings_change = ? WHERE id = ?",
                [$name, $tag, $motto, $min_score, time(), $my_guild]
            );
        } else {
            $this->db->execute_query(
                "UPDATE guilds SET motto = ?, min_score = ? WHERE id = ?",
                [$motto, $min_score, $my_guild]
            );
        }

        $changes_str = implode(", ", $changed_fields);
        $this->notify_guild(
            "Gilden-Update",
            "Die Gilden-Einstellungen wurden durch <b>" . $this->user->get_user_name() . "</b> aktualisiert:<br>
            <div style='text-align: center; margin-top: 15px;'><i>$changes_str</i></div>", "", "neutral", [$uid], GuildRanks::GUILD_OFFICER
        );

        return null;
    }

    private function get_settings_error(string $name, string $tag, string $motto, int $min_score): string
    {
        if (mb_strlen($name) < GUILD_NAME_MIN || mb_strlen($name) > GUILD_NAME_MAX) {
            return "Der Gildenname muss zwischen " . GUILD_NAME_MIN . " und " . GUILD_NAME_MAX . " Zeichen lang sein.";
        }
        if (!preg_match('/^[a-zA-Z0-9 äöüÄÖÜß\-_]+$/u', $name)) {
            return "Erlaubte Zeichen: Groß- und Kleinbuchstaben, Zahlen, _, - und Leerzeichen.";
        }
        if (is_name_monotonous($name)) {
            return "Dieser Gildenname ist zu eintönig!";
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
        if ($min_score < 0 && $min_score !== -1) {
            return "Mindestpunktzahl darf nicht negativ sein.";
        }
        if ($min_score > GUILD_MAX_MINIMUM_SCORE) {
            return "Die Mindestpunktzahl darf nicht höher als " . GUILD_MAX_MINIMUM_SCORE . " sein!";
        }
        return "";
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
                inviter.id as inviter_id,
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

        $check_result = check_image_content($file_tmp);

        if ($check_result === "loading") {
            return "Sicherheitsprüfung lädt noch... Bitte in 15 Sekunden erneut versuchen.";
        }
        if (str_starts_with($check_result, "error")) {
            return "Technischer Fehler bei der Bildprüfung: " . htmlspecialchars($check_result);
        }
        if ($check_result === "blocked") {
            return "Dein Bild wurde als unangemessen eingestuft und ist nicht erlaubt.";
        }
        if ($check_result !== "ok") {
            return "Bild konnte nicht verifiziert werden (" . htmlspecialchars($check_result) . ").";
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
            SELECT st.soldier_id, SUM(st.soldiercount) as soldiercount,
                   st.source_kingdom_id, st.target_kingdom_id, st.owner_id,
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
            WHERE (st.owner_id = ? OR k_tgt.userid = ?) 
              AND st.owner_id != k_tgt.userid
            GROUP BY st.owner_id, st.source_kingdom_id, st.target_kingdom_id, st.soldier_id,
                     k_src.mapx, k_src.mapy, k_src.kingdomname,
                     k_tgt.mapx, k_tgt.mapy, k_tgt.kingdomname,
                     u_owner.username, u_owner.id, u_host.username, u_host.id,
                     sl.soldiername, sl.icon
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

        $map_helper = new Map($this->user);
        $now = time();

        foreach ($stacks as $stack) {
            $info = $stack["info"];
            $units = $stack["units"];

            $travel = $map_helper->get_arrival_time(
                (int)$info["tgt_x"], (int)$info["tgt_y"],
                (int)$info["src_x"], (int)$info["src_y"],
                (int)$info["source_kingdom_id"],
                null, false, false, true
            );

            $this->db->execute_query("
                INSERT INTO events (actionid, userid, kingdomid, targetid, targetx, targety, arrivaltime, buildingtime, buildingname)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                ActionTypes::ACTION_SUPPORT_RETURN,
                (int)$info["owner_uid"],
                (int)$info["source_kingdom_id"],
                (int)$info["target_kingdom_id"],
                (int)$info["tgt_x"],
                (int)$info["tgt_y"],
                $now + $travel,
                $now,
                "Gilden-Rückzug"
            ]);
            $new_event_id = $this->db->insert_id;

            $insert_troops = [];
            $units_html = "<div style='display:flex; flex-wrap:wrap; gap:10px; justify-content:center; margin-top:15px;'>";

            foreach ($units as $u) {
                $insert_troops[] = "($new_event_id, " . (int)$u["soldier_id"] . ", " . (int)$u["soldiercount"] . ", " . (int)$u["soldiercount"] . ", " . (int)$info["source_kingdom_id"] . ")";

                $units_html .= BattleReportRenderer::render_unit_card(
                    $u["soldiername"],
                    (int)$u["soldiercount"],
                    0,
                    $u["icon"],
                    true
                );
            }
            $units_html .= "</div>";

            if (!empty($insert_troops)) {
                $this->db->query("INSERT INTO sent_troops (eventid, soldierid, soldiercount, initial_count, source_kingdom_id) VALUES " . implode(',', $insert_troops));
            }

            $this->db->execute_query("
                DELETE FROM stationed_troops 
                WHERE owner_id = ? AND source_kingdom_id = ? AND target_kingdom_id = ?
            ", [(int)$info["owner_uid"], (int)$info["source_kingdom_id"], (int)$info["target_kingdom_id"]]);

            $msg_owner = "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                    "Truppenrückzug: Allianz beendet",
                    "Da die Allianz mit <b>" . e($info["host_name"]) . "</b> nicht mehr besteht, haben deine Truppen das Königreich <b>" . e($info["tgt_name"]) . "</b> verlassen und den Rückmarsch angetreten.$units_html",
                    0, 0, "Ankunft in " . convert_sec_to_str($travel), "support"
                ) . "</div>";
            send_server_message((int)$info["owner_uid"], $info["owner_name"], $msg_owner, MessageCategories::CATEGORY_GUILD);

            $msg_host = "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                    "Unterstützung verloren",
                    "Aufgrund einer Beendigung der Gildenallianz haben die Truppen von <b>" . e($info["owner_name"]) . "</b> dein Königreich <b>" . e($info["tgt_name"]) . "</b> verlassen.$units_html",
                    0, 0, "Deine Verteidigung wurde geschwächt.", "error"
                ) . "</div>";
            send_server_message((int)$info["host_uid"], $info["host_name"], $msg_host, MessageCategories::CATEGORY_GUILD);
        }
    }

    public function handle_leader_deletion(int $user_id): void
    {
        $res = $this->db->execute_query("SELECT username, guildid, guild_rank_id FROM users WHERE id = ?", [$user_id]);
        $user_data = $res->fetch_assoc();

        if ($user_data && (int)$user_data["guild_rank_id"] === GuildRanks::GUILD_LEADER) {
            $guild_id = (int)$user_data["guildid"];
            $old_leader_name = $user_data["username"];

            $res_next = $this->db->execute_query("
                SELECT id, username FROM users 
                WHERE guildid = ? AND id != ? 
                ORDER BY guild_rank_id, ranking_points DESC 
                LIMIT 1",
                [$guild_id, $user_id]
            );

            $successor = $res_next->fetch_assoc();

            if ($successor) {
                $next_id = (int)$successor["id"];
                $next_name = $successor["username"];

                $this->db->execute_query("DELETE FROM guild_invites WHERE invited_by = ?", [$user_id]);
                $this->db->execute_query("UPDATE users SET guild_rank_id = ? WHERE id = ?", [GuildRanks::GUILD_LEADER, $next_id]);
                $this->db->execute_query("UPDATE guilds SET founder_id = ? WHERE id = ?", [$next_id, $guild_id]);

                $msg = "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                        "Gildenführung übertragen",
                        "Der bisherige Gilden-Anführer <b>" . e($old_leader_name) . "</b> hat das Reich verlassen. <br><br><b>Du bist nun der neue Anführer der Gilde!</b>",
                        0, 0,
                        "Verwalte deine Mitglieder weise und führe sie zu Ruhm.",
                        "success"
                    ) . "</div>";

                send_server_message($next_id, $next_name, $msg, MessageCategories::CATEGORY_GUILD);
            } else {
                $this->db->execute_query("DELETE FROM guilds WHERE id = ?", [$guild_id]);
            }
        }
    }

    public function calculate_tech_costs(array $tech, int $current_lvl): array
    {
        $m = $tech["multiplicator"];

        return [
            "food" => (int)(($tech["food_cost"] ?? 0) * pow($m, $current_lvl)),
            "wood" => (int)(($tech["wood_cost"] ?? 0) * pow($m, $current_lvl)),
            "stone" => (int)(($tech["stone_cost"] ?? 0) * pow($m, $current_lvl)),
            "gold" => (int)(($tech["gold_cost"] ?? 0) * pow($m, $current_lvl)),
            "coal" => (int)(($tech["coal_cost"] ?? 0) * pow($m, $current_lvl)),
            "iron" => (int)(($tech["iron_cost"] ?? 0) * pow($m, $current_lvl)),
            "sapphire" => (int)(($tech["sapphire_cost"] ?? 0) * pow($m, $current_lvl)),
            "diamond" => (int)(($tech["diamond_cost"] ?? 0) * pow($m, $current_lvl)),
            "time" => (int)(($tech["base_time"] ?? 3600) * pow($m, $current_lvl))
        ];
    }

    public function get_all_techs(): array
    {
        $query = "SELECT tl.*, IFNULL(gt.level, 0) as current_level 
              FROM guild_tech_list tl 
              LEFT JOIN guild_techs gt ON tl.id = gt.tech_id AND gt.guild_id = ?
              ORDER BY tl.id";
        return $this->db->execute_query($query, [$this->id])->fetch_all(MYSQLI_ASSOC);
    }

    public function is_researching(): bool
    {
        $res = $this->db->execute_query("SELECT 1 FROM events WHERE guild_id = ? AND actionid = ? LIMIT 1",
            [$this->id, ActionTypes::ACTION_RESEARCH_TECH]);
        return $res->num_rows > 0;
    }

    public function modify_storage_resource(string $res_key, int $diff): int
    {
        $allowed = ["coal", "iron", "sapphire", "diamond"];
        if (!in_array($res_key, $allowed) || $this->id <= 0) return 0;

        $max_limit = $this->get_storage_limit($res_key);

        $current = (int)$this->db->execute_query(
            "SELECT `$res_key` FROM guilds WHERE id = ? FOR UPDATE",
            [$this->id]
        )->fetch_column();

        $new_val = max(0, min($max_limit, $current + $diff));
        $actual_diff = $new_val - $current;

        $this->db->execute_query("UPDATE guilds SET `$res_key` = ? WHERE id = ?", [$new_val, $this->id]);
        $this->storage[$res_key] = $new_val;

        return $actual_diff;
    }

    public function get_tech_level(int $tech_id): int
    {
        $res = $this->db->execute_query("SELECT level FROM guild_techs WHERE guild_id = ? AND tech_id = ?",
            [$this->id, $tech_id]);
        return (int)($res->fetch_column() ?? 0);
    }

    public function get_storage_limit(string $res_key): int
    {
        $lvl = $this->get_tech_level(GuildTechTypes::GUILD_TECH_TYPE_STORAGE);

        $base = match ($res_key) {
            "coal" => GUILD_STORAGE_BASE_COAL,
            "iron" => GUILD_STORAGE_BASE_IRON,
            "sapphire" => GUILD_STORAGE_BASE_SAPPHIRE,
            "diamond" => GUILD_STORAGE_BASE_DIAMOND,
            default => 0
        };

        if ($lvl <= 0) {
            return $base;
        }

        return (int)round($base * pow(GUILD_STORAGE_INC_FACTOR, $lvl));
    }

    public function get_storage_amount(string $res_key): int
    {
        return $this->storage[$res_key] ?? 0;
    }

    public function get_active_project(): ?array
    {
        $res = $this->db->execute_query("
            SELECT gp.*, gtl.name, gtl.icon, gtl.multiplicator, 
                   gtl.food_cost, gtl.wood_cost, gtl.stone_cost, gtl.gold_cost,
                   gtl.coal_cost, gtl.iron_cost, gtl.sapphire_cost, gtl.diamond_cost,
                   gtl.base_time
            FROM guild_projects gp
            JOIN guild_tech_list gtl ON gp.tech_id = gtl.id
            WHERE gp.guild_id = ?",
            [$this->id]);
        return $res->fetch_assoc();
    }

    public function set_active_project(int $tech_id): void
    {
        $this->db->execute_query("
            INSERT INTO guild_projects (guild_id, tech_id) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE tech_id = VALUES(tech_id), 
            current_food = 0, current_wood = 0, current_stone = 0, current_gold = 0",
            [$this->id, $tech_id]);

        $this->db->execute_query("
            UPDATE guild_member_contributions 
            SET current_project_amount = 0 
            WHERE guild_id = ?",
            [$this->id]);
    }

    public function add_contribution(int $uid, array $amounts): void
    {
        $total = array_sum($amounts);

        $this->db->execute_query("
            UPDATE guild_projects SET 
                current_food = current_food + ?, current_wood = current_wood + ?, 
                current_stone = current_stone + ?, current_gold = current_gold + ?
            WHERE guild_id = ?",
            [$amounts[0], $amounts[1], $amounts[2], $amounts[3], $this->id]);

        $this->db->execute_query("
            INSERT INTO guild_member_contributions (guild_id, user_id, current_project_amount, food, wood, stone, gold) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            current_project_amount = current_project_amount + VALUES(current_project_amount),
            food = food + VALUES(food),
            wood = wood + VALUES(wood),
            stone = stone + VALUES(stone),
            gold = gold + VALUES(gold)",
            [$this->id, $uid, $total, $amounts[0], $amounts[1], $amounts[2], $amounts[3]]);
    }

    public function get_project_contributors(): mysqli_result
    {
        return $this->db->execute_query("
            SELECT u.id, u.username, c.current_project_amount as val 
            FROM guild_member_contributions c
            JOIN users u ON c.user_id = u.id
            WHERE c.guild_id = ? AND c.current_project_amount > 0
            ORDER BY c.current_project_amount DESC",
            [$this->id]);
    }

    public function get_top_contributors(): mysqli_result
    {
        return $this->db->execute_query("
            SELECT u.username, c.total_resources 
            FROM guild_member_contributions c
            JOIN users u ON c.user_id = u.id
            WHERE c.guild_id = ? AND c.total_resources > 0
            ORDER BY c.total_resources DESC LIMIT 10",
            [$this->id]);
    }

    public function cancel_active_project(): void
    {
        $project = $this->get_active_project();
        if ($project) {
            $cur_lvl = $this->get_tech_level($project["tech_id"]);
            $costs = $this->calculate_tech_costs($project, $cur_lvl);

            $special_keys = ["coal", "iron", "sapphire", "diamond"];
            foreach ($special_keys as $k) {
                if (($costs[$k] ?? 0) > 0) {
                    $this->modify_storage_resource($k, $costs[$k]);
                }
            }
        }

        $this->db->execute_query("DELETE FROM guild_projects WHERE guild_id = ?", [$this->id]);
        $this->db->execute_query("
            UPDATE guild_member_contributions 
            SET current_project_amount = 0, food = 0, wood = 0, stone = 0, gold = 0 
            WHERE guild_id = ?",
            [$this->id]
        );
    }

    public function notify_guild(string $title, string $main_text, string $sub_text = "", string $type = "neutral",
                                 array  $exclude_ids = [], ?int $min_rank_id = null, ?int $target_guild_id = null): void
    {
        $html = "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                $title,
                $main_text,
                0, 0,
                $sub_text,
                $type
            ) . "</div>";

        $query = "SELECT id, username FROM users WHERE guildid = ?";
        $params = [($target_guild_id !== null ? $target_guild_id : $this->id)];

        if (!empty($exclude_ids)) {
            $placeholders = implode(',', array_fill(0, count($exclude_ids), '?'));
            $query .= " AND id NOT IN ($placeholders)";
            $params = array_merge($params, $exclude_ids);
        }

        if ($min_rank_id !== null) {
            $query .= " AND guild_rank_id <= ?";
            $params[] = $min_rank_id;
        }

        $members = $this->db->execute_query($query, $params);

        while ($m = $members->fetch_assoc()) {
            send_server_message((int)$m["id"], $m["username"], $html, MessageCategories::CATEGORY_GUILD);
        }
    }

    public static function get_user_guild_tech_level(int $user_id, int $tech_id): int
    {
        if ($user_id <= 0) return 0;

        $db = Database::get_instance()->get_connection();

        $res = $db->execute_query("
            SELECT gt.level 
            FROM users u
            JOIN guild_techs gt ON u.guildid = gt.guild_id
            WHERE u.id = ? AND gt.tech_id = ?
        ", [$user_id, $tech_id]);

        return (int)($res->fetch_column() ?? 0);
    }

    public function get_max_members(?int $specific_guild_id = null): int
    {
        $gid = $specific_guild_id ?? $this->id;
        if (!$gid || $gid <= 0) return GUILD_BASE_MEMBER_LIMIT;

        $res = $this->db->execute_query("SELECT level FROM guild_techs WHERE guild_id = ? AND tech_id = ?",
            [$gid, GuildTechTypes::GUILD_TECH_MEMBER_LIMIT]);
        $tech_lvl = (int)($res->fetch_column() ?? 0);

        return GUILD_BASE_MEMBER_LIMIT + ($tech_lvl * GUILD_BONUS_MEMBER_LIMIT_PER_LVL);
    }

    private function recall_all_mine_troops(int $user_id): void
    {
        $res = $this->db->execute_query("
            SELECT DISTINCT mn.id as mine_id, mn.mapx, mn.mapy, mst.kingdom_id
            FROM mine_stationed_troops mst
            JOIN mines mn ON mst.mine_id = mn.id
            WHERE mst.user_id = ?
        ", [$user_id]);

        $now = time();

        while ($row = $res->fetch_assoc()) {
            $mid = (int)$row["mine_id"];
            $kid = (int)$row["kingdom_id"];
            $mx = (int)$row["mapx"];
            $my = (int)$row["mapy"];

            // Are there still other users in the mine?
            $other_users_count = (int)$this->db->execute_query("
                SELECT COUNT(DISTINCT user_id) 
                FROM mine_stationed_troops 
                WHERE mine_id = ? AND user_id != ?
            ", [$mid, $user_id])->fetch_column();

            // CASE A: Player is ALONE in the mine
            // Troops stay, guild id gets removed
            if ($other_users_count === 0) {
                $this->db->execute_query("
                    UPDATE mines 
                    SET claimed_guild_id = NULL, claimed_user_id = ? 
                    WHERE id = ?
                ", [$user_id, $mid]);
                continue;
            }

            // CASE B: SHARED mine with other guild members
            // Remove troops but calculate share of stone and gold
            $mine = $this->db->execute_query("SELECT * FROM mines WHERE id = ? FOR UPDATE", [$mid])->fetch_assoc();
            if (!$mine) continue;

            // Update mining progress up to this point
            $last_update = (int)($mine["last_update"] ?: $now);
            $elapsed = max(0, $now - $last_update);
            $current_atk = (float)$this->db->execute_query(
                "SELECT IFNULL(SUM(soldiercount * unit_atk), 0) FROM mine_stationed_troops WHERE mine_id = ?",
                [$mid]
            )->fetch_column();

            if ($elapsed > 0 && $current_atk > 0) {
                $max_rate = (float)$mine["work_total"] / MINE_MIN_DURATION_SECONDS;
                $effective_rate = min($current_atk * MINE_WORK_RATE_FACTOR, $max_rate);
                $work_delta = $effective_rate * $elapsed;

                $this->db->execute_query("
                    UPDATE mine_stationed_troops 
                    SET work_contributed = work_contributed + (? * ((soldiercount * unit_atk) / ?))
                    WHERE mine_id = ?
                ", [$work_delta, $current_atk, $mid]);

                $mine["work_done"] += $work_delta;
                $this->db->execute_query("UPDATE mines SET work_done = ?, last_update = ? WHERE id = ?", [$mine["work_done"], $now, $mid]);
            }

            // Calculate own work load
            $my_troops = $this->db->execute_query("
                SELECT * FROM mine_stationed_troops 
                WHERE mine_id = ? AND user_id = ? 
                FOR UPDATE
            ", [$mid, $user_id])->fetch_all(MYSQLI_ASSOC);

            if (empty($my_troops)) continue;

            $work_total = max(1, (int)$mine["work_total"]);
            $work_done = (int)$mine["work_done"];
            $mined_ratio = min(1.0, $work_done / $work_total);

            $my_work = array_sum(array_column($my_troops, "work_contributed"));
            $share = ($work_done > 0) ? min(1.0, $my_work / $work_done) : 0;

            // Give leaving user his share of stone and gold
            $loot_stone = (int)floor($mine["stone"] * $mined_ratio * $share);
            $loot_gold = (int)floor($mine["gold"] * $mined_ratio * $share);

            // Remove user from mine (Ores remain for remaining guild members)
            $this->db->execute_query("
                UPDATE mines SET 
                    stone = GREATEST(0, stone - ?),
                    gold = GREATEST(0, gold - ?),
                    last_update = ?
                WHERE id = ?
            ", [$loot_stone, $loot_gold, $now, $mid]);

            // Calculate movement time
            $res_k = $this->db->execute_query("SELECT mapx, mapy FROM kingdoms WHERE id = ?", [$kid])->fetch_assoc();
            $kx = (int)($res_k["mapx"] ?? 1);
            $ky = (int)($res_k["mapy"] ?? 1);

            $map_helper = new Map(new User($user_id, ""));
            $travel_time = $map_helper->get_arrival_time($kx, $ky, $mx, $my, $kid, MapFieldTypes::MAP_FIELD_MINE);

            // Create return event with loot
            $this->db->execute_query("
                INSERT INTO events (actionid, userid, kingdomid, targetid, targetx, targety, arrivaltime, buildingtime, 
                                    loot_food, loot_wood, loot_stone, loot_gold, loot_coal, loot_iron, loot_sapphire, loot_diamond, buildingname)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, 0, 0, 0, 0, 'Minen-Rückzug')
            ", [
                ActionTypes::ACTION_RETURN_TROOPS, $user_id, $kid,
                MapFieldTypes::MAP_FIELD_MINE, $mx, $my, $now + $travel_time, $now,
                $loot_stone, $loot_gold
            ]);
            $ret_id = $this->db->insert_id;

            // Insert troops in sent_troops table
            $grouped_troops = $this->db->execute_query("
                SELECT soldier_id, SUM(soldiercount) as soldiercount 
                FROM mine_stationed_troops 
                WHERE mine_id = ? AND user_id = ? 
                GROUP BY soldier_id
            ", [$mid, $user_id]);

            $insert_troops = [];
            while ($t = $grouped_troops->fetch_assoc()) {
                $sid = (int)$t["soldier_id"];
                $scnt = (int)$t["soldiercount"];
                $insert_troops[] = "($ret_id, $sid, $scnt, $scnt, $kid)";
            }

            if (!empty($insert_troops)) {
                $this->db->query("INSERT INTO sent_troops (eventid, soldierid, soldiercount, initial_count, source_kingdom_id) VALUES " . implode(',', $insert_troops));
            }

            // Remove user from mine
            $this->db->execute_query("DELETE FROM mine_stationed_troops WHERE mine_id = ? AND user_id = ?", [$mid, $user_id]);

            // If the user was the first in the mine -> transfer claimer id
            if ((int)$mine["claimed_user_id"] === $user_id) {
                $new_claimer = (int)$this->db->execute_query(
                    "SELECT user_id FROM mine_stationed_troops WHERE mine_id = ? LIMIT 1",
                    [$mid]
                )->fetch_column();

                if ($new_claimer > 0) {
                    $this->db->execute_query("UPDATE mines SET claimed_user_id = ? WHERE id = ?", [$new_claimer, $mid]);
                }
            }

            Logger::get_instance()->log_game("ECONOMY", "MINE_GUILD_LEAVE_RECALL", [
                "mine_id" => $mid,
                "coords" => "$mx:$my",
                "loot_stone" => $loot_stone,
                "loot_gold" => $loot_gold
            ], $kid);
        }
    }
}