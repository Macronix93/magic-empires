<?php

class Soldier
{
    private int $soldier_id;
    private string $s_name;
    private string $s_desc;
    private int $s_attack;
    private int $s_defense;
    private int $s_food;
    private int $s_gold;
    private int $s_stone;
    private int $s_wood;
    private int $s_villager;
    private int $s_req_level;
    private int $s_req_time;
    private int $s_score_gain;
    private int $s_soldier_category;
    private string $s_soldier_icon;

    public function get_soldier_id(): int
    {
        return $this->soldier_id;
    }

    public function set_soldier_id($id): void
    {
        $this->soldier_id = $id;
    }

    public function get_soldier_icon(string $class = "buildable-icons"): string
    {
        $icon_file = ($this->s_soldier_icon ?? "icon_error") . ".png";
        $path = "images/icons/" . $icon_file;
        $alt_text = !empty($this->s_name) ? $this->s_name : "Soldat";

        return "<img src='$path' class='$class' alt='$alt_text' title='$alt_text'>";
    }

    public function set_soldier_icon(string $icon): void
    {
        $this->s_soldier_icon = $icon;
    }

    public function get_soldier_category(): int
    {
        return $this->s_soldier_category;
    }

    public function set_soldier_category($category): void
    {
        $this->s_soldier_category = $category;
    }

    public function get_soldier_required_level(): int
    {
        return $this->s_req_level;
    }

    public function set_soldier_required_level($level): void
    {
        $this->s_req_level = $level;
    }

    public function get_soldier_time(): int
    {
        return $this->s_req_time;
    }

    public function set_soldier_time($time): void
    {
        $this->s_req_time = $time;
    }

    public function get_soldier_name(): string
    {
        return $this->s_name;
    }

    public function set_soldier_name($name): void
    {
        $this->s_name = $name;
    }

    public function get_soldier_food_cost(): int
    {
        return $this->s_food;
    }

    public function set_soldier_food_cost($food): void
    {
        $this->s_food = $food;
    }

    public function get_soldier_gold_cost(): int
    {
        return $this->s_gold;
    }

    public function set_soldier_gold_cost($gold): void
    {
        $this->s_gold = $gold;
    }

    public function get_soldier_stone_cost(): int
    {
        return $this->s_stone;
    }

    public function set_soldier_stone_cost($stone): void
    {
        $this->s_stone = $stone;
    }

    public function get_soldier_wood_cost(): int
    {
        return $this->s_wood;
    }

    public function set_soldier_wood_cost($wood): void
    {
        $this->s_wood = $wood;
    }

    public function get_soldier_villager_cost(): int
    {
        return $this->s_villager;
    }

    public function set_soldier_villager_cost($villager): void
    {
        $this->s_villager = $villager;
    }

    public function get_soldier_attack(): int
    {
        return $this->s_attack;
    }

    public function set_soldier_attack($attack): void
    {
        $this->s_attack = $attack;
    }

    public function get_soldier_defense(): int
    {
        return $this->s_defense;
    }

    public function set_soldier_defense($defense): void
    {
        $this->s_defense = $defense;
    }

    public function get_soldier_score_gain(): int
    {
        return $this->s_score_gain;
    }

    public function set_soldier_score_gain($score): void
    {
        $this->s_score_gain = $score;
    }

    public function get_soldier_description(): string
    {
        return $this->s_desc;
    }

    public function set_soldier_description($description): void
    {
        $this->s_desc = $description;
    }
}