<?php

namespace slice_cap;

use rex;
use rex_addon;
use rex_api_slice_cap;
use rex_article;
use rex_extension;
use rex_extension_point;
use rex_i18n;
use rex_sql;
use rex_url;
use rex_view;

/**
 * Die Backend-Oberfläche: Kopieren/Ausschneiden im Block-Menü, Einfügen im
 * "Block hinzufügen"-Dropdown.
 *
 * Alle Links zeigen auf rex_api_slice_cap und tragen dessen CSRF-Token. Es wird
 * kein Core-Request nachgebaut — das Anlegen des Blocks macht der Core-Service.
 */
final class SliceCapBackend
{
    public static function init(): void
    {
        // Die beiden EPs feuern ohnehin nur auf der Content-Seite; die Callbacks
        // pruefen selbst, ob sie etwas beizutragen haben.
        rex_extension::register('STRUCTURE_CONTENT_SLICE_MENU', self::addSliceButtons(...));
        rex_extension::register('STRUCTURE_CONTENT_MODULE_SELECT', self::addPasteEntry(...));

        if (str_starts_with(\rex_request('page', 'string'), 'content/edit')) {
            rex_view::addCssFile(rex_addon::get('slice_cap')->getAssetsUrl('slice_cap.css'));
        }
    }

    /**
     * Kopieren- und Ausschneiden-Button an jedem Block.
     *
     * @return list<array<string, mixed>>
     */
    public static function addSliceButtons(rex_extension_point $ep): array
    {
        /** @var list<array<string, mixed>> $items */
        $items = (array) $ep->getSubject();

        $user = rex::getUser();
        $sliceId = (int) $ep->getParam('slice_id');

        // 'perm' ist die Modul-Berechtigung, die der Core bereits geprueft hat
        if (!$user || !SliceCap::mayUse($user) || true !== $ep->getParam('perm') || $sliceId <= 0) {
            return $items;
        }

        $clipboardId = SliceCap::getSliceId();
        $clipboardAction = SliceCap::getAction();

        foreach ([SliceCap::ACTION_COPY, SliceCap::ACTION_CUT] as $action) {
            $label = rex_i18n::msg('slice_cap_' . $action);
            $isActive = $sliceId === $clipboardId && $action === $clipboardAction;

            $classes = ['slice-cap-btn', 'slice-cap-btn-' . $action];
            if ($isActive) {
                $classes[] = 'slice-cap-btn-active';
            }

            $items[] = [
                'hidden_label' => $label,
                'url' => rex_url::backendPage('content/edit', [
                    'article_id' => (int) $ep->getParam('article_id'),
                    'clang' => (int) $ep->getParam('clang'),
                    'ctype' => (int) $ep->getParam('ctype'),
                    'slice_id' => $sliceId,
                    'slice_cap_action' => $action,
                ] + rex_api_slice_cap::getUrlParams()),
                'attributes' => [
                    'class' => $classes,
                    'title' => $label,
                    'data-pjax-no-history' => 'true',
                ],
                'icon' => 'slice-cap-' . $action,
            ];
        }

        return $items;
    }

    /**
     * Einfügen-Eintrag oben im "Block hinzufügen"-Dropdown.
     */
    public static function addPasteEntry(rex_extension_point $ep): string
    {
        $subject = (string) $ep->getSubject();

        $user = rex::getUser();

        if (!$user || !SliceCap::mayUse($user)) {
            return $subject;
        }

        $row = SliceCap::getSlice();
        $action = SliceCap::getAction();

        if (null === $row || null === $action) {
            return $subject;
        }

        $articleId = (int) $ep->getParam('article_id');
        $clang = (int) $ep->getParam('clang');
        $ctype = (int) $ep->getParam('ctype');
        $moduleId = (int) $row['module_id'];

        // Kein Eintrag, wenn der Block hier gar nicht landen darf — sonst
        // stolpert der Redakteur erst beim Klick in eine Fehlermeldung.
        if (!$user->getComplexPerm('modules')->hasPerm($moduleId)) {
            return $subject;
        }

        if (!SliceCap::templateAllowsModule($articleId, $clang, $ctype, $moduleId)) {
            return $subject;
        }

        $url = rex_url::backendPage((string) $ep->getParam('page'), [
            'article_id' => $articleId,
            'clang' => $clang,
            'ctype' => $ctype,
            'slice_id' => (int) $ep->getParam('slice_id'),
            'slice_cap_action' => 'paste',
        ] + rex_api_slice_cap::getUrlParams());

        $item = '<li class="slice-cap-paste slice-cap-paste-' . $action . '">'
            . '<a href="' . $url . '" data-pjax-no-history="true">'
            . \rex_escape(self::getPasteLabel($row, $action))
            . '</a></li>';

        // Ist das Dropdown-Fragment ueberschrieben und passt das Muster nicht,
        // bleibt das Markup unangetastet statt kaputt.
        $patched = preg_replace_callback(
            '/<ul\b[^>]*\bdropdown-menu\b[^>]*>/i',
            static fn (array $matches): string => $matches[0] . $item,
            $subject,
            1,
        );

        return $patched ?? $subject;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function getPasteLabel(array $row, string $action): string
    {
        $article = rex_article::get((int) $row['article_id'], (int) $row['clang_id']);

        return rex_i18n::msg(
            'slice_cap_paste_' . $action,
            self::getModuleName((int) $row['module_id']),
            $article ? $article->getName() : (string) $row['article_id'],
        );
    }

    private static function getModuleName(int $moduleId): string
    {
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT name FROM ' . rex::getTable('module') . ' WHERE id = ?', [$moduleId]);

        if (1 !== $sql->getRows()) {
            return (string) $moduleId;
        }

        return rex_i18n::translate((string) $sql->getValue('name'));
    }
}
