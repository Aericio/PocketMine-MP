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

namespace pocketmine\block;

use pocketmine\item\Item;
use pocketmine\item\ToolTier;
use pocketmine\item\VanillaItems;
use pocketmine\world\sound\NoteInstrument;
use function mt_rand;

class EmeraldOre extends Opaque{

	public function __construct(BlockIdentifier $idInfo, string $name, ?BlockBreakInfo $breakInfo = null, ?NoteInstrument $noteblockInstrument = null){
		parent::__construct($idInfo, $name, $breakInfo ?? new BlockBreakInfo(3.0, BlockToolType::PICKAXE, ToolTier::IRON()->getHarvestLevel()), $noteblockInstrument ?? NoteInstrument::BASS_DRUM());
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [
			VanillaItems::EMERALD()
		];
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}

	protected function getXpDropAmount() : int{
		return mt_rand(3, 7);
	}
}
