<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use ZipArchive;

trait DatPackageAssertions
{
    private function datFromPackage(TestResponse $response, string $datFileName): string
    {
        $response->assertOk();
        $this->assertStringContainsString(
            'filename=' . preg_replace('/\.DAT$/i', '.zip', $datFileName),
            (string) $response->headers->get('content-disposition')
        );

        $zipPath = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive();

        $this->assertTrue($zip->open($zipPath));
        $this->assertNotFalse($zip->locateName($datFileName));
        $this->assertNotFalse($zip->locateName(preg_replace('/\.DAT$/i', '-ATTACHMENT.pdf', $datFileName)));

        $content = $zip->getFromName($datFileName);
        $pdf = $zip->getFromName(preg_replace('/\.DAT$/i', '-ATTACHMENT.pdf', $datFileName));
        $zip->close();

        $this->assertIsString($content);
        $this->assertIsString($pdf);
        $this->assertStringStartsWith('%PDF-', $pdf);

        return $content;
    }
}
