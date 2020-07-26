<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types=1);

/**
 * All Block classes are in here
 */
namespace pocketmine\block;

use pocketmine\block\tile\Spawnable;
use pocketmine\block\tile\Tile;
use pocketmine\block\utils\InvalidBlockStateException;
use pocketmine\entity\Entity;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\RayTraceResult;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\Position;
use pocketmine\world\sound\NoteInstrument;
use pocketmine\world\World;
use function assert;
use function count;
use function dechex;
use const PHP_INT_MAX;

class Block{

	/** @var BlockIdentifier */
	protected $idInfo;

	/** @var string */
	protected $fallbackName;

	/** @var BlockBreakInfo */
	protected $breakInfo;

	/** @var Position */
	protected $pos;

	/** @var AxisAlignedBB[]|null */
	protected $collisionBoxes = null;

	/** @var NoteInstrument */
	protected $noteblockInstrument;

	/**
	 * @param string          $name English name of the block type (TODO: implement translations)
	 */
	public function __construct(BlockIdentifier $idInfo, string $name, BlockBreakInfo $breakInfo, ?NoteInstrument $noteblockInstrument = null){
		if(($idInfo->getVariant() & $this->getStateBitmask()) !== 0){
			throw new \InvalidArgumentException("Variant 0x" . dechex($idInfo->getVariant()) . " collides with state bitmask 0x" . dechex($this->getStateBitmask()));
		}
		$this->idInfo = $idInfo;
		$this->fallbackName = $name;
		$this->breakInfo = $breakInfo;
		$this->pos = new Position(0, 0, 0, null);
		$this->noteblockInstrument = $noteblockInstrument ?? NoteInstrument::PIANO();
	}

	public function __clone(){
		$this->pos = clone $this->pos;
	}

	public function getIdInfo() : BlockIdentifier{
		return $this->idInfo;
	}

	public function getName() : string{
		return $this->fallbackName;
	}

	public function getId() : int{
		return $this->idInfo->getBlockId();
	}

	/**
	 * @internal
	 */
	public function getFullId() : int{
		return ($this->getId() << 4) | $this->getMeta();
	}

	public function asItem() : Item{
		return ItemFactory::getInstance()->get($this->idInfo->getItemId(), $this->idInfo->getVariant());
	}

	public function getMeta() : int{
		$stateMeta = $this->writeStateToMeta();
		assert(($stateMeta & ~$this->getStateBitmask()) === 0);
		return $this->idInfo->getVariant() | $stateMeta;
	}

	/**
	 * Returns a bitmask used to extract state bits from block metadata.
	 */
	public function getStateBitmask() : int{
		return 0;
	}

	protected function writeStateToMeta() : int{
		return 0;
	}

	/**
	 * @throws InvalidBlockStateException
	 */
	public function readStateFromData(int $id, int $stateMeta) : void{
		//NOOP
	}

	/**
	 * Called when this block is created, set, or has a neighbouring block update, to re-detect dynamic properties which
	 * are not saved on the world.
	 *
	 * Clears any cached precomputed objects, such as bounding boxes. Remove any outdated precomputed things such as
	 * AABBs and force recalculation.
	 */
	public function readStateFromWorld() : void{
		$this->collisionBoxes = null;
	}

	public function writeStateToWorld() : void{
		$this->pos->getWorld()->getChunkAtPosition($this->pos)->setFullBlock($this->pos->x & 0xf, $this->pos->y, $this->pos->z & 0xf, $this->getFullId());

		$tileType = $this->idInfo->getTileClass();
		$oldTile = $this->pos->getWorld()->getTile($this->pos);
		if($oldTile !== null){
			if($tileType === null or !($oldTile instanceof $tileType)){
				$oldTile->close();
				$oldTile = null;
			}elseif($oldTile instanceof Spawnable){
				$oldTile->setDirty(); //destroy old network cache
			}
		}
		if($oldTile === null and $tileType !== null){
			/**
			 * @var Tile $tile
			 * @see Tile::__construct()
			 */
			$tile = new $tileType($this->pos->getWorld(), $this->pos->asVector3());
			$this->pos->getWorld()->addTile($tile);
		}
	}

	/**
	 * Returns whether the given block has an equivalent type to this one. This compares base legacy ID and variant.
	 *
	 * Note: This ignores additional IDs used to represent additional states. This means that, for example, a lit
	 * furnace and unlit furnace are considered the same type.
	 */
	public function isSameType(Block $other) : bool{
		return $this->idInfo->getBlockId() === $other->idInfo->getBlockId() and $this->idInfo->getVariant() === $other->idInfo->getVariant();
	}

	/**
	 * Returns whether the given block has the same type and properties as this block.
	 */
	public function isSameState(Block $other) : bool{
		return $this->isSameType($other) and $this->writeStateToMeta() === $other->writeStateToMeta();
	}

	/**
	 * AKA: Block->isPlaceable
	 */
	public function canBePlaced() : bool{
		return true;
	}

	public function canBeReplaced() : bool{
		return false;
	}

	public function canBePlacedAt(Block $blockReplace, Vector3 $clickVector, int $face, bool $isClickedBlock) : bool{
		return $blockReplace->canBeReplaced();
	}

	/**
	 * Places the Block, using block space and block target, and side. Returns if the block has been placed.
	 */
	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$tx->addBlock($blockReplace->pos, $this);
		return true;
	}

	public function onPostPlace() : void{

	}

	/**
	 * Returns an object containing information about the destruction requirements of this block.
	 */
	public function getBreakInfo() : BlockBreakInfo{
		return $this->breakInfo;
	}

	/**
	 * Do the actions needed so the block is broken with the Item
	 */
	public function onBreak(Item $item, ?Player $player = null) : bool{
		if(($t = $this->pos->getWorld()->getTile($this->pos)) !== null){
			$t->onBlockDestroyed();
		}
		$this->pos->getWorld()->setBlock($this->pos, VanillaBlocks::AIR());
		return true;
	}

	/**
	 * Called when this block or a block immediately adjacent to it changes state.
	 */
	public function onNearbyBlockChange() : void{

	}

	/**
	 * Returns whether random block updates will be done on this block.
	 */
	public function ticksRandomly() : bool{
		return false;
	}

	/**
	 * Called when this block is randomly updated due to chunk ticking.
	 * WARNING: This will not be called if ticksRandomly() does not return true!
	 */
	public function onRandomTick() : void{

	}

	/**
	 * Called when this block is updated by the delayed blockupdate scheduler in the world.
	 */
	public function onScheduledUpdate() : void{

	}

	/**
	 * Do actions when interacted by Item. Returns if it has done anything
	 */
	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		return false;
	}

	/**
	 * Called when this block is attacked (left-clicked). This is called when a player left-clicks the block to try and
	 * start to break it in survival mode.
	 *
	 * @return bool if an action took place, prevents starting to break the block if true.
	 */
	public function onAttack(Item $item, int $face, ?Player $player = null) : bool{
		return false;
	}

	public function getFrictionFactor() : float{
		return 0.6;
	}

	/**
	 * @return int 0-15
	 */
	public function getLightLevel() : int{
		return 0;
	}

	/**
	 * Returns the amount of light this block will filter out when light passes through this block.
	 * This value is used in light spread calculation.
	 *
	 * @return int 0-15
	 */
	public function getLightFilter() : int{
		return $this->isTransparent() ? 0 : 15;
	}

	/**
	 * Returns whether this block will diffuse sky light passing through it vertically.
	 * Diffusion means that full-strength sky light passing through this block will not be reduced, but will start being filtered below the block.
	 * Examples of this behaviour include leaves and cobwebs.
	 *
	 * Light-diffusing blocks are included by the heightmap.
	 */
	public function diffusesSkyLight() : bool{
		return false;
	}

	public function isTransparent() : bool{
		return false;
	}

	public function isSolid() : bool{
		return true;
	}

	/**
	 * AKA: Block->isFlowable
	 */
	public function canBeFlowedInto() : bool{
		return false;
	}

	public function hasEntityCollision() : bool{
		return false;
	}

	/**
	 * Returns whether entities can climb up this block.
	 */
	public function canClimb() : bool{
		return false;
	}

	public function addVelocityToEntity(Entity $entity) : ?Vector3{
		return null;
	}

	final public function getPos() : Position{
		return $this->pos;
	}

	/**
	 * @internal
	 */
	final public function position(World $world, int $x, int $y, int $z) : void{
		$this->pos = new Position($x, $y, $z, $world);
	}

	/**
	 * Returns an array of Item objects to be dropped
	 *
	 * @return Item[]
	 */
	public function getDrops(Item $item) : array{
		if($this->breakInfo->isToolCompatible($item)){
			if($this->isAffectedBySilkTouch() and $item->hasEnchantment(Enchantment::SILK_TOUCH())){
				return $this->getSilkTouchDrops($item);
			}

			return $this->getDropsForCompatibleTool($item);
		}

		return [];
	}

	/**
	 * Returns an array of Items to be dropped when the block is broken using the correct tool type.
	 *
	 * @return Item[]
	 */
	public function getDropsForCompatibleTool(Item $item) : array{
		return [$this->asItem()];
	}

	/**
	 * Returns an array of Items to be dropped when the block is broken using a compatible Silk Touch-enchanted tool.
	 *
	 * @return Item[]
	 */
	public function getSilkTouchDrops(Item $item) : array{
		return [$this->asItem()];
	}

	/**
	 * Returns how much XP will be dropped by breaking this block with the given item.
	 */
	public function getXpDropForTool(Item $item) : int{
		if($item->hasEnchantment(Enchantment::SILK_TOUCH()) or !$this->breakInfo->isToolCompatible($item)){
			return 0;
		}

		return $this->getXpDropAmount();
	}

	/**
	 * Returns how much XP this block will drop when broken with an appropriate tool.
	 */
	protected function getXpDropAmount() : int{
		return 0;
	}

	/**
	 * Returns whether Silk Touch enchanted tools will cause this block to drop as itself.
	 */
	public function isAffectedBySilkTouch() : bool{
		return false;
	}

	/**
	 * Return the instrument that a noteblock on top of this block will play
	 *
	 * @return NoteInstrument
	 */
	public function getNoteblockInstrument() : NoteInstrument {
		return $this->noteblockInstrument;
	}

	/**
	 * Returns the item that players will equip when middle-clicking on this block.
	 */
	public function getPickedItem(bool $addUserData = false) : Item{
		$item = $this->asItem();
		if($addUserData){
			$tile = $this->pos->getWorld()->getTile($this->pos);
			if($tile instanceof Tile){
				$nbt = $tile->getCleanedNBT();
				if($nbt instanceof CompoundTag){
					$item->setCustomBlockData($nbt);
					$item->setLore(["+(DATA)"]);
				}
			}
		}
		return $item;
	}

	/**
	 * Returns the time in ticks which the block will fuel a furnace for.
	 */
	public function getFuelTime() : int{
		return 0;
	}

	/**
	 * Returns the chance that the block will catch fire from nearby fire sources. Higher values lead to faster catching
	 * fire.
	 */
	public function getFlameEncouragement() : int{
		return 0;
	}

	/**
	 * Returns the base flammability of this block. Higher values lead to the block burning away more quickly.
	 */
	public function getFlammability() : int{
		return 0;
	}

	/**
	 * Returns whether fire lit on this block will burn indefinitely.
	 */
	public function burnsForever() : bool{
		return false;
	}

	/**
	 * Returns whether this block can catch fire.
	 */
	public function isFlammable() : bool{
		return $this->getFlammability() > 0;
	}

	/**
	 * Called when this block is burned away by being on fire.
	 */
	public function onIncinerate() : void{

	}

	/**
	 * Returns the Block on the side $side, works like Vector3::getSide()
	 *
	 * @return Block
	 */
	public function getSide(int $side, int $step = 1){
		if($this->pos->isValid()){
			return $this->pos->getWorld()->getBlock($this->pos->getSide($side, $step));
		}

		throw new \InvalidStateException("Block does not have a valid world");
	}

	/**
	 * Returns the 4 blocks on the horizontal axes around the block (north, south, east, west)
	 *
	 * @return Block[]|\Generator
	 * @phpstan-return \Generator<int, Block, void, void>
	 */
	public function getHorizontalSides() : \Generator{
		$world = $this->pos->getWorld();
		foreach($this->pos->sidesAroundAxis(Facing::AXIS_Y) as $vector3){
			yield $world->getBlock($vector3);
		}
	}

	/**
	 * Returns the six blocks around this block.
	 *
	 * @return Block[]|\Generator
	 * @phpstan-return \Generator<int, Block, void, void>
	 */
	public function getAllSides() : \Generator{
		$world = $this->pos->getWorld();
		foreach($this->pos->sides() as $vector3){
			yield $world->getBlock($vector3);
		}
	}

	/**
	 * Returns a list of blocks that this block is part of. In most cases, only contains the block itself, but in cases
	 * such as double plants, beds and doors, will contain both halves.
	 *
	 * @return Block[]
	 */
	public function getAffectedBlocks() : array{
		return [$this];
	}

	/**
	 * @return string
	 */
	public function __toString(){
		return "Block[" . $this->getName() . "] (" . $this->getId() . ":" . $this->getMeta() . ")";
	}

	/**
	 * Checks for collision against an AxisAlignedBB
	 */
	public function collidesWithBB(AxisAlignedBB $bb) : bool{
		foreach($this->getCollisionBoxes() as $bb2){
			if($bb->intersectsWith($bb2)){
				return true;
			}
		}

		return false;
	}

	/**
	 * Called when an entity's bounding box clips inside this block's cell. Note that the entity may not be intersecting
	 * with the collision box or bounding box.
	 */
	public function onEntityInside(Entity $entity) : void{

	}

	/**
	 * @return AxisAlignedBB[]
	 */
	final public function getCollisionBoxes() : array{
		if($this->collisionBoxes === null){
			$this->collisionBoxes = $this->recalculateCollisionBoxes();
			foreach($this->collisionBoxes as $bb){
				$bb->offset($this->pos->x, $this->pos->y, $this->pos->z);
			}
		}

		return $this->collisionBoxes;
	}

	/**
	 * @return AxisAlignedBB[]
	 */
	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()];
	}

	public function isFullCube() : bool{
		$bb = $this->getCollisionBoxes();

		return count($bb) === 1 and $bb[0]->getAverageEdgeLength() >= 1; //TODO: average length 1 != cube
	}

	public function calculateIntercept(Vector3 $pos1, Vector3 $pos2) : ?RayTraceResult{
		$bbs = $this->getCollisionBoxes();
		if(count($bbs) === 0){
			return null;
		}

		/** @var RayTraceResult|null $currentHit */
		$currentHit = null;
		/** @var int|float $currentDistance */
		$currentDistance = PHP_INT_MAX;

		foreach($bbs as $bb){
			$nextHit = $bb->calculateIntercept($pos1, $pos2);
			if($nextHit === null){
				continue;
			}

			$nextDistance = $nextHit->hitVector->distanceSquared($pos1);
			if($nextDistance < $currentDistance){
				$currentHit = $nextHit;
				$currentDistance = $nextDistance;
			}
		}

		return $currentHit;
	}
}
