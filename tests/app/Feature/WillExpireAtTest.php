<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Helpers\TeHelper;
use Carbon\Carbon;

class WillExpireAtTest extends TestCase {
    /**
     * Test when difference is less than or equal to 90 hours
     *
     * @return void
     */
    public function testWillExpireAtLessThanOrEqual90Hours()
    {
        $created_at   = Carbon::now();
        $due_time     = Carbon::now()->addHours(72); // 3 days or 72 hours from now.

        $result       = TeHelper::willExpireAt($due_time->toDateTimeString(), $created_at->toDateTimeString());
        
        $this->assertEquals($due_time->format('Y-m-d H:i:s'), $result);
    }

    /**
     * Test when difference is less than or equal to 24 hours
     *
     * @return void
     */
    public function testWillExpireAtLessThanOrEqual24Hours()
    {
        $created_at   = Carbon::now();
        $due_time     = Carbon::now()->addHours(4); // 4 hours from now.
        
        $expected   = Carbon::parse($created_at)->addMinutes(90)->format('Y-m-d H:i:s');
        $result     = TeHelper::willExpireAt($due_time->toDateTimeString(), $created_at->toDateTimeString());
        
        $this->assertEquals($expected, $result);
    }

    /**
     * Test when difference is between 24 and 72 hours
     *
     * @return void
     */
    public function testWillExpireAtBetween24And72Hours()
    {
        $created_at   = Carbon::now();
        $due_time     = Carbon::now()->addHours(48); // 48 hours from now.
        
        $expected   = Carbon::parse($created_at)->addHours(16)->format('Y-m-d H:i:s');
        $result     = TeHelper::willExpireAt($due_time->toDateTimeString(), $created_at->toDateTimeString());
        
        $this->assertEquals($expected, $result);
    }

    /**
     * Test when difference is greater than 72 hours
     *
     * @return void
     */
    public function testWillExpireAtGreaterThan72Hours()
    {
        $created_at   = Carbon::now();
        $due_time     = Carbon::now()->addHours(96); // 96 hours from now.
        
        $expected   = Carbon::parse($due_time)->subHours(48)->format('Y-m-d H:i:s');
        $result     = TeHelper::willExpireAt($due_time->toDateTimeString(), $created_at->toDateTimeString());
        
        $this->assertEquals($expected, $result);
    }
}