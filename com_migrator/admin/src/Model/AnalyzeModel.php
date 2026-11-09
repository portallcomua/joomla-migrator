<?php
namespace Migrator\Component\Migrator\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Migrator\Component\Migrator\Administrator\Service\ExtensionAssessmentService;
use Migrator\Component\Migrator\Administrator\Service\TargetTemplateService;

class AnalyzeModel extends BaseDatabaseModel
{
    public function analyze(array $data): array
    {
        $report = [
            'success' => false,
            'error'   => '',
            'version' => '',
            'version_source' => '',
            'version_family' => '',
            'version_override' => false,
            'target_version' => '',
            'target_extensions_checked' => false,
            'summary' => [],
            'extensions' => [],
            'module_positions' => [],
            'target_template_style_id' => 0,
            'target_template' => '',
            'target_template_positions' => [],
        ];

        try {
            $report['target_version'] = (new \Joomla\CMS\Version())->getShortVersion();
        } catch (\Throwable $e) {
            $report['target_version'] = 'Не вдалося визначити';
        }

        $templateStyles = $this->getTargetTemplateStyles();
        $requestedStyleId = (int) ($data['target_template_style_id'] ?? 0);
        $selectedStyle = null;

        foreach ($templateStyles as $style) {
            if ($style['id'] === $requestedStyleId && $requestedStyleId > 0) {
                $selectedStyle = $style;
                break;
            }
        }

        if ($selectedStyle !== null) {
            $report['target_template_style_id'] = $selectedStyle['id'];
            $report['target_template'] = $selectedStyle['template'];
            $report['target_template_positions'] = $selectedStyle['positions'];
        }

        foreach (['host', 'db', 'user', 'prefix'] as $key) {
            if (!isset($data[$key]) || trim((string) $data[$key]) === '') {
                $report['error'] = 'Заповніть усі обов’язкові поля підключення.';

                return $report;
            }
        }

        if (!preg_match('/\A[A-Za-z0-9_]+\z/', $data['prefix'])) {
            $report['error'] = 'Префікс таблиць містить неприпустимі символи.';

            return $report;
        }

        if (!preg_match('/\A[A-Za-z0-9._:-]+\z/', $data['host'])
            || !preg_match('/\A[A-Za-z0-9_$-]+\z/', $data['db'])) {
            $report['error'] = 'Хост або назва бази даних мають неприпустимий формат.';

            return $report;
        }

        $connected = false;
        $targetExtensions = $this->getTargetExtensions();
        $report['target_extensions_checked'] = $targetExtensions !== null;

        try {
            $dsn = "mysql:host={$data['host']};dbname={$data['db']};charset=utf8mb4";
            $pdo = new \PDO($dsn, $data['user'], $data['pass'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $connected = true;

            $p = $data['prefix'];

            $tables = [
                'content', 'categories', 'languages', 'users', 'user_usergroup_map', 'usergroups',
                'menu', 'modules', 'extensions', 'assets', 'fields', 'fields_values',
                'redirect_links', 'schemas', 'banners', 'contacts', 'weblinks', 'tags',
                'contentitem_tag_map', 'content_frontpage', 'k2_items', 'k2_categories',
                'virtuemart_products', 'virtuemart_categories', 'virtuemart_orders',
            ];

            foreach ($tables as $t) {
                $exists = $pdo->prepare(
                    'SELECT COUNT(*) FROM information_schema.tables '
                    . 'WHERE table_schema = DATABASE() AND table_name = ?'
                );
                $exists->execute([$p . $t]);

                if (!(int) $exists->fetchColumn()) {
                    $report['summary'][$t] = null;

                    continue;
                }

                $stmt = $pdo->query("SELECT COUNT(*) AS c FROM `{$p}{$t}`");
                $report['summary'][$t] = (int) $stmt->fetch()['c'];
            }

            if ($report['summary']['modules'] !== null) {
                $stmt = $pdo->query(
                    "SELECT COUNT(*) FROM `{$p}modules` WHERE client_id = 0 AND module = 'mod_custom'"
                );
                $report['summary']['custom_html_modules'] = (int) $stmt->fetchColumn();

                $stmt = $pdo->query(
                    "SELECT position, COUNT(*) AS amount FROM `{$p}modules` "
                    . "WHERE client_id = 0 AND module = 'mod_custom' GROUP BY position ORDER BY position"
                );

                foreach ($stmt->fetchAll() as $row) {
                    $report['module_positions'][] = [
                        'position' => (string) ($row['position'] ?? ''),
                        'count' => (int) $row['amount'],
                    ];
                }
            } else {
                $report['summary']['custom_html_modules'] = null;
            }

            $versionProfile = (new \Migrator\Component\Migrator\Administrator\Service\SourceVersionResolver())
                ->resolve($pdo, $p, (string) ($data['old_root'] ?? '..'), (string) ($data['version_override'] ?? 'auto'));
            $report['version'] = $versionProfile['detected_version'] !== ''
                ? $versionProfile['detected_version']
                : 'Не визначено автоматично';
            $report['version_family'] = $versionProfile['effective_family'];
            $report['version_override'] = $versionProfile['is_manual_override'];
            $report['version_source'] = $versionProfile['is_manual_override']
                ? 'ручний резервний вибір'
                : $versionProfile['version_source'];

            if ($report['summary']['extensions'] !== null) {
                $stmt = $pdo->query(
                    "SELECT name, element, type, folder, client_id, enabled, manifest_cache FROM `{$p}extensions` "
                    . "WHERE type IN ('component','module','plugin','template','language') ORDER BY type, name, client_id"
                );

                $sourceExtensions = [];

                foreach ($stmt->fetchAll() as $ext) {
                    $metadata = json_decode((string) ($ext['manifest_cache'] ?? ''), true);
                    $sourceExtensions[] = [
                        'name'    => (string) $ext['name'],
                        'element' => (string) $ext['element'],
                        'type'    => (string) $ext['type'],
                        'folder'  => (string) $ext['folder'],
                        'client_id' => (int) $ext['client_id'],
                        'enabled' => (int) $ext['enabled'],
                        'version' => is_array($metadata) ? (string) ($metadata['version'] ?? '') : '',
                    ];
                }

                $report['extensions'] = (new ExtensionAssessmentService())->assess(
                    $sourceExtensions,
                    $targetExtensions ?? []
                );

                if ($targetExtensions === null) {
                    foreach ($report['extensions'] as &$extension) {
                        $extension['target_status'] = 'Не вдалося перевірити';
                        $extension['can_install'] = false;
                    }
                    unset($extension);
                }
            }

            $report['success'] = true;
        } catch (\PDOException $e) {
            $report['error'] = $connected
                ? 'З’єднання встановлено, але не вдалося прочитати схему бази даних. Перевірте права користувача та префікс таблиць.'
                : 'Не вдалося підключитися до бази даних. Перевірте хост, назву БД, користувача та пароль.';
        }

        return $report;
    }

    public function getTargetTemplateStyles(): array
    {
        try {
            return (new TargetTemplateService())->getStyles($this->getDatabase());
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function getTargetExtensions(): ?array
    {
        try {
            $database = $this->getDatabase();
            $database->setQuery('SELECT element, type, folder, client_id, enabled FROM #__extensions');

            return $database->loadAssocList() ?: [];
        } catch (\Throwable $e) {
            return null;
        }
    }
}
