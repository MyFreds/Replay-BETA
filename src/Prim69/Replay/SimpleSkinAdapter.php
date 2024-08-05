<?php

namespace Prim69\Replay;

use pocketmine\entity\InvalidSkinException;
use pocketmine\entity\Skin;
use pocketmine\network\mcpe\protocol\types\skin\SkinData;
use pocketmine\network\mcpe\convert\SkinAdapter;
use pocketmine\network\mcpe\convert\LegacySkinAdapter;

/**
 * Accessor for SkinAdapter
 */
class SimpleSkinAdapter
{
    private static ?SkinAdapter $skinAdapter = null;

    public static function get(): SkinAdapter
    {
        if (self::$skinAdapter === null) {
            self::$skinAdapter = new LegacySkinAdapter();
        }
        return self::$skinAdapter;
    }

    public static function set(SkinAdapter $adapter): void
    {
        self::$skinAdapter = $adapter;
    }
}
