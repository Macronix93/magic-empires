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

    public function setSoldierID($id): void {
        $this->sid = $id;
    }

    public function getSoldierID(): int {
        return $this->sid;
    }

    public function getSoldierRequiredLevel(): int {
        return $this->sreqlevel;
    }

    public function setSoldierRequiredLevel($level): void {
        $this->sreqlevel = $level;
    }

    public function getSoldierTime(): int {
        return $this->sreqtime;
    }

    public function setSoldierTime($time): void {
        $this->sreqtime = $time;
    }

    public function getSoldierName(): string {
        return $this->sname;
    }

    public function setSoldierName($name): void {
        $this->sname = $name;
    }

    public function getSoldierFoodCost(): int {
        return $this->sfood;
    }

    public function setSoldierFoodCost($food): void {
        $this->sfood = $food;
    }

    public function getSoldierGoldCost(): int {
        return $this->sgold;
    }

    public function setSoldierGoldCost($gold): void {
        $this->sgold = $gold;
    }

    public function getSoldierVillagerCost(): int {
        return $this->svillager;
    }

    public function setSoldierVillagerCost($villager): void {
        $this->svillager = $villager;
    }

    public function getSoldierAttack(): int {
        return $this->sattack;
    }

    public function setSoldierAttack($attack): void {
        $this->sattack = $attack;
    }

    public function getSoldierDefense(): int {
        return $this->sdefense;
    }

    public function setSoldierDefense($defense): void {
        $this->sdefense = $defense;
    }

    public function getSoldierScoreGain(): int {
        return $this->sscoregain;
    }

    public function setSoldierScoreGain($score): void {
        $this->sscoregain = $score;
    }

    public function getSoldierIcon(): string {
        return match ($this->sid) {
            0 => " <img src='images/icons/icon_militia.png' class='item-icons' alt='Milizsoldat'>",
            1 => " <img src='images/icons/icon_swordsman.png' class='item-icons' alt='Schwertkämpfer'>",
            2 => " <img src='images/icons/icon_thief.png' class='item-icons' alt='Dieb'>",
            3 => " <img src='images/icons/icon_conqueror.png' class='item-icons' alt='Eroberer'>",
            default => "ICON NOT FOUND",
        };
    }

    public function getSoldierDescription(): string {
        return $this->sdesc;
    }

    public function setSoldierDescription($description): void {
        $this->sdesc = $description;
    }
}