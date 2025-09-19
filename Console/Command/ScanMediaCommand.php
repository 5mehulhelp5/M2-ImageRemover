<?php
namespace Merlin\ImageRemover\Console\Command;

use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Merlin\ImageRemover\Model\Service\ReferenceCollector;
use Merlin\ImageRemover\Model\Service\MediaCleaner;

class ScanMediaCommand extends Command
{
    public const INPUT_DRY_RUN = 'dry-run';
    public const INPUT_CONFIRM = 'yes';
    public const INPUT_NO_DB_SCAN = 'no-db-scan';
    public const INPUT_DB_FAST = 'db-fast';
    public const INPUT_EXCLUDE = 'exclude'; // repeatable

    /** @var ReferenceCollector */
    private $collector;

    /** @var MediaCleaner */
    private $cleaner;

    /** @var State */
    private $appState;

    public function __construct(
        ReferenceCollector $collector,
        MediaCleaner $cleaner,
        State $appState,
        string $name = null
    ) {
        parent::__construct($name);
        $this->collector = $collector;
        $this->cleaner = $cleaner;
        $this->appState = $appState;
    }

    protected function configure()
    {
        $this->setName('merlin:image-remover:scan')
            ->setDescription('Scan pub/media for files not referenced by DB (products, categories, CMS, config, Page Builder, module-specific). Optionally delete them.')
            ->addOption(self::INPUT_DRY_RUN, null, InputOption::VALUE_NONE, 'Only output list of files that would be deleted')
            ->addOption(self::INPUT_CONFIRM, 'y', InputOption::VALUE_NONE, 'Skip confirmation prompt when deleting')
            ->addOption(self::INPUT_NO_DB_SCAN, null, InputOption::VALUE_NONE, 'Skip the whole-database scan (faster)')
            ->addOption(self::INPUT_DB_FAST, null, InputOption::VALUE_NONE, 'Use fast DB scan (no JSON/serialized decoding)')
            ->addOption(self::INPUT_EXCLUDE, 'e', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Exclude prefixes under pub/media (repeatable). Example: -e amasty/webp -e logo -e wysiwyg/homepage');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            try { $this->appState->setAreaCode('adminhtml'); } catch (\Magento\Framework\Exception\LocalizedException $e) {}

            $dryRun = (bool)$input->getOption(self::INPUT_DRY_RUN);
            $isVerbose = $output->isVerbose();
            $autoYes = (bool)$input->getOption(self::INPUT_CONFIRM);
            $excludes = (array)$input->getOption(self::INPUT_EXCLUDE);

            // Always protect these by default
            $exLow = array_map('strtolower', $excludes);
            if (!in_array('amasty/webp', $exLow, true)) { $excludes[] = 'amasty/webp'; }
            if (!in_array('logo', $exLow, true)) { $excludes[] = 'logo'; }

            $output->writeln('<info>Collecting referenced media paths from DB...</info>');
            $referenced = $this->collector->collectAll(
                !$input->getOption(self::INPUT_NO_DB_SCAN),
                !$input->getOption(self::INPUT_DB_FAST)
            );

            $output->writeln(sprintf('<info>Total referenced paths: %d</info>', count($referenced)));

            $output->writeln('<info>Scanning filesystem under pub/media...</info>');
            [$allFiles, $candidates] = $this->cleaner->scan($referenced, $excludes);

            $output->writeln(sprintf('<info>Total files in pub/media (filtered): %d</info>', count($allFiles)));
            $output->writeln(sprintf('<comment>Unused candidates: %d</comment>', count($candidates)));

            if ($isVerbose || $dryRun) {
                foreach ($candidates as $rel) {
                    $output->writeln($rel);
                }
            }

            if ($dryRun) {
                $output->writeln('<info>Dry run complete. No files were deleted.</info>');
                return Cli::RETURN_SUCCESS;
            }

            if (!$autoYes) {
                $output->writeln('Type "yes" to permanently delete these files:');
                $handle = fopen("php://stdin", "r");
                $line = trim(fgets($handle));
                if ($line !== 'yes') {
                    $output->writeln('<comment>Aborted. No files were deleted.</comment>');
                    return Cli::RETURN_SUCCESS;
                }
            }

            [$deleted, $errors] = $this->cleaner->delete($candidates, $excludes);
            $output->writeln(sprintf('<info>Deleted: %d</info>', $deleted));
            if ($errors) {
                $output->writeln(sprintf('<error>Errors: %d</error>', count($errors)));
                foreach ($errors as $msg) {
                    $output->writeln('<error>- ' . $msg . '</error>');
                }
                return Cli::RETURN_FAILURE;
            }

            $output->writeln('<info>Done.</info>');
            return Cli::RETURN_SUCCESS;
        } catch (\Throwable $t) {
            $output->writeln('<error>' . $t->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }
    }
}
