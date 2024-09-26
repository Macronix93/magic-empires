<?php

class Soldier {
    private int $sid;
    private string $sname;
    private string $sdesc;
    private int $sattack;
    private int $sdefense;
    private int $sfood;
    private int $sgold;
    private int $svillager;
    private int $sreqlevel;
    private int $sreqtime;
    private int $sscoregain;

    public function set_soldier_id($id): void {
        $this->sid = $id;
    }

    public function get_soldier_id(): int {
        return $this->sid;
    }

    public function get_soldier_required_level(): int {
        return $this->sreqlevel;
    }

    public function set_soldier_required_level($level): void {
        $this->sreqlevel = $level;
    }

    public function get_soldier_time(): int {
        return $this->sreqtime;
    }

    public function set_soldier_time($time): void {
        $this->sreqtime = $time;
    }

    public function get_soldier_name(): string {
        return $this->sname;
    }

    public function set_soldier_name($name): void {
        $this->sname = $name;
    }

    public function get_soldier_food_cost(): int {
        return $this->sfood;
    }

    public function set_soldier_food_cost($food): void {
        $this->sfood = $food;
    }

    public function get_soldier_gold_cost(): int {
        return $this->sgold;
    }

    public function set_soldier_gold_cost($gold): void {
        $this->sgold = $gold;
    }

    public function get_soldier_villager_cost(): int {
        return $this->svillager;
    }

    public function set_soldier_villager_cost($villager): void {
        $this->svillager = $villager;
    }

    public function get_soldier_attack(): int {
        return $this->sattack;
    }

    public function set_soldier_attack($attack): void {
        $this->sattack = $attack;
    }

    public function get_soldier_defense(): int {
        return $this->sdefense;
    }

    public function set_soldier_defense($defense): void {
        $this->sdefense = $defense;
    }

    public function get_soldier_score_gain(): int {
        return $this->sscoregain;
    }

    public function set_soldier_score_gain($score): void {
        $this->sscoregain = $score;
    }

    public function get_soldier_icon(): string {
        return match ($this->sid) {
            0 => " <img src='images/icons/icon_militia.png' class='item-icons' alt='Milizsoldat'>",
            1 => " <img src='images/icons/icon_swordsman.png' class='item-icons' alt='Schwertkämpfer'>",
            2 => " <img src='images/icons/icon_thief.png' class='item-icons' alt='Dieb'>",
            3 => " <img src='images/icons/icon_conqueror.png' class='item-icons' alt='Eroberer'>",
            default => "ICON NOT FOUND",
        };
    }

    public function get_soldier_description(): string {
        return $this->sdesc;
    }

    public function set_soldier_description($description): void {
        $this->sdesc = $description;
    }
}