<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

abstract class Middleware
{
    public function __construct(protected ?string $argument = null)
    {
    }

    /** @param callable(Request):Response $next */
    abstract public function handle(Request $request, callable $next): Response;
}
