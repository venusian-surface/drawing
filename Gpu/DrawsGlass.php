<?php

namespace Surface\Drawing\Gpu;

/**
 * A device with a glass pass of its own (drawing-path spec G4): Lowering hands it Op::GLASS_BACKDROP, the region under
 * a list's panes read once and taken down and up a dual-filter pyramid, and one Op::GLASS draw a pane. A device
 * without it is given each pane as a backdrop layer.
 */
interface DrawsGlass {}
