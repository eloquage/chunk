<?php

namespace Eloquage\Chunk;

/**
 * Primary entrypoint for eloquage/chunk.
 *
 * Pure-PHP implementation lives here. Optional TypePHP/native acceleration
 * can be added under native/ later without changing this public API.
 */
final class Chunk
{
    public function name(): string
    {
        return 'chunk';
    }
}
