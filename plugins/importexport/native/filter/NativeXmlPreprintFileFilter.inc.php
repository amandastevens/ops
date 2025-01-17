<?php

/**
 * @file plugins/importexport/native/filter/NativeXmlPreprintFileFilter.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class NativeXmlPreprintFileFilter
 * @ingroup plugins_importexport_native
 *
 * @brief Class that converts a Native XML document to an Preprint file.
 */

import('lib.pkp.plugins.importexport.native.filter.NativeXmlSubmissionFileFilter');

class NativeXmlPreprintFileFilter extends NativeXmlSubmissionFileFilter {
	//
	// Implement template methods from PersistableFilter
	//
	/**
	 * @copydoc PersistableFilter::getClassName()
	 */
	function getClassName() {
		return 'plugins.importexport.native.filter.NativeXmlPreprintFileFilter';
	}
}


