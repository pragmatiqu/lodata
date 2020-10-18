<?php

namespace Flat3\Lodata\Type;

use Flat3\Lodata\Helper\Constants;
use Flat3\Lodata\PrimitiveType;

class Byte extends PrimitiveType
{
    protected $identifier = 'Edm.Byte';
    public const format = 'C';

    /** @var ?int $value */
    protected $value;

    public function toUrl(): string
    {
        if (null === $this->value) {
            return Constants::NULL;
        }

        return (string) $this->value;
    }

    public function toJson(): ?int
    {
        return $this->value;
    }

    public function set($value): self
    {
        parent::set($value);

        $this->value = $this->maybeNull(null === $value ? null : $this->repack($value));

        return $this;
    }

    protected function repack($value)
    {
        return unpack($this::format, pack('i', $value))[1];
    }

    protected function getEmpty()
    {
        return 0;
    }
}
