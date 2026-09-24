<?php

namespace Restruct\Silverstripe\SVG\Tasks;

use SilverStripe\Control\Director;
use SilverStripe\Core\Convert;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/*
 * Version-specific entry point for ClearSVGVariantsTask.
 *
 * WHY THIS FILE DECLARES THE SAME TRAIT TWICE
 *
 * BuildTask changed shape between Silverstripe 5 and 6 in ways one class body cannot satisfy:
 *  - SS5: `abstract public function run($request)`, untyped `protected $title` / `$description`.
 *  - SS6: `run(InputInterface, PolyOutput): int` is concrete and `execute()` is the abstract hook;
 *    `protected string $title` and `protected static string $description` are typed, so a child
 *    redeclaring them untyped (or typed, on SS5) is a fatal "must be compatible" error.
 *
 * The obvious alternative - two task classes, each with a file-level `return` guard for the
 * wrong major - does NOT work for a BuildTask: the class manifest still lists the guarded class
 * as a BuildTask descendant, and TaskRunner::taskEnabled() does `new ReflectionClass($class)` on
 * every descendant, which throws for a class that was never declared and breaks the whole task
 * list (dev/tasks, and sake on SS6). The task class itself must therefore exist on both majors.
 *
 * So the task class is declared once, at top level (the manifest only sees top-level classes),
 * and pulls its version-specific METHODS from this trait. Properties cannot come from here:
 * PHP rejects a trait property that redeclares a parent property with a different default
 * ("define the same property ... considered incompatible"), so the title is assigned in the
 * task's constructor and the description is served by getDescription() below. The trait is declared conditionally,
 * which PHP allows; the manifest never sees it (its visitor does not descend into `if` blocks),
 * and it does not need to: Composer's PSR-4 autoloader resolves it by file name.
 *
 * PolyOutput exists only on Silverstripe 6 (framework 6 `src/PolyExecution/`), so its presence is
 * the version switch. The `use` imports above are inert on SS5: an import never autoloads.
 */
if (class_exists(PolyOutput::class)) {
    /**
     * Silverstripe 6: sake command `tasks:ClearSVGVariantsTask`, or dev/tasks/ClearSVGVariantsTask.
     */
    trait ClearSVGVariantsTaskEntryPoint
    {
        /**
         * SS6 declares getDescription() static (PolyCommand::getDescription()). Overridden here
         * rather than via a `$description` property: PHP refuses a trait property whose default
         * differs from the parent class's, and on SS5 the parent property is not even static.
         */
        public static function getDescription(): string
        {
            return _t(
                static::class . '.description',
                'Removes all SVG variant files from the asset store. Run with --confirm to actually delete.'
            );
        }

        public function getOptions(): array
        {
            return [
                new InputOption('confirm', 'c', InputOption::VALUE_NONE, 'Actually delete the variants (without this flag, only shows what would be deleted)'),
                // No task-level --verbose|-v: the console Application already defines it globally,
                // and redeclaring it made sake refuse the task outright ("An option named
                // "verbose" already exists"). The global -v (CLI) / ?verbose=1 (browser, via
                // HttpRequestInput) sets the output verbosity, read with isVerbose() below.
                // new InputOption('verbose', 'v', InputOption::VALUE_NONE, 'Show detailed output for each file'),
            ];
        }

        /**
         * How to ask for a real run, in this major's syntax (used in the dry-run messages).
         */
        protected function confirmHint(): string
        {
            return '--confirm';
        }

        protected function execute(InputInterface $input, PolyOutput $output): int
        {
            $this->clearVariants(
                (bool)$input->getOption('confirm'),
                $output->isVerbose(),
                function (string $line) use ($output): void {
                    // PolyOutput renders the <info>/<comment> tags for both ANSI and HTML
                    $output->writeln($line);
                }
            );

            return Command::SUCCESS;
        }
    }
} else {
    /**
     * Silverstripe 5: dev/tasks/ClearSVGVariantsTask (browser or `sake dev/tasks/...`).
     */
    trait ClearSVGVariantsTaskEntryPoint
    {
        /**
         * SS5 declares getDescription() as an instance method (and deprecates reading the
         * `$description` property through it); see the SS6 variant for why this is a method.
         *
         * @return string
         */
        public function getDescription()
        {
            return 'Removes all SVG variant files from the asset store. Run with confirm=1 to actually delete.';
        }

        /**
         * How to ask for a real run, in this major's syntax (used in the dry-run messages).
         */
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

            $this->clearVariants(
                $request->getVar('confirm') === '1',
                $request->getVar('verbose') === '1',
                function (string $line) use ($cli): void {
                    // Lines carry symfony/console style tags (<info>, <comment>) for SS6's
                    // PolyOutput; SS5 has no formatter for them, so strip them and escape.
                    $text = strip_tags($line);
                    echo $cli ? $text . PHP_EOL : '<p>' . Convert::raw2xml($text) . "</p>\n";
                }
            );
        }
    }
}
