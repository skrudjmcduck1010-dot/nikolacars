<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicStorageRouteTest extends TestCase
{
    public function test_public_storage_files_are_served_without_authentication(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('donor-cars/photo.jpg', 'fake image');

        $response = $this->get('/storage/donor-cars/photo.jpg');

        $response->assertOk();
    }

    public function test_missing_public_storage_files_return_not_found(): void
    {
        Storage::fake('public');

        $response = $this->get('/storage/donor-cars/missing.jpg');

        $response->assertNotFound();
    }
}
