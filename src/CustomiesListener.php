<?php
declare(strict_types=1);

namespace customiesdevs\customies;

use customiesdevs\customies\block\CustomiesBlockFactory;
use customiesdevs\customies\item\CustomiesItemFactory;
use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\network\mcpe\protocol\BiomeDefinitionListPacket;
use pocketmine\network\mcpe\protocol\ItemRegistryPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\ResourcePackStackPacket;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\network\mcpe\protocol\types\BlockPaletteEntry;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\Experiments;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use function array_map;
use function method_exists;

final class CustomiesListener implements Listener {

	/**
	 * The first protocol that can construct each component. An older client refuses the whole item registry over a
	 * single one ("Failed to construct component") and shows "server sent a broken packet" instead of joining, even
	 * if it never sees that item. Taken from what live clients reported: rarity fails up to 1.21.20, use_modifiers up
	 * to 1.20.40 and interact_button on 1.20.0.
	 */
	private const COMPONENT_MIN_PROTOCOLS = [
		"minecraft:rarity" => ProtocolInfo::PROTOCOL_1_21_30,
		"minecraft:use_modifiers" => ProtocolInfo::PROTOCOL_1_20_50,
		"minecraft:interact_button" => ProtocolInfo::PROTOCOL_1_20_40,
	];

	/** @var ItemRegistryPacket[] */
	private array $cachedItemComponentPackets = [];
	/** @var ItemTypeEntry[] */
	private array $cachedItemTable = [];
	/** @var BlockPaletteEntry[] */
	private array $cachedBlockPalette = [];
	private Experiments $experiments;

	public function __construct() {
		$this->experiments = new Experiments([
			// "data_driven_items" is required for custom blocks to render in-game. With this disabled, they will be
			// shown as the UPDATE texture block.
			"data_driven_items" => true,
		], true);
	}

	public function onDataPacketSend(DataPacketSendEvent $event): void {
		foreach($event->getPackets() as $packet){
			if($packet instanceof BiomeDefinitionListPacket) {
				foreach($event->getTargets() as $session){
					if(method_exists($session, "getProtocolId")) {
						// https://github.com/NetherGamesMC/PocketMine-MP/blob/9bb498806586517473012b2e7fab12402dcd3cd5/src/network/mcpe/handler/PreSpawnPacketHandler.php#L117-L120
						$protocolId = $session->getProtocolId();
						if($protocolId >= ProtocolInfo::PROTOCOL_1_21_60) {
							return;
						}
						// ItemComponentPacket needs to be sent after the BiomeDefinitionListPacket.
						if(!isset($this->cachedItemComponentPackets[$protocolId])) {
							// Wait for the data to be needed before it is actually cached. Allows for all blocks and items to be
							// registered before they are cached for the rest of the runtime.
							$this->cachedItemComponentPackets[$protocolId] = ItemRegistryPacket::create(array_map(
								fn(ItemTypeEntry $entry) => $this->mapEntryForProtocol($entry, $protocolId),
								CustomiesItemFactory::getInstance()->getItemTableEntries()
							));
						}
						$session->sendDataPacket($this->cachedItemComponentPackets[$protocolId]);
					}
					break;//expect there is only one recipient
				}
			} elseif($packet instanceof StartGamePacket) {
				$protocolId = ProtocolInfo::CURRENT_PROTOCOL;
				foreach($event->getTargets() as $session){
					if(method_exists($session, "getProtocolId")) {
						$protocolId = $session->getProtocolId();
					}
					break;//expect there is only one recipient
				}
				$packet->levelSettings->experiments = $this->experiments;
				$packet->blockPalette = $this->cachedBlockPalette[$protocolId] ??= CustomiesBlockFactory::getInstance()->getBlockPaletteEntries($protocolId);
			} elseif($packet instanceof ResourcePackStackPacket) {
				$packet->experiments = $this->experiments;
			}
		}
	}

	/**
	 * Returns the item as a client on the given protocol can read it, without the components it would refuse.
	 */
	private function mapEntryForProtocol(ItemTypeEntry $entry, int $protocolId): ItemTypeEntry {
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_30) {
			return $entry;
		}
		$root = clone $entry->getComponentNbt()->getRoot();
		$components = $root instanceof CompoundTag ? $root->getCompoundTag("components") : null;
		if($components === null) {
			return $entry;
		}

		if($protocolId < ProtocolInfo::PROTOCOL_1_20_50) {
			// Before use_modifiers the use time was this property, in ticks instead of seconds. Bows need it to charge.
			$useDuration = $components->getCompoundTag("minecraft:use_modifiers")?->getTag("use_duration");
			if($useDuration instanceof FloatTag) {
				$components->getCompoundTag("item_properties")?->setInt("use_duration", (int) ($useDuration->getValue() * 20));
			}
		}
		foreach(self::COMPONENT_MIN_PROTOCOLS as $name => $minProtocol){
			if($protocolId < $minProtocol) {
				$components->removeTag($name);
			}
		}

		return new ItemTypeEntry(
			$entry->getStringId(),
			$entry->getNumericId(),
			$entry->isComponentBased(),
			$entry->getVersion(),
			new CacheableNbt($root)
		);
	}
}
