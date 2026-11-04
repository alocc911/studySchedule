<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

final class HealthTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testHealthReturnsServiceStatus(): void
    {
        $result = $this->get('/health');

        $result->assertStatus(200);
        $this->assertSame('ok', json_decode($result->getJSON(), true)['status'] ?? null);
    }
}
