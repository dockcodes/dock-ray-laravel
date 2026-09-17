<?php

declare(strict_types=1);

namespace Dock\Ray\Laravel\Events;

use Illuminate\Http\Request;

/** A listener may return false to skip measuring this request. */
final class TransactionStarting
{
    public Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }
}
