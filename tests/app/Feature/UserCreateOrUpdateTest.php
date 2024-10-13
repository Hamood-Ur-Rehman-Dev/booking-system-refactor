<?php

namespace Tests\Feature;


use Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

use App\Models\User;

class UserCreateOrUpdateTest extends TestCase {
    use RefreshDatabase;

     /**
     * Test when new user created
     *
     * @return void
     */
    public function testNewUserCreated(){

        $requestData = [
            'role'            => env('CUSTOMER_ROLE_ID'),
            'name'            => 'Hamood',
            'email'           => 'hamoodr@example.com',
            'password'        => 'password123',
            'consumer_type'   => 'paid',
            'company_id'      => '',
            'department_id'   => '',
            'dob_or_orgid'    => '01/01/1990',
            'phone'           => '0514123812',
            'mobile'          => '923345243524',
            'customer_type'   => 'customer',
            'username'        => 'hamoodr',
            'post_code'       => '12345',
            'address'         => '123 Main St',
            'city'            => 'Islamabad',
            'town'            => 'Islamabad',
            'country'         => 'Pakistan',
            'additional_info' => 'Some info about user',
            'status'          => '1'
        ];

        $user = (new User())->createOrUpdate(null, $requestData);

        $this->assertNotNull($user);
        $this->assertEquals('Hamood', $user->name);
        $this->assertEquals('hamoodr@example.com', $user->email);
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertEquals('customer', $user->userMeta->consumer_type);
        $this->assertEquals($user->status, '1');

    }


     /**
     * Test when user updated
     *
     * @return void
     */
    public function testOldUserUpdated(){

        $existingUser = User::factory()->create();

        $requestData = [
            'role'            => env('CUSTOMER_ROLE_ID'),
            'name'            => 'Hamood Ur Rehman',
            'additional_info' => 'Updated info',
            'status'          => '0'
        ];

        $user = (new User())->createOrUpdate($existingUser->id, $requestData);

        $this->assertNotNull($user);
        $this->assertEquals('Hamood Ur Rehman', $user->name);
        $this->assertEquals('Updated info', $user->additional_info);
        $this->assertEquals($user->status, '0');

    }
}