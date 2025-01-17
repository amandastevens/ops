<?php
/**
 * @file classes/services/PublicationService.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PublicationService
 * @ingroup services
 *
 * @brief Extends the base publication service class with app-specific
 *  requirements.
 */

namespace APP\services;

use PKP\db\DAORegistry;
use PKP\plugins\HookRegistry;
use PKP\services\PKPPublicationService;
use PKP\plugins\PluginRegistry;
use PKP\submission\PKPSubmission;
use PKP\services\interfaces\EntityWriteInterface;

use APP\core\Services;
use APP\i18n\AppLocale;
use APP\publication\Publication;
use APP\core\Application;

class PublicationService extends PKPPublicationService
{
    /**
     * Initialize hooks for extending PKPPublicationService
     */
    public function __construct()
    {
        \HookRegistry::register('Publication::getProperties', [$this, 'getPublicationProperties']);
        \HookRegistry::register('Publication::validate', [$this, 'validatePublication']);
        \HookRegistry::register('Publication::validatePublish', [$this, 'validatePublishPublication']);
        \HookRegistry::register('Publication::add', [$this, 'addPublication']);
        \HookRegistry::register('Publication::version', [$this, 'versionPublication']);
        \HookRegistry::register('Publication::publish::before', [$this, 'publishPublicationBefore']);
        \HookRegistry::register('Publication::publish', [$this, 'publishPublication']);
        \HookRegistry::register('Publication::delete::before', [$this, 'deletePublicationBefore']);
    }

    /**
     * Add values when retrieving an object's properties
     *
     * @param $hookName string
     * @param $args array [
     *		@option array Property values
     *		@option Publication
     *		@option array The props requested
     *		@option array Additional arguments (such as the request object) passed
     * ]
     */
    public function getPublicationProperties($hookName, $args)
    {
        $values = & $args[0];
        $publication = $args[1];
        $props = $args[2];
        $dependencies = $args[3];
        $request = $dependencies['request'];
        $dispatcher = $request->getDispatcher();

        // Get required submission and context
        $submission = !empty($args['submission'])
            ? $args['submission']
            : $args['submission'] = Services::get('submission')->get($publication->getData('submissionId'));

        $submissionContext = !empty($dependencies['context'])
            ? $dependencies['context']
            : $dependencies['context'] = Services::get('context')->get($submission->getData('contextId'));

        foreach ($props as $prop) {
            switch ($prop) {
                case 'galleys':
                    $values[$prop] = array_map(
                        function ($galley) use ($dependencies) {
                            return Services::get('galley')->getSummaryProperties($galley, $dependencies);
                        },
                        $publication->getData('galleys')
                    );
                    break;
                case 'urlPublished':
                    $values[$prop] = $dispatcher->url(
                        $request,
                        \PKPApplication::ROUTE_PAGE,
                        $submissionContext->getData('urlPath'),
                        'preprint',
                        'view',
                        [$submission->getBestId(), 'version', $publication->getId()]
                    );
                    break;
            }
        }
    }

    /**
     * Make additional validation checks
     *
     * @param $hookName string
     * @param $args array [
     *		@option array Validation errors already identified
        *		@option string One of the VALIDATE_ACTION_* constants
        *		@option array The props being validated
        *		@option array The locales accepted for this object
        *    @option string The primary locale for this object
        * ]
        */
    public function validatePublication($hookName, $args)
    {
        $errors = & $args[0];
        $action = $args[1];
        $props = $args[2];
        $allowedLocales = $args[3];
        $primaryLocale = $args[4];

        // Ensure that the specified section exists
        $section = null;
        if (isset($props['sectionId'])) {
            $section = Application::get()->getSectionDAO()->getById($props['sectionId']);
            if (!$section) {
                $errors['sectionId'] = [__('publication.invalidSection')];
            }
        }

        // Get the section so we can validate section abstract requirements
        if (!$section && isset($props['id'])) {
            $publication = Services::get('publication')->get($props['id']);
            $sectionDao = DAORegistry::getDAO('SectionDAO'); /* @var $sectionDao SectionDAO */
            $section = $sectionDao->getById($publication->getData('sectionId'));
        }

        if ($section) {

            // Require abstracts if the section requires them
            if ($action === EntityWriteInterface::VALIDATE_ACTION_ADD && !$section->getData('abstractsNotRequired') && empty($props['abstract'])) {
                $errors['abstract'][$primaryLocale] = [__('author.submit.form.abstractRequired')];
            }

            if (isset($props['abstract']) && empty($errors['abstract'])) {

                // Require abstracts in the primary language if the section requires them
                if (!$section->getData('abstractsNotRequired')) {
                    if (empty($props['abstract'][$primaryLocale])) {
                        if (!isset($errors['abstract'])) {
                            $errors['abstract'] = [];
                        };
                        AppLocale::requireComponents(LOCALE_COMPONENT_APP_AUTHOR);
                        $errors['abstract'][$primaryLocale] = [__('author.submit.form.abstractRequired')];
                    }
                }

                // Check the word count on abstracts
                foreach ($allowedLocales as $localeKey) {
                    if (empty($props['abstract'][$localeKey])) {
                        continue;
                    }
                    $wordCount = count(preg_split('/\s+/', trim(str_replace('&nbsp;', ' ', strip_tags($props['abstract'][$localeKey])))));
                    $wordCountLimit = $section->getData('wordCount');
                    if ($wordCountLimit && $wordCount > $wordCountLimit) {
                        if (!isset($errors['abstract'])) {
                            $errors['abstract'] = [];
                        };
                        $errors['abstract'][$localeKey] = [__('publication.wordCountLong', ['limit' => $wordCountLimit, 'count' => $wordCount])];
                    }
                }
            }
        }
    }

    /**
     * Make additional validation checks against publishing requirements
     *
     * @see PKPPublicationService::validatePublish()
     *
     * @param $hookName string
     * @param $args array [
     *		@option array Validation errors already identified
     *		@option Publication The publication to validate
     *		@option Submission The submission of the publication being validated
     *		@option array The locales accepted for this object
     *		@option string The primary locale for this object
     * ]
     */
    public function validatePublishPublication($hookName, $args)
    {
        $errors = & $args[0];
        $submission = $args[2];
        if (!$this->canAuthorPublish($submission->getId())) {
            $errors['authorCheck'] = __('author.submit.authorsCanNotPublish');
        }
    }

    /**
     * Set OPS-specific objects when a new publication is created
     *
     * @param $hookName string
     * @param $args array [
     *		@option Publication The new publication
     *		@option Request
     * ]
     */
    public function addPublication($hookName, $args)
    {
        $publication = $args[0];
        $request = $args[1];

        // Assign DOI if automatic assigment is enabled
        $context = $request->getContext();
        $pubIdPlugins = PluginRegistry::loadCategory('pubIds', true, $context->getId());
        $doiPubIdPlugin = $pubIdPlugins['doipubidplugin'] ?? null;
        if ($doiPubIdPlugin && $doiPubIdPlugin->getSetting($context->getId(), 'enablePublicationDoiAutoAssign')) {
            $publication->setData('pub-id::doi', $doiPubIdPlugin->getPubId($publication));
        }
    }

    /**
     * Copy OPS-specific objects when a new publication version is created
     *
     * @param $hookName string
     * @param $args array [
     *		@option Publication The new version of the publication
     *		@option Publication The old version of the publication
     *		@option Request
     * ]
     */
    public function versionPublication($hookName, $args)
    {
        $newPublication = $args[0];
        $oldPublication = $args[1];
        $request = $args[2];

        // Duplicate galleys
        $galleys = $oldPublication->getData('galleys');
        if (!empty($galleys)) {
            foreach ($galleys as $galley) {
                $newGalley = clone $galley;
                $newGalley->setData('id', null);
                $newGalley->setData('publicationId', $newPublication->getId());
                Services::get('galley')->add($newGalley, $request);
            }
        }

        $newPublication->setData('galleys', $this->get($newPublication->getId())->getData('galleys'));

        // Version DOI if the pattern includes the publication id
        $context = $request->getContext();
        $pubIdPlugins = PluginRegistry::loadCategory('pubIds', true, $context->getId());
        $doiPubIdPlugin = $pubIdPlugins['doipubidplugin'] ?? null;
        if ($doiPubIdPlugin) {
            $pattern = $doiPubIdPlugin->getSetting($context->getId(), 'doiPublicationSuffixPattern');
            if (strpos($pattern, '%b')) {
                $newPublication->setData('pub-id::doi', $doiPubIdPlugin->versionPubId($newPublication));
            }
        }
    }

    /**
     * Modify a publication before it is published
     *
     * @param $hookName string
     * @param $args array [
     *		@option Publication The new version of the publication
     *		@option Publication The old version of the publication
     * ]
     */
    public function publishPublicationBefore($hookName, $args)
    {
        $newPublication = $args[0];
        $oldPublication = $args[1];

        // If the publish date is in the future, set the status to scheduled
        $datePublished = $oldPublication->getData('datePublished');
        if ($datePublished && strtotime($datePublished) > strtotime(\Core::getCurrentDate())) {
            $newPublication->setData('status', PKPSubmission::STATUS_SCHEDULED);
        }
    }

    /**
     * Fire events after a publication has been published
     *
     * @param $hookName string
     * @param $args array [
     *		@option Publication The new version of the publication
     *		@option Publication The old version of the publication
     *		@option Submission The publication's submission
     * ]
     */
    public function publishPublication($hookName, $args)
    {
        $newPublication = $args[0];
        $submission = $args[2];
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $dispatcher = $request->getDispatcher();

        // Send preprint posted acknowledgement email when the first version is published
        if ($newPublication->getData('version') == 1) {
            import('classes.mail.PreprintMailTemplate');
            $mail = new \PreprintMailTemplate($submission, 'POSTED_ACK', null, null, false);

            if ($mail->isEnabled()) {

                // posted ack emails should be from the contact.
                $mail->setFrom($context->getData('contactEmail'), $context->getData('contactName'));

                $user = $request->getUser();
                $mail->addRecipient($user->getEmail(), $user->getFullName());

                $mail->assignParams([
                    'authorName' => $user->getFullName(),
                    'authorUsername' => $user->getUsername(),
                    'editorialContactSignature' => $context->getData('contactName'),
                    'publicationUrl' => $dispatcher->url($request, \PKPApplication::ROUTE_PAGE, $context->getData('urlPath'), 'preprint', 'view', $submission->getBestId(), null, null, true),
                ]);

                if (!$mail->send($request)) {
                    import('classes.notification.NotificationManager');
                    $notificationMgr = new \NotificationManager();
                    $notificationMgr->createTrivialNotification($request->getUser()->getId(), NOTIFICATION_TYPE_ERROR, ['contents' => __('email.compose.error')]);
                }
            }
        }
    }

    /**
     * Delete OPS-specific objects before a publication is deleted
     *
     * @param $hookName string
     * @param $args array [
     *		@option Publication The publication being deleted
     * ]
     */
    public function deletePublicationBefore($hookName, $args)
    {
        $publication = $args[0];

        $galleysIterator = Services::get('galley')->getMany(['publicationIds' => $publication->getId()]);
        foreach ($galleysIterator as $galley) {
            Services::get('galley')->delete($galley);
        }
    }

    /**
     * Set preprint relations
     *
     * @param Publication $publication The publication to copy
     * @param Request
     *
     * @return Publication The new publication
     */
    public function relate($publication, $params)
    {
        $newPublication = Services::get('publication')->edit($publication, ['relationStatus' => $params['relationStatus'], 'vorDoi' => $params['vorDoi']], \Application::get()->getRequest());
        return $newPublication;
    }

    /**
     * Check if the server allows authors to publish
     *
     * @param $submissionId string
     *
     * @return boolean
     *
     */
    public function canAuthorPublish($submissionId)
    {

        // Check if current user is an author
        $isAuthor = false;
        $currentUser = Application::get()->getRequest()->getUser();
        $stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO'); /* @var $stageAssignmentDao StageAssignmentDAO */
        $submitterAssignments = $stageAssignmentDao->getBySubmissionAndRoleId($submissionId, ROLE_ID_AUTHOR);
        while ($assignment = $submitterAssignments->next()) {
            if ($currentUser->getId() == $assignment->getUserId()) {
                $isAuthor = true;
            }
        }

        // By default authors can not publish, but this can be overridden in screening plugins with the hook Publication::canAuthorPublish
        if ($isAuthor) {
            if (HookRegistry::call('Publication::canAuthorPublish', [$this])) {
                return true;
            } else {
                return false;
            }
        }

        // If the user is not an author, has to be an editor, return true
        return true;
    }

    /**
     * Get the preprint relation options
     *
     * @return json
     *
     */
    public function getRelationOptions()
    {
        return [
            [
                'value' => Publication::PUBLICATION_RELATION_NONE,
                'label' => __('publication.relation.none')
            ],
            [
                'value' => Publication::PUBLICATION_RELATION_SUBMITTED,
                'label' => __('publication.relation.submitted')
            ],
            [
                'value' => Publication::PUBLICATION_RELATION_PUBLISHED,
                'label' => __('publication.relation.published')
            ]
        ];
    }
}
