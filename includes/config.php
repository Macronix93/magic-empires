<?php

/*
    Constants (defines)
*/
// --- Server, System & Performance ---
const MAX_PLAYER_LIMIT = 100;
const TIMEOUT_MAX_SECONDS = 3600; // 60 Minutes
const ONLINE_MAX_SECONDS = 1800; // 30 Minutes
const AFK_SECONDS = 300; // 5 Minutes
const USER_UPDATE_TICK = 30; // 30 Seconds
const INACTIVITY_DELAY = 864000;
const REMEMBER_ME_COOKIE_DAYS = 14;
const BACKGROUND_IMAGE = "images/background.png";
const ERROR_LOG_FILE = __DIR__ . "/../logs/error.log";
const ERROR_DATE_FORMAT = "D M d H:i:s";

// --- Admin-Level & Support-System ---
const ADMIN_LEVEL_USER = 0;
const ADMIN_LEVEL_SUPPORTER = 1;
const ADMIN_LEVEL_LIGHT_ADMIN = 2;
const ADMIN_LEVEL_FULL_ADMIN = 3;
const SUPPORT_TICKET_AUTO_DELETE_DAYS = 14;
const SUPPORT_TICKET_ROWS_PER_PAGE = 10;
const MAX_SUPPORT_TICKET_SUBJECT_LENGTH = 24;
const EMAIL_BLOCK_DAYS_AFTER_DELETION = 7;

// --- User-Accounts & Safety ---
const MIN_USERNAME_LENGTH = 4;
const MAX_USERNAME_LENGTH = 24;
const MIN_PASSWORD_LENGTH = 5;
const MAX_PASSWORD_LENGTH = 65;
const USERNAME_CHANGE_COOLDOWN_DAYS = 7;
const AVATAR_CHANGE_COOLDOWN_DAYS = 5;
const NUM_NAME_LENGTH_CHECK = 6;
const NUM_UNIQUE_CHARS = 3;
const UPLOADS_FILE_PATH = "uploads/";
const DEFAULT_AVATAR = UPLOADS_FILE_PATH . "default_avatar.jpg";
const AVATAR_SALT = "Dpf89!jkl#45mAlmDlp";
const MAX_UPLOAD_FILE_SIZE = 128; // In KB
const MAX_EMAIL_LENGTH = 64;

// --- Worldmap ---
const MAX_X = 100;
const MAX_Y = 100;
const MAX_RESOURCE_TILES = 500;
const RESOURCE_TILES_SPAWN_RATE = 250;
const SPAWN_LIFETIME_MIN = 5; // In days
const SPAWN_LIFETIME_MAX = 7; // In days

// --- Economy & Resources Base Values ---
const STARTING_FOOD = 10000;
const STARTING_WOOD = 10000;
const STARTING_STONE = 10000;
const STARTING_GOLD = 10000;
const BASE_FOOD_GAIN = 1000;
const BASE_WOOD_GAIN = 1000;
const BASE_STONE_GAIN = 800;
const BASE_GOLD_GAIN = 600;
const MIN_RESOURCES_PER_TILE = 5890;
const MAX_RESOURCES_PER_TILE = 32334;
const STORAGE_STARTING_VALUE = 10000;
const STORAGE_INC_FACTOR = 1.888;
const STORAGE_SECURE_PERCENT_STEP = 0.015;
const BASE_BOOST_DURATION = 2;
const BOOST_PRODUCTION_BONUS = 1.0;
const BOOST_COIN_BASE = 25;
const BOOST_COIN_FACTOR = 10;
const SHRINE_CHANGE_COST = 25000;

// --- Marketplace ---
const MAX_DAILY_TRADES = 5;
const MARKET_BASE_FEE = 10;
const MARKET_OFFER_DURATION = 86400; // 24 hours
const MARKET_CAPACITY_PER_LEVEL = 100000;
const MARKET_FEE_MULTIPLIER_FOOD = 0.0001;
const MARKET_FEE_MULTIPLIER_WOOD = 0.0001;
const MARKET_FEE_MULTIPLIER_STONE = 0.0002;
const MARKET_FEE_MULTIPLIER_GOLD = 0.0005;
const MAX_MARKET_RATIO = 10;
const MARKET_LISTING_FEE_STEP = 20000;

// --- Building & Kingdom Development ---
const MAX_BUILDING_LEVEL = 10;
const MIN_KINGDOM_NAME_LENGTH = 3;
const MAX_KINGDOM_NAME_LENGTH = 32;
const KINGDOM_NAME_CHANGE_COOLDOWN_DAYS = 7;
const ESTATE_VILLAGER_GROWTH_STEP = 2;
const ESTATE_VILLAGER_BASE_INC = 10;
const ESTATE_VILLAGER_BASE_STEP = 5;
const WATCHTOWER_DETECTION_PER_LEVEL = 2100;

// --- Military, Battle ---
const STARTING_SCORE = 0;
const MAX_ROWS_PER_RANKING_PAGE = 10;
const RPS_BONUS = 0.75;
const BASE_SEND_TROOPS_LIMIT = 2;
const BASE_SETTLEMENT_LIMIT = 3;
const GLOBAL_SETTLEMENT_MAX = 8;
const DEFAULT_WALL_HP = 1000;
const WALL_ABSORPTION_PER_LEVEL = 100;
const WALL_EFFECTIVE_DMG_FACTOR = 0.03;
const WALL_ACCUMULATED_DMG_FACTOR = 0.001;
const MIN_WALL_DEFENSE = 150;
const MAX_WALL_DEFENSE = 5000;
const WALL_DEFENSE_FACTOR = 0.7;
const BASE_WALL_REPAIR_COST = 15;
const NOOB_PROTECTION_MULT = 0.5;
const RAM_WALL_DAMAGE_FACTOR = 0.05; // 5% per battering ram
const RAM_WALL_DAMAGE_LIMIT = 2.0; // Max 200%
const RAM_FLAT_DAMAGE = 150;
const LETHALITY_PVP = 2.0;
const LETHALITY_PVE = 3.5;

// --- Troops, Recruiting ---
const MIN_SOLDIERS_RECRUIT_INPUT = 10;
const MAX_SOLDIERS_RECRUIT_INPUT = 99;
const TROOP_LIMIT_BASE = 50;
const TROOP_LIMIT_FACTOR = 50;
const TROOP_LIMIT_EXPONENT = 2.3;
const THIEF_BASE_CAPACITY = 500;
const RAIDER_BASE_CAPACITY = 300;
const RAIDER_LOSS_CHANCE = 10;
const MIN_PLUNDER_PERC = 90;
const MAX_PLUNDER_PERC = 120;
const BASE_CONQUEST_CHANCE = 0.2;
const MIN_CONQUEST_CHANCE = 0.05;
const MAX_CONQUEST_CHANCE = 0.9;
const BASE_SETTLER_CHANCE = 0.3;
const SETTLER_CHANCE_STEP = 0.2;
const MAX_SETTLER_CHANCE = 1.0;

// --- Techs ---
const RESEARCH_FOOD_INC = 2500;
const RESEARCH_WOOD_INC = 2500;
const RESEARCH_STONE_INC = 2000;
const RESEARCH_GOLD_INC = 1250;
const RESEARCH_STORAGE_INC = 150000;
const RESEARCH_WALL_HP_INC = 250;
const CARTOGRAPHY_SPEED_BONUS = 0.075;
const PLUNDER_CAPACITY_BONUS = 0.035;
const ARCHITECTURE_TIME_REDUCTION = 0.04;
const MAINTENANCE_REPAIR_REDUCTION = 0.06;
const SHRINE_TECH_STEP = 0.05;
const SMITHY_INF_ATK_BONUS = 8;
const SMITHY_INF_DEF_BONUS = 6;
const SMITHY_CAV_ATK_BONUS = 9;
const SMITHY_CAV_DEF_BONUS = 4;
const SMITHY_ARC_ATK_BONUS = 7;
const SMITHY_ARC_DEF_BONUS = 7;
const SMITHY_WEIGHT_REDUCTION = 0.05;
const SMITHY_SIEGE_BONUS = 0.10;

// --- Monster Camps ---
const MAX_MONSTER_CAMPS = 150;
const MONSTER_CAMP_SPAWN_RATE = 75;
const MONSTER_CAMP_TRAVEL_BOOST = 0.2;
const MONSTER_CAMP_SCOUT_BOOST = 0.0085;
const MONSTER_CAMP_BASE_RESOURCE_LOOT = 1344;
const MONSTER_CAMP_RES_CHANCE = 70;
const MONSTER_CAMP_COIN_MIN_PER_LVL = 1;
const MONSTER_CAMP_COIN_MAX_PER_LVL = 2;
const MIN_NUM_MONSTERS_PER_TYPE = 6;
const MAX_NUM_MONSTERS_PER_TYPE = 15;
const MONSTER_CAMP_EXTRA_MONSTER = 18;
const MONSTER_CAMP_EXTRA_LEVEL_CAP = 1;
const MIN_MONSTER_CAMP_EXTRA_SLOTS_LOW = 0;
const MAX_MONSTER_CAMP_EXTRA_SLOTS_LOW = 2;
const MIN_MONSTER_CAMP_EXTRA_SLOTS_HIGH = 2;
const MAX_MONSTER_CAMP_EXTRA_SLOTS_HIGH = 4;
const MIN_MONSTER_CAMP_RESOURCE_PERC = 90;
const MAX_MONSTER_CAMP_RESOURCE_PERC = 115;
const MIN_MONSTER_CAMP_WOOD_AND_STONE_PERC = 50;
const MAX_MONSTER_CAMP_WOOD_AND_STONE_PERC = 70;
const BASE_DANGER_RATE_SCOUTING = 40;
const MONSTER_CAMP_WEIGHT_LOW = 0.4;
const MONSTER_CAMP_WEIGHT_MID = 0.3;
const MONSTER_CAMP_WEIGHT_HIGH = 0.2;
const MONSTER_CAMP_WEIGHT_BOSS = 0.1;
const LOOT_FACTOR_HIGH_CAMPS = 2.5;
const LOOT_FACTOR_MID_CAMPS = 1.25;
const MONSTER_DMG_CLAMPED_MAX_VAL = 4.0;
const MONSTER_DMG_LOSS_EXPONENT = 1.15;

// --- Communication & UI ---
const MAX_MESSAGE_LENGTH = 500;
const MAX_LINE_BREAK_COUNT = 10;
const MESSAGES_RATE_INTERVAL = 60;
const MAX_MESSAGES_RATELIMIT = 10;
const SHOW_MESSAGES_LIMIT = 30;
const CONV_INACTIVITY_TIME = 1209600;
const MAX_NEWS_TITLE_LENGTH = 50;
const MAX_NEWS_CONTENT_LENGTH = 2000;
const MAX_WORLD_CHAT_MESSAGES_SHOWN = 30;
const MAX_UNIT_BADGES_PER_ROW_DESKTOP = 5;
const MAX_UNIT_BADGES_PER_ROW_MOBILE = 3;
const DATE_FORMAT_CHAT = "d.m.Y H:i:s";

// --- World Events ---
const WORLD_EVENT_DURATION = 86400;
const WORLD_EVENT_ID = -999;
const WORLD_EVENT_ATTACK_DURATION = 30;
const WORLD_EVENT_MAX_ATTEMPTS = 5;
const WORLD_EVENT_REWARD_MIN_TRESHOLD = 1500;
const WORLD_EVENT_REWARD_TRESHOLD_1 = 75000;
const WORLD_EVENT_REWARD_TRESHOLD_2 = 250000;
const WORLD_EVENT_REWARD_TRESHOLD_3 = 625000;
const WORLD_EVENT_REWARD_TRESHOLD_4 = 1200000;
const WORLD_EVENT_REWARD_TRESHOLD_5 = 2500000;
const WORLD_EVENT_REWARD_COINS_MIN = 5;
const WORLD_EVENT_REWARD_COINS_1 = 10;
const WORLD_EVENT_REWARD_COINS_2 = 15;
const WORLD_EVENT_REWARD_COINS_3 = 20;
const WORLD_EVENT_REWARD_COINS_4 = 25;
const WORLD_EVENT_REWARD_COINS_5 = 30;
// HP-Boss Resources
const WORLD_EVENT_HP_RES_BASE = 5000;            // Base resources per average building level
const WORLD_EVENT_HP_RES_VAR_MIN = 80;           // Min resource %
const WORLD_EVENT_HP_RES_VAR_MAX = 120;          // Max resource %

// HP-Boss Troop slots (TC Thresholds)
const WORLD_EVENT_HP_SLOT_LOW = 1;               // Standard slots
const WORLD_EVENT_HP_SLOT_MID_TC = 5;            // From this TC level: 2 slots
const WORLD_EVENT_HP_SLOT_HIGH_TC = 8;           // From this TC level: 3 slots

// HP-Boss Special units chance
const WORLD_EVENT_HP_SPECIAL_CHANCE_BASE = 5;   // Base chance in %
const WORLD_EVENT_HP_SPECIAL_CHANCE_TC_MULT = 2; // Bonus chance per TC level

// HP-Boss Number of troops for reward
const WORLD_EVENT_HP_UNIT_STD_MIN = 2;          // Standard troops min
const WORLD_EVENT_HP_UNIT_STD_MAX = 4;          // Standard troops max
const WORLD_EVENT_HP_UNIT_SPEC_MIN = 1;          // Special troops min
const WORLD_EVENT_HP_UNIT_SPEC_MAX = 2;          // Special troops max

// Damage-Boss Loot
const WORLD_EVENT_DMG_GOLD_RATIO = 50;           // 1 Gold per X Dmg
const WORLD_EVENT_DMG_GOLD_MAX = 100000;         // Max Gold Cap

/*
 * Interfaces
 */

interface AlignmentTypes
{
    const int ALIGN_NONE = 0;
    const int ALIGN_WAR = 1;
    const int ALIGN_IDOL = 2;
    const int ALIGN_NATURE = 3;
}

interface MessageCategories
{
    const string CATEGORY_DEFAULT = "Default";
    const string CATEGORY_WAR = "Militärisch";
    const string CATEGORY_TRADE = "Handel";
}

interface BuildingTypes
{
    const int BUILDING_TOWNCENTER = 0;
    const int BUILDING_UNIVERSITY = 1;
    const int BUILDING_BARRACKS = 2;
    const int BUILDING_WALL = 3;
    const int BUILDING_SMITHY = 4;
    const int BUILDING_MILL = 5;
    const int BUILDING_SAWMILL = 6;
    const int BUILDING_STONEMINE = 7;
    const int BUILDING_GOLDMINE = 8;
    const int BUILDING_STORAGE = 9;
    const int BUILDING_MARKETPLACE = 10;
    const int BUILDING_ESTATE = 11;
    const int BUILDING_WATCHTOWER = 12;
    const int BUILDING_SHRINE = 13;
    const int BUILDING_EMBASSY = 14;
}

interface ResourceTypes
{
    const int RESOURCE_TYPE_FOOD = 0;
    const int RESOURCE_TYPE_WOOD = 1;
    const int RESOURCE_TYPE_STONE = 2;
    const int RESOURCE_TYPE_GOLD = 3;
    const int RESOURCE_TYPE_TIME = 4;
    const int RESOURCE_TYPE_VILLAGER = 5;
    const int RESOURCE_TYPE_ATTACK = 6;
    const int RESOURCE_TYPE_DEFENSE = 7;
    const int RESOURCE_TYPE_RECRUIT_TIME = 8;
    const int RESOURCE_TYPE_HEALTH = 9;
    const int RESOURCE_TYPE_COINS = 10;
}

interface ActionTypes
{
    const int ACTION_BUILD_BUILDING = 0;
    const int ACTION_BUILD_TROOPS = 1;
    const int ACTION_SEND_TROOPS = 2;
    const int ACTION_RETURN_TROOPS = 3;
    const int ACTION_RESEARCH_TECH = 4;
    const int ACTION_RECEIVE_RESOURCES = 5;
    const int ACTION_RETURN_RESOURCES = 6;
    const int ACTION_UPGRADE_TROOPS = 7;
    const int ACTION_SMITHY_UPGRADE = 8;
}

interface TechTypes
{
    const int TECH_TYPE_FOOD_INC = 0;
    const int TECH_TYPE_WOOD_INC = 1;
    const int TECH_TYPE_STONE_INC = 2;
    const int TECH_TYPE_GOLD_INC = 3;
    const int TECH_TYPE_WALL_HP_INC = 4;
    const int TECH_TYPE_STORAGE_INC = 5;
    const int TECH_TYPE_CARTOGRAPHY = 6;
    const int TECH_TYPE_PLUNDER = 7;
    const int TECH_TYPE_ARCHITECTURE = 8;
    const int TECH_TYPE_ARCANE_INTEL = 9;
    const int TECH_TYPE_ANCESTRAL_RITES = 10;
    const int TECH_TYPE_MAINTENANCE = 11;
    const int TECH_TYPE_IMPERIAL = 12;
    const int TECH_TYPE_BLADES = 13;
    const int TECH_TYPE_SHIELDWALL = 14;
    const int TECH_TYPE_LANCE_RIDING = 15;
    const int TECH_TYPE_CUIRASS = 16;
    const int TECH_TYPE_ARROWHEADS = 17;
    const int TECH_TYPE_DOUBLET = 18;
    const int TECH_TYPE_WEIGHT = 19;
    const int TECH_TYPE_SIEGE = 20;
}

class SoldierTypes
{
    const int SOLDIER_TYPE_INFANTRY = 0;
    const int SOLDIER_TYPE_CAVALRY = 1;
    const int SOLDIER_TYPE_ARCHERS = 2;
    const int SOLDIER_TYPE_SPECIAL = 3;

    public static function get_labels(): array
    {
        return [
            self::SOLDIER_TYPE_INFANTRY => "Infanterie",
            self::SOLDIER_TYPE_CAVALRY => "Kavallerie",
            self::SOLDIER_TYPE_ARCHERS => "Schützen",
            self::SOLDIER_TYPE_SPECIAL => "Spezial"
        ];
    }
}

interface Soldiers
{
    const int SOLDIER_MILITIA = 0;
    const int SOLDIER_SWORDSMAN = 1;
    const int SOLDIER_HALBERDIER = 2;
    const int SOLDIER_KNIGHT = 3;
    const int SOLDIER_PALADIN = 4;
    const int SOLDIER_CUIRASSIER = 5;
    const int SOLDIER_ARCHER = 6;
    const int SOLDIER_LONGBOWMAN = 7;
    const int SOLDIER_CROSSBOWMAN = 8;
    const int SOLDIER_CONQUEROR = 9;
    const int SOLDIER_SETTLER_WAGON = 10;
    const int SOLDIER_THIEF = 11;
    const int SOLDIER_SCOUT = 12;
    const int SOLDIER_RAIDER = 13;
    const int SOLDIER_HERO = 14;
    const int SOLDIER_RAM = 15;
}

/*
 * Interfaces end
 */