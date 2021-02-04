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

use pocketmine\world\sound\NoteInstrument;

class Bedrock extends Opaque{

	/** @var bool */
	private $burnsForever = false;

	public function __construct(BlockIdentifier $idInfo, string $name, ?BlockBreakInfo $breakInfo = null, ?NoteInstrument $noteblockInstrument = null){
		parent::__construct($idInfo, $name, $breakInfo ?? BlockBreakInfo::indestructible(), $noteblockInstrument ?? NoteInstrument::BASS_DRUM());
	}

	public function readStateFromData(int $id, int $stateMeta) : void{
		$this->burnsForever = ($stateMeta & BlockLegacyMetadata::BEDROCK_FLAG_INFINIBURN) !== 0;
	}

	protected function writeStateToMeta() : int{
		return $this->burnsForever ? BlockLegacyMetadata::BEDROCK_FLAG_INFINIBURN : 0;
	}

	public function getStateBitmask() : int{
		return 0b1;
	}

	public function burnsForever() : bool{
		return $this->burnsForever;
	}

	/** @return $this */
	public function setBurnsForever(bool $burnsForever) : self{
		$this->burnsForever = $burnsForever;
		return $this;
	}
}
