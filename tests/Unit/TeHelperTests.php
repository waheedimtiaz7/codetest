<?php

namespace Tests\Unit;

use Carbon\Carbon;

class TeHelperTests extends \Tests\TestCase
{
    public function testWillExpireAt()
    {
        // Test that the method returns the due time if it is within 90 minutes of the created time
        $created_at = Carbon::now();
        $due_time = $created_at->addMinutes(45);
        $this->assertEquals($due_time->format('Y-m-d H:i:s'), User::willExpireAt($due_time, $created_at));

        // Test that the method returns the created time + 90 minutes if the due time is within 24 hours of the created time
        $created_at = Carbon::now();
        $due_time = $created_at->addHours(2);
        $this->assertEquals($created_at->addMinutes(90)->format('Y-m-d H:i:s'), User::willExpireAt($due_time, $created_at));

        // Test that the method returns the created time + 16 hours if the due time is within 72 hours of the created time
        $created_at = Carbon::now();
        $due_time = $created_at->addHours(70);
        $this->assertEquals($created_at->addHours(16)->format('Y-m-d H:i:s'), User::willExpireAt($due_time, $created_at));

        // Test that the method returns the due time - 48 hours if the due time is more than 72 hours of the created time
        $created_at = Carbon::now();
        $due_time = $created_at->addHours(120);
        $this->assertEquals($due_time->subHours(48)->format('Y-m-d H:i:s'), User::willExpireAt($due_time, $created_at));

    }
}