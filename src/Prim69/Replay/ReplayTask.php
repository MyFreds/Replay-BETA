<?php
declare(strict_types=1);

namespace Prim69\Replay;

use pocketmine\entity\Entity;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\AddPlayerPacket;
use pocketmine\network\mcpe\protocol\PlayerSkinPacket;
use pocketmine\network\mcpe\protocol\RemoveActorPacket;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\network\mcpe\protocol\UpdateAbilitiesPacket;
use pocketmine\network\mcpe\protocol\types\AbilitiesData;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\command\CommandPermissions;
use pocketmine\network\mcpe\protocol\types\DeviceOS;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\network\mcpe\protocol\types\GameMode;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\PlayerPermissions;
use pocketmine\player\Player;
use pocketmine\scheduler\Task;
use pocketmine\block\Block;
use pocketmine\world\World;
use Ramsey\Uuid\Uuid;
use function array_key_first;
use function count;
use function property_exists;

class ReplayTask extends Task
{
    private bool $started = false;
    private int $eid;
    private array $list;
    private array $blocks;
    private array $setBlocks = [];

    public function __construct(
        private Player $player,
        Player $target,
        Main $main
    ) {
        $this->list = $main->saved[$target->getName()]["packets"];
        $this->blocks = $main->saved[$target->getName()]["blocks"];

        foreach (
            $main->saved[$target->getName()]["preBlocks"]
            as $hash => $block
        ) {
            World::getBlockXYZ($hash, $blockX, $blockY, $blockZ);
            $player->getNetworkSession()->sendDataPacket(
                UpdateBlockPacket::create(
                    new BlockPosition($blockX, $blockY, $blockZ),
                    TypeConverter::getInstance()
                        ->getBlockTranslator()
                        ->internalIdToNetworkId($block->getStateId()),
                    UpdateBlockPacket::FLAG_NETWORK,
                    UpdateBlockPacket::DATA_LAYER_NORMAL
                )
            );
        }

        $this->eid = Entity::nextRuntimeId();
        $p = $main->positions[$target->getName()];

        $abilities = new AbilitiesData(
            CommandPermissions::NORMAL,
            PlayerPermissions::VISITOR,
            $this->eid,
            [],
            true,
            false,
            1.0,
            1.0
        );

        $player
            ->getNetworkSession()
            ->sendDataPacket(
                AddPlayerPacket::create(
                    $uuid = Uuid::uuid4(),
                    $target->getName(),
                    $this->eid,
                    "",
                    new Vector3($p[2], $p[3], $p[4]),
                    null,
                    $p[1],
                    $p[0],
                    $p[0],
                    ItemStackWrapper::legacy(
                        TypeConverter::getInstance()->coreItemStackToNet(
                            VanillaItems::AIR()
                        )
                    ),
                    GameMode::SURVIVAL,
                    [],
                    new PropertySyncData([], []),
                    UpdateAbilitiesPacket::create($abilities),
                    [],
                    "",
                    DeviceOS::UNKNOWN
                )
            );

        $sa = new SimpleSkinAdapter();
        $player
            ->getNetworkSession()
            ->sendDataPacket(
                PlayerSkinPacket::create(
                    $uuid,
                    "",
                    "",
                    SimpleSkinAdapter::get()->toSkinData($target->getSkin())
                )
            );
    }

    public function onRun(): void
    {
        if (!$this->player->isOnline()) {
            $this->getHandler()->cancel();
            return;
        }
        if (count($this->list) <= 0) {
            if ($this->started) {
                $this->player
                    ->getNetworkSession()
                    ->sendDataPacket(RemoveActorPacket::create($this->eid));
                foreach ($this->setBlocks as $block) {
                    if (
                        !$block instanceof Block ||
                        !($blockPos = $block->getPosition())->isValid()
                    ) {
                        continue;
                    }
                    $this->player->getNetworkSession()->sendDataPacket(
                        UpdateBlockPacket::create(
                            new BlockPosition(
                                $blockPos->x,
                                $blockPos->y,
                                $blockPos->z
                            ),
                            TypeConverter::getInstance()
                                ->getBlockTranslator()
                                ->internalIdToNetworkId($block->getStateId()),
                            UpdateBlockPacket::FLAG_NETWORK,
                            UpdateBlockPacket::DATA_LAYER_NORMAL
                        )
                    );
                }
            }
            $this->getHandler()->cancel();
            return;
        }
        if (!$this->started) {
            $this->started = true;
        }
        $key = array_key_first($this->list);

        $relayed = clone $this->list[$key];
        if (property_exists($relayed, "actorUniqueId")) {
            $relayed->actorUniqueId = $this->eid;
        }
        if (property_exists($relayed, "actorRuntimeId")) {
            $relayed->actorRuntimeId = $this->eid;
        }
        if (
            property_exists($relayed, "actorUniqueId") ||
            property_exists($relayed, "actorRuntimeId")
        ) {
            $this->player->getNetworkSession()->sendDataPacket($relayed);
        }

        if (isset($this->blocks[$key])) {
            $relayed = $this->blocks[$key];
            if ($relayed instanceof Block) {
                $blockPos = $relayed->getPosition();
                $this->player->getNetworkSession()->sendDataPacket(
                    UpdateBlockPacket::create(
                        new BlockPosition(
                            $blockPos->x,
                            $blockPos->y,
                            $blockPos->z
                        ),
                        TypeConverter::getInstance()
                            ->getBlockTranslator()
                            ->internalIdToNetworkId($block->getStateId()),
                        UpdateBlockPacket::FLAG_NETWORK,
                        UpdateBlockPacket::DATA_LAYER_NORMAL
                    )
                );
                $this->setBlocks[] = $relayed;
            }
        }

        unset($this->blocks[$key], $this->list[$key]);
    }
}
