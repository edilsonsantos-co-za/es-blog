<?php

declare(strict_types=1);

namespace App\Tests\Service\Automation;

use App\Service\Automation\BannerGenerator;
use PHPUnit\Framework\TestCase;

final class BannerGeneratorTest extends TestCase
{
    public function testRendersOpenGraphSizedImageForLongTitles(): void
    {
        $image = (new BannerGenerator(dirname(__DIR__, 3)))->render(
            str_repeat('A very long headline about PHP and Symfony ', 8),
            'Symfony release',
            'Symfony Blog'
        );

        self::assertSame(BannerGenerator::WIDTH, imagesx($image));
        self::assertSame(BannerGenerator::HEIGHT, imagesy($image));
    }
}
