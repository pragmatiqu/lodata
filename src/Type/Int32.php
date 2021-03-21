<?php

namespace Flat3\Lodata\Type;

/**
 * Int32
 * @package Flat3\Lodata\Type
 * @method static self factory($value = null, ?bool $nullable = true)
 */
class Int32 extends Byte
{
    const identifier = 'Edm.Int32';
    public const format = 'l';
}
