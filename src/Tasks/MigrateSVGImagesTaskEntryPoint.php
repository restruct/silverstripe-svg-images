<?php

namespace Restruct\Silverstripe\SVG\Tasks;

use SilverStripe\Control\Director;
use SilverStripe\Core\Convert;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/*
 * Version-specific entry point for MigrateSVGImagesTask. Declared conditionally, once per
 * Silverstripe major: ClearSVGVariantsTaskEntryPoint documents why a BuildTask that serves both
 * majors has to be built this way.
 */
if (class_exists(PolyOutput::class)) {
    /**
     * Silverstripe 6: sake command `tasks:MigrateSVGImagesTask`, or dev/tasks/MigrateSVGImagesTask.
     */
    trait MigrateSVGImagesTaskEntryPoint
    {
        public static function getDescription(): string
        {
            return _t(
                static::class . '.description',
                'Turns .svg files uploaded before svg-images was installed into SVGImage records. Run with --confirm to actually migrate.'
            );
        }

        public function getOptions(): array
        {
            return [
                // No --verbose: sake defines it globally (see ClearSVGVariantsTaskEntryPoint)
                new InputOption('confirm', 'c', InputOption::VALUE_NONE, 'Actually migrate (without this flag, only shows what would change)'),
            ];
        }

        protected function confirmHint(): string
        {
            return '--confirm';
        }

        protected function execute(InputInterface $input, PolyOutput $output): int
        {
            $this->migrate(
                (bool)$input->getOption('confirm'),
                function (string $line) use ($output): void {
                    $output->writeln($line);
                }
            );

            return Command::SUCCESS;
        }
    }
} else {
    /**
     * Silverstripe 5: dev/tasks/MigrateSVGImagesTask (browser or `sake dev/tasks/...`).
     */
    trait MigrateSVGImagesTaskEntryPoint
    {
        /**
         * @return string
         */
        public function getDescription()
        {
            return 'Turns .svg files uploaded before svg-images was installed into SVGImage records. Run with confirm=1 to actually migrate.';
        }

        protected function confirmHint(): string
        {
            return 'confirm=1';
        }

        /**
         * @param \SilverStripe\Control\HTTPRequest $request
         * @return void
         */
        public function run($request)
        {
            $cli = Director::is_cli();

            $this->migrate(
                $request->getVar('confirm') === '1',
                function (string $line) use ($cli): void {
                    # Same tag stripping as ClearSVGVariantsTaskEntryPoint: SS5 has no formatter
                    $text = strip_tags($line);
                    echo $cli ? $text . PHP_EOL : '<p>' . Convert::raw2xml($text) . "</p>\n";
                }
            );
        }
    }
}
