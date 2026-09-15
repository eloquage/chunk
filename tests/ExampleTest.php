<?php

use Eloquage\Chunk\Chunk;

it('bootstraps the package entrypoint', function () {
    $instance = new Chunk();

    expect($instance->name())->toBe('chunk');
});
