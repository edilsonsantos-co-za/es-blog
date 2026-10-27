<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AppVersion;
use App\Service\Theme\ThemePackageException;
use App\Service\Theme\ThemePackageVerifier;
use PHPUnit\Framework\TestCase;

final class ThemePackageVerifierTest extends TestCase
{
    private string $dir;
    private string $secret;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/theme-verify-' . uniqid();
        mkdir($this->dir . '/keys', 0777, true);
        file_put_contents($this->dir . '/VERSION', "0.1.2\n");
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        file_put_contents($this->dir . '/keys/test.pub', base64_encode(sodium_crypto_sign_publickey($pair)));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testAcceptsASignedPackage(): void
    {
        $result = $this->verifier()->verify($this->package());

        self::assertTrue($result['signed']);
        self::assertSame('casper', $result['manifest']['id']);
        self::assertArrayHasKey('templates/blog/layout.html.twig', $result['files']);
    }

    public function testRejectsAnUnsignedPackage(): void
    {
        $this->expectException(ThemePackageException::class);
        $this->verifier()->verify($this->package(sign: false));
    }

    public function testAllowsUnsignedWhenConfigured(): void
    {
        self::assertFalse($this->verifier(allowUnsigned: true)->verify($this->package(sign: false))['signed']);
    }

    public function testRejectsAFileChangedAfterSigning(): void
    {
        $this->expectExceptionMessage('checksum');
        $this->verifier()->verify($this->package(tamper: true));
    }

    public function testRejectsFilesTheManifestDoesNotList(): void
    {
        $this->expectExceptionMessage('does not list');
        $this->verifier()->verify($this->package(extra: ['templates/blog/extra.html.twig' => 'x']));
    }

    public function testRejectsPathsOutsideTheTheme(): void
    {
        $this->expectExceptionMessage('not allowed');
        $this->verifier()->verify($this->package(files: ['../../src/Kernel.php' => '<?php']));
    }

    public function testRejectsAnotherAppsPackage(): void
    {
        $this->expectExceptionMessage('not EdFolio Blog');
        $this->verifier()->verify($this->package(manifest: ['app' => 'edfolio-portfolio']));
    }

    public function testRejectsAnIncompatibleAppVersion(): void
    {
        $this->expectExceptionMessage('needs EdFolio Blog');
        $this->verifier()->verify($this->package(manifest: ['requires' => '>=2.0.0']));
    }

    public function testAllowedPaths(): void
    {
        self::assertTrue(ThemePackageVerifier::allowedPath('templates/blog/post/show.html.twig'));
        self::assertTrue(ThemePackageVerifier::allowedPath('assets/fonts/inter.woff2'));
        self::assertFalse(ThemePackageVerifier::allowedPath('assets/shell.php'));
        self::assertFalse(ThemePackageVerifier::allowedPath('templates/admin/layout.html.twig'));
        self::assertFalse(ThemePackageVerifier::allowedPath('assets/../../x.css'));
        self::assertFalse(ThemePackageVerifier::allowedPath('/etc/passwd'));
        self::assertFalse(ThemePackageVerifier::allowedPath('assets/.htaccess'));
    }

    public function testVersionConstraints(): void
    {
        self::assertTrue(ThemePackageVerifier::satisfies('0.1.2', '>=0.1.0 <1.0.0'));
        self::assertFalse(ThemePackageVerifier::satisfies('1.0.0', '>=0.1.0 <1.0.0'));
        self::assertTrue(ThemePackageVerifier::satisfies('0.1.2', ''));
        self::assertFalse(ThemePackageVerifier::satisfies('0.1.2', '~0.1'));
    }

    private function verifier(bool $allowUnsigned = false): ThemePackageVerifier
    {
        return new ThemePackageVerifier(new AppVersion($this->dir), $this->dir . '/keys', $allowUnsigned);
    }

    /**
     * @param array<string, string> $files
     * @param array<string, string> $extra files in the zip but not the manifest
     * @param array<string, mixed>  $manifest
     */
    private function package(bool $sign = true, bool $tamper = false, array $files = [], array $extra = [], array $manifest = []): string
    {
        $files += ['templates/blog/layout.html.twig' => '{% block content %}{% endblock %}', 'assets/theme.css' => 'body{}'];
        $json = json_encode($manifest + [
            'schema' => 1, 'id' => 'casper', 'name' => 'Casper', 'version' => '2.1.0', 'app' => 'edfolio-blog',
            'requires' => '>=0.1.0 <1.0.0', 'files' => array_map(fn ($d) => 'sha256:' . hash('sha256', $d), $files),
        ]);

        $path = $this->dir . '/' . uniqid() . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('manifest.json', $json);
        if ($sign) {
            $zip->addFromString('manifest.sig', json_encode(['key' => 'test', 'alg' => 'ed25519', 'sig' => base64_encode(sodium_crypto_sign_detached($json, $this->secret))]));
        }
        foreach ($files + $extra as $name => $data) {
            $zip->addFromString($name, $tamper && $name === 'assets/theme.css' ? 'body{color:red}' : $data);
        }
        $zip->close();

        return $path;
    }
}
