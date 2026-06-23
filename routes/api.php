<?php

use Illuminate\Support\Facades\Route;

foreach (glob(base_path('Modules/*/Routes/api.php')) as $routeFile) {
    require $routeFile;
}