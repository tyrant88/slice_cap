<?php

use slice_cap\SliceCap;

/**
 * Endpunkt für Kopieren, Ausschneiden und Einfügen.
 *
 * `$published = false` erzwingt Backend-Kontext UND eine gültige Session,
 * `requiresCsrfProtection()` erzwingt das Token — beides übernimmt der Core in
 * rex_api_function::handleCall().
 */
class rex_api_slice_cap extends rex_api_function
{
    protected $published = false;

    public function execute()
    {
        $user = rex::requireUser();

        if (!SliceCap::mayUse($user)) {
            throw new rex_api_exception(rex_i18n::msg('no_rights_to_this_function'));
        }

        $action = rex_request('slice_cap_action', 'string');

        return match ($action) {
            SliceCap::ACTION_COPY, SliceCap::ACTION_CUT => $this->remember($action),
            'paste' => $this->paste(),
            default => throw new rex_api_exception('Unknown slice_cap action "' . $action . '"'),
        };
    }

    protected function requiresCsrfProtection()
    {
        return true;
    }

    /**
     * Legt einen Block in die Zwischenablage. Derselbe Button ein zweites Mal
     * geklickt leert sie wieder.
     */
    private function remember(string $action): rex_api_result
    {
        $user = rex::requireUser();

        $articleId = rex_request('article_id', 'int');
        $clang = rex_request('clang', 'int');
        $sliceId = rex_request('slice_id', 'int');

        if (!SliceCap::mayEditArticle($user, $articleId, $clang)) {
            throw new rex_api_exception(rex_i18n::msg('no_rights_to_this_function'));
        }

        $row = SliceCap::findSlice($sliceId);

        // Der Block muss zu dem Artikel gehoeren, dessen Rechte oben geprueft
        // wurden — sonst waeren ueber die ID fremde Bloecke adressierbar.
        if (null === $row || $articleId !== (int) $row['article_id'] || $clang !== (int) $row['clang_id']) {
            throw new rex_api_exception(rex_i18n::msg('no_rights_to_this_function'));
        }

        if (!$user->getComplexPerm('modules')->hasPerm((int) $row['module_id'])) {
            throw new rex_api_exception(rex_i18n::msg('no_rights_to_this_function'));
        }

        if ($sliceId === SliceCap::getSliceId() && $action === SliceCap::getAction()) {
            SliceCap::clear();

            return new rex_api_result(true, rex_i18n::msg('slice_cap_cleared'));
        }

        SliceCap::put($sliceId, $action);

        return new rex_api_result(true, rex_i18n::msg('slice_cap_' . $action . '_done', $sliceId));
    }

    private function paste(): rex_api_result
    {
        $user = rex::requireUser();

        $articleId = rex_request('article_id', 'int');
        $clang = rex_request('clang', 'int');
        $ctype = rex_request('ctype', 'int', 1);
        // Positionsanker: der Block, an dessen Stelle eingefuegt wird. -1 = ans Ende.
        $anchorId = rex_request('slice_id', 'int', -1);

        if (!SliceCap::mayEditArticle($user, $articleId, $clang)) {
            throw new rex_api_exception(rex_i18n::msg('no_rights_to_this_function'));
        }

        $action = SliceCap::getAction();
        $row = SliceCap::getSlice();

        if (null === $action) {
            throw new rex_api_exception(rex_i18n::msg('slice_cap_clipboard_empty'));
        }

        if (null === $row) {
            SliceCap::clear();

            throw new rex_api_exception(rex_i18n::msg('slice_cap_slice_gone'));
        }

        $moduleId = (int) $row['module_id'];

        // Rechte an der Quelle koennen sich seit dem Kopieren geaendert haben
        if (
            !SliceCap::mayEditArticle($user, (int) $row['article_id'], (int) $row['clang_id'])
            || !$user->getComplexPerm('modules')->hasPerm($moduleId)
        ) {
            throw new rex_api_exception(rex_i18n::msg('no_rights_to_this_function'));
        }

        if (!SliceCap::templateAllowsModule($articleId, $clang, $ctype, $moduleId)) {
            throw new rex_api_exception(rex_i18n::msg('slice_cap_module_not_allowed'));
        }

        $revision = SliceCap::getRevision($articleId);

        $data = SliceCap::buildSliceData($row);
        $data['revision'] = $revision;

        $priority = $this->resolvePriority($articleId, $clang, $ctype, $revision, $anchorId);
        if (null !== $priority) {
            $data['priority'] = $priority;
        }

        // Rueckgabewert bewusst verworfen: addSlice() liefert rex_i18n::msg('slice_added'),
        // und diesen Key definiert structure in keiner Sprachdatei.
        rex_content_service::addSlice($articleId, $clang, $ctype, $moduleId, $data);

        $message = rex_i18n::msg('slice_cap_pasted');

        if (SliceCap::ACTION_CUT === $action) {
            rex_content_service::deleteSlice((int) $row['id']);
            SliceCap::clear();
            $message .= ' ' . rex_i18n::msg('slice_cap_source_removed');
        }

        $result = new rex_api_result(true, $message);
        // Nach dem Einfuegen auf die Seite ohne API-Parameter umleiten, damit ein
        // Reload den Block nicht ein zweites Mal anlegt.
        $result->setRequiresReboot(true);

        return $result;
    }

    /**
     * Die Priorität des Ankerblocks — der neue Block nimmt dessen Platz ein und
     * schiebt ihn nach unten. Null bedeutet: ans Ende hängen.
     */
    private function resolvePriority(int $articleId, int $clang, int $ctype, int $revision, int $anchorId): ?int
    {
        if ($anchorId <= 0) {
            return null;
        }

        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT priority FROM ' . rex::getTable('article_slice') . '
                WHERE id = ? AND article_id = ? AND clang_id = ? AND ctype_id = ? AND revision = ?',
            [$anchorId, $articleId, $clang, $ctype, $revision],
        );

        if (1 !== $sql->getRows()) {
            return null;
        }

        return (int) $sql->getValue('priority');
    }
}
