<?php
namespace Migrator\Component\Migrator\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Language\Text;

class DisplayController extends BaseController
{
    public function display($cachable = false, $urlparams = [])
    {
        $view = $this->getView('Dashboard', 'html');
        $view->report = null;
        $view->display();
    }

    public function analyze()
    {
        $app = Factory::getApplication();

        if (!Session::checkToken('post')) {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }

        $data = $this->getAnalysisData($app->getInput());

        /** @var \Migrator\Component\Migrator\Administrator\Model\AnalyzeModel $model */
        $model = $this->getModel('Analyze', 'Administrator');
        $report = $model->analyze($data);

        $view = $this->getView('Dashboard', 'html');
        $view->report = $report;
        $view->display();
    }

    public function exportCsv()
    {
        $app = Factory::getApplication();

        if (!Session::checkToken('post')) {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }

        $model = $this->getModel('Analyze', 'Administrator');
        $report = $model->analyze($this->getAnalysisData($app->getInput()));
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");

        $writeRow = static function (array $values) use ($stream): void {
            $values = array_map(static function ($value): string {
                $value = (string) $value;

                return preg_match('/^\s*[=+\-@]/u', $value) ? "'" . $value : $value;
            }, $values);

            fputcsv($stream, $values, ';', '"', '');
        };

        $writeRow(['Розділ', 'Показник', 'Значення']);
        $writeRow(['Сайт', 'Цільова Joomla', $report['target_version'] ?? '']);
        $writeRow(['Сайт', 'Версія Joomla', $report['version'] ?? '']);
        $writeRow(['Сайт', 'Джерело версії', $report['version_source'] ?? '']);
        $writeRow(['Сайт', 'Профіль імпорту', $report['version_family'] ?? '']);
        $writeRow(['Сайт', 'Результат аналізу', !empty($report['success']) ? 'Успішно' : 'Помилка']);

        if (!empty($report['error'])) {
            $writeRow(['Сайт', 'Помилка', $report['error']]);
        }

        $summaryLabels = [
            'content' => 'Матеріали',
            'categories' => 'Категорії',
            'users' => 'Користувачі',
            'user_usergroup_map' => 'Зв’язки користувачів із групами',
            'usergroups' => 'Групи користувачів',
            'menu' => 'Пункти меню',
            'modules' => 'Модулі',
            'custom_html_modules' => 'Власні HTML-модулі',
            'extensions' => 'Розширення',
            'assets' => 'Правила доступу',
            'fields' => 'Власні поля',
            'fields_values' => 'Значення власних полів',
            'redirect_links' => 'Перенаправлення',
            'schemas' => 'Схеми оновлення',
            'banners' => 'Банери',
            'contacts' => 'Контакти',
            'weblinks' => 'Посилання',
            'tags' => 'Теги',
            'contentitem_tag_map' => 'Зв’язки тегів із матеріалами',
            'content_frontpage' => 'Матеріали головної сторінки (Joomla 2.5)',
            'k2_items' => 'Матеріали K2',
            'k2_categories' => 'Категорії K2',
            'virtuemart_products' => 'Товари VirtueMart',
            'virtuemart_categories' => 'Категорії VirtueMart',
            'virtuemart_orders' => 'Замовлення VirtueMart',
        ];

        $writeRow(['Дані', 'Таблиця', 'Кількість записів']);

        foreach (($report['summary'] ?? []) as $table => $count) {
            $writeRow([
                'Дані',
                $summaryLabels[$table] ?? $table,
                $count === null ? 'таблицю не знайдено' : $count,
            ]);
        }

        $writeRow(['Розширення', 'Назва', 'Елемент', 'Тип', 'Версія', 'Статус перенесення']);

        foreach (($report['extensions'] ?? []) as $extension) {
            $writeRow([
                'Розширення',
                $extension['name'],
                $extension['element'],
                $extension['type'],
                $extension['version'] ?: 'не визначена',
                $extension['status'],
            ]);
        }

        $app->setHeader('Content-Type', 'text/csv; charset=UTF-8', true);
        $app->setHeader('Content-Disposition', 'attachment; filename="joomla-migrator-report.csv"', true);
        $app->setHeader('Cache-Control', 'no-store', true);
        $app->sendHeaders();
        rewind($stream);
        echo stream_get_contents($stream);
        fclose($stream);
        $app->close();
    }

    private function getAnalysisData($input): array
    {
        return [
            'host'   => $input->getString('old_host', 'localhost'),
            'db'     => $input->getString('old_db', ''),
            'prefix' => $input->getString('old_prefix', 'jos_'),
            'user'   => $input->getString('old_user', ''),
            'pass'   => $input->get('old_pass', '', 'raw'),
            'old_root' => $input->getString('old_root', '..'),
            'version_override' => $input->getString('old_version', 'auto'),
        ];
    }

    public function copyMedia()
    {
        $app = Factory::getApplication();

        if (!Session::checkToken('post')) {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }

        $sourceRoot = $app->getInput()->getString('old_root', '..');

        /** @var \Migrator\Component\Migrator\Administrator\Model\MigrationModel $model */
        $model = $this->getModel('Migration', 'Administrator');
        $mediaReport = $model->copyMedia($sourceRoot);

        $view = $this->getView('Dashboard', 'html');
        $view->report = null;
        $view->mediaReport = $mediaReport;
        $view->display();
    }

    public function importCore()
    {
        $app = Factory::getApplication();

        if (!Session::checkToken('post')) {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }

        $input = $app->getInput();
        $data = [
            'host' => $input->getString('old_host', 'localhost'),
            'db' => $input->getString('old_db', ''),
            'prefix' => $input->getString('old_prefix', ''),
            'user' => $input->getString('old_user', ''),
            'pass' => $input->get('old_pass', '', 'raw'),
            'old_root' => $input->getString('old_root', '..'),
            'version_override' => $input->getString('old_version', 'auto'),
        ];

        /** @var \Migrator\Component\Migrator\Administrator\Model\MigrationModel $model */
        $model = $this->getModel('Migration', 'Administrator');
        $migrationReport = $model->importCore($data);
        $mediaReport = $model->copyMedia($input->getString('old_root', '..'));

        $view = $this->getView('Dashboard', 'html');
        $view->report = null;
        $view->mediaReport = $mediaReport;
        $view->migrationReport = $migrationReport;
        $view->display();
    }
}
