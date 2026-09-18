<?php

namespace Surface\Drawing;

/** A 2D affine [a b c d tx ty]: x' = a·x + c·y + tx, y' = b·x + d·y + ty. Immutable. */
final class Affine
{
    public function __construct(
        public readonly float $a = 1.0,
        public readonly float $b = 0.0,
        public readonly float $c = 0.0,
        public readonly float $d = 1.0,
        public readonly float $tx = 0.0,
        public readonly float $ty = 0.0,
    ) {}

    public static function identity(): self
    {
        return new self();
    }

    public static function translation(float $dx, float $dy): self
    {
        return new self(1.0, 0.0, 0.0, 1.0, $dx, $dy);
    }

    public static function rotation(float $radians): self
    {
        $c = cos($radians);
        $s = sin($radians);

        return new self($c, $s, -$s, $c, 0.0, 0.0);
    }

    public static function scaling(float $sx, float $sy): self
    {
        return new self($sx, 0.0, 0.0, $sy, 0.0, 0.0);
    }

    /** this × m — m applies to a point first. */
    public function compose(Affine $m): self
    {
        return new self(
            $this->a * $m->a + $this->c * $m->b,
            $this->b * $m->a + $this->d * $m->b,
            $this->a * $m->c + $this->c * $m->d,
            $this->b * $m->c + $this->d * $m->d,
            $this->a * $m->tx + $this->c * $m->ty + $this->tx,
            $this->b * $m->tx + $this->d * $m->ty + $this->ty,
        );
    }

    /** @return array{float, float} */
    public function apply(float $x, float $y): array
    {
        return [$this->a * $x + $this->c * $y + $this->tx, $this->b * $x + $this->d * $y + $this->ty];
    }

    public function invert(): ?self
    {
        $det = $this->a * $this->d - $this->b * $this->c;
        if (abs($det) < 1e-12) {
            return null;
        }

        return new self(
            $this->d / $det,
            -$this->b / $det,
            -$this->c / $det,
            $this->a / $det,
            ($this->c * $this->ty - $this->d * $this->tx) / $det,
            ($this->b * $this->tx - $this->a * $this->ty) / $det,
        );
    }

    public function isTranslation(): bool
    {
        return $this->a === 1.0 && $this->b === 0.0 && $this->c === 0.0 && $this->d === 1.0;
    }
}
