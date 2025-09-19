<?php
namespace Merlin\ImageRemover\Model\Service;

use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File as FileDriver;

class MediaCleaner
{
    /** @var Filesystem */
    private $filesystem;
    /** @var FileDriver */
    private $fileDriver;

    public function __construct(
        Filesystem $filesystem,
        FileDriver $fileDriver
    ) {
        $this->filesystem = $filesystem;
        $this->fileDriver = $fileDriver;
    }

    public function scan(array $referenced, array $excludes = []): array
    {
        $mediaDir = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        $root = $mediaDir->getAbsolutePath();

        $keep = [];
        foreach ($referenced as $rel) {
            $keep[strtolower($this->normalize($rel))] = true;
        }

        $all = [];
        $candidates = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $info) {
            /** @var \SplFileInfo $info */
            if ($info->isDir()) {
                continue;
            }
            $abs = $info->getPathname();
            $rel = ltrim(str_replace(['\\', $root], ['/', ''], $abs), '/');

            if ($this->isSkippable($rel) || $this->isExcluded($rel, $excludes)) {
                continue;
            }

            $all[] = $rel;

            $key = strtolower($this->normalize($rel));
            if (!isset($keep[$key])) {
                $candidates[] = $rel;
            }
        }

        sort($all);
        sort($candidates);

        return [$all, $candidates];
    }

    public function delete(array $candidates, array $excludes = []): array
    {
        $mediaDir = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $root = $mediaDir->getAbsolutePath();

        $deleted = 0;
        $errors = [];

        foreach ($candidates as $rel) {
            if ($this->isExcluded($rel, $excludes)) { continue; }
            $abs = $root . ltrim($rel, '/');
            try {
                if ($this->fileDriver->isExists($abs) && $this->fileDriver->isFile($abs)) {
                    $this->fileDriver->deleteFile($abs);
                    $deleted++;
                }
            } catch (\Throwable $t) {
                $errors[] = $rel . ' -> ' . $t->getMessage();
            }
        }

        return [$deleted, $errors];
    }

    private function normalize(string $path): string
    {
        $path = trim(str_replace('\\','/',$path));
        $path = preg_replace('#^/?pub/media/#i', '', $path);
        $path = preg_replace('#^/?media/#i', '', $path);
        return ltrim($path, '/');
    }

    private function isSkippable(string $rel): bool
    {
        $rel = ltrim(strtolower($rel), '/');
        $skipPrefixes = [
            'catalog/product/cache/',
            'tmp/',
            'captcha/',
            'import/',
            'downloadable/tmp/',
            'amasty/',
            'amasty/webp/',
            'amasty/webp/wysiwyg/',
            'logo/',
        ];
        foreach ($skipPrefixes as $p) {
            if (stripos($rel, $p) === 0) return true;
        }
        $basename = basename($rel);
        if (in_array($basename, ['.htaccess', 'placeholder', 'index.php', 'index.html'])) {
            return true;
        }
        return false;
    }

    private function isExcluded(string $rel, array $excludes): bool
    {
        $rel = ltrim(strtolower($rel), '/');
        foreach ($excludes as $ex) {
            $ex = rtrim(ltrim(strtolower($ex), '/'), '/') . '/';
            if (stripos($rel, $ex) === 0) return true;
        }
        return false;
    }
}
