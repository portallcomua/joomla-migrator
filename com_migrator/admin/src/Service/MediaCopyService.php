<?php
namespace Migrator\Component\Migrator\Administrator\Service;

class MediaCopyService
{
    public function copyImages(string $targetRoot, string $sourceRoot): array
    {
        $result = [
            'success' => false,
            'copied' => 0,
            'identical' => 0,
            'conflicts' => [],
            'errors' => [],
            'skipped_symlinks' => 0,
        ];

        $targetRoot = realpath($targetRoot);
        $sourceRoot = realpath($sourceRoot);

        if ($targetRoot === false || $sourceRoot === false) {
            $result['errors'][] = 'Не вдалося визначити папку джерела або цільового сайту.';

            return $result;
        }

        if ($targetRoot === $sourceRoot || !is_file($sourceRoot . DIRECTORY_SEPARATOR . 'configuration.php')) {
            $result['errors'][] = 'Вихідна папка не схожа на корінь старого сайту Joomla.';

            return $result;
        }

        $sourceImagesPath = $sourceRoot . DIRECTORY_SEPARATOR . 'images';
        $targetImagesPath = $targetRoot . DIRECTORY_SEPARATOR . 'images';

        if (is_link($sourceImagesPath) || !is_dir($sourceImagesPath)) {
            $result['errors'][] = 'У старому сайті не знайдено звичайну папку images.';

            return $result;
        }

        if (is_link($targetImagesPath)) {
            $result['errors'][] = 'Папка images нового сайту є symlink; копіювання зупинено.';

            return $result;
        }

        $sourceImages = realpath($sourceImagesPath);
        $resolvedTargetImages = realpath($targetImagesPath) ?: $targetImagesPath;
        $sourcePrefix = rtrim($sourceImages, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (strpos($resolvedTargetImages, $sourcePrefix) === 0) {
            $result['errors'][] = 'Папка призначення розташована всередині вихідної images; копіювання зупинено.';

            return $result;
        }

        if (!$this->ensureDirectory($targetRoot, 'images')) {
            $result['errors'][] = 'Не вдалося безпечно створити папку images у новому сайті.';

            return $result;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourceImages, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $entry) {
                if ($entry->isLink()) {
                    $result['skipped_symlinks']++;

                    continue;
                }

                $sourcePath = $entry->getPathname();
                $relativePath = substr($sourcePath, strlen($sourceImages) + 1);
                $destinationPath = $targetImagesPath . DIRECTORY_SEPARATOR . $relativePath;

                if ($entry->isDir()) {
                    if (!$this->ensureDirectory($targetImagesPath, $relativePath)) {
                        $result['errors'][] = 'Не вдалося безпечно створити папку: ' . $relativePath;
                    }

                    continue;
                }

                if (!$entry->isFile()) {
                    continue;
                }

                $relativeDirectory = dirname($relativePath);

                if ($relativeDirectory !== '.' && !$this->ensureDirectory($targetImagesPath, $relativeDirectory)) {
                    $result['errors'][] = 'Не вдалося безпечно створити папку для файлу: ' . $relativePath;

                    continue;
                }

                if (is_link($destinationPath)) {
                    $result['conflicts'][] = $relativePath;

                    continue;
                }

                if (is_file($destinationPath)) {
                    if (filesize($sourcePath) === filesize($destinationPath)
                        && hash_file('sha256', $sourcePath) === hash_file('sha256', $destinationPath)) {
                        $result['identical']++;
                    } else {
                        $result['conflicts'][] = $relativePath;
                    }

                    continue;
                }

                $temporaryPath = tempnam($destinationDirectory, '.migrator-');

                if ($temporaryPath === false) {
                    $result['errors'][] = 'Не вдалося створити тимчасовий файл для: ' . $relativePath;

                    continue;
                }

                if (!copy($sourcePath, $temporaryPath) || !rename($temporaryPath, $destinationPath)) {
                    @unlink($temporaryPath);
                    $result['errors'][] = 'Не вдалося скопіювати файл: ' . $relativePath;

                    continue;
                }

                @chmod($destinationPath, 0644);
                $result['copied']++;
            }
        } catch (\Throwable $e) {
            $result['errors'][] = 'Помилка читання медіафайлів старого сайту.';
        }

        $result['success'] = $result['errors'] === [];

        return $result;
    }

    private function ensureDirectory(string $root, string $relativePath): bool
    {
        $parts = explode(DIRECTORY_SEPARATOR, str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath));
        $directory = rtrim($root, DIRECTORY_SEPARATOR);

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                return false;
            }

            $directory .= DIRECTORY_SEPARATOR . $part;

            if (is_link($directory) || (file_exists($directory) && !is_dir($directory))) {
                return false;
            }

            if (!is_dir($directory) && !@mkdir($directory, 0755)) {
                return false;
            }
        }

        return is_dir($directory) && !is_link($directory);
    }
}