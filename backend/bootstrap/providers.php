<?php

use App\Providers\AppServiceProvider;
use App\Providers\MalwareScannerServiceProvider;
use App\Providers\NotificationServiceProvider;

return [
    AppServiceProvider::class,
    NotificationServiceProvider::class,
    MalwareScannerServiceProvider::class,
];
