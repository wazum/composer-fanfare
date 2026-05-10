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
use Wazum\ComposerFanfare\CommandProvider;
use Wazum\ComposerFanfare\Plugin;
use Wazum\ComposerFanfare\Preset;

final class PluginTest extends TestCase
{
    private string $fixtureDir = '';

    #[Test]
    public function getSubscribedEventsReturnsBothScriptEvents(): void
    {
        self::assertSame(
            [
                ScriptEvents::POST_INSTALL_CMD => 'onPostCmd',
                ScriptEvents::POST_UPDATE_CMD => 'onPostCmd',
            ],
            Plugin::getSubscribedEvents(),
        );
    }

    #[Test]
    public function getCapabilitiesAdvertisesCommandProvider(): void
    {
        self::assertSame(
            [\Composer\Plugin\Capability\CommandProvider::class => CommandProvider::class],
            (new Plugin())->getCapabilities(),
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
    public function templateThatIsADirectoryEmitsWarning(): void
    {
        mkdir($this->fixtureDir.'/banner.txt');
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'banner.txt'],
        ]);

        $this->runPlugin($composer, $io);

        rmdir($this->fixtureDir.'/banner.txt');

        self::assertStringContainsString('Template "banner.txt" not found', $io->getOutput());
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
    public function everyPresetRendersItsFirstStopOnSingleLine(): void
    {
        $this->writeBanner('x');
        $this->pinComposerFile();

        foreach (Preset::cases() as $preset) {
            $io = $this->decoratedIo();
            $composer = $this->makeComposer([
                'fanfare' => ['template' => 'banner.txt', 'colors' => $preset->value],
            ]);

            $this->runPlugin($composer, $io);

            $firstStop = ltrim($preset->colors()[0], '#');
            $parsed = sscanf($firstStop, '%02x%02x%02x');
            self::assertIsArray($parsed);
            [$red, $green, $blue] = $parsed;
            $expectedEscape = sprintf("\033[38;2;%d;%d;%dmx", $red, $green, $blue);

            self::assertStringContainsString(
                $expectedEscape,
                $io->getOutput(),
                "Preset {$preset->value} should render its first stop",
            );
        }
    }

    #[Test]
    public function unknownPresetWarns(): void
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
    }

    #[Test]
    public function unknownPresetRendersPlain(): void
    {
        $this->writeBanner('hello');
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'banner.txt', 'colors' => 'neon'],
        ]);

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
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
    public function singleHexStringInColorsRendersAsThatColor(): void
    {
        $this->writeBanner('x');
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'banner.txt', 'colors' => '#ff0000'],
        ]);

        $this->runPlugin($composer, $io);

        self::assertStringContainsString("\033[38;2;255;0;0mx\033[0m", $io->getOutput());
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
    public function unknownDirectionWarns(): void
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
    }

    #[Test]
    public function unknownDirectionDefaultsToVertical(): void
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

        // Vertical wraps the whole line in a single color escape.
        self::assertStringContainsString("\033[38;2;255;0;0mab\033[0m", $io->getOutput());
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
        file_put_contents($this->fixtureDir.'/banner.txt', "line one\r\nline two\r\n");
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'banner.txt', 'colors' => ['#ff0000']],
        ]);

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        self::assertStringNotContainsString("\r", $output);
        // Crucially: no extra blank line between the two banner rows (would happen
        // if "\r\n" weren't normalized to a single "\n" by the str_replace pair).
        self::assertMatchesRegularExpression(
            '/line one\033\[0m\n\033\[38;2;255;0;0mline two/',
            $output,
        );
    }

    #[Test]
    public function bareCrLineEndingsAreNormalizedToLf(): void
    {
        file_put_contents($this->fixtureDir.'/banner.txt', "old\rstyle\rbanner");
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'banner.txt', 'colors' => ['#ff0000']],
        ]);

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        self::assertStringNotContainsString("\r", $output);
        self::assertStringContainsString("\033[38;2;255;0;0mold\033[0m", $output);
        self::assertStringContainsString("\033[38;2;255;0;0mstyle\033[0m", $output);
        self::assertStringContainsString("\033[38;2;255;0;0mbanner\033[0m", $output);
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
    public function siblingDirectoryWithSamePrefixIsRejected(): void
    {
        // Create a sibling directory whose realpath shares a prefix with the project root
        // but isn't the project root itself.
        $sibling = $this->fixtureDir.'-sibling';
        mkdir($sibling);
        $bannerPath = $sibling.'/banner.txt';
        file_put_contents($bannerPath, 'leak');

        try {
            $this->pinComposerFile();

            $io = $this->decoratedIo();
            $composer = $this->makeComposer([
                'fanfare' => ['template' => $bannerPath],
            ]);

            $this->runPlugin($composer, $io);

            self::assertStringContainsString('outside project root', $io->getOutput());
        } finally {
            @unlink($bannerPath);
            @rmdir($sibling);
        }
    }

    #[Test]
    public function templateAtMaxBytesIsAccepted(): void
    {
        file_put_contents($this->fixtureDir.'/banner.txt', str_repeat('x', 16384));
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'banner.txt', 'colors' => ['#ff0000']],
        ]);

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        self::assertStringNotContainsString('too large', $output);
    }

    #[Test]
    public function templateOneByteOverMaxIsRejected(): void
    {
        file_put_contents($this->fixtureDir.'/banner.txt', str_repeat('x', 16385));
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'banner.txt', 'colors' => ['#ff0000']],
        ]);

        $this->runPlugin($composer, $io);

        self::assertStringContainsString('too large', $io->getOutput());
    }

    #[Test]
    public function oversizedTemplateIsRejected(): void
    {
        file_put_contents($this->fixtureDir.'/banner.txt', str_repeat('x', 20000));
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
        file_put_contents($this->fixtureDir.'/banner.txt', "hello\n\n\n");
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
                'colors' => ['#ff0000'],
                'footer' => false,
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

        self::assertStringContainsString('PHP '.PHP_VERSION, $io->getOutput());
    }

    #[Test]
    public function statusLineOmitsComposerNoVersionPlaceholder(): void
    {
        $this->writeBanner('x');
        $this->pinComposerFile();

        $composer = new Composer();
        $package = new RootPackage('acme/site', '1.0.0.0', '1.0.0+no-version-set');
        $package->setExtra(['fanfare' => ['template' => 'banner.txt', 'colors' => ['#ff0000']]]);
        $composer->setPackage($package);

        $io = $this->decoratedIo();
        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        self::assertStringContainsString('acme/site', $output);
        self::assertStringNotContainsString('no-version-set', $output);
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
                'template' => '  banner.txt  ',
                'colors' => '  fire  ',
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
                'colors' => ['  #ff0000  ', "\t#00ff00\n"],
            ],
        ]);

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        self::assertStringContainsString("\033[38;2;255;0;0ma\033[0m", $output);
        self::assertStringContainsString("\033[38;2;0;255;0mb\033[0m", $output);
    }

    #[Test]
    public function nonStringEntryInColorsArrayIsSkipped(): void
    {
        $this->writeBanner("a\nb");
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => [
                'template' => 'banner.txt',
                'colors' => [42, false, '#00ff00', null],
            ],
        ]);

        $this->runPlugin($composer, $io);

        // Only the valid #00ff00 hex is kept; non-strings are dropped.
        $output = $io->getOutput();
        self::assertStringContainsString("\033[38;2;0;255;0ma\033[0m", $output);
        self::assertStringContainsString("\033[38;2;0;255;0mb\033[0m", $output);
    }

    #[Test]
    public function nonStringEntryInColorsArrayWarns(): void
    {
        $this->writeBanner('x');
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => [
                'template' => 'banner.txt',
                'colors' => [42, '#00ff00'],
            ],
        ]);

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        self::assertStringContainsString('Invalid hex color int', $output);
        self::assertStringContainsString('expected #RRGGBB', $output);
    }

    #[Test]
    public function reverseTransformFlipsThePresetOrdering(): void
    {
        $this->writeBanner("a\nb\nc");
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => [
                'template' => 'banner.txt',
                'colors' => 'fire',
                'transform' => 'reverse',
            ],
        ]);

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        // fire = ['#3a0000', '#ff5400', '#ffd700']; reversed → first line gets the last stop.
        self::assertStringContainsString("\033[38;2;255;215;0ma\033[0m", $output);
        self::assertStringContainsString("\033[38;2;255;84;0mb\033[0m", $output);
        self::assertStringContainsString("\033[38;2;58;0;0mc\033[0m", $output);
    }

    #[Test]
    public function reverseTransformAppliesToHexArrays(): void
    {
        $this->writeBanner("a\nb");
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => [
                'template' => 'banner.txt',
                'colors' => ['#ff0000', '#00ff00'],
                'transform' => 'reverse',
            ],
        ]);

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        // Reversed: green on first line, red on second.
        self::assertStringContainsString("\033[38;2;0;255;0ma\033[0m", $output);
        self::assertStringContainsString("\033[38;2;255;0;0mb\033[0m", $output);
    }

    #[Test]
    public function unknownTransformWarns(): void
    {
        $this->writeBanner("a\nb\nc");
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => [
                'template' => 'banner.txt',
                'colors' => 'fire',
                'transform' => 'pastel',
            ],
        ]);

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        self::assertStringContainsString('Unknown transform "pastel"', $output);
        self::assertStringContainsString('available: reverse', $output);
    }

    #[Test]
    public function unknownTransformKeepsOriginalOrdering(): void
    {
        $this->writeBanner("a\nb\nc");
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => [
                'template' => 'banner.txt',
                'colors' => 'fire',
                'transform' => 'pastel',
            ],
        ]);

        $this->runPlugin($composer, $io);

        // fire's first stop on line 1 — preserved despite the unknown transform.
        self::assertStringContainsString("\033[38;2;58;0;0ma\033[0m", $io->getOutput());
    }

    #[Test]
    public function whitespaceAroundTransformIsTrimmed(): void
    {
        $this->writeBanner("a\nb");
        $this->pinComposerFile();

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => [
                'template' => 'banner.txt',
                'colors' => ['#ff0000', '#00ff00'],
                'transform' => '  reverse  ',
            ],
        ]);

        $this->runPlugin($composer, $io);

        $output = $io->getOutput();
        self::assertStringContainsString("\033[38;2;0;255;0ma\033[0m", $output);
        self::assertStringNotContainsString('Unknown', $output);
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

    #[Test]
    public function invalidHexInArrayWarns(): void
    {
        $this->writeBanner('x');
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
        self::assertStringContainsString('Invalid hex color "notahex"', $output);
        self::assertStringContainsString('expected #RRGGBB', $output);
    }

    #[Test]
    public function composerFanfareEnvVarSetToZeroSuppressesBanner(): void
    {
        $this->writeBanner('hello');
        $this->pinComposerFile();
        putenv('COMPOSER_FANFARE=0');

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'banner.txt', 'colors' => '#ff0000'],
        ]);

        $this->runPlugin($composer, $io);

        self::assertSame('', $io->getOutput());
    }

    #[Test]
    public function composerFanfareEnvVarSetToOneStillRendersBanner(): void
    {
        $this->writeBanner('hello');
        $this->pinComposerFile();
        putenv('COMPOSER_FANFARE=1');

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'banner.txt', 'colors' => '#ff0000'],
        ]);

        $this->runPlugin($composer, $io);

        self::assertStringContainsString("\033[38;2;255;0;0mhello", $io->getOutput());
    }

    #[Test]
    public function composerFanfareEnvVarWithSurroundingWhitespaceSuppressesBanner(): void
    {
        $this->writeBanner('hello');
        $this->pinComposerFile();
        putenv('COMPOSER_FANFARE= 0 ');

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'banner.txt', 'colors' => '#ff0000'],
        ]);

        $this->runPlugin($composer, $io);

        self::assertSame('', $io->getOutput());
    }

    #[Test]
    public function bannerWiderThanTerminalIsSilentlySkipped(): void
    {
        $this->writeBanner(str_repeat('x', 50));
        $this->pinComposerFile();
        putenv('COLUMNS=20');

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'banner.txt', 'colors' => '#ff0000'],
        ]);

        $this->runPlugin($composer, $io);

        self::assertSame('', $io->getOutput());
    }

    #[Test]
    public function bannerExactlyTerminalWidthRenders(): void
    {
        $this->writeBanner(str_repeat('x', 20));
        $this->pinComposerFile();
        putenv('COLUMNS=20');

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'banner.txt', 'colors' => '#ff0000'],
        ]);

        $this->runPlugin($composer, $io);

        self::assertStringContainsString("\033[38;2;255;0;0m".str_repeat('x', 20), $io->getOutput());
    }

    #[Test]
    public function bannerNarrowerThanTerminalRenders(): void
    {
        $this->writeBanner('hi');
        $this->pinComposerFile();
        putenv('COLUMNS=200');

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'banner.txt', 'colors' => '#ff0000'],
        ]);

        $this->runPlugin($composer, $io);

        self::assertStringContainsString("\033[38;2;255;0;0mhi", $io->getOutput());
    }

    #[Test]
    public function multibyteBannerFittingByCharCountRenders(): void
    {
        $banner = str_repeat('ä', 10);
        // Precondition: width fits by char count (10 ≤ 15) but exceeds by byte count (20 > 15).
        self::assertSame(10, mb_strlen($banner));
        self::assertSame(20, strlen($banner));

        $this->writeBanner($banner);
        $this->pinComposerFile();
        putenv('COLUMNS=15');

        $io = $this->decoratedIo();
        $composer = $this->makeComposer([
            'fanfare' => ['template' => 'banner.txt', 'colors' => '#ff0000'],
        ]);

        $this->runPlugin($composer, $io);

        self::assertStringContainsString("\033[38;2;255;0;0m", $io->getOutput());
    }

    protected function setUp(): void
    {
        $this->fixtureDir = sys_get_temp_dir().'/composer-fanfare-'.bin2hex(random_bytes(4));
        mkdir($this->fixtureDir, 0o700, true);
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
        putenv('COMPOSER_FANFARE');
        putenv('COLUMNS');
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
        file_put_contents($this->fixtureDir.'/banner.txt', $contents);
    }

    private function pinComposerFile(): void
    {
        $composerJson = $this->fixtureDir.'/composer.json';
        file_put_contents($composerJson, '{"name":"wazum/fanfare-test"}');
        putenv('COMPOSER='.$composerJson);
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
        for ($i = 0; $i < $count; ++$i) {
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
