<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Shortdark\Powercable;

final class PowercableTest extends TestCase
{

    //This test will not work because resetDateToMidnight() is a private function
    
    public function testCanBeUsedAsString()
    {
        $this->assertEquals(
            1556409600,
            Powercable::resetDateToMidnight(1556454600)
        );
    }
}
