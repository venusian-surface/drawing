<?php

namespace Surface\Drawing\Gpu;

use Surface\Contracts\Drawing\ColorSpace;
use Surface\Contracts\Drawing\TargetFormat;
use Surface\Contracts\Framebuffers\GLFramebuffer;

/**
 * A device that makes targets past RGBA8 sRGB. A target in an HDR colour
 * space answers readRgba8() encoded to sRGB and clamped at SDR white, and
 * implements HdrReadback; SDR colours in a DrawList land at the lent
 * surface's hdr()->sdrWhiteLevel (1.0 where it reports none).
 */
interface TargetsFormats
{
    /**
     * The targets it makes besides RGBA8 sRGB, best first, each a format and
     * one of its TargetFormat::colorSpaces().
     *
     * @return list<array{TargetFormat, ColorSpace}>
     */
    public function targetFormats(): array;

    /** As GpuDevice::target(), in $format and $space: one of targetFormats(). */
    public function targetAs(int $width, int $height, int $samples, TargetFormat $format, ColorSpace $space): GLFramebuffer;
}
