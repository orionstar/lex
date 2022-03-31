<?php declare(strict_types=1);

namespace Lex;

/**
 * Part of the Lex Template Parser.
 *
 * @author     PyroCMS Team
 * @license    MIT License
 * @copyright  2011 - 2014 PyroCMS
 */
class ArrayableObjectExample implements Arrayable {
	
	/**
	 * Attributes
	 */ 
	private array $attributes = ['foo' => 'bar'];

	/**
	 * Define how the object will be converted to an array
	 */ 
	public function toArray(): array
	{
		return $this->attributes;
	}

}
