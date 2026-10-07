<?php

use App\Http\Middleware\AutentikasiTv;
use App\Http\Middleware\EnsureShiftAktif;
use App\Http\Middleware\HeaderKeamanan;
use App\Http\Middleware\JagaHakCipta;
use App\Http\Middleware\SetTenancy;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenancy' => SetTenancy::class,
            'shift' => EnsureShiftAktif::class,
            'tv' => AutentikasiTv::class,
        ]);
        $middleware->appendToGroup('web', HeaderKeamanan::class);
        $middleware->appendToGroup('web', JagaHakCipta::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API TV selalu menerima JSON (termasuk error validasi & 404)
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
    })->create();
