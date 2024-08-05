<?php

namespace Prim69\Replay;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\LevelChunkPacket;
use pocketmine\network\mcpe\protocol\RequestChunkRadiusPacket;
use pocketmine\network\mcpe\protocol\ResourcePackChunkDataPacket;
use pocketmine\network\mcpe\protocol\ResourcePackChunkRequestPacket;
use pocketmine\network\mcpe\protocol\TextPacket;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\utils\UUID;
use pocketmine\world\World;
use function get_class;
use function in_array;
use function microtime;
use function round;

class Main extends PluginBase implements Listener
{
    /** @var array */
    public array $recording = [];

    /** @var array */
    public array $saved = [];

    /** @var array */
    public array $positions = [];

    public const IGNORE_SERVERBOUND = [
        LevelChunkPacket::class,
        RequestChunkRadiusPacket::class,
        ResourcePackChunkRequestPacket::class,
        ResourcePackChunkDataPacket::class,
        TextPacket::class,
    ];

    public function onEnable(): void
    {
        $this->getServer()
            ->getPluginManager()
            ->registerEvents($this, $this);
        $this->getServer()
            ->getCommandMap()
            ->register($this->getName(), new ReplayCommand($this));
    }

    public function showRecording(Player $player, Player $target)
    {
        $this->getScheduler()->scheduleRepeatingTask(
            new ReplayTask($player, $target, $this),
            1
        );
    }

    public function isRecording(string $name): bool
    {
        return isset($this->recording[$name]);
    }

    public function onBlockPlace(BlockPlaceEvent $event)
    {
        $player = $event->getPlayer();
        if (!$this->isRecording($player->getName())) {
            return;
        }
        $air = VanillaBlocks::AIR();
        $blockPos = $event->getBlockAgainst()->getPosition();
        $air->position(
            $player->getWorld(),
            $blockPos->x,
            $blockPos->y,
            $blockPos->z
        );
        $this->recording[$player->getName()]["blocks"][
            (string) round(microtime(true), 2)
        ] = $air;
        if (
            !isset(
                $this->recording[$player->getName()]["preBlocks"][
                    ($hash = World::blockHash(
                        $blockPos->x,
                        $blockPos->y,
                        $blockPos->z
                    ))
                ]
            )
        ) {
            $this->recording[$player->getName()]["preBlocks"][
                $hash
            ] = $event->getBlockAgainst();
        }
    }

    public function onBlockBreak(BlockBreakEvent $event)
    {
        $player = $event->getPlayer();
        if (!$this->isRecording($player->getName())) {
            return;
        }
        $air = VanillaBlocks::AIR();
        $blockPos = $event->getBlock()->getPosition();
        $air->position(
            $player->getWorld(),
            $blockPos->x,
            $blockPos->y,
            $blockPos->z
        );
        $this->recording[$player->getName()]["blocks"][
            (string) round(microtime(true), 2)
        ] = $air;
        if (
            !isset(
                $this->recording[$player->getName()]["preBlocks"][
                    ($hash = World::blockHash(
                        $blockPos->x,
                        $blockPos->y,
                        $blockPos->z
                    ))
                ]
            )
        ) {
            $this->recording[$player->getName()]["preBlocks"][
                $hash
            ] = $event->getBlock();
        }
    }

    public function onReceive(DataPacketReceiveEvent $event)
    {
        $pk = $event->getPacket();
        $player = $event->getOrigin()->getPlayer();
        if ($player !== null && $this->isRecording($player->getName())) {
            if ($pk instanceof PlayerAuthInputPacket) {
                $this->recording[$player->getName()]["packets"][
                    (string) round(microtime(true), 2)
                ] = MovePlayerPacket::simple(
                    $player->getId(),
                    $pk->getPosition(),
                    $pk->getPitch(),
                    $pk->getYaw(),
                    $pk->getHeadYaw(),
                    MovePlayerPacket::MODE_NORMAL,
                    true,
                    0,
                    0
                );
            } elseif (
                $pk instanceof ClientboundPacket &&
                !in_array(get_class($pk), self::IGNORE_SERVERBOUND)
            ) {
                $this->recording[$player->getName()]["packets"][
                    (string) round(microtime(true), 2)
                ] = $pk;
            }
        }
    }

    public function onQuit(PlayerQuitEvent $event)
    {
        $name = $event->getPlayer()->getName();
        if ($this->isRecording($name)) {
            $this->saved[$name] = $this->recording[$name];
            unset($this->recording[$name]);
        }
    }

    /*public function throwFakeProjectile(Player $player, ?ProjectileItem $item, Location $l){
		$pk = new AddActorPacket();
		$pk->position = $l;
		$pk->pitch = $l->pitch;
		//$pk->type = "minecraft:splash_potion";
		$pk->yaw = $l->yaw;
		$pk->entityRuntimeId = $pk->entityUniqueId = Entity::$entityCount++;

		$flags = 0;
		$flags |= 1 << Entity::DATA_FLAG_LINGER;
		$pk->metadata = [
			Entity::DATA_FLAGS => [Entity::DATA_TYPE_LONG, $flags],
			Entity::DATA_OWNER_EID => [Entity::DATA_TYPE_LONG, -1],
			Entity::DATA_NAMETAG => [Entity::DATA_TYPE_STRING, ""],
			Entity::DATA_POTION_COLOR => [Entity::DATA_TYPE_INT, 23]
		];

		$player->dataPacket($pk);
	}*/
}
