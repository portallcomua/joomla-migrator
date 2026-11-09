<?php
namespace Migrator\Component\Migrator\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;

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
        $input = $app->getInput();

        $data = [
            'host'   => $input->getString('old_host', 'localhost'),
            'db'     => $input->getString('old_db', ''),
            'prefix' => $input->getString('old_prefix', 'jos_'),
            'user'   => $input->getString('old_user', ''),
            'pass'   => $input->getString('old_pass', ''),
        ];

        /** @var \Migrator\Component\Migrator\Administrator\Model\AnalyzeModel $model */
        $model = $this->getModel('Analyze', 'Administrator');
        $report = $model->analyze($data);

        $view = $this->getView('Dashboard', 'html');
        $view->report = $report;
        $view->display();
    }
}
