<?php
namespace Migrator\Component\Migrator\Administrator\Service;

class SourceVersionResolver
{
    private const FAMILIES = ['2.5', '3', '4', '5', '6'];

    public function resolve(\PDO $source, string $prefix, string $relativeRoot, string $override = 'auto'): array
    {
        $detectedVersion = $this->detectFilesystemVersion($relativeRoot);
        $versionSource = $detectedVersion === '' ? '' : 'administrator/manifests/files/joomla.xml';

        if ($detectedVersion === '') {
            $detectedVersion = $this->detectDatabaseVersion($source, $prefix);
            $versionSource = $detectedVersion === '' ? '' : 'manifest_cache БД';
        }

        $detectedFamily = $this->familyFromVersion($detectedVersion);
        $selectedFamily = $override === 'auto' ? $detectedFamily : $override;

        if (!in_array($selectedFamily, self::FAMILIES, true)) {
            $selectedFamily = '';
        }

        return [
            'detected_version' => $detectedVersion,
            'detected_family' => $detectedFamily,
            'effective_family' => $selectedFamily,
            'version_source' => $versionSource,
            'is_manual_override' => $override !== 'auto' && $selectedFamily !== '',
            'supported' => $selectedFamily !== '',
        ];
    }

    private function detectDatabaseVersion(\PDO $source, string $prefix): string
    {
        try {
            $statement = $source->query(
                "SELECT manifest_cache FROM `{$prefix}extensions` "
                . "WHERE type = 'component' AND element = 'com_admin' LIMIT 1"
            );
            $manifest = $statement->fetchColumn();
            $metadata = is_string($manifest) ? json_decode($manifest, true) : null;

            return is_array($metadata) ? (string) ($metadata['version'] ?? '') : '';
        } catch (\PDOException $e) {
            return '';
        }
    }

    private function detectFilesystemVersion(string $relativeRoot): string
    {
        if (!defined('JPATH_ROOT')) {
            return '';
        }

        $relativeRoot = trim($relativeRoot);

        if ($relativeRoot === '' || $relativeRoot[0] === '/' || $relativeRoot[0] === '\\'
            || preg_match('/\A[A-Za-z]:/', $relativeRoot)) {
            return '';
        }

        $siteRoot = realpath(JPATH_ROOT . DIRECTORY_SEPARATOR . $relativeRoot);

        if ($siteRoot === false || $siteRoot === realpath(JPATH_ROOT)
            || !is_file($siteRoot . DIRECTORY_SEPARATOR . 'configuration.php')) {
            return '';
        }

        $manifestPath = $siteRoot . DIRECTORY_SEPARATOR . 'administrator'
            . DIRECTORY_SEPARATOR . 'manifests' . DIRECTORY_SEPARATOR . 'files'
            . DIRECTORY_SEPARATOR . 'joomla.xml';

        if (!is_file($manifestPath)) {
            return '';
        }

        $manifest = @simplexml_load_file($manifestPath, '\\SimpleXMLElement', LIBXML_NONET);

        return $manifest && isset($manifest->version) ? trim((string) $manifest->version) : '';
    }

    private function familyFromVersion(string $version): string
    {
        if (!preg_match('/\A(2\.5|[3-6])(?:\.|\z)/', trim($version), $matches)) {
            return '';
        }

        return $matches[1];
    }
}