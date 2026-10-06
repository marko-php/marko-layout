<?php

declare(strict_types=1);
use Marko\Layout\ComponentCollectorInterface;
use Marko\Layout\DiscoveringComponentCollector;
use Marko\Layout\HandleResolver;
use Marko\Layout\LayoutProcessor;
use Marko\Layout\LayoutProcessorInterface;
use Marko\Layout\LayoutResolver;

it('has a valid composer.json with correct name, dependencies, and autoload', function (): void {
    $composerPath = dirname(__DIR__) . '/composer.json';
    $composer = json_decode(file_get_contents($composerPath), true);

    expect($composer)->toBeArray()
        ->and($composer['name'])->toBe('marko/layout')
        ->and($composer['autoload']['psr-4']['Marko\\Layout\\'])->toBe('src/')
        ->and($composer['require'])->toHaveKey('marko/core')
        ->and($composer['require'])->toHaveKey('marko/view')
        ->and($composer['require'])->toHaveKey('marko/routing');
});

it('has a module.php that returns a valid module configuration array', function (): void {
    $modulePath = dirname(__DIR__) . '/module.php';

    expect(file_exists($modulePath))->toBeTrue();

    $module = require $modulePath;

    expect($module)->toBeArray();
});

it('binds HandleResolver as a singleton in module.php', function (): void {
    $module = require dirname(__DIR__) . '/module.php';

    expect($module['singletons'])->toHaveKey(HandleResolver::class);
});

it('binds LayoutResolver as a singleton in module.php', function (): void {
    $module = require dirname(__DIR__) . '/module.php';

    expect($module['singletons'])->toHaveKey(LayoutResolver::class);
});

it('binds LayoutProcessorInterface to LayoutProcessor in module.php', function (): void {
    $module = require dirname(__DIR__) . '/module.php';

    expect($module['bindings'])->toHaveKey(LayoutProcessorInterface::class)
        ->and($module['bindings'][LayoutProcessorInterface::class])->toBe(LayoutProcessor::class);
});

it('binds ComponentCollectorInterface to DiscoveringComponentCollector in module.php', function (): void {
    $module = require dirname(__DIR__) . '/module.php';

    expect($module['bindings'])->toHaveKey(ComponentCollectorInterface::class)
        ->and($module['bindings'][ComponentCollectorInterface::class])->toBe(DiscoveringComponentCollector::class);
});

it('ships no config file, since layouts and components are discovered from attributes', function (): void {
    expect(is_dir(dirname(__DIR__) . '/config'))->toBeFalse();
});

it('module.php declares LayoutMiddleware as globalMiddleware', function (): void {
    $module = require dirname(__DIR__) . '/module.php';

    expect($module['globalMiddleware'] ?? [])
        ->toContain('Marko\\Layout\\Middleware\\LayoutMiddleware');
});

it('module.php declares marko/session as a soft after dependency', function (): void {
    $module = require dirname(__DIR__) . '/module.php';

    expect($module['sequence']['after'] ?? [])->toContain('marko/session');
});
