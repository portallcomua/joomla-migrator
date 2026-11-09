<?php
namespace Migrator\Component\Migrator\Administrator\Service;

class TargetTemplateService
{
    public function getStyles($database): array
    {
        $database->setQuery(
            'SELECT id, template, title, home FROM #__template_styles '
            . 'WHERE client_id = 0 ORDER BY home DESC, title, id'
        );

        $styles = $database->loadAssocList() ?: [];

        foreach ($styles as &$style) {
            $style['id'] = (int) $style['id'];
            $style['home'] = (int) $style['home'];
            $style['template'] = (string) $style['template'];
            $style['title'] = (string) $style['title'];
            $style['positions'] = $this->getPositions($style['template']);
        }
        unset($style);

        return $styles;
    }

    public function setDefaultStyle($database, int $styleId): void
    {
        if ($styleId < 1) {
            throw new \InvalidArgumentException('Не вибрано стиль шаблону.');
        }

        $database->setQuery(
            'UPDATE #__template_styles SET home = CASE WHEN id = ' . $styleId
            . ' THEN 1 ELSE 0 END WHERE client_id = 0'
        )->execute();
    }

    private function getPositions(string $template): array
    {
        if (!defined('JPATH_ROOT') || !preg_match('/\A[A-Za-z0-9_-]+\z/', $template)) {
            return [];
        }

        $templatesRoot = realpath(JPATH_ROOT . DIRECTORY_SEPARATOR . 'templates');

        if ($templatesRoot === false) {
            return [];
        }

        $templateRoot = realpath($templatesRoot . DIRECTORY_SEPARATOR . $template);

        if ($templateRoot === false || !str_starts_with($templateRoot, $templatesRoot . DIRECTORY_SEPARATOR)) {
            return [];
        }

        $manifestPath = $templateRoot . DIRECTORY_SEPARATOR . 'templateDetails.xml';

        if (!is_file($manifestPath)) {
            return [];
        }

        $manifest = @simplexml_load_file($manifestPath, \SimpleXMLElement::class, LIBXML_NONET);

        if (!$manifest || !isset($manifest->positions->position)) {
            return [];
        }

        $positions = [];

        foreach ($manifest->positions->position as $position) {
            $name = trim((string) $position);

            if ($name !== '' && !in_array($name, $positions, true)) {
                $positions[] = $name;
            }
        }

        return $positions;
    }
}