<?php

namespace Tests\Unit;

use App\Services\IntegrateCore\FileLibrary;
use PHPUnit\Framework\TestCase;

class IntegrateCoreFilePathsTest extends TestCase
{
    public function test_hidden_and_unsafe_paths_are_rejected(): void
    {
        foreach (['', '.DS_Store', '._.DS_Store', 'Client/.cache/file.pdf', 'Client/../Other/file.pdf', '/Client/file.pdf', 'Client//file.pdf', 'Client\\file.pdf', "Client/fi\0le.pdf"] as $path) {
            self::assertFalse(FileLibrary::visiblePath($path), $path);
        }
    }

    public function test_folder_membership_requires_an_entire_path_segment(): void
    {
        self::assertTrue(FileLibrary::inside('Ari Miller - FundMax/Project Docs/report.v2.pdf', 'Ari Miller - FundMax'));
        self::assertFalse(FileLibrary::inside('Client B/document.pdf', 'Client'));
        self::assertFalse(FileLibrary::inside('Client/../Other/document.pdf', 'Client'));
        self::assertFalse(FileLibrary::inside('Client/.private/document.pdf', 'Client'));
        self::assertTrue(FileLibrary::visiblePath('02. Client Information/Client/report.v2.pdf'));
    }
}
