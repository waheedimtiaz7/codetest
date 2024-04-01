<?php

namespace Tests\Unit;

use DTApi\Models\User;

use Carbon\Carbon;

class UserTests extends \Tests\TestCase{


    public function testCreateOrUpdate()
    {
        // Create a new user with the customer role
        $customerRequest = [
            'role' => env('CUSTOMER_ROLE_ID'),
            'name' => 'Test Customer',
            'email' => 'waheed@gmail.com',
            'password' => 'password',
            'dob_or_orgid' => '123456789',
            'consumer_type' => 'paid',
            'customer_type' => 'individual',
            'username' => 'test_customer',
            'post_code' => '12345',
            'address' => '123 Test St',
            'city' => 'Test City',
            'town' => 'Test Town',
            'country' => 'Test Country',
        ];
        $customer = $this->createOrUpdate(null, $customerRequest);
        $this->assertInstanceOf(User::class, $customer);
        $this->assertEquals($customerRequest['name'], $customer->name);
        $this->assertEquals($customerRequest['email'], $customer->email);
        $this->assertTrue($customer->password_is($customerRequest['password']));
        $this->assertEquals($customerRequest['dob_or_orgid'], $customer->dob_or_orgid);
        $this->assertEquals($customerRequest['consumer_type'], $customer->user_meta->consumer_type);
        $this->assertEquals($customerRequest['customer_type'], $customer->user_meta->customer_type);
        $this->assertEquals($customerRequest['username'], $customer->user_meta->username);
        $this->assertEquals($customerRequest['post_code'], $customer->user_meta->post_code);
        $this->assertEquals($customerRequest['address'], $customer->user_meta->address);
        $this->assertEquals($customerRequest['city'], $customer->user_meta->city);
        $this->assertEquals($customerRequest['town'], $customer->user_meta->town);
        $this->assertEquals($customerRequest['country'], $customer->user_meta->country);

        // Update the user's email
        $customerRequest['email'] = 'waheed1@gmail.com';
        $customer = $this->createOrUpdate($customer->id, $customerRequest);
        $this->assertInstanceOf(User::class, $customer);
        $this->assertEquals($customerRequest['email'], $customer->email);

        // Create a new user with the translator role
        $translatorRequest = [
            'role' => env('TRANSLATOR_ROLE_ID'),
            'name' => 'Test Translator',
            'email' => 'waheed2@gmail.com',
            'password' => 'password',
            'translator_type' => 'freelance',
            'gender' => 'male',
            'translator_level' => 'intermediate',
            'user_language' => [1, 2],
            'post_code' => '12345',
            'address' => '123 Test St',
            'address_2' => 'Test Unit',
            'town' => 'Test Town',
        ];
        $translator = $this->createOrUpdate(null, $translatorRequest);
        $this->assertInstanceOf(User::class, $translator);
        $this->assertEquals($translatorRequest['name'], $translator->name);
        $this->assertEquals($translatorRequest['email'], $translator->email);
        $this->assertTrue($translator->password_is($translatorRequest['password']));
        $this->assertEquals($translatorRequest['translator_type'], $translator->user_meta->translator_type);
        $this->assertEquals($translatorRequest['gender'], $translator->user_meta->gender);
        $this->assertEquals($translatorRequest['translator_level'], $translator->user_meta->translator_level);
        $this->assertEquals($translatorRequest['post_code'], $translator->user_meta->post_code);
        $this->assertEquals($translatorRequest['address'], $translator->user_meta->address);
        $this->assertEquals($translatorRequest['address_2'], $translator->user_meta->address_2);
        $this->assertEquals($translatorRequest['town'], $translator->user_meta->town);
        $this->assertEquals(2, $translator->user_languages->count());
        $this->assertEquals(1, $translator->user_languages->first()->lang_id);
        $this->assertEquals(2, $translator->user_languages->last()->lang_id);
    }

    private function createOrUpdate($id, $request)
    {
        $user = new UserController();
        return $user->createOrUpdate($id, $request);
    }

    public function testEnable()
    {
        // Create a new user
        $user = factory(User::class)->create(['status' => '0']);
        $this->assertFalse($user->status);

        // Enable the user
        $this->enable($user->id);
        $user = $user->fresh();
        $this->assertTrue($user->status);
    }

    public function testDisable()
    {
        $user = factory(User::class)->create(['status' => '1']);
        $this->assertTrue($user->status);

        $this->disable($user->id);
        $user = $user->fresh();
        $this->assertFalse($user->status);
    }

    public function testGetTranslators()
    {
        $translators = factory(User::class, 3)->create(['user_type' => env('TRANSLATOR_ROLE_ID')]);
        factory(User::class, 3)->create(['user_type' => env('CUSTOMER_ROLE_ID')]);

        $this->assertEquals($translators, $this->getTranslators());
    }

    private function enable($id)
    {
        $user = new UserController();
        $user->enable($id);
    }

    private function disable($id)
    {
        $user = new UserController();
        $user->disable($id);
    }

    private function getTranslators()
    {
        $user = new UserController();
        return $user->getTranslators();
    }
}