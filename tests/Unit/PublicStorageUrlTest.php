<?php

namespace Tests\Unit;

use App\Support\PublicStorageUrl;
use Tests\TestCase;

class PublicStorageUrlTest extends TestCase
{
    public function test_relative_public_storage_path_uses_configured_storage_base_url(): void
    {
        config([
            'filesystems.public_fallback_url' => 'https://sklad.nikolacars.kiev.ua/storage',
        ]);

        $this->assertSame(
            'https://sklad.nikolacars.kiev.ua/storage/product-photos/missing-live-fallback.jpg',
            PublicStorageUrl::url('product-photos/missing-live-fallback.jpg'),
        );
    }

    public function test_storage_absolute_path_uses_configured_storage_base_url(): void
    {
        config([
            'filesystems.public_fallback_url' => 'https://sklad.nikolacars.kiev.ua/storage',
        ]);

        $this->assertSame(
            'https://sklad.nikolacars.kiev.ua/storage/product-photos/main.jpg',
            PublicStorageUrl::url('/storage/product-photos/main.jpg'),
        );
    }

    public function test_legacy_absolute_public_storage_path_uses_configured_storage_base_url(): void
    {
        config([
            'filesystems.public_fallback_url' => 'https://sklad.nikolacars.kiev.ua/storage',
        ]);

        $this->assertSame(
            'https://sklad.nikolacars.kiev.ua/storage/nikolacars/prod/179_1.jpg',
            PublicStorageUrl::url('/nikolacars/prod/179_1.jpg'),
        );
    }

    public function test_external_remote_url_is_kept_as_is(): void
    {
        config([
            'app.url' => 'http://sklad-zapchastey.test',
            'filesystems.public_fallback_url' => 'https://sklad.nikolacars.kiev.ua/storage',
        ]);

        $this->assertSame(
            'https://drive-parts.com.ua/content/images/part.jpg',
            PublicStorageUrl::url('https://drive-parts.com.ua/content/images/part.jpg'),
        );
    }
}
