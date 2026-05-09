<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Tests;

use Composer\Composer;
use Composer\IO\BufferIO;
use Composer\Package\Locker;
use Composer\Package\RootPackage;
use Composer\Repository\LockArrayRepository;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\StreamOutput;
use Wazum\ComposerFanfare\Plugin;

final class PluginTest extends TestCase
{
    private string $fixtureDir = '';

    protected function setUp(): void
    {
        $this->fixtureDir = sys_get_temp_dir() . '/composer-fanfare-' . bin2hex(random_bytes(4));
        mkdir($this->fixtureDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        if ($this->fixtureDir !== '' && is_dir($this->fixtureDir)) {
            foreach (glob($this->fixtureDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->fixtureDir);
        }
        putenv('COMPOSER');
    }

    #[Test]
    public function getSubscribedEventsReturnsBothScriptEvents(): void
    {
        self::assertSame(
            [
                ScriptEvents::POST_INSTALL_CMD => 'onPostCmd',
                ScriptEvents::POST_UPDATE_CMD  => 'onPostCmd',
            ],
            Plugin::getSubscribedEvents(),
        );
    }

    #[Test]
    public function absentFanfareConfigProducesNoOutput(): void
    {
        $io = $this->decoratedIo();
        $composer = $this->makeComposer([]);

        $this->runPlugin($composer, $io);

        self::assertSame('', $io->getOutput());
    }

    #[Test]
    public function missingTemplateEmitsWarning(): void
    {
        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'no/such/file.txt'],
        ]);

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        self::assertStringContainsString('Template "no/such/file.txt" not found', $output);
    }

    #[Test]
    public function knownPresetExpandsToPalette(): void
    {
        $this->writeBanner("a\nb\nc");
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'banner.txt', 'colors' => 'pride'],
        ]);

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        // pride has 6 stops; 3 lines sample at fractions 0, 0.5, 1
        self::assertStringContainsString("\033[38;2;228;3;3ma\033[0m", $output);
        self::assertStringContainsString("\033[38;2;128;183;19mb\033[0m", $output);
        self::assertStringContainsString("\033[38;2;117;7;135mc\033[0m", $output);
    }

    #[Test]
    public function unknownPresetWarnsAndRendersPlain(): void
    {
        $this->writeBanner('hello');
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'banner.txt', 'colors' => 'neon'],
        ]);

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        self::assertStringContainsString('Unknown preset "neon"', $output);
        self::assertStringContainsString('available: aurora, catppuccin, doom, dracula, fire, gruvbox, iceberg, matrix, monokai, nord, ocean, pride, solarized, sunset, synthwave', $output);
        self::assertStringContainsString('hello', $output);
        self::assertStringNotContainsString("\033[38;", $output);
    }

    #[Test]
    public function templateResolvedRelativeToRootComposerFile(): void
    {
        $this->writeBanner('hi');
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'banner.txt'],
        ]);

        $this->runPlugin($composer, $io);

        self::assertStringContainsString('hi', $io->getOutput());
    }

    #[Test]
    public function horizontalDirectionAppliesPerCharacterColors(): void
    {
        $this->writeBanner('ab');
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => [
                'template' => 'banner.txt',
                'colors' => ['#ff0000', '#00ff00'],
                'direction' => 'horizontal',
            ],
        ]);

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        self::assertStringContainsString("\033[38;2;255;0;0ma", $output);
        self::assertStringContainsString("\033[38;2;0;255;0mb", $output);
    }

    #[Test]
    public function unknownDirectionWarnsAndDefaultsToVertical(): void
    {
        $this->writeBanner('ab');
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => [
                'template' => 'banner.txt',
                'colors' => ['#ff0000'],
                'direction' => 'spiral',
            ],
        ]);

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        self::assertStringContainsString('Unknown direction "spiral"', $output);
        self::assertStringContainsString('available: vertical, horizontal, diagonal', $output);
        self::assertStringContainsString("\033[38;2;255;0;0mab\033[0m", $output);
    }

    #[Test]
    public function firePresetExpandsToGradient(): void
    {
        $this->writeBanner("a\nb\nc");
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'banner.txt', 'colors' => 'fire'],
        ]);

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        // fire = ['#3a0000', '#ff5400', '#ffd700']; 3 lines map exactly to the 3 stops
        self::assertStringContainsString("\033[38;2;58;0;0ma\033[0m", $output);
        self::assertStringContainsString("\033[38;2;255;84;0mb\033[0m", $output);
        self::assertStringContainsString("\033[38;2;255;215;0mc\033[0m", $output);
    }

    #[Test]
    public function draculaPresetExpandsToGradient(): void
    {
        $this->writeBanner("x\ny\nz");
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'banner.txt', 'colors' => 'dracula'],
        ]);

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        // dracula = ['#ff79c6', '#bd93f9', '#8be9fd']
        self::assertStringContainsString("\033[38;2;255;121;198mx\033[0m", $output);
        self::assertStringContainsString("\033[38;2;189;147;249my\033[0m", $output);
        self::assertStringContainsString("\033[38;2;139;233;253mz\033[0m", $output);
    }

    #[Test]
    public function diagonalDirectionAppliesToBanner(): void
    {
        $this->writeBanner("ab\ncd");
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => [
                'template' => 'banner.txt',
                'colors' => ['#ff0000', '#0000ff'],
                'direction' => 'diagonal',
            ],
        ]);

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        self::assertStringContainsString("\033[38;2;255;0;0ma", $output);
        self::assertStringContainsString("\033[38;2;128;0;128mb", $output);
        self::assertStringContainsString("\033[38;2;128;0;128mc", $output);
        self::assertStringContainsString("\033[38;2;0;0;255md", $output);
    }

    #[Test]
    public function crlfTemplateIsNormalizedToLf(): void
    {
        file_put_contents($this->fixtureDir . '/banner.txt', "line one\r\nline two\r\n");
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'banner.txt', 'colors' => ['#ff0000']],
        ]);

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        self::assertStringNotContainsString("\r", $output);
        self::assertStringContainsString("\033[38;2;255;0;0mline one\033[0m", $output);
        self::assertStringContainsString("\033[38;2;255;0;0mline two\033[0m", $output);
    }

    #[Test]
    public function streamWrapperTemplateIsRejected(): void
    {
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'php://stdin'],
        ]);

        $this->runPlugin($composer, $io);

        self::assertStringContainsString('Template "php://stdin" outside project root', $io->getOutput());
    }

    #[Test]
    public function templateEscapingRootDirectoryIsRejected(): void
    {
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => '/etc/passwd'],
        ]);

        $this->runPlugin($composer, $io);

        self::assertStringContainsString('Template "/etc/passwd" outside project root', $io->getOutput());
    }

    #[Test]
    public function oversizedTemplateIsRejected(): void
    {
        file_put_contents($this->fixtureDir . '/banner.txt', str_repeat('x', 20000));
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'banner.txt', 'colors' => ['#ff0000']],
        ]);

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        self::assertStringContainsString('Template "banner.txt"', $output);
        self::assertStringContainsString('too large', $output);
    }

    #[Test]
    public function controlCharactersInTemplateNameAreEscapedInWarning(): void
    {
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => "evil\033]2;pwned\007name"],
        ]);

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        self::assertStringNotContainsString("\033]2;pwned\007", $output);
        self::assertStringContainsString('\\033', $output);
    }

    #[Test]
    public function trailingBlankLinesInTemplateArePreserved(): void
    {
        // Three trailing newlines: one terminator + two intentional blank rows.
        file_put_contents($this->fixtureDir . '/banner.txt', "hello\n\n\n");
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'banner.txt', 'colors' => ['#ff0000']],
        ]);

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        $helloPosition = strpos($output, 'hello');
        $statusPosition = strpos($output, 'PHP');
        self::assertNotFalse($helloPosition);
        self::assertNotFalse($statusPosition);
        $betweenSection = substr($output, $helloPosition, $statusPosition - $helloPosition);
        // Expected newlines between "hello" and "PHP": hello terminator + 2 intentional blank rows
        self::assertSame(3, substr_count($betweenSection, "\n"));
    }

    #[Test]
    public function footerCanBeHiddenViaConfig(): void
    {
        $this->writeBanner('x');
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => [
                'template' => 'banner.txt',
                'colors'   => ['#ff0000'],
                'footer'   => false,
            ],
        ]);

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        self::assertStringNotContainsString('PHP', $output);
        self::assertStringNotContainsString('wazum/fanfare-test', $output);
        self::assertStringContainsString("\033[38;2;255;0;0mx\033[0m", $output);
    }

    #[Test]
    public function footerShownByDefault(): void
    {
        $this->writeBanner('x');
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'banner.txt', 'colors' => ['#ff0000']],
        ]);

        $this->runPlugin($composer, $io);

        self::assertStringContainsString('PHP ' . PHP_VERSION, $io->getOutput());
    }

    #[Test]
    public function statusLineIncludesProjectNameAndVersion(): void
    {
        $this->writeBanner('x');
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'banner.txt', 'colors' => ['#ff0000']],
        ]);

        $this->runPlugin($composer, $io);

        self::assertStringContainsString('wazum/fanfare-test 1.0.0', $io->getOutput());
    }

    #[Test]
    public function statusLineUsesSingularForOnePackage(): void
    {
        $this->writeBanner('x');
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'banner.txt', 'colors' => ['#ff0000']],
        ]);
        $composer->setLocker($this->makeLockerWithPackageCount(1));

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        self::assertStringContainsString('1 package', $output);
        self::assertStringNotContainsString('1 packages', $output);
    }

    #[Test]
    public function statusLineUsesPluralForMultiplePackages(): void
    {
        $this->writeBanner('x');
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'banner.txt', 'colors' => ['#ff0000']],
        ]);
        $composer->setLocker($this->makeLockerWithPackageCount(3));

        $this->runPlugin($composer, $io);

        self::assertStringContainsString('3 packages', $io->getOutput());
    }

    #[Test]
    public function whitespaceAroundConfigValuesIsTolerated(): void
    {
        $this->writeBanner("a\nb\nc");
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => [
                'template'  => '  banner.txt  ',
                'colors'    => '  fire  ',
                'direction' => "\thorizontal\n",
            ],
        ]);

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        // fire palette renders without warnings
        self::assertStringContainsString("\033[38;2;58;0;0m", $output);
        self::assertStringNotContainsString('Unknown', $output);
        self::assertStringNotContainsString('not found', $output);
    }

    #[Test]
    public function whitespaceInColorsArrayIsTrimmed(): void
    {
        $this->writeBanner("a\nb");
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => [
                'template' => 'banner.txt',
                'colors'   => ['  #ff0000  ', "\t#00ff00\n"],
            ],
        ]);

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        self::assertStringContainsString("\033[38;2;255;0;0ma\033[0m", $output);
        self::assertStringContainsString("\033[38;2;0;255;0mb\033[0m", $output);
    }

    #[Test]
    public function randomColorsPicksAPreset(): void
    {
        $this->writeBanner('x');
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'banner.txt', 'colors' => 'random'],
        ]);

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        self::assertStringContainsString('x', $output);
        self::assertMatchesRegularExpression('/\033\[38;2;\d+;\d+;\d+m/', $output);
    }

    #[Test]
    public function invalidHexInArrayIsSkipped(): void
    {
        $this->writeBanner("a\nb");
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => [
                'template' => 'banner.txt',
                'colors' => ['notahex', '#00ff00'],
            ],
        ]);

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        self::assertStringContainsString("\033[38;2;0;255;0ma\033[0m", $output);
        self::assertStringContainsString("\033[38;2;0;255;0mb\033[0m", $output);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function makeComposer(array $extra): Composer
    {
        $composer = new Composer();
        $package = new RootPackage('wazum/fanfare-test', '1.0.0', '1.0.0');
        $package->setExtra($extra);
        $composer->setPackage($package);

        return $composer;
    }

    private function writeBanner(string $contents): void
    {
        file_put_contents($this->fixtureDir . '/banner.txt', $contents);
    }

    private function pinComposerFile(): void
    {
        $composerJson = $this->fixtureDir . '/composer.json';
        file_put_contents($composerJson, '{"name":"wazum/fanfare-test"}');
        putenv('COMPOSER=' . $composerJson);
    }

    private function runPlugin(Composer $composer, BufferIO $io): void
    {
        $plugin = new Plugin();
        $plugin->activate($composer, $io);
        $plugin->onPostCmd(new Event(ScriptEvents::POST_INSTALL_CMD, $composer, $io));
    }

    private function decoratedIo(): BufferIO
    {
        return new BufferIO('', StreamOutput::VERBOSITY_NORMAL, new OutputFormatter(true));
    }

    private function makeLockerWithPackageCount(int $count): Locker
    {
        $packages = [];
        for ($i = 0; $i < $count; $i++) {
            $packages[] = new RootPackage(sprintf('vendor/dep-%d', $i), '1.0.0', '1.0.0');
        }

        $repository = $this->createMock(LockArrayRepository::class);
        $repository->method('getPackages')->willReturn($packages);

        $locker = $this->createMock(Locker::class);
        $locker->method('isLocked')->willReturn(true);
        $locker->method('getLockedRepository')->willReturn($repository);

        return $locker;
    }
}
