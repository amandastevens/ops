<?php

/**
 * @file classes/core/Services.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Services
 * @ingroup core
 *
 * @see Core
 *
 * @brief Pimple Dependency Injection Container.
 */

namespace APP\core;

class Services extends \PKP\core\PKPServices
{
    /**
     * container initialization
     */
    protected function init()
    {
        $this->container->register(new \APP\Services\OPSServiceProvider());
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\core\Services', '\Services');
}
