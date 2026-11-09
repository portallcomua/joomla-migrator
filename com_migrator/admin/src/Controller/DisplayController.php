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
        $this->setTemplateOptions($view);
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
        $view->analysisData = $data;
        unset($view->analysisData['pass']);
        $this->setTemplateOptions($view);
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
        $writeRow(['Шаблон', 'Цільовий стиль', $report['target_template'] ?? '']);
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

        $writeRow(['Модулі', 'Позиція джерела', 'Кількість']);

        foreach (($report['module_positions'] ?? []) as $modulePosition) {
            $writeRow(['Модулі', $modulePosition['position'], $modulePosition['count']]);
        }

        $writeRow(['Шаблон', 'Доступна позиція', '']);

        foreach (($report['target_template_positions'] ?? []) as $targetPosition) {
            $writeRow(['Шаблон', $targetPosition, '']);
        }

        $writeRow(['Розширення', 'Назва', 'Елемент', 'Тип', 'Версія джерела', 'Стан на цілі', 'Перенесення даних', 'Рекомендація']);

        foreach (($report['extensions'] ?? []) as $extension) {
            $writeRow([
                'Розширення',
                $extension['name'],
                $extension['element'],
                $extension['type'],
                $extension['version'] ?: 'не визначена',
                $extension['target_status'],
                $extension['migration_status'],
                $extension['recommendation'],
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
            'target_template_style_id' => $input->getInt('target_template_style_id', 0),
        ];
    }

    private function getModulePositionMap($input): array
    {
        $sources = $input->get('module_position_sources', [], 'array');
        $targets = $input->get('target_module_positions', [], 'array');
        $map = [];

        foreach ($sources as $index => $source) {
            if (is_scalar($source) && isset($targets[$index]) && is_scalar($targets[$index])) {
                $map[(string) $source] = (string) $targets[$index];
            }
        }

        return $map;
    }

    private function setTemplateOptions($view): void
    {
        $model = $this->getModel('Analyze', 'Administrator');
        $view->templateOptions = $model->getTargetTemplateStyles();
    }

    public function copyMedia()
    {
        $app = Factory::getApplication();

        if (!Session::checkToken('post')) {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }

        $input = $app->getInput();
        $sourceRoot = $input->getString('old_root', '..');

        /** @var \Migrator\Component\Migrator\Administrator\Model\MigrationModel $model */
        $model = $this->getModel('Migration', 'Administrator');
        $mediaReport = $model->copyMedia($sourceRoot);

        $view = $this->getView('Dashboard', 'html');
        $view->report = null;
        $view->mediaReport = $mediaReport;
        $this->setTemplateOptions($view);
        $view->display();
    }

    public function importCore()
    {
        $app = Factory::getApplication();

        if (!Session::checkToken('post')) {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }

        $input = $app->getInput();
        $data = $this->getAnalysisData($input);
        $data['module_position_map'] = $this->getModulePositionMap($input);
        $data['set_template_default'] = $input->getBool('set_template_default', false);

        /** @var \Migrator\Component\Migrator\Administrator\Model\MigrationModel $model */
        $model = $this->getModel('Migration', 'Administrator');
        $migrationReport = $model->importCore($data);
        $mediaReport = $model->copyMedia($input->getString('old_root', '..'));

        $view = $this->getView('Dashboard', 'html');
        $view->report = null;
        $view->mediaReport = $mediaReport;
        $view->migrationReport = $migrationReport;
        $view->analysisData = $data;
        unset($view->analysisData['pass'], $view->analysisData['module_position_map'], $view->analysisData['set_template_default']);
        $this->setTemplateOptions($view);
        $view->display();
    }
}
