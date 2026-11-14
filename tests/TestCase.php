<?php

namespace Tests;

use App\Domain\Company\Models\Company;
use App\Domain\Company\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Multi-tenant: kode test di luar request HTTP (persiapan data, assert) berjalan sebagai
        // perusahaan DEFAULT, seperti aplikasi satu perusahaan. Request HTTP tetap memakai
        // perusahaan user login (atau kosong bila tanpa login). Test boleh menimpa dengan runAs().
        $this->app->make(CurrentCompany::class)->useFallback(
            fn (): ?int => ($id = Company::where('code', Company::DEFAULT_CODE)->value('id')) === null ? null : (int) $id,
        );
    }
}
