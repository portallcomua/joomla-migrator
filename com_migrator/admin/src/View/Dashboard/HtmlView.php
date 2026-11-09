<?php
namespace Migrator\Component\Migrator\Administrator\View\Dashboard;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

class HtmlView extends BaseHtmlView
{
    public $report = null;
    public $mediaReport = null;
    public $migrationReport = null;

    public function display($tpl = null)
    {
        parent::display($tpl);
    }
}
