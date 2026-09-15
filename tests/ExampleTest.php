<?php

use Eloquage\Chunk\Chunk;

it('bootstraps the package entrypoint', function () {
    $instance = new Chunk;

    expect($instance->name())->toBe('chunk')
        ->and($instance->split('chunk'))->toBe([
            ['text' => 'chunk', 'index' => 0, 'start' => 0, 'end' => 5],
        ]);
});
