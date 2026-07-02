<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2021. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace Tests\Pdf;

use Beganovich\Snappdf\Snappdf;
use Tests\TestCase;

/**
 *
 *   App\DataMapper\BaseSettings
 */
class PdfGenerationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('ninja.testvars.travis') !== false) {
            $this->markTestSkipped('Skip test for GH Actions');
        }

    }

    public function testPdfGeneration()
    {
        $pdf = new Snappdf();

        if ($chromiumPath = $this->resolveChromiumPath()) {
            $pdf->setChromiumPath($chromiumPath);
        }

        if (config('ninja.snappdf_chromium_arguments')) {
            $pdf->clearChromiumArguments();
            $pdf->addChromiumArguments(config('ninja.snappdf_chromium_arguments'));
        }

        $pdf = $pdf
            ->setHtml('<h1>Invoice Ninja</h1>')
            ->generate();

        $this->assertNotNull($pdf);
    }

    private function resolveChromiumPath(): ?string
    {
        $configuredPath = config('ninja.snappdf_chromium_path');

        if (is_string($configuredPath) && $configuredPath !== '') {
            return $configuredPath;
        }

        $macBrowserPaths = [
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/Applications/Chromium.app/Contents/MacOS/Chromium',
            '/Applications/Brave Browser.app/Contents/MacOS/Brave Browser',
            '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
        ];

        foreach ($macBrowserPaths as $browserPath) {
            if (is_file($browserPath) && is_executable($browserPath)) {
                return $browserPath;
            }
        }

        return null;
    }
}
