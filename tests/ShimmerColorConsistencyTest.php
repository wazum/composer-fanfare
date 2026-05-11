<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Tests;

use Composer\Composer;
use Composer\Package\RootPackage;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\StreamOutput;
use Wazum\ComposerFanfare\Plugin;
use Wazum\ComposerFanfare\Tests\Support\InteractiveBufferIO;

final class ShimmerColorConsistencyTest extends TestCase
{
    private string $fixtureDir = '';

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function scenarios(): iterable
    {
        $oneLine = 'COMPOSER';
        $threeLines = "ABC\nDEF\nGHI";
        $fiveLines = "AAA\nBBB\nCCC\nDDD\nEEE";
        $wideLines = "abcdefghij\nklmnopqrst\nuvwxyzabcd";

        yield 'vertical 1-line preset' => [$oneLine, [
            'colors' => 'sunset', 'direction' => 'vertical',
        ]];
        yield 'vertical 3-line preset' => [$threeLines, [
            'colors' => 'sunset', 'direction' => 'vertical',
        ]];
        yield 'vertical 5-line preset' => [$fiveLines, [
            'colors' => 'pride', 'direction' => 'vertical',
        ]];
        yield 'vertical 5-line custom hex array' => [$fiveLines, [
            'colors' => ['#ff0000', '#00ff00', '#0000ff'], 'direction' => 'vertical',
        ]];
        yield 'horizontal preset' => [$wideLines, [
            'colors' => 'sunset', 'direction' => 'horizontal',
        ]];
        yield 'horizontal 5-line preset' => [$fiveLines, [
            'colors' => 'pride', 'direction' => 'horizontal',
        ]];
        // Diagonal scenarios are intentionally excluded: smooth planar flow
        // requires continuous phase shifts, which sample colors *between* the
        // discrete static-render positions. The visual constraint we keep is
        // that the values stay within the gradient bounds — verified by other
        // direction-specific tests rather than equality with the static set.
    }

    /**
     * @param array<string, mixed> $config
     */
    #[Test]
    #[DataProvider('scenarios')]
    public function shimmerEmitsOnlyColorsAlsoPresentInTheStaticRender(string $banner, array $config): void
    {
        $staticColors = $this->captureColors($banner, $config);
        self::assertNotEmpty($staticColors, 'static render must emit at least one color');

        $animatedColors = $this->captureColors($banner, $config + ['animation' => 'shimmer']);
        self::assertNotEmpty($animatedColors, 'shimmer animation must emit at least one color');

        $extras = array_diff($animatedColors, $staticColors);
        self::assertSame(
            [],
            array_values($extras),
            'shimmer emitted colors that never appear in the final static render: '.implode(', ', $extras),
        );
    }

    protected function setUp(): void
    {
        $this->fixtureDir = sys_get_temp_dir().'/composer-fanfare-shimmer-'.bin2hex(random_bytes(4));
        mkdir($this->fixtureDir, 0o700, true);
        // Force truecolor regardless of host TERM so the test palette comparison
        // is deterministic; under truecolor, ColorSupport::detect emits
        // \033[38;2;R;G;Bm which the regex below matches.
        putenv('COLORTERM=truecolor');
    }

    protected function tearDown(): void
    {
        if ('' !== $this->fixtureDir && is_dir($this->fixtureDir)) {
            foreach (glob($this->fixtureDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->fixtureDir);
        }
        putenv('COMPOSER');
        putenv('COLORTERM');
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    private function captureColors(string $banner, array $config): array
    {
        file_put_contents($this->fixtureDir.'/banner.txt', $banner);
        $composerJson = $this->fixtureDir.'/composer.json';
        file_put_contents($composerJson, '{"name":"wazum/fanfare-test"}');
        putenv('COMPOSER='.$composerJson);

        $io = new InteractiveBufferIO('', StreamOutput::VERBOSITY_NORMAL, new OutputFormatter(true));
        $composer = new Composer();
        $package = new RootPackage('wazum/fanfare-test', '1.0.0', '1.0.0');
        $package->setExtra(['fanfare' => array_merge(['template' => 'banner.txt'], $config)]);
        $composer->setPackage($package);

        $plugin = new Plugin();
        $plugin->activate($composer, $io);
        $plugin->onPostCmd(new Event(ScriptEvents::POST_INSTALL_CMD, $composer, $io));

        $output = $io->getOutput();
        if (0 < preg_match_all('/\033\[38;2;\d+;\d+;\d+m/', $output, $matches)) {
            return array_values(array_unique($matches[0]));
        }

        return [];
    }
}
