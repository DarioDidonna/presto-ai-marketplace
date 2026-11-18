<?php

namespace Tests\Unit;

use App\Models\Image;
use Tests\TestCase;

class ImageUrlTest extends TestCase
{
    public function test_image_url_without_size_points_to_original(): void
    {
        $this->assertStringEndsWith('/storage/articles/5/foto.jpg', Image::getUrlByFilePath('articles/5/foto.jpg'));
    }

    public function test_image_url_with_size_points_to_cropped_version(): void
    {
        $this->assertStringEndsWith(
            '/storage/articles/5/crop_300x300_foto.jpg',
            (new Image(['path' => 'articles/5/foto.jpg']))->getUrl(300, 300)
        );
    }
}
