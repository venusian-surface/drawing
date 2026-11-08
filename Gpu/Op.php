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
    case COVER_GRADIENT;
    case ELLIPSE;
    case RING;
    case UPLOAD;
    case IMAGE;
    case RECTS;
    case ATLAS;
    case MASK;
    case GLYPHS;
    case ROUNDRECT;
    case ROUNDRING;
    case SHADOW;
    case TARGET;
    case BACKDROP;
    case FILTER;
    case LAYER_SOURCE;
    case GLASS_BACKDROP;
    case GLASS;
}
