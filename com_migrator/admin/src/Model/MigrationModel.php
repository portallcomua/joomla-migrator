<?php
namespace Migrator\Component\Migrator\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Migrator\Component\Migrator\Administrator\Service\CoreDataMigrationService;
use Migrator\Component\Migrator\Administrator\Service\MediaCopyService;
use Migrator\Component\Migrator\Administrator\Service\SourceVersionResolver;

class MigrationModel extends BaseDatabaseModel
{
    public function importCore(array $data): array
    {
        foreach (['host', 'db', 'user', 'prefix'] as $key) {
            if (!isset($data[$key]) || trim((string) $data[$key]) === '') {
                return $this->migrationError('Заповніть усі обов’язкові поля джерела.');
            }
        }

        if (!preg_match('/\A[A-Za-z0-9_]+\z/', $data['prefix'])
            || !preg_match('/\A[A-Za-z0-9._:-]+\z/', $data['host'])
            || !preg_match('/\A[A-Za-z0-9_$-]+\z/', $data['db'])) {
            return $this->migrationError('Хост, назва БД або префікс мають неприпустимий формат.');
        }

        $target = $this->getDatabase();
        try {
            $source = new \PDO(
                "mysql:host={$data['host']};dbname={$data['db']};charset=utf8mb4",
                $data['user'],
                $data['pass'] ?? '',
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            $sourceDatabase = (string) $source->query('SELECT DATABASE()')->fetchColumn();
            $target->setQuery('SELECT DATABASE()');
            $targetDatabase = (string) $target->loadResult();

            if (strtolower($targetDatabase) === strtolower($sourceDatabase)
                && $target->getPrefix() === $data['prefix']) {
                return $this->migrationError('Джерело збігається з БД цільового сайту; імпорт зупинено.');
            }

            $versionProfile = (new SourceVersionResolver())->resolve(
                $source,
                $data['prefix'],
                (string) ($data['old_root'] ?? '..'),
                (string) ($data['version_override'] ?? 'auto')
            );

            if (!$versionProfile['supported']) {
                return $this->migrationError(
                    'Версію старої Joomla не визначено автоматично. Виберіть сімейство версій у списку й повторіть імпорт.'
                );
            }

            $service = new CoreDataMigrationService();
            $identity = hash('sha256', strtolower($data['host']) . "\0" . $data['db'] . "\0" . $data['prefix']);

            return $service->migrate($source, $data['prefix'], $target, $identity, $versionProfile);
        } catch (\PDOException $e) {
            return $this->migrationError('Не вдалося підключитися до старої БД. Перевірте доступ і драйвер PDO MySQL.');
        } catch (\Throwable $e) {
            return $this->migrationError('Не вдалося запустити імпорт основних даних.');
        }
    }

    public function copyMedia(string $sourceRelativePath): array
    {
        $sourceRelativePath = trim($sourceRelativePath);

        if ($sourceRelativePath === '' || $sourceRelativePath[0] === '/' || $sourceRelativePath[0] === '\\'
            || preg_match('/\A[A-Za-z]:/', $sourceRelativePath)) {
            return [
                'success' => false,
                'copied' => 0,
                'identical' => 0,
                'conflicts' => [],
                'errors' => ['Вкажіть відносний шлях до кореня старого сайту.'],
                'skipped_symlinks' => 0,
            ];
        }

        $sourceRoot = realpath(JPATH_ROOT . DIRECTORY_SEPARATOR . $sourceRelativePath);

        if ($sourceRoot === false) {
            return [
                'success' => false,
                'copied' => 0,
                'identical' => 0,
                'conflicts' => [],
                'errors' => ['Не вдалося знайти старий сайт за вказаним шляхом.'],
                'skipped_symlinks' => 0,
            ];
        }

        $service = new MediaCopyService();

        return $service->copyImages(JPATH_ROOT, $sourceRoot);
    }

    private function migrationError(string $message): array
    {
        return [
            'success' => false,
            'source_version' => '',
            'source_version_family' => '',
            'usergroups' => 0,
            'users' => 0,
            'memberships' => 0,
            'categories' => 0,
            'articles' => 0,
            'featured_articles' => 0,
            'menu_types' => 0,
            'menu_items' => 0,
            'home_items_reset' => 0,
            'custom_modules' => 0,
            'module_assignments' => 0,
            'user_conflicts' => 0,
            'unmapped_authors' => 0,
            'errors' => [$message],
        ];
    }
}