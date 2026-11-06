<?php
namespace Migrator\Component\Migrator\Administrator\Model;

use Joomla\CMS\MVC\Model\BaseDatabaseModel;

class AnalyzeModel extends BaseDatabaseModel
{
    public function analyze(array $data): array
    {
        $report = [
            'success' => false,
            'error'   => '',
            'summary' => [],
            'extensions' => [],
        ];

        try {
            $dsn = "mysql:host={$data['host']};dbname={$data['db']};charset=utf8mb4";
            $pdo = new \PDO($dsn, $data['user'], $data['pass'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);

            $p = $data['prefix'];

            $tables = ['content', 'categories', 'users', 'menu', 'modules'];
            foreach ($tables as $t) {
                try {
                    $stmt = $pdo->query("SELECT COUNT(*) AS c FROM {$p}{$t}");
                    $report['summary'][$t] = (int) $stmt->fetch()['c'];
                } catch (\Exception $e) {
                    $report['summary'][$t] = 'таблиця не існує';
                }
            }

            $stmt = $pdo->query("SELECT name, element, type, folder, enabled FROM {$p}extensions WHERE type IN ('component','module','plugin','template')");
            foreach ($stmt->fetchAll() as $ext) {
                $report['extensions'][] = [
                    'name'    => $ext['name'],
                    'element' => $ext['element'],
                    'type'    => $ext['type'],
                    'folder'  => $ext['folder'],
                    'enabled' => (int) $ext['enabled'],
                    'status'  => $this->classifyExtension($ext),
                ];
            }

            $report['success'] = true;
        } catch (\Exception $e) {
            $report['error'] = $e->getMessage();
        }

        return $report;
    }

    private function classifyExtension(array $ext): string
    {
        $el = $ext['element'];
        if (strpos($el, 'com_content') === 0) return '✅ основний контент, підтримується';
        if (strpos($el, 'com_users') === 0) return '✅ основний контент, підтримується';
        if (strpos($el, 'com_menus') === 0) return '✅ основний контент, підтримується';
        if (strpos($el, 'com_modules') === 0) return '✅ основний контент, підтримується';
        if (strpos($el, 'com_k2') === 0) return '❌ K2 не підтримується, рекомендовано Migrate K2 Pro';
        if (strpos($el, 'com_virtuemart') === 0) return '⚠️ VirtueMart потребує адаптера';
        if (strpos($el, 'com_kunena') === 0) return '⚠️ Kunena потребує адаптера';
        return '⚠️ потребує ручної обробки';
    }
}