<?php

use slice_cap\SliceCapBackend;

rex_perm::register('slice_cap[]', rex_i18n::msg('slice_cap_perm'), rex_perm::GENERAL);

if (rex::isBackend() && 'console' !== rex::getEnvironment()) {
    rex_extension::register('PACKAGES_INCLUDED', SliceCapBackend::init(...));
}
