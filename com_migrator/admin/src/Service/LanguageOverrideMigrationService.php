<?php
namespace Migrator\Component\Migrator\Administrator\Service;

class LanguageOverrideMigrationService
{
    public function copyOverrides(string $targetRoot, string $sourceRoot): array
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

        if ($targetRoot === false || $sourceRoot === false || $targetRoot === $sourceRoot
            || !is_file($sourceRoot . DIRECTORY_SEPARATOR . 'configuration.php')) {
            $result['errors'][] = 'Не вдалося визначити корінь старого або нового сайту Joomla.';

            return $result;
        }

        foreach (['language/overrides', 'administrator/language/overrides'] as $relativeDirectory) {
            $sourceDirectory = $sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
            $targetDirectory = $targetRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);

            if (is_link($sourceDirectory)) {
                $result['skipped_symlinks']++;
                continue;
            }

            if (!is_dir($sourceDirectory)) {
                continue;
            }

            if (!$this->ensureDirectory($targetRoot, $relativeDirectory)) {
                $result['errors'][] = 'Не вдалося безпечно створити папку ' . $relativeDirectory . '.';
                continue;
            }

            foreach (scandir($sourceDirectory) ?: [] as $filename) {
                if (!preg_match('/\A[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*\.override\.ini\z/', $filename)) {
                    continue;
                }

                $sourcePath = $sourceDirectory . DIRECTORY_SEPARATOR . $filename;
                $targetPath = $targetDirectory . DIRECTORY_SEPARATOR . $filename;
                $relativePath = $relativeDirectory . '/' . $filename;

                if (is_link($sourcePath)) {
                    $result['skipped_symlinks']++;
                    continue;
                }

                if (!is_file($sourcePath)) {
                    continue;
                }

                if (is_link($targetPath)) {
                    $result['conflicts'][] = $relativePath;
                    continue;
                }

                if (file_exists($targetPath)) {
                    if (is_file($targetPath) && filesize($sourcePath) === filesize($targetPath)
                        && hash_file('sha256', $sourcePath) === hash_file('sha256', $targetPath)) {
                        $result['identical']++;
                    } else {
                        $result['conflicts'][] = $relativePath;
                    }
                    continue;
                }

                $temporaryPath = tempnam($targetDirectory, '.migrator-');

                if ($temporaryPath === false) {
                    $result['errors'][] = 'Не вдалося створити тимчасовий файл для ' . $relativePath . '.';
                    continue;
                }

                if (!copy($sourcePath, $temporaryPath) || !rename($temporaryPath, $targetPath)) {
                    @unlink($temporaryPath);
                    $result['errors'][] = 'Не вдалося скопіювати ' . $relativePath . '.';
                    continue;
                }

                @chmod($targetPath, 0644);
                $result['copied']++;
            }
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
