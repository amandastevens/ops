<?php

/**
 * @file classes/submission/SubmissionFileDAO.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SubmissionFileDAO
 * @ingroup submission
 *
 * @see SubmissionFile
 *
 * @brief Operations for retrieving and modifying submission files
 */

namespace APP\submission;

use PKP\submission\PKPSubmissionFileDAO;
use PKP\db\DAORegistry;

class SubmissionFileDAO extends PKPSubmissionFileDAO
{
    /**
     * @copydoc SchemaDAO::insertObject
     */
    public function insertObject($submissionFile)
    {
        parent::insertObject($submissionFile);

        if ($submissionFile->getData('assocType') === ASSOC_TYPE_REPRESENTATION) {
            $galleyDao = DAORegistry::getDAO('PreprintGalleyDAO'); /* @var $galleyDao PreprintGalleyDAO */
            $galley = $galleyDao->getById($submissionFile->getData('assocId'));
            if (!$galley) {
                throw new Exception('Galley not found when adding submission file.');
            }
            $galley->setFileId($submissionFile->getId());
            $galleyDao->updateObject($galley);
        }

        return $submissionFile->getId();
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\submission\SubmissionFileDAO', '\SubmissionFileDAO');
}

