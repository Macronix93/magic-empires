<?php

/*
    Constants (defines)
*/
const STARTING_SCORE = 0;
const ADMIN_LEVEL_USER = 0;
const ADMIN_LEVEL_SUPPORTER = 1;
const ADMIN_LEVEL_LIGHT_ADMIN = 2;
const ADMIN_LEVEL_FULL_ADMIN = 3;
const SUPPORT_TICKET_AUTO_DELETE_DAYS = 14;
const SUPPORT_TICKET_ROWS_PER_PAGE = 10;
const MAX_SUPPORT_TICKET_SUBJECT_LENGTH = 16;
const USERNAME_CHANGE_COOLDOWN_DAYS = 7;
const KINGDOM_NAME_CHANGE_COOLDOWN_DAYS = 7;
const MIN_KINGDOM_NAME_LENGTH = 3;
const MAX_KINGDOM_NAME_LENGTH = 32;
const BASE_SEND_TROOPS_LIMIT = 2;
const BASE_SETTLEMENT_LIMIT = 5;
const MAX_RESOURCE_TILES = 500;
const RESOURCE_TILES_SPAWN_RATE = 20;
const MIN_RESOURCES_FOR_TILE = 89;
const MAX_RESOURCES_FOR_TILE = 12334;
const MAX_ROWS_PER_RANKING_PAGE = 10;
const BASE_CONQUEST_CHANCE = 0.2;
const MIN_CONQUEST_CHANCE = 0.05;
const MAX_CONQUEST_CHANCE = 0.9;
const BACKGROUND_IMAGE = "images/background.png";
const ERROR_LOG_FILE = "logs/error.log";
const ERROR_DATE_FORMAT = "D M d H:i:s";
const MIN_USERNAME_LENGTH = 4;
const MAX_USERNAME_LENGTH = 16;
const MIN_PASSWORD_LENGTH = 5;
const MAX_PASSWORD_LENGTH = 65;
const MAX_X = 100;
const MAX_Y = 100;
const MAX_BUILDING_LEVEL = 10;
const DEFAULT_WALL_HP = 1000;
const MIN_WALL_DEFENSE = 10;
const MAX_WALL_DEFENSE = 150;
const WALL_DEFENSE_FACTOR = 0.7;
const BASE_WALL_REPAIR_COST = 15;
const TIMEOUT_MAX_SECONDS = 1800; // 30 Minutes
const AFK_SECONDS = 300; // 5 Minutes
const USER_UPDATE_TICK = 30; // 30 Seconds
const MAX_MESSAGE_LENGTH = 400;
const MAX_LINE_BREAK_COUNT = 10;
const MESSAGES_RATE_INTERVAL = 60;
const MAX_MESSAGES_RATELIMIT = 10;
const SHOW_MESSAGES_LIMIT = 50;
const INACTIVITY_DELAY = 864000;
const STARTING_FOOD = 10000;
const STARTING_WOOD = 10000;
const STARTING_STONE = 10000;
const STARTING_GOLD = 10000;
const STORAGE_STARTING_VALUE = 10000; // Starting value of each resource for the Storage
const STORAGE_INC_FACTOR = 1.85;
const BASE_FOOD_GAIN = 1000;
const BASE_WOOD_GAIN = 1000;
const BASE_STONE_GAIN = 800;
const BASE_GOLD_GAIN = 500;
const CONV_INACTIVITY_TIME = 1209600; // In seconds (currently 1209600 seconds = 14 days)
const UPLOADS_FILE_PATH = "uploads/";
const DEFAULT_AVATAR = UPLOADS_FILE_PATH . "default_avatar.jpg";
const AVATAR_SALT = "Dpf89!jkl#45mAlmDlp";
const MAX_UPLOAD_FILE_SIZE = 64; // In KB
const NOOB_PROTECTION_MULT = 0.5;
const RESEARCH_FOOD_INC = 250;
const RESEARCH_WOOD_INC = 250;
const RESEARCH_STONE_INC = 200;
const RESEARCH_GOLD_INC = 125;
const RESEARCH_STORAGE_INC = 150000;
const RESEARCH_WALL_HP_INC = 250;
const MARKET_BASE_FEE = 10;
const MARKET_CAPACITY_PER_LEVEL = 100000;
const MARKET_FEE_MULTIPLIER_FOOD = 0.0001;
const MARKET_FEE_MULTIPLIER_WOOD = 0.0001;
const MARKET_FEE_MULTIPLIER_STONE = 0.0002;
const MARKET_FEE_MULTIPLIER_GOLD = 0.0005;
const MARKET_OFFER_DURATION = 86400; // 24 hours
const MIN_SOLDIERS_RECRUIT_INPUT = 10;
const MAX_SOLDIERS_RECRUIT_INPUT = 99;
const ESTATE_VILLAGER_GROWTH_STEP = 2;
const ESTATE_MAX_VILLAGER_INC = 50;
const WATCHTOWER_DETECTION_PER_LEVEL = 1200;
const SHRINE_BONUS_BASE = 0.15;
const SHRINE_MALUS_BASE = 0.08;
const SHRINE_CHANGE_COST = 25000;
const SHRINE_TECH_STEP = 0.05; // Every level of Ahnenritus increases Bonus by X %
const CARTOGRAPHY_SPEED_BONUS = 0.075;
const PLUNDER_CAPACITY_BONUS = 0.035;
const ARCHITECTURE_TIME_REDUCTION = 0.03;
const MAINTENANCE_REPAIR_REDUCTION = 0.06;
const BASE_SETTLER_CHANCE = 0.3; // 30% with one waggon
const SETTLER_CHANCE_STEP = 0.2; // +20% for every additional waggon
const MAX_SETTLER_CHANCE = 1.0;  // max is 100
const THIEF_BASE_CAPACITY = 500;
const STORAGE_SECURE_PERCENT_STEP = 0.015;
const RAIDER_BASE_CAPACITY = 300;
const RAIDER_LOSS_CHANCE = 10;
const BOOST_DURATION_MULTIPLIER = 1.0;  // 1.0 = 1 hour per Level
const BOOST_PRODUCTION_BONUS = 1.0;
const BOOST_COST_PER_LEVEL = 10000;
const MAX_DAILY_TRADES = 5;
const SMITHY_INF_ATK_BONUS = 2;
const SMITHY_INF_DEF_BONUS = 2;
const SMITHY_CAV_ATK_BONUS = 3;
const SMITHY_CAV_DEF_BONUS = 3;
const SMITHY_ARC_ATK_BONUS = 2;
const SMITHY_ARC_DEF_BONUS = 1;
const SMITHY_WEIGHT_REDUCTION = 0.25;
const SMITHY_SIEGE_BONUS = 0.20;
const MAX_NEWS_TITLE_LENGTH = 50;
const MAX_NEWS_CONTENT_LENGTH = 500;

/*
 * Interfaces
 */

interface AlignmentTypes
{
    const int ALIGN_NONE = 0;
    const int ALIGN_WAR = 1;    // Bonus: Attack | Malus: Gold
    const int ALIGN_TRADE = 2;  // Bonus: Gold | Malus: Defense (Wall)
    const int ALIGN_NATURE = 3; // Bonus: Food/Wood | Malus: Stone
}

interface MessageCategories
{
    const string CATEGORY_DEFAULT = "Default";
    const string CATEGORY_WAR = "Krieg";
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

interface SoldierTypes
{
    const int SOLDIER_TYPE_INFANTRY = 0;
    const int SOLDIER_TYPE_CAVALRY = 1;
    const int SOLDIER_TYPE_ARCHERS = 2;
    const int SOLDIER_TYPE_SPECIAL = 3;
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