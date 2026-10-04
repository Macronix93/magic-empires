<?php

class Marketplace
{
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
}