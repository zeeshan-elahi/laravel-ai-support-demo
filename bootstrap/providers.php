<?php

use App\Providers\AgentServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\InvoiceServiceProvider;

return [
    AppServiceProvider::class,
    AgentServiceProvider::class,
    InvoiceServiceProvider::class,
];
