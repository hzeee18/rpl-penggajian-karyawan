<?php

use App\Domain\Company\TenancyServiceProvider;
use App\Domain\Payroll\PayrollServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    PayrollServiceProvider::class,
];
