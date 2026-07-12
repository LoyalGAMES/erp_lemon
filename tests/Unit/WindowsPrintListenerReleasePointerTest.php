<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

class WindowsPrintListenerReleasePointerTest extends TestCase
{
    private string $distPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->distPath = storage_path('framework/testing/windows-release-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($this->distPath.'/releases/0.1.0-123-1');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->distPath);

        parent::tearDown();
    }

    public function test_current_pointer_resolves_a_complete_versioned_release(): void
    {
        file_put_contents($this->distPath.'/CURRENT', "0.1.0-123-1\n");

        $this->assertSame(
            realpath($this->distPath.'/releases/0.1.0-123-1'),
            $this->resolveReleaseDirectory(),
        );
    }

    public function test_missing_invalid_or_escaping_pointer_is_rejected(): void
    {
        $this->assertNull($this->resolveReleaseDirectory());

        foreach (["../outside\n", "/tmp/outside\n", "0.1.0/other\n", str_repeat('a', 129)] as $pointer) {
            file_put_contents($this->distPath.'/CURRENT', $pointer);
            $this->assertNull($this->resolveReleaseDirectory());
        }

        File::deleteDirectory($this->distPath.'/releases/0.1.0-123-1');
        File::ensureDirectoryExists($this->distPath.'/releases');
        symlink(storage_path(), $this->distPath.'/releases/0.1.0-123-1');
        file_put_contents($this->distPath.'/CURRENT', "0.1.0-123-1\n");

        $this->assertNull($this->resolveReleaseDirectory());
    }

    private function resolveReleaseDirectory(): ?string
    {
        $method = new ReflectionMethod(SettingsController::class, 'windowsPrintListenerReleaseDirectory');
        $method->setAccessible(true);

        $result = $method->invoke(app(SettingsController::class), $this->distPath);

        return is_string($result) ? $result : null;
    }
}
