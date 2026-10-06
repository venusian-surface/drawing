<?php

namespace Surface\Drawing\Gpu;

/** What a DrawList operation does; the shapes are listed on DrawList. */
enum Op
{
    case CLEAR;
    case SCISSOR;
    case SOLID;
    case STENCIL_FILL;
    case COVER;
    case ELLIPSE;
    case RING;
    case UPLOAD;
    case IMAGE;
    case RECTS;
}
