<?php

namespace Surface\Drawing\Canvases;

use Closure;
use Surface\Contracts\Drawing\CPUEngine;
use Surface\Contracts\Drawing\CPUHost;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\Frame;
use Surface\Contracts\Drawing\PagedDrawTarget;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\PagedFramebuffer;
use Surface\Contracts\Framebuffers\Region;

/**
 * True U8G2: one page of RAM, the hook run once per page with the same
 * Frame, everything outside the page clipped, and each finished page pushed
 * to the sink before the window is reused. flush()/rgba8() re-run the pages
 * (hooks are pure by contract) and concatenate; nothing full-size is held.
 * flush() answers the host format only.
 */
class PagedCanvas extends CPUCanvas implements PagedDrawTarget
{
    protected ?Closure $sink = null;

    protected bool $sink_as_array = false;

    protected ?Frame $last_frame = null;

    public function __construct(CPUEngine $engine, protected PagedFramebuffer $paged, CPUHost $host)
    {
        parent::__construct($engine, $paged, $host);
    }

    public function onPage(callable $sink, bool $as_array = false): static
    {
        $this->sink = $sink(...);
        $this->sink_as_array = $as_array;

        return $this;
    }

    public function renderFrame(): bool
    {
        if (! $this->frameWanted()) {
            return false;
        }
        $this->last_frame = $this->nextFrame($this->host->width, $this->host->height, 1.0);
        $this->pages(function (Region $page): void {
            if (! is_null($this->sink)) {
                ($this->sink)($page, $this->paged->flush($this->host->format, $this->sink_as_array));
            }
        });

        return true;
    }

    public function damage(): array
    {
        $regions = [];
        for ($p = 0; $p < $this->paged->pages(); $p++) {
            $regions[] = $this->paged->pageRegion($p);
        }

        return $regions;
    }

    public function flush(?FormatSpec $spec = null, bool $as_array = false): string|array
    {
        if (! is_null($spec) && ! $spec->equals($this->host->format)) {
            throw DrawingException::pagedHostOnly();
        }
        $out = '';
        $this->pages(function () use (&$out): void {
            $out .= $this->paged->flush($this->host->format);
        });

        return $as_array ? array_values(unpack('C*', $out)) : $out;
    }

    public function flushRegion(Region $region, ?FormatSpec $spec = null, bool $as_array = false): string|array
    {
        if (! is_null($spec) && ! $spec->equals($this->host->format)) {
            throw DrawingException::pagedHostOnly();
        }
        $out = '';
        $this->pages(function (Region $page) use (&$out, $region): void {
            if (! is_null($page->intersect($region))) {
                $out .= $this->paged->flushRegion($region, $this->host->format);
            }
        });

        return $as_array ? array_values(unpack('C*', $out)) : $out;
    }

    public function rgba8(): string
    {
        $out = '';
        $this->pages(function () use (&$out): void {
            $out .= $this->paged->toRgba8();
        });

        return $out;
    }

    protected function begin(): void {}

    protected function present(): void {}

    /** Run the hook over every page in order, calling $after with the page region once the page is drawn. */
    protected function pages(callable $after): void
    {
        $frame = $this->last_frame ?? new Frame(0, 0.0, 0.0, $this->host->width, $this->host->height, 1.0);
        for ($p = 0; $p < $this->paged->pages(); $p++) {
            $page = $this->paged->pageRegion($p);
            $this->paged->setPage($p);
            $this->paged->fill($this->word($this->clear_color));
            $this->rasterizer->begin($page);
            if (! is_null($this->on_draw)) {
                ($this->on_draw)($this->rasterizer, $frame);
            }
            $after($page);
        }
    }
}
