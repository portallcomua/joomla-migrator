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

        $input = $app->getInput();

        $data = [
            'host'   => $input->getString('old_host', 'localhost'),
            'db'     => $input->getString('old_db', ''),
            'prefix' => $input->getString('old_prefix', 'jos_'),
            'user'   => $input->getString('old_user', ''),
            'pass'   => $input->get('old_pass', '', 'raw'),
            'old_root' => $input->getString('old_root', '..'),
            'version_override' => $input->getString('old_version', 'auto'),
        ];

        /** @var \Migrator\Component\Migrator\Administrator\Model\AnalyzeModel $model */
        $model = $this->getModel('Analyze', 'Administrator');
        $report = $model->analyze($data);

        $view = $this->getView('Dashboard', 'html');
        $view->report = $report;
        $view->display();
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
